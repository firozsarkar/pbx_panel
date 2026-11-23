<?php
declare(strict_types=1);

$PANEL_ACCESS_KEY = "app";

$SERVERS = [
    [
        "name"  => "FreeSWITCH-Main (38.60.235.146)",
        "url"   => "http://38.60.235.146/fail2ban.php",
        "token" => "app",
        "jail"  => "freeswitch",
    ],
];

$providedKey = (string)($_GET['key'] ?? $_POST['key'] ?? $_SERVER['HTTP_X_PANEL_KEY'] ?? '');
if ($PANEL_ACCESS_KEY !== '' && !hash_equals($PANEL_ACCESS_KEY, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden: Invalid or missing ?key= parameter";
    exit;
}

function callNode(string $url, string $token, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension disabled'];
    }

    $body = array_merge($payload, ['token' => $token]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($body),
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
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'error' => "cURL ($errno): $err"];
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => "Invalid JSON (HTTP $code): " . substr(strip_tags((string)$raw), 0, 180)];
    }

    if ($code >= 400 || empty($json['status'])) {
        return ['ok' => false, 'error' => (string)($json['error'] ?? "HTTP $code")];
    }

    return ['ok' => true, 'data' => $json];
}

$unbanIp    = trim((string)($_GET['unban'] ?? $_POST['unban'] ?? ''));
$targetJail = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['jail'] ?? $_POST['jail'] ?? ''));
$format     = strtolower(trim((string)($_GET['format'] ?? 'html')));

$actionSummary = [];
$actionError   = null;

if ($unbanIp !== '') {
    if (filter_var($unbanIp, FILTER_VALIDATE_IP) === false) {
        $actionError = "Invalid IP: " . htmlspecialchars($unbanIp, ENT_QUOTES, 'UTF-8');
    } else {
        foreach ($SERVERS as $srv) {
            $jailUse = $targetJail !== '' ? $targetJail : ($srv['jail'] ?? 'freeswitch');
            $res = callNode($srv['url'], $srv['token'], [
                'action' => 'unban',
                'ip'     => $unbanIp,
                'jail'   => $jailUse,
            ]);
            $actionSummary[] = [
                'server'  => $srv['name'],
                'jail'    => $jailUse,
                'ip'      => $unbanIp,
                'ok'      => $res['ok'],
                'message' => $res['ok'] ? ($res['data']['output'] ?: 'Unbanned') : $res['error'],
            ];
        }
    }
}

$states = [];
foreach ($SERVERS as $srv) {
    $jailUse = $targetJail !== '' ? $targetJail : ($srv['jail'] ?? 'freeswitch');
    $res = callNode($srv['url'], $srv['token'], [
        'action' => 'list',
        'jail'   => $jailUse,
    ]);

    if ($res['ok']) {
        $states[] = [
            'name'        => $srv['name'],
            'online'      => true,
            'jail'        => (string)($res['data']['jail'] ?? $jailUse),
            'blacklisted' => $res['data']['blacklisted'] ?? [],
            'whitelisted' => $res['data']['whitelisted'] ?? [],
        ];
    } else {
        $states[] = [
            'name'        => $srv['name'],
            'online'      => false,
            'error'       => $res['error'],
            'jail'        => $jailUse,
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
        'servers'        => $states,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fail2Ban Central Unblock Panel</title>
  <style>
    :root {
      --bg: #0b1020; --card: #121a30; --border: #233052;
      --text: #e6ecff; --muted: #93a4d4; --gold: #f5b942; --green: #22c55e; --red: #ef4444;
    }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 24px; font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .wrap { max-width: 1120px; margin: 0 auto; }
    h1 { margin: 0 0 6px; font-size: 22px; color: var(--gold); }
    .sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }
    .box { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
    form.quick-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    input[type="text"] { background: #0b1020; border: 1px solid var(--border); color: var(--text); padding: 10px 12px; border-radius: 8px; min-width: 220px; }
    button, a.btn { border: 0; border-radius: 8px; padding: 10px 14px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-unban { background: var(--green); color: #052e16; }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .notice-ok { border-left: 4px solid var(--green); background: rgba(34, 197, 94, 0.1); padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
    .notice-err { border-left: 4px solid var(--red); background: rgba(239, 68, 68, 0.12); padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
    .server-hdr { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .badge-ok { background: rgba(34, 197, 94, 0.18); color: #86efac; padding: 4px 10px; border-radius: 99px; font-size: 12px; }
    .badge-err { background: rgba(239, 68, 68, 0.2); color: #fca5a5; padding: 4px 10px; border-radius: 99px; font-size: 12px; }
    .ip-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .ip-chip { background: #0b1020; border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; display: inline-flex; align-items: center; gap: 8px; font-family: monospace; font-size: 13px; }
    .muted { color: var(--muted); font-size: 13px; }
    code { color: var(--gold); }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Fail2Ban Central Unblock Panel</h1>
  <div class="sub">সরাসরি URL ভিজিট করতে পারেন: <code>?key=<?= e($providedKey) ?>&amp;unban=IP_ADDRESS</code></div>

  <?php if ($actionError !== null): ?>
    <div class="notice-err"><?= $actionError ?></div>
  <?php endif; ?>

  <?php if (!empty($actionSummary)): ?>
    <div class="notice-ok">
      <strong>অ্যাকশন রেজাল্ট:</strong>
      <ul style="margin: 8px 0 0; padding-left: 18px;">
        <?php foreach ($actionSummary as $item): ?>
          <li><strong><?= e($item['server']) ?></strong> [<?= e($item['jail']) ?>] — IP: <code><?= e($item['ip']) ?></code> — <?= $item['ok'] ? 'সফল' : 'ব্যর্থ' ?> (<?= e($item['message']) ?>)</li>
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

  <?php foreach ($states as $st): ?>
    <div class="box">
      <div class="server-hdr">
        <div>
          <strong><?= e($st['name']) ?></strong>
          <span class="muted"> — Jail: <code><?= e($st['jail']) ?></code></span>
        </div>
        <?= $st['online'] ? '<span class="badge-ok">Online</span>' : '<span class="badge-err">Offline / Error</span>' ?>
      </div>

      <?php if (!$st['online']): ?>
        <div class="notice-err" style="margin:0;"><?= e($st['error'] ?? 'Error') ?></div>
      <?php else: ?>
        <div class="muted">Banned IPs (<?= count($st['blacklisted']) ?>) | Whitelisted: <code><?= e(implode(', ', $st['whitelisted'])) ?></code></div>
        <?php if (empty($st['blacklisted'])): ?>
          <p class="muted" style="margin-top:10px;">এই জেলে বর্তমানে কোনো ব্যান IP নেই।</p>
        <?php else: ?>
          <div class="ip-list">
            <?php foreach ($st['blacklisted'] as $bip): ?>
              <?php $u = "?key=" . urlencode($providedKey) . "&jail=" . urlencode((string)$st['jail']) . "&unban=" . urlencode((string)$bip); ?>
              <div class="ip-chip">
                <span><?= e((string)$bip) ?></span>
                <a class="btn btn-unban btn-sm" href="<?= e($u) ?>">Unblock</a>
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
