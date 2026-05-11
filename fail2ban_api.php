<?php
/**
 * Fail2Ban API
 * Location: /var/www/html/fail2ban_api.php
 * VPS: vps.hostserverbd.com
 */

// ===== Security Token =====
define('API_TOKEN', '0909');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Token check
$token = $_GET['token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($token !== API_TOKEN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function resp($success, $data = [], $message = '') {
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message, 'time' => date('Y-m-d H:i:s')],
        $data
    ));
    exit;
}

function safe_exec($cmd) {
    $output = shell_exec($cmd . ' 2>&1');
    return $output ?? '';
}

$action = trim($_GET['action'] ?? '');

switch ($action) {

    // ===== Service Status =====
    case 'service_status':
        $status  = safe_exec('sudo fail2ban-client ping');
        $running = strpos($status, 'pong') !== false;
        $uptime  = safe_exec('systemctl show fail2ban --property=ActiveEnterTimestamp 2>/dev/null');
        preg_match('/=(.+)/', $uptime, $m);

        resp(true, [
            'running'  => $running,
            'status'   => $running ? 'running' : 'stopped',
            'ping'     => trim($status),
            'since'    => trim($m[1] ?? ''),
            'version'  => trim(safe_exec('fail2ban-client --version 2>/dev/null | head -1')),
        ]);

    // ===== All Jails =====
    case 'all_jails':
        $raw   = safe_exec('sudo fail2ban-client status');
        preg_match('/Jail list:\s*(.+)/i', $raw, $m);
        $jails = [];
        if (!empty($m[1])) {
            $names = array_map('trim', explode(',', $m[1]));
            foreach ($names as $jail) {
                if (!$jail) continue;
                $js = safe_exec("sudo fail2ban-client status $jail");
                preg_match('/Currently failed:\s*(\d+)/i', $js, $f);
                preg_match('/Total failed:\s*(\d+)/i',     $js, $tf);
                preg_match('/Currently banned:\s*(\d+)/i', $js, $b);
                preg_match('/Total banned:\s*(\d+)/i',     $js, $tb);

                $jails[] = [
                    'name'           => $jail,
                    'currently_failed' => (int)($f[1]  ?? 0),
                    'total_failed'   => (int)($tf[1] ?? 0),
                    'currently_banned' => (int)($b[1]  ?? 0),
                    'total_banned'   => (int)($tb[1] ?? 0),
                ];
            }
        }
        resp(true, ['jails' => $jails, 'total' => count($jails)]);

    // ===== Banned IPs (all jails) =====
    case 'banned_ips':
        $raw   = safe_exec('sudo fail2ban-client status');
        preg_match('/Jail list:\s*(.+)/i', $raw, $m);
        $all_banned = [];
        if (!empty($m[1])) {
            $names = array_map('trim', explode(',', $m[1]));
            foreach ($names as $jail) {
                if (!$jail) continue;
                $js = safe_exec("sudo fail2ban-client status $jail");
                preg_match('/Banned IP list:\s*([^\n]+)/i', $js, $bm);
                $ips = !empty($bm[1]) ? array_filter(array_map('trim', explode(' ', $bm[1]))) : [];
                foreach ($ips as $ip) {
                    $all_banned[] = ['ip' => $ip, 'jail' => $jail];
                }
            }
        }
        resp(true, ['banned' => $all_banned, 'total' => count($all_banned)]);

    // ===== Single Jail Status =====
    case 'jail_status':
        $jail = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['jail'] ?? '');
        if (!$jail) resp(false, [], 'Jail name required');

        $raw = safe_exec("sudo fail2ban-client status $jail");
        if (strpos($raw, 'Unknown jail') !== false) {
            resp(false, [], "Jail '$jail' not found");
        }

        preg_match('/Currently failed:\s*(\d+)/i', $raw, $f);
        preg_match('/Total failed:\s*(\d+)/i',     $raw, $tf);
        preg_match('/Currently banned:\s*(\d+)/i', $raw, $b);
        preg_match('/Total banned:\s*(\d+)/i',     $raw, $tb);
        preg_match('/Banned IP list:\s*([^\n]*)/i, $raw, $bm);
        preg_match('/File list:\s*([^\n]*)/i,      $raw, $fl);

        $banned_ips = [];
        if (!empty($bm[1])) {
            $banned_ips = array_values(array_filter(array_map('trim', explode(' ', $bm[1]))));
        }

        resp(true, [
            'jail'             => $jail,
            'currently_failed' => (int)($f[1]  ?? 0),
            'total_failed'     => (int)($tf[1] ?? 0),
            'currently_banned' => (int)($b[1]  ?? 0),
            'total_banned'     => (int)($tb[1] ?? 0),
            'banned_ips'       => $banned_ips,
            'log_files'        => trim($fl[1] ?? ''),
            'raw'              => $raw,
        ]);

    // ===== Unban IP =====
    case 'unban_ip':
        $jail = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['jail'] ?? '');
        $ip   = filter_var($_GET['ip'] ?? '', FILTER_VALIDATE_IP);
        if (!$jail || !$ip) resp(false, [], 'Valid jail and IP required');

        $res = safe_exec("sudo fail2ban-client set $jail unbanip $ip");
        $ok  = strpos($res, '1') !== false || strpos($res, 'OK') !== false;

        resp($ok, ['ip' => $ip, 'jail' => $jail, 'output' => trim($res)],
             $ok ? "IP $ip unbanned from $jail" : "Unban failed: $res");

    // ===== Ban IP manually =====
    case 'ban_ip':
        $jail = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['jail'] ?? '');
        $ip   = filter_var($_GET['ip'] ?? '', FILTER_VALIDATE_IP);
        if (!$jail || !$ip) resp(false, [], 'Valid jail and IP required');

        $res = safe_exec("sudo fail2ban-client set $jail banip $ip");
        $ok  = strpos($res, '1') !== false;

        resp($ok, ['ip' => $ip, 'jail' => $jail, 'output' => trim($res)],
             $ok ? "IP $ip banned in $jail" : "Ban failed: $res");

    // ===== Fail2Ban Logs =====
    case 'logs':
        $lines = (int)($_GET['lines'] ?? 100);
        $lines = max(10, min(500, $lines));
        $filter = trim($_GET['filter'] ?? '');

        $log_file = '/var/log/fail2ban.log';
        if (!file_exists($log_file)) {
            resp(false, [], 'Log file not found');
        }

        $cmd = "tail -n $lines $log_file";
        if ($filter) {
            $f   = escapeshellarg($filter);
            $cmd = "tail -n 500 $log_file | grep -i $f | tail -n $lines";
        }

        $raw  = safe_exec($cmd);
        $log_lines = array_filter(array_map('trim', explode("\n", $raw)));

        // Parse log lines
        $parsed = [];
        foreach ($log_lines as $line) {
            preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+\s+fail2ban\.(\w+)\s+\[(\d+)\]:\s+(\w+)\s+(.+)$/', $line, $m);
            if ($m) {
                $parsed[] = [
                    'time'    => $m[1],
                    'module'  => $m[2],
                    'pid'     => $m[3],
                    'level'   => $m[4],
                    'message' => $m[5],
                    'raw'     => $line,
                ];
            } else {
                $parsed[] = ['raw' => $line, 'time' => '', 'level' => 'INFO', 'message' => $line];
            }
        }

        resp(true, ['logs' => array_values($parsed), 'total' => count($parsed)]);

    // ===== FreeSWITCH Logs =====
    case 'freeswitch_logs':
        $lines  = (int)($_GET['lines'] ?? 100);
        $lines  = max(10, min(500, $lines));
        $filter = trim($_GET['filter'] ?? '');

        // FreeSWITCH log locations চেক
        $fs_logs = [
            '/var/log/freeswitch/freeswitch.log',
            '/usr/local/freeswitch/log/freeswitch.log',
            '/var/log/freeswitch.log',
        ];

        $log_file = '';
        foreach ($fs_logs as $f) {
            if (file_exists($f)) { $log_file = $f; break; }
        }

        if (!$log_file) {
            resp(false, [], 'FreeSWITCH log file not found');
        }

        $cmd = "tail -n $lines $log_file";
        if ($filter) {
            $f   = escapeshellarg($filter);
            $cmd = "tail -n 500 $log_file | grep -i $f | tail -n $lines";
        }

        $raw   = safe_exec($cmd);
        $lines_arr = array_filter(array_map('trim', explode("\n", $raw)));

        $parsed = [];
        foreach ($lines_arr as $line) {
            // FreeSWITCH log format: YYYY-MM-DD HH:MM:SS.mmm [LEVEL] file:line func()
            preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+)\s+\[(\w+)\]\s+(.+)$/', $line, $m);
            $parsed[] = [
                'time'    => $m[1] ?? '',
                'level'   => $m[2] ?? 'INFO',
                'message' => $m[3] ?? $line,
                'raw'     => $line,
            ];
        }

        resp(true, ['logs' => array_values($parsed), 'total' => count($parsed), 'file' => $log_file]);

    // ===== Reload Fail2Ban =====
    case 'reload':
        $res = safe_exec('sudo fail2ban-client reload');
        $ok  = strpos($res, 'OK') !== false;
        resp($ok, ['output' => trim($res)], $ok ? 'Fail2Ban reloaded' : 'Reload failed');

    // ===== System Stats =====
    case 'system_stats':
        // CPU
        $cpu_raw = safe_exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2}'");
        $cpu     = trim(preg_replace('/[^0-9.]/', '', $cpu_raw));

        // RAM
        $mem_raw = safe_exec('free -m');
        preg_match('/Mem:\s+(\d+)\s+(\d+)/', $mem_raw, $mm);
        $mem_total = $mm[1] ?? 0;
        $mem_used  = $mm[2] ?? 0;

        // Disk
        $disk_raw = safe_exec("df -h / | tail -1");
        $disk_parts = preg_split('/\s+/', $disk_raw);

        // Uptime
        $uptime = safe_exec('uptime -p');

        resp(true, [
            'cpu_percent'  => (float)$cpu,
            'mem_total_mb' => (int)$mem_total,
            'mem_used_mb'  => (int)$mem_used,
            'mem_percent'  => $mem_total > 0 ? round(($mem_used / $mem_total) * 100, 1) : 0,
            'disk_used'    => $disk_parts[2] ?? '?',
            'disk_total'   => $disk_parts[1] ?? '?',
            'disk_percent' => $disk_parts[4] ?? '?',
            'uptime'       => trim($uptime),
        ]);

    default:
        resp(false, [], 'Unknown action. Available: service_status, all_jails, banned_ips, jail_status, unban_ip, ban_ip, logs, freeswitch_logs, reload, system_stats');
}
