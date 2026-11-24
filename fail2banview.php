<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

/**
 * নিরাপত্তা: চাইলে একটি সিক্রেট কী সেট করুন (যেমন: ?key=mysecret123)
 * ফাঁকা রাখলে ('') সরাসরি অ্যাক্সেস করা যাবে।
 */
$SECRET_KEY = '';

if ($SECRET_KEY !== '') {
    $provided = (string)($_REQUEST['key'] ?? '');
    if (!hash_equals($SECRET_KEY, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden: Invalid key');
    }
}

/**
 * কমান্ড চালানোর হেল্পার (www-data থেকে sudo বা root থেকে সরাসরি)
 */
function runCmd(string $cmd): array {
    if (!function_exists('exec')) {
        return ['code' => 127, 'output' => [], 'raw' => 'exec() disabled'];
    }
    $prefix = (function_exists('posix_geteuid') && posix_geteuid() === 0) ? '' : 'sudo -n ';
    $fullCmd = $prefix . $cmd . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($fullCmd, $out, $code);
    return [
        'code'   => $code,
        'output' => $out,
        'raw'    => implode("\n", $out),
    ];
}

function isValidIpOrCidr(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    if (strpos($value, '/') !== false) {
        [$ip, $mask] = explode('/', $value, 2);
        if (!ctype_digit($mask)) {
            return false;
        }
        $m = (int)$mask;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $m >= 0 && $m <= 32;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $m >= 0 && $m <= 128;
        }
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function isValidJailName(string $jail): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_.-]+$/', $jail);
}

/**
 * সব Jail-এর তালিকা সংগ্রহ
 */
function getAllJails(): array {
    $res = runCmd('fail2ban-client status');
    $jails = [];
    foreach ($res['output'] as $line) {
        if (stripos($line, 'Jail list:') !== false) {
            $parts = explode(':', $line, 2);
            if (isset($parts[1])) {
                foreach (explode(',', $parts[1]) as $j) {
                    $j = trim($j);
                    if ($j !== '' && isValidJailName($j)) {
                        $jails[] = $j;
                    }
                }
            }
        }
    }
    return $jails;
}

/**
 * প্রতিটি Jail-এর বিস্তারিত স্ট্যাটাস ও ব্যান হওয়া IP লিস্ট
 */
function getJailDetails(array $jails): array {
    $details = [];
    foreach ($jails as $jail) {
        $res = runCmd('fail2ban-client status ' . escapeshellarg($jail));
        $currentlyFailed = 0;
        $totalFailed = 0;
        $currentlyBanned = 0;
        $totalBanned = 0;
        $bannedIps = [];

        foreach ($res['output'] as $line) {
            if (preg_match('/Currently failed:\s*(\d+)/i', $line, $m)) {
                $currentlyFailed = (int)$m[1];
            } elseif (preg_match('/Total failed:\s*(\d+)/i', $line, $m)) {
                $totalFailed = (int)$m[1];
            } elseif (preg_match('/Currently banned:\s*(\d+)/i', $line, $m)) {
                $currentlyBanned = (int)$m[1];
            } elseif (preg_match('/Total banned:\s*(\d+)/i', $line, $m)) {
                $totalBanned = (int)$m[1];
            } elseif (preg_match('/Banned IP list:\s*(.*)$/i', $line, $m)) {
                $listStr = trim($m[1]);
                if ($listStr !== '') {
                    foreach (preg_split('/\s+/', $listStr) as $ip) {
                        $ip = trim($ip);
                        if ($ip !== '') {
                            $bannedIps[] = $ip;
                        }
                    }
                }
            }
        }

        $details[$jail] = [
            'currently_failed' => $currentlyFailed,
            'total_failed'     => $totalFailed,
            'currently_banned' => $currentlyBanned,
            'total_banned'     => $totalBanned,
            'banned_ips'       => $bannedIps,
        ];
    }
    return $details;
}

/**
 * নির্দিষ্ট Jail-এর হোয়াইটলিস্ট (ignoreip) পড়া
 */
function getIgnoreIps(string $jail): array {
    $res = runCmd('fail2ban-client get ' . escapeshellarg($jail) . ' ignoreip');
    $ips = [];
    foreach ($res['output'] as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'These IP addresses/networks') !== false || stripos($line, 'No IP address') !== false) {
            continue;
        }
        $line = preg_replace('/^[|`-\s]+/', '', $line);
        if ($line !== '' && isValidIpOrCidr($line)) {
            $ips[] = $line;
        }
    }
    return $ips;
}

/**
 * iptables-এ REJECT / DROP হওয়া ব্যান এন্ট্রিগুলো পড়া
 */
function getIptablesBanned(): array {
    $res = runCmd('iptables -L -n');
    $entries = [];
    $currentChain = '';
    foreach ($res['output'] as $line) {
        if (preg_match('/^Chain\s+(\S+)/', $line, $m)) {
            $currentChain = $m[1];
            continue;
        }
        if (preg_match('/^(REJECT|DROP)\s+\S+\s+--\s+(\d+\.\d+\.\d+\.\d+(?:\/\d+)?)\s+0\.0\.0\.0\/0/', $line, $m)) {
            if ($m[2] !== '0.0.0.0/0') {
                $entries[] = [
                    'chain'  => $currentChain,
                    'target' => $m[1],
                    'ip'     => $m[2],
                ];
            }
        }
    }
    return $entries;
}

/**
 * JSON API মোড (?api=1 বা Accept: application/json)
 */
function wantsJson(): bool {
    if (isset($_REQUEST['api']) && $_REQUEST['api'] === '1') {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false;
}

$jails = getAllJails();
$flashMsg = '';
$flashType = 'ok';

/**
 * অ্যাকশন হ্যান্ডলার (Unban / Ban / Whitelist / Unban All)
 */
$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    if ($action === 'unban') {
        $ip = trim((string)($_REQUEST['ip'] ?? ''));
        $jail = trim((string)($_REQUEST['jail'] ?? 'all'));

        if (!isValidIpOrCidr($ip)) {
            $flashMsg = 'সঠিক IP অ্যাড্রেস দিন।';
            $flashType = 'err';
        } else {
            $unbannedFrom = [];
            $targets = ($jail === 'all' || $jail === '') ? $jails : (isValidJailName($jail) ? [$jail] : []);
            foreach ($targets as $j) {
                $r = runCmd('fail2ban-client set ' . escapeshellarg($j) . ' unbanip ' . escapeshellarg($ip));
                if ($r['code'] === 0 && trim($r['raw']) === '1') {
                    $unbannedFrom[] = $j;
                }
            }
            // সরাসরি global unban কমান্ডও চালানো
            runCmd('fail2ban-client unban ' . escapeshellarg($ip));

            if (!empty($unbannedFrom)) {
                $flashMsg = "IP {$ip} সফলভাবে আনব্যান করা হয়েছে (" . implode(', ', $unbannedFrom) . ")।";
                $flashType = 'ok';
            } else {
                $flashMsg = "IP {$ip} এর জন্য আনব্যান কমান্ড চালানো হয়েছে।";
                $flashType = 'ok';
            }
        }
    } elseif ($action === 'ban') {
        $ip = trim((string)($_REQUEST['ip'] ?? ''));
        $jail = trim((string)($_REQUEST['jail'] ?? 'freeswitch'));

        if (!isValidIpOrCidr($ip)) {
            $flashMsg = 'সঠিক IP অ্যাড্রেস দিন।';
            $flashType = 'err';
        } elseif (!isValidJailName($jail) || !in_array($jail, $jails, true)) {
            $flashMsg = 'সঠিক Jail নির্বাচন করুন।';
            $flashType = 'err';
        } else {
            $r = runCmd('fail2ban-client set ' . escapeshellarg($jail) . ' banip ' . escapeshellarg($ip));
            if ($r['code'] === 0) {
                $flashMsg = "IP {$ip} কে '{$jail}' jail-এ ব্যান করা হয়েছে।";
                $flashType = 'ok';
            } else {
                $flashMsg = "ব্যান ব্যর্থ হয়েছে: " . $r['raw'];
                $flashType = 'err';
            }
        }
    } elseif ($action === 'whitelist_add') {
        $ip = trim((string)($_REQUEST['ip'] ?? ''));
        if (!isValidIpOrCidr($ip)) {
            $flashMsg = 'হোয়াইটলিস্টের জন্য সঠিক IP বা CIDR দিন।';
            $flashType = 'err';
        } else {
            foreach ($jails as $j) {
                runCmd('fail2ban-client set ' . escapeshellarg($j) . ' addignoreip ' . escapeshellarg($ip));
                runCmd('fail2ban-client set ' . escapeshellarg($j) . ' unbanip ' . escapeshellarg($ip));
            }
            $flashMsg = "IP {$ip} সব jail-এ হোয়াইটলিস্ট (ignoreip) এবং আনব্যান করা হয়েছে।";
            $flashType = 'ok';
        }
    } elseif ($action === 'whitelist_del') {
        $ip = trim((string)($_REQUEST['ip'] ?? ''));
        if (isValidIpOrCidr($ip)) {
            foreach ($jails as $j) {
                runCmd('fail2ban-client set ' . escapeshellarg($j) . ' delignoreip ' . escapeshellarg($ip));
            }
            $flashMsg = "IP {$ip} হোয়াইটলিস্ট থেকে সরানো হয়েছে।";
            $flashType = 'ok';
        }
    } elseif ($action === 'unban_all') {
        $r = runCmd('fail2ban-client unban --all');
        if ($r['code'] === 0) {
            $flashMsg = 'সব jail থেকে সব ব্যান হওয়া IP আনব্যান করা হয়েছে।';
            $flashType = 'ok';
        } else {
            $flashMsg = 'সব আনব্যান ব্যর্থ: ' . $r['raw'];
            $flashType = 'err';
        }
    }

    if (wantsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => $flashType === 'ok' ? 'success' : 'error',
            'message' => $flashMsg,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$jailDetails = getJailDetails($jails);
$iptablesList = getIptablesBanned();
$whitelistIps = !empty($jails) ? getIgnoreIps($jails[0]) : [];

/**
 * ফ্ল্যাট ব্যান লিস্ট তৈরি (IP অনুযায়ী গ্রুপ করা)
 */
$bannedMap = [];
foreach ($jailDetails as $jailName => $info) {
    foreach ($info['banned_ips'] as $ip) {
        if (!isset($bannedMap[$ip])) {
            $bannedMap[$ip] = [
                'ip'        => $ip,
                'jails'     => [],
                'in_ipt'    => false,
                'ipt_chain' => [],
            ];
        }
        $bannedMap[$ip]['jails'][] = $jailName;
    }
}

foreach ($iptablesList as $ipt) {
    $ip = $ipt['ip'];
    if (!isset($bannedMap[$ip])) {
        $bannedMap[$ip] = [
            'ip'        => $ip,
            'jails'     => [],
            'in_ipt'    => true,
            'ipt_chain' => [$ipt['chain']],
        ];
    } else {
        $bannedMap[$ip]['in_ipt'] = true;
        if (!in_array($ipt['chain'], $bannedMap[$ip]['ipt_chain'], true)) {
            $bannedMap[$ip]['ipt_chain'][] = $ipt['chain'];
        }
    }
}

/**
 * IP সার্চ ও Jail ফিল্টার
 */
$searchIp = trim((string)($_GET['search'] ?? ''));
$filterJail = trim((string)($_GET['jail'] ?? ''));

$filteredBanned = array_filter($bannedMap, static function (array $row) use ($searchIp, $filterJail): bool {
    if ($searchIp !== '' && stripos($row['ip'], $searchIp) === false) {
        return false;
    }
    if ($filterJail !== '' && !in_array($filterJail, $row['jails'], true)) {
        return false;
    }
    return true;
});

/**
 * সার্চ করা IP-এর Fail2Ban লগ হিস্ট্রি দেখা (যদি সার্চ বক্সে IP লেখা হয়)
 */
$searchLogs = [];
if ($searchIp !== '' && preg_match('/^[0-9a-fA-F.:\/]+$/', $searchIp)) {
    $logRes = runCmd('grep -F ' . escapeshellarg($searchIp) . ' /var/log/fail2ban.log | tail -n 25');
    if ($logRes['code'] === 0) {
        $searchLogs = $logRes['output'];
    }
}

/**
 * JSON API আউটপুট
 */
if (wantsJson()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'       => 'success',
        'total_jails'  => count($jails),
        'total_banned' => count($bannedMap),
        'jails'        => $jailDetails,
        'banned_list'  => array_values($filteredBanned),
        'whitelist'    => $whitelistIps,
        'search_logs'  => $searchLogs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$selfUrl = basename((string)($_SERVER['PHP_SELF'] ?? 'fail2ban.php'));
$keyQuery = $SECRET_KEY !== '' ? ('?key=' . urlencode($SECRET_KEY)) : '';
$keyAmp = $SECRET_KEY !== '' ? ('&key=' . urlencode($SECRET_KEY)) : '';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fail2Ban Firewall & IP Manager</title>
  <style>
    :root {
      --bg: #090d16;
      --card: #111827;
      --card-2: #0d1320;
      --border: rgba(255, 255, 255, 0.08);
      --text: #e5edf8;
      --muted: #94a3b8;
      --cyan: #22d3ee;
      --gold: #eab308;
      --danger: #f87171;
      --danger-bg: rgba(248, 113, 113, 0.14);
      --ok: #4ade80;
      --ok-bg: rgba(74, 222, 128, 0.14);
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 22px;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .wrap { max-width: 1200px; margin: 0 auto; }
    .topbar {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 18px;
    }
    .topbar h1 {
      margin: 0 0 4px;
      font-size: 22px;
      color: var(--cyan);
    }
    .topbar p { margin: 0; font-size: 13px; color: var(--muted); }
    .alert {
      padding: 12px 16px;
      border-radius: 10px;
      margin-bottom: 16px;
      font-size: 14px;
      border: 1px solid transparent;
    }
    .alert-ok { background: var(--ok-bg); border-color: rgba(74, 222, 128, 0.35); color: var(--ok); }
    .alert-err { background: var(--danger-bg); border-color: rgba(248, 113, 113, 0.35); color: var(--danger); }
    .jail-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .jail-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px;
      text-decoration: none;
      color: var(--text);
      display: block;
    }
    .jail-card.active { border-color: var(--cyan); }
    .jail-name { font-weight: 700; font-size: 14px; color: var(--gold); margin-bottom: 6px; }
    .jail-stats { font-size: 12px; color: var(--muted); display: flex; justify-content: space-between; }
    .jail-stats strong { color: var(--text); font-size: 14px; }
    .panel-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 14px;
      margin-bottom: 18px;
    }
    .box {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
    }
    .box h2 {
      margin: 0 0 12px;
      font-size: 15px;
      color: var(--cyan);
    }
    .form-inline {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    input[type="text"], select {
      padding: 9px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--card-2);
      color: var(--text);
      font-size: 13px;
      flex: 1;
      min-width: 140px;
    }
    .btn {
      padding: 9px 14px;
      border-radius: 8px;
      border: none;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn-cyan { background: var(--cyan); color: #06101c; }
    .btn-gold { background: var(--gold); color: #111827; }
    .btn-danger {
      background: var(--danger-bg);
      color: var(--danger);
      border: 1px solid rgba(248, 113, 113, 0.35);
    }
    .btn-ok {
      background: var(--ok-bg);
      color: var(--ok);
      border: 1px solid rgba(74, 222, 128, 0.35);
    }
    .btn-ghost {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--border);
    }
    .table-wrap {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow-x: auto;
      margin-bottom: 18px;
    }
    table { width: 100%; border-collapse: collapse; min-width: 680px; }
    th, td {
      padding: 12px 14px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      font-size: 13px;
    }
    th { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
    tr:last-child td { border-bottom: none; }
    .ip-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 14px; font-weight: 700; color: #fff; }
    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      margin: 2px 4px 2px 0;
      background: rgba(234, 179, 8, 0.14);
      color: var(--gold);
      border: 1px solid rgba(234, 179, 8, 0.3);
    }
    .badge-ipt {
      background: rgba(34, 211, 238, 0.12);
      color: var(--cyan);
      border-color: rgba(34, 211, 238, 0.3);
    }
    .chip-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 10px;
      border-radius: 999px;
      background: var(--card-2);
      border: 1px solid var(--border);
      font-size: 12px;
      font-family: ui-monospace, monospace;
    }
    .chip button {
      background: none;
      border: none;
      color: var(--danger);
      cursor: pointer;
      font-weight: 700;
      padding: 0 2px;
    }
    pre.log-box {
      background: #060911;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px;
      font-size: 12px;
      color: #cbd5e1;
      overflow-x: auto;
      max-height: 260px;
      margin: 0;
    }
    .empty { padding: 30px; text-align: center; color: var(--muted); }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <h1>Fail2Ban IP Manager</h1>
        <p>Total Jails: <strong><?= count($jails) ?></strong> • Active Banned IPs: <strong><?= count($bannedMap) ?></strong> • iptables Rules: <strong><?= count($iptablesList) ?></strong></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-ghost" href="<?= h($selfUrl . $keyQuery) ?>">Refresh</a>
        <a class="btn btn-ghost" href="<?= h($selfUrl . '?api=1' . $keyAmp) ?>" target="_blank">JSON API</a>
        <?php if (!empty($bannedMap)): ?>
          <form method="post" action="<?= h($selfUrl . $keyQuery) ?>" onsubmit="return confirm('সব jail থেকে সব ব্যান হওয়া IP আনব্যান করতে চান?');">
            <input type="hidden" name="action" value="unban_all">
            <button type="submit" class="btn btn-danger">Unban All</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($jails)): ?>
      <div class="alert alert-err">
        Fail2Ban status পড়া যাচ্ছে না। নিচের কমান্ড দিয়ে <code>www-data</code> ইউজারকে <code>fail2ban-client</code> চালানোর পারমিশন দিন।
      </div>
    <?php endif; ?>

    <?php if ($flashMsg !== ''): ?>
      <div class="alert <?= $flashType === 'ok' ? 'alert-ok' : 'alert-err' ?>">
        <?= h($flashMsg) ?>
      </div>
    <?php endif; ?>

    <!-- Jail Summary Cards -->
    <div class="jail-grid">
      <?php foreach ($jailDetails as $jName => $jData): ?>
        <?php $isActive = ($filterJail === $jName); ?>
        <a class="jail-card <?= $isActive ? 'active' : '' ?>" href="<?= h($selfUrl . '?jail=' . urlencode($isActive ? '' : $jName) . ($searchIp !== '' ? '&search=' . urlencode($searchIp) : '') . $keyAmp) ?>">
          <div class="jail-name"><?= h($jName) ?></div>
          <div class="jail-stats">
            <span>Banned: <strong><?= $jData['currently_banned'] ?></strong> (Total <?= $jData['total_banned'] ?>)</span>
            <span>Failed: <strong><?= $jData['currently_failed'] ?></strong></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Search, Manual Ban & Whitelist -->
    <div class="panel-row">
      <div class="box">
        <h2>IP দিয়ে সার্চ করুন</h2>
        <form method="get" action="<?= h($selfUrl) ?>" class="form-inline">
          <?php if ($SECRET_KEY !== ''): ?>
            <input type="hidden" name="key" value="<?= h($SECRET_KEY) ?>">
          <?php endif; ?>
          <input type="text" name="search" value="<?= h($searchIp) ?>" placeholder="যেমন: 135.125.102.16">
          <select name="jail">
            <option value="">সব Jail</option>
            <?php foreach ($jails as $j): ?>
              <option value="<?= h($j) ?>" <?= $filterJail === $j ? 'selected' : '' ?>><?= h($j) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-cyan">Search</button>
          <?php if ($searchIp !== '' || $filterJail !== ''): ?>
            <a class="btn btn-ghost" href="<?= h($selfUrl . $keyQuery) ?>">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="box">
        <h2>ম্যানুয়ালি IP Ban করুন</h2>
        <form method="post" action="<?= h($selfUrl . $keyQuery) ?>" class="form-inline">
          <input type="hidden" name="action" value="ban">
          <input type="text" name="ip" placeholder="IP অ্যাড্রেস দিন..." required>
          <select name="jail">
            <?php foreach ($jails as $j): ?>
              <option value="<?= h($j) ?>" <?= $j === 'freeswitch' ? 'selected' : '' ?>><?= h($j) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-danger">Ban IP</button>
        </form>
      </div>

      <div class="box">
        <h2>Whitelist (Ignore IP)</h2>
        <form method="post" action="<?= h($selfUrl . $keyQuery) ?>" class="form-inline">
          <input type="hidden" name="action" value="whitelist_add">
          <input type="text" name="ip" placeholder="নিজের অফিস/সার্ভার IP বা CIDR...">
          <button type="submit" class="btn btn-gold">Whitelist</button>
        </form>
        <?php if (!empty($whitelistIps)): ?>
          <div class="chip-list">
            <?php foreach ($whitelistIps as $wip): ?>
              <span class="chip">
                <?= h($wip) ?>
                <form method="post" action="<?= h($selfUrl . $keyQuery) ?>" style="display:inline;" onsubmit="return confirm('হোয়াইটলিস্ট থেকে সরাতে চান?');">
                  <input type="hidden" name="action" value="whitelist_del">
                  <input type="hidden" name="ip" value="<?= h($wip) ?>">
                  <button type="submit" title="Remove">&times;</button>
                </form>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Banned IPs Table -->
    <div class="table-wrap">
      <?php if (empty($filteredBanned)): ?>
        <div class="empty">
          <?= $searchIp !== '' ? 'সার্চ করা IP (' . h($searchIp) . ') বর্তমানে ব্যান লিস্টে পাওয়া যায়নি।' : 'বর্তমানে কোনো ব্যান হওয়া IP নেই।' ?>
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Banned IP</th>
              <th>Jail(s)</th>
              <th>iptables Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filteredBanned as $row): ?>
              <tr>
                <td><span class="ip-code"><?= h($row['ip']) ?></span></td>
                <td>
                  <?php if (empty($row['jails'])): ?>
                    <span class="badge">iptables only</span>
                  <?php else: ?>
                    <?php foreach ($row['jails'] as $j): ?>
                      <span class="badge"><?= h($j) ?></span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($row['in_ipt']): ?>
                    <span class="badge badge-ipt">REJECT (<?= h(implode(', ', $row['ipt_chain'])) ?>)</span>
                  <?php else: ?>
                    <span style="color:var(--muted);font-size:12px;">Not in iptables</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <form method="post" action="<?= h($selfUrl . $keyQuery) ?>">
                      <input type="hidden" name="action" value="unban">
                      <input type="hidden" name="jail" value="all">
                      <input type="hidden" name="ip" value="<?= h($row['ip']) ?>">
                      <button type="submit" class="btn btn-ok">Unban</button>
                    </form>
                    <form method="post" action="<?= h($selfUrl . $keyQuery) ?>">
                      <input type="hidden" name="action" value="whitelist_add">
                      <input type="hidden" name="ip" value="<?= h($row['ip']) ?>">
                      <button type="submit" class="btn btn-ghost">Whitelist</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Search Log History -->
    <?php if ($searchIp !== ''): ?>
      <div class="box">
        <h2>Fail2Ban Log History for <code><?= h($searchIp) ?></code></h2>
        <?php if (empty($searchLogs)): ?>
          <p style="color:var(--muted);font-size:13px;margin:0;"><code>/var/log/fail2ban.log</code> ফাইলে এই IP-এর কোনো রেকর্ড পাওয়া যায়নি।</p>
        <?php else: ?>
          <pre class="log-box"><?= h(implode("\n", $searchLogs)) ?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
