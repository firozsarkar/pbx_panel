<?php
declare(strict_types=1);

// ==========================================
// ১. কনফিগারেশন
// ==========================================
$ACCESS_KEY     = "app"; // প্যানেল (?key=) ও API (?token= বা X-API-Token) এর কী
$DEFAULT_JAIL   = "freeswitch";
$JAIL_LOCAL     = "/etc/fail2ban/jail.local";

// অন্যান্য সার্ভারের একই ফাইলের URL এখানে যোগ করতে পারেন
$REMOTE_NODES = [
    // [
    //     "name"  => "Remote-Node-2",
    //     "url"   => "http://OTHER_SERVER_IP/index.php",
    //     "token" => "app",
    //     "jail"  => "freeswitch",
    // ],
];

// ==========================================
// ২. হেল্পার ফাংশন (লোকাল Fail2Ban + cURL)
// ==========================================
function runLocalCmd(string $cmd): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $out = shell_exec($cmd . " 2>&1");
    return is_string($out) ? trim($out) : '';
}

function getLocalJails(): array
{
    $raw = runLocalCmd("sudo /usr/bin/fail2ban-client status");
    if (preg_match('/Jail list:\s*(.+)/', $raw, $m)) {
        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }
    return [];
}

function getLocalBanned(string $jail): array
{
    $raw = runLocalCmd("sudo /usr/bin/fail2ban-client status " . escapeshellarg($jail));
    if (preg_match('/Banned IP list:\s*(.*)/', $raw, $m)) {
        $list = trim($m[1]);
        return $list !== '' ? (preg_split('/\s+/', $list) ?: []) : [];
    }
    return [];
}

function getLocalWhitelist(string $jail): array
{
    $raw = runLocalCmd("sudo /usr/bin/fail2ban-client get " . escapeshellarg($jail) . " ignoreip");
    if (preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?\b/', $raw, $m)) {
        return array_values(array_unique($m[0]));
    }
    return [];
}

function executeLocalAction(string $action, string $ip, string $jail, string $jailLocalPath): array
{
    $jails = getLocalJails();
    if (!empty($jails) && !in_array($jail, $jails, true)) {
        $jail = $jails[0];
    }

    $cmdOut = '';
    $syncOk = null;

    if ($action !== 'list') {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'error' => 'Invalid IP address'];
        }

        $qIp   = escapeshellarg($ip);
        $qJail = escapeshellarg($jail);

        if ($action === 'ban') {
            $cmdOut = runLocalCmd("sudo /usr/bin/fail2ban-client set $qJail banip $qIp");
        } elseif ($action === 'unban') {
            $cmdOut = runLocalCmd("sudo /usr/bin/fail2ban-client set $qJail unbanip $qIp");
            runLocalCmd("sudo /usr/bin/fail2ban-client set recidive unbanip $qIp");
            runLocalCmd("sudo /usr/sbin/conntrack -D -s $qIp");
            runLocalCmd("sudo /usr/sbin/conntrack -D -d $qIp");
        } elseif ($action === 'whitelist') {
            runLocalCmd("sudo /usr/bin/fail2ban-client set $qJail unbanip $qIp");
            runLocalCmd("sudo /usr/bin/fail2ban-client set recidive unbanip $qIp");
            $cmdOut = runLocalCmd("sudo /usr/bin/fail2ban-client set $qJail addignoreip $qIp");
            if (is_file($jailLocalPath) && is_writable($jailLocalPath)) {
                $txt = (string)file_get_contents($jailLocalPath);
                if ($txt !== '' && !preg_match('/(?:^|\s)' . preg_quote($ip, '/') . '(?=\s|$)/', $txt)) {
                    $upd = preg_replace('/^(ignoreip\s*=\s*.*)$/m', '$1 ' . $ip, $txt, 1);
                    if (is_string($upd)) {
                        $syncOk = file_put_contents($jailLocalPath, $upd, LOCK_EX) !== false;
                    }
                }
            }
        } elseif ($action === 'del_whitelist') {
            $cmdOut = runLocalCmd("sudo /usr/bin/fail2ban-client set $qJail delignoreip $qIp");
        }
    }

    return [
        'ok'              => true,
        'jail'            => $jail,
        'available_jails' => $jails,
        'ip'              => $ip !== '' ? $ip : null,
        'jail_local_sync' => $syncOk,
        'output'          => $cmdOut,
        'blacklisted'     => getLocalBanned($jail),
        'whitelisted'     => getLocalWhitelist($jail),
    ];
}

function callRemoteNode(string $url, string $token, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension disabled'];
    }

    $postBody = array_merge($payload, ['api' => '1', 'token' => $token]);
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $targetUrl = $url . $sep . 'api=1&token=' . urlencode($token);

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postBody),
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

// ==========================================
// ৩. অথেনটিকেশন
// ==========================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$normHeaders = [];
foreach ($headers as $k => $v) {
    $normHeaders[strtolower((string)$k)] = (string)$v;
}

$rawBody = file_get_contents("php://input");
$jsonBody = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : [];
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$input = array_merge($_GET, $_POST, $jsonBody);
$authHeader = $normHeaders['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearer = '';
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
    $bearer = trim($m[1]);
}

$providedCred = (string)(
    $normHeaders['x-api-token']
    ?? $normHeaders['x-panel-key']
    ?? ($bearer !== '' ? $bearer : null)
    ?? ($input['token'] ?? $input['key'] ?? '')
);

if ($ACCESS_KEY !== '' && !hash_equals($ACCESS_KEY, $providedCred)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => false,
        "error"  => "403 Forbidden: Invalid or missing key/token"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$isApiMode  = isset($input['api']) && in_array(strtolower((string)$input['api']), ['1', 'true', 'json'], true);
$format     = strtolower(trim((string)($input['format'] ?? 'html')));
$targetJail = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['jail'] ?? $DEFAULT_JAIL));
if ($targetJail === '') {
    $targetJail = $DEFAULT_JAIL;
}

// ==========================================
// ৪. JSON API মোড (?api=1)
// ==========================================
if ($isApiMode || $format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $action = strtolower(trim((string)($input['action'] ?? 'list')));
    $ip     = trim((string)($input['ip'] ?? ''));
    $allowedActions = ['list', 'ban', 'unban', 'whitelist', 'del_whitelist'];

    if (!in_array($action, $allowedActions, true)) {
        http_response_code(400);
        echo json_encode(["status" => false, "error" => "Invalid action"], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $localRes = executeLocalAction($action, $ip, $targetJail, $JAIL_LOCAL);
    if (!$localRes['ok']) {
        http_response_code(400);
        echo json_encode(["status" => false, "error" => $localRes['error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "status"          => true,
        "action"          => $action,
        "jail"            => $localRes['jail'],
        "available_jails" => $localRes['available_jails'],
        "ip"              => $localRes['ip'],
        "jail_local_sync" => $localRes['jail_local_sync'],
        "output"          => $localRes['output'],
        "blacklisted"     => $localRes['blacklisted'],
        "whitelisted"     => $localRes['whitelisted'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// ৫. ড্যাশবোর্ড ও এক-URL Unban হ্যান্ডলার
// ==========================================
$unbanIp       = trim((string)($input['unban'] ?? ''));
$actionSummary = [];
$actionError   = null;

if ($unbanIp !== '') {
    if (filter_var($unbanIp, FILTER_VALIDATE_IP) === false) {
        $actionError = "Invalid IP address: " . htmlspecialchars($unbanIp, ENT_QUOTES, 'UTF-8');
    } else {
        $localUnban = executeLocalAction('unban', $unbanIp, $targetJail, $JAIL_LOCAL);
        $actionSummary[] = [
            'server'  => 'Local Server (' . ($_SERVER['SERVER_ADDR'] ?? 'this-node') . ')',
            'jail'    => $localUnban['jail'] ?? $targetJail,
            'ip'      => $unbanIp,
            'ok'      => (bool)($localUnban['ok'] ?? false),
            'message' => ($localUnban['output'] ?? '') !== '' ? $localUnban['output'] : 'Unbanned',
        ];

        foreach ($REMOTE_NODES as $node) {
            $nodeJail = $targetJail !== '' ? $targetJail : ($node['jail'] ?? $DEFAULT_JAIL);
            $res = callRemoteNode($node['url'], $node['token'], [
                'action' => 'unban',
                'ip'     => $unbanIp,
                'jail'   => $nodeJail,
            ]);
            $actionSummary[] = [
                'server'  => $node['name'],
                'jail'    => $nodeJail,
                'ip'      => $unbanIp,
                'ok'      => $res['ok'],
                'message' => $res['ok'] ? (($res['data']['output'] ?? '') !== '' ? $res['data']['output'] : 'Unbanned') : $res['error'],
            ];
        }
    }
}

$localList = executeLocalAction('list', '', $targetJail, $JAIL_LOCAL);
$serverStates = [
    [
        'name'        => 'Local Server (' . ($_SERVER['SERVER_ADDR'] ?? 'this-node') . ')',
        'online'      => (bool)($localList['ok'] ?? false),
        'error'       => $localList['error'] ?? null,
        'jail'        => (string)($localList['jail'] ?? $targetJail),
        'blacklisted' => $localList['blacklisted'] ?? [],
        'whitelisted' => $localList['whitelisted'] ?? [],
    ],
];

foreach ($REMOTE_NODES as $node) {
    $nodeJail = $targetJail !== '' ? $targetJail : ($node['jail'] ?? $DEFAULT_JAIL);
    $res = callRemoteNode($node['url'], $node['token'], [
        'action' => 'list',
        'jail'   => $nodeJail,
    ]);
    if ($res['ok']) {
        $serverStates[] = [
            'name'        => $node['name'],
            'online'      => true,
            'jail'        => (string)($res['data']['jail'] ?? $nodeJail),
            'blacklisted' => $res['data']['blacklisted'] ?? [],
            'whitelisted' => $res['data']['whitelisted'] ?? [],
        ];
    } else {
        $serverStates[] = [
            'name'        => $node['name'],
            'online'      => false,
            'error'       => $res['error'],
            'jail'        => $nodeJail,
            'blacklisted' => [],
            'whitelisted' => [],
        ];
    }
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
  <div class="sub">সরাসরি URL ভিজিট করতে পারেন: <code>?key=<?= e($providedCred) ?>&amp;unban=IP_ADDRESS</code></div>

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
      <input type="hidden" name="key" value="<?= e($providedCred) ?>">
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
              <?php $u = "?key=" . urlencode($providedCred) . "&jail=" . urlencode((string)$st['jail']) . "&unban=" . urlencode((string)$bip); ?>
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
