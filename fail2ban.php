<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// আপনার অ্যাপের জন্য সিক্রেট টোকেন সেট করুন
$API_TOKEN = "app";

// হেডার, POST বা GET থেকে টোকেন সংগ্রহ
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearerToken = '';
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
    $bearerToken = trim($m[1]);
}

$providedToken = $headers['X-API-Token']
    ?? $headers['x-api-token']
    ?? ($bearerToken !== '' ? $bearerToken : null)
    ?? ($_POST['token'] ?? $_GET['token'] ?? '');

if (!is_string($providedToken) || !hash_equals($API_TOKEN, $providedToken)) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "error"  => "Unauthorized: Invalid or missing API token"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ইনপুট প্যারামিটার (JSON body, POST বা GET)
$rawInput = file_get_contents("php://input");
$jsonInput = json_decode($rawInput, true);
if (!is_array($jsonInput)) {
    $jsonInput = [];
}

$input = array_merge($_GET, $_POST, $jsonInput);

$action = strtolower(trim($input['action'] ?? 'list'));
$ip     = trim($input['ip'] ?? '');
$jail   = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['jail'] ?? 'freeswitch');
if ($jail === '') {
    $jail = 'freeswitch';
}

// সক্রিয় Jail যাচাই
$statusOut = shell_exec("sudo fail2ban-client status 2>&1");
$jails = [];
if (is_string($statusOut) && preg_match('/Jail list:\s*(.+)/', $statusOut, $matches)) {
    $jails = array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
}
if (!empty($jails) && !in_array($jail, $jails, true)) {
    $jail = $jails[0];
}

// তালিকা দেখার হেল্পার ফাংশন
function getBannedList(string $jail): array {
    $out = shell_exec("sudo fail2ban-client status " . escapeshellarg($jail) . " 2>&1");
    if (is_string($out) && preg_match('/Banned IP list:\s*(.*)/', $out, $m)) {
        $raw = trim($m[1]);
        if ($raw !== '') {
            return preg_split('/\s+/', $raw);
        }
    }
    return [];
}

function getWhitelist(string $jail): array {
    $out = shell_exec("sudo fail2ban-client get " . escapeshellarg($jail) . " ignoreip 2>&1");
    if (is_string($out) && preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?\b/', $out, $m)) {
        return array_values(array_unique($m[0]));
    }
    return [];
}

$allowedActions = ['list', 'ban', 'unban', 'whitelist', 'del_whitelist'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode([
        "status"  => false,
        "error"   => "Invalid action. Allowed: list, ban, unban, whitelist, del_whitelist"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cmdOutput = "";

if ($action !== 'list') {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "error"  => "Invalid or missing IP address"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safeIp   = escapeshellarg($ip);
    $safeJail = escapeshellarg($jail);

    if ($action === 'ban') {
        $cmdOutput = trim((string)shell_exec("sudo fail2ban-client set $safeJail banip $safeIp 2>&1"));
    } elseif ($action === 'unban') {
        $cmdOutput = trim((string)shell_exec("sudo fail2ban-client set $safeJail unbanip $safeIp 2>&1"));
    } elseif ($action === 'whitelist') {
        shell_exec("sudo fail2ban-client set $safeJail unbanip $safeIp 2>&1");
        $cmdOutput = trim((string)shell_exec("sudo fail2ban-client set $safeJail addignoreip $safeIp 2>&1"));

        $jailLocal = "/etc/fail2ban/jail.local";
        if (is_writable($jailLocal)) {
            $content = (string)file_get_contents($jailLocal);
            if (strpos($content, $ip) === false) {
                $updated = preg_replace('/(ignoreip\s*=\s*[^\n]+)/', "$1 $ip", $content, 1);
                if (is_string($updated)) {
                    file_put_contents($jailLocal, $updated);
                }
            }
        }
    } elseif ($action === 'del_whitelist') {
        $cmdOutput = trim((string)shell_exec("sudo fail2ban-client set $safeJail delignoreip $safeIp 2>&1"));
    }
}

echo json_encode([
    "status"      => true,
    "action"      => $action,
    "jail"        => $jail,
    "available_jails" => $jails,
    "ip"          => $ip !== '' ? $ip : null,
    "output"      => $cmdOutput,
    "blacklisted" => getBannedList($jail),
    "whitelisted" => getWhitelist($jail)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
