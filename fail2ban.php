<?php
declare(strict_types=1);

// ==========================================
// ১. কনফিগারেশন (নিজের সার্ভার ও টোকেন বসান)
// ==========================================
$PANEL_ACCESS_KEY = "app"; // প্যানেল/URL ভিজিটের সিক্রেট কী

$SERVERS = [
    [
        "name"  => "FreeSWITCH-Main (38.60.235.146)",
        "url"   => "http://38.60.235.146/fail2ban.php",
        "token" => "app", // ওই সার্ভারের fail2ban.php এ থাকা $API_TOKEN
        "jail"  => "freeswitch",
    ],
    // ভবিষ্যতে আরও সার্ভার এভাবে যোগ করতে পারবেন:
    // [
    //     "name"  => "VoIP-Node-2",
    //     "url"   => "http://OTHER_SERVER_IP/fail2ban.php",
    //     "token" => "OTHER_SERVER_TOKEN",
    //     "jail"  => "freeswitch",
    // ],
];

// ==========================================
// ২. অ্যাক্সেস ভেরিফিকেশন
// ==========================================
$providedKey = (string)($_GET['key'] ?? $_POST['key'] ?? $_SERVER['HTTP_X_PANEL_KEY'] ?? '');
if ($PANEL_ACCESS_KEY !== '' && !hash_equals($PANEL_ACCESS_KEY, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden: Invalid or missing ?key= parameter";
    exit;
}

// ==========================================
// ৩. API কল হেল্পার (cURL)
// ==========================================
function callNodeApi(string $url, string $token, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is not enabled'];
    }

    $postData = array_merge($payload, ['token' => $token]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_HTTPHEADER     => [
            "X-API-Token: " . $token,
            "Content-Type: application/x-www-form-urlencoded",
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $raw = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        return ['ok' => false, 'error' => "cURL Error ($errNo): $errMsg"];
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return [
            'ok'    => false,
            'error' => "Invalid JSON response (HTTP $httpCode): " . substr((string)$raw, 0, 200),
        ];
    }

    if ($httpCode >= 400 || empty($decoded['status'])) {
        return [
            'ok'    => false,
            'error' => (string)($decoded['error'] ?? "HTTP $httpCode error"),
            'raw'   => $decoded,
        ];
    }

    return ['ok' => true, 'data' => $decoded];
}

// ==========================================
// ৪. Unban / Whitelist / List প্রসেসিং
// ==========================================
$unbanIp     = trim((string)($_GET['unban'] ?? $_POST['unban'] ?? ''));
$whitelistIp = trim((string)($_GET['whitelist'] ?? $_POST['whitelist'] ?? ''));
$targetJail  = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['jail'] ?? $_POST['jail'] ?? ''));
$format      = strtolower(trim((string)($_GET['format'] ?? 'html')));

$actionSummary = [];
$actionError   = null;

if ($unbanIp !== '') {
    if (filter_var($unbanIp, FILTER_VALIDATE_IP) === false) {
        $actionError = "Invalid IP address provided for unban: " . htmlspecialchars($unbanIp, ENT_QUOTES, 'UTF-8');
    } else {
        foreach ($SERVERS as $srv) {
            $jailToUse = $targetJail !== '' ? $targetJail : ($srv['jail'] ?? 'freeswitch');
            $res = callNodeApi($srv['url'], $srv['token'], [
                'action' => 'unban',
                'ip'     => $unbanIp,
                'jail'   => $jailToUse,
            ]);
            // recidive জেলেও আনব্যান করার চেষ্টা
            $recidiveRes = callNodeApi($srv['url'], $srv['token'], [
                'action' => 'unban',
                'ip'     => $unbanIp,
                'jail'   => 'recidive',
            ]);

            $actionSummary[] = [
                'server'       => $srv['name'],
                'jail'         => $jailToUse,
                'ip'           => $unbanIp,
                'ok'           => $res['ok'],
                'message'      => $res['ok'] ? ($res['data']['output'] ?: 'Unbanned') : $res['error'],
                'recidive_msg' => $recidiveRes['ok'] ? ($recidiveRes['data']['output'] ?: 'Checked') : $recidiveRes['error'],
            ];
        }
    }
}

if ($whitelistIp !== '') {
    if (filter_var($whitelistIp, FILTER_VALIDATE_IP) === false) {
        $actionError = "Invalid IP address provided for whitelist: " . htmlspecialchars($whitelistIp, ENT_QUOTES, 'UTF-8');
    } else {
        foreach ($SERVERS as $srv) {
            $jailToUse = $targetJail !== '' ? $targetJail : ($srv['jail'] ?? 'freeswitch');
            $res = callNodeApi($srv['url'], $srv['token'], [
                'action' => 'whitelist',
                'ip'     => $whitelistIp,
                'jail'   => $jailToUse,
            ]);

            $actionSummary[] = [
                'server'  => $srv['name'],
                'jail'    => $jailToUse,
                'ip'      => $whitelistIp,
                'ok'      => $res['ok'],
                'message' => $res['ok'] ? 'Whitelisted & Unbanned' : $res['error'],
            ];
        }
    }
}

// সব সার্ভার থেকে লাইভ লিস্ট সংগ্রহ
$serverStates = [];
foreach ($SERVERS as $srv) {
    $jailToUse = $targetJail !== '' ? $targetJail : ($srv['jail'] ?? 'freeswitch');
    $res = callNodeApi($srv['url'], $srv['token'], [
        'action' => 'list',
        'jail'   => $jailToUse,
    ]);

    if ($res['ok']) {
        $serverStates[] = [
            'name'            => $srv['name'],
            'url'             => $srv['url'],
            'online'          => true,
            'jail'            => (string)($res['data']['jail'] ?? $jailToUse),
            'available_jails' => $res['data']['available_jails'] ?? [],
            'blacklisted'     => $res['data']['blacklisted'] ?? [],
            'whitelisted'     => $res['data']['whitelisted'] ?? [],
        ];
    } else {
        $serverStates[] = [
            'name'        => $srv['name'],
            'url'         => $srv['url'],
            'online'      => false,
            'error'       => $res['error'],
            'jail'        => $jailToUse,
            'blacklisted' => [],
            'whitelisted' => [],
        ];
    }
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'         => $actionError === null,
        'action_error'   => $actionError,
        'action_summary' => $actionSummary,
        'servers'        => $serverStates,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function e(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fail2Ban Central Unblock & Monitor</title>
  <style>
    :root {
      --bg: #0b1020;
      --card: #121a30;
      --border: #233052;
      --text: #e6ecff;
      --muted: #93a4d4;
      --gold: #f5b942;
      --green: #22c55e;
      --red: #ef4444;
      --blue: #3b82f6;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 24px;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .wrap { max-width: 1120px; margin: 0 auto; }
    h1 { margin: 0 0 6px; font-size: 22px; color: var(--gold); }
    .sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }
    .box {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px 18px;
      margin-bottom: 18px;
    }
    form.quick-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    input[type="text"] {
      background: #0b1020;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px 12px;
      border-radius: 8px;
      min-width: 220px;
      font-size: 14px;
    }
    button, a.btn {
      border: 0;
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
    }
    .btn-unban { background: var(--green); color: #052e16; }
    .btn-wl { background: var(--blue); color: #fff; }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .notice-ok {
      border-left: 4px solid var(--green);
      background: rgba(34, 197, 94, 0.1);
      padding: 12px 14px;
      border-radius: 8px;
      margin-bottom: 16px;
    }
    .notice-err {
      border-left: 4px solid var(--red);
      background: rgba(239, 68, 68, 0.12);
      padding: 12px 14px;
      border-radius: 8px;
      margin-bottom: 16px;
    }
    .server-hdr { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .badge-ok { background: rgba(34, 197, 94, 0.18); color: #86efac; padding: 4px 10px; border-radius: 99px; font-size: 12px; }
    .badge-err { background: rgba(239, 68, 68, 0.2); color: #fca5a5; padding: 4px 10px; border-radius: 99px; font-size: 12px; }
    .ip-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .ip-chip {
      background: #0b1020;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 6px 10px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 13px;
    }
    .muted { color: var(--muted); font-size: 13px; }
    code { color: var(--gold); }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Fail2Ban Central Unblock Panel</h1>
  <div class="sub">
    সরাসরি URL ভিজিট করতে পারেন:
    <code>?unban=IP_ADDRESS&amp;key=<?= e($providedKey) ?></code>
  </div>

  <?php if ($actionError !== null): ?>
    <div class="notice-err"><?= $actionError ?></div>
  <?php endif; ?>

  <?php if (!empty($actionSummary)): ?>
    <div class="notice-ok">
      <strong>অ্যাকশন রেজাল্ট:</strong>
      <ul style="margin: 8px 0 0; padding-left: 18px;">
        <?php foreach ($actionSummary as $item): ?>
          <li>
            <strong><?= e($item['server']) ?></strong> [<?= e($item['jail']) ?>] —
            IP: <code><?= e($item['ip']) ?></code> —
            <?= $item['ok'] ? 'সফল' : 'ব্যর্থ' ?> (<?= e($item['message']) ?>)
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="box">
    <form method="GET" class="quick-form">
      <input type="hidden" name="key" value="<?= e($providedKey) ?>">
      <input type="text" name="unban" placeholder="IP লিখুন (যেমন: 103.x.x.x)" value="<?= e($unbanIp) ?>">
      <input type="text" name="jail" placeholder="Jail (ডিফল্ট: freeswitch)" value="<?= e($targetJail) ?>">
      <button type="submit" class="btn-unban">সব সার্ভারে Unblock করুন</button>
    </form>
  </div>

  <?php foreach ($serverStates as $st): ?>
    <div class="box">
      <div class="server-hdr">
        <div>
          <strong><?= e($st['name']) ?></strong>
          <span class="muted"> — Jail: <code><?= e($st['jail']) ?></code></span>
        </div>
        <?php if ($st['online']): ?>
          <span class="badge-ok">Online</span>
        <?php else: ?>
          <span class="badge-err">Offline / Error</span>
        <?php endif; ?>
      </div>

      <?php if (!$st['online']): ?>
        <div class="notice-err" style="margin:0;"><?= e($st['error'] ?? 'Unknown error') ?></div>
      <?php else: ?>
        <div class="muted">
          Banned IPs (<?= count($st['blacklisted']) ?>) |
          Whitelisted: <code><?= e(implode(', ', $st['whitelisted'])) ?></code>
        </div>

        <?php if (empty($st['blacklisted'])): ?>
          <p class="muted" style="margin-top:10px;">এই জেলে বর্তমানে কোনো ব্যান IP নেই।</p>
        <?php else: ?>
          <div class="ip-list">
            <?php foreach ($st['blacklisted'] as $bip): ?>
              <?php
                $unbanUrl = "?unban=" . urlencode((string)$bip)
                          . "&jail=" . urlencode((string)$st['jail'])
                          . "&key=" . urlencode($providedKey);
              ?>
              <div class="ip-chip">
                <span><?= e((string)$bip) ?></span>
                <a class="btn btn-unban btn-sm" href="<?= e($unbanUrl) ?>">Unblock</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
