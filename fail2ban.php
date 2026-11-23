<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==========================================
// ১. কনফিগারেশন
// ==========================================
$API_TOKEN  = "app";
$DEFAULT_JAIL = "freeswitch";
$JAIL_LOCAL_PATH = "/etc/fail2ban/jail.local";

function respondJson(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function runCmd(string $cmd): array
{
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'output' => 'shell_exec is disabled in PHP'];
    }
    $out = shell_exec($cmd . " 2>&1");
    $text = is_string($out) ? trim($out) : '';
    return ['ok' => true, 'output' => $text];
}

// ==========================================
// ২. অথেনটিকেশন চেক
// ==========================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$normalizedHeaders = [];
foreach ($headers as $k => $v) {
    $normalizedHeaders[strtolower((string)$k)] = (string)$v;
}

$authHeader = $normalizedHeaders['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearerToken = '';
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
    $bearerToken = trim($m[1]);
}

$providedToken = $normalizedHeaders['x-api-token']
    ?? ($bearerToken !== '' ? $bearerToken : null)
    ?? ($_POST['token'] ?? $_GET['token'] ?? '');

if (!is_string($providedToken) || $providedToken === '' || !hash_equals($API_TOKEN, $providedToken)) {
    respondJson(401, [
        "status" => false,
        "error"  => "Unauthorized: Invalid or missing API token"
    ]);
}

// ==========================================
// ৩. ইনপুট পার্সিং ও ভ্যালিডেশন
// ==========================================
$rawInput = file_get_contents("php://input");
$jsonInput = is_string($rawInput) && $rawInput !== '' ? json_decode($rawInput, true) : [];
if (!is_array($jsonInput)) {
    $jsonInput = [];
}

$input = array_merge($_GET, $_POST, $jsonInput);

$action = strtolower(trim((string)($input['action'] ?? 'list')));
$ip     = trim((string)($input['ip'] ?? ''));
$jail   = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['jail'] ?? $DEFAULT_JAIL));
if ($jail === '') {
    $jail = $DEFAULT_JAIL;
}

$allowedActions = ['list', 'ban', 'unban', 'whitelist', 'del_whitelist'];
if (!in_array($action, $allowedActions, true)) {
    respondJson(400, [
        "status" => false,
        "error"  => "Invalid action. Allowed: list, ban, unban, whitelist, del_whitelist"
    ]);
}

// ==========================================
// ৪. সক্রিয় Jail এবং হেল্পার ফাংশন
// ==========================================
$statusRes = runCmd("sudo fail2ban-client status");
if (!$statusRes['ok']) {
    respondJson(500, [
        "status" => false,
        "error"  => $statusRes['output']
    ]);
}

$jails = [];
if (preg_match('/Jail list:\s*(.+)/', $statusRes['output'], $matches)) {
    $jails = array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
}

if (!empty($jails) && !in_array($jail, $jails, true)) {
    $jail = $jails[0];
}

function getBannedList(string $jail): array
{
    $res = runCmd("sudo fail2ban-client status " . escapeshellarg($jail));
    if ($res['ok'] && preg_match('/Banned IP list:\s*(.*)/', $res['output'], $m)) {
        $raw = trim($m[1]);
        if ($raw !== '') {
            return preg_split('/\s+/', $raw) ?: [];
        }
    }
    return [];
}

function getWhitelist(string $jail): array
{
    $res = runCmd("sudo fail2ban-client get " . escapeshellarg($jail) . " ignoreip");
    if ($res['ok'] && preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?\b/', $res['output'], $m)) {
        return array_values(array_unique($m[0]));
    }
    return [];
}

function addIpToJailLocal(string $filePath, string $ip): bool
{
    if (!is_file($filePath) || !is_writable($filePath)) {
        return false;
    }
    $content = (string)file_get_contents($filePath);
    if ($content === '') {
        return false;
    }
    if (preg_match('/(?:^|\s)' . preg_quote($ip, '/') . '(?:\/\d{1,2})?(?=\s|$)/', $content)) {
        return true;
    }
    $updated = preg_replace('/^(ignoreip\s*=\s*.*)$/m', '$1 ' . $ip, $content, 1);
    if (!is_string($updated) || $updated === $content) {
        return false;
    }
    return file_put_contents($filePath, $updated, LOCK_EX) !== false;
}

function removeIpFromJailLocal(string $filePath, string $ip): bool
{
    if (!is_file($filePath) || !is_writable($filePath)) {
        return false;
    }
    $content = (string)file_get_contents($filePath);
    if ($content === '') {
        return false;
    }
    $updated = preg_replace('/\s+' . preg_quote($ip, '/') . '(?:\/\d{1,2})?(?=\s|$)/', '', $content);
    if (!is_string($updated)) {
        return false;
    }
    if ($updated === $content) {
        return true;
    }
    return file_put_contents($filePath, $updated, LOCK_EX) !== false;
}

// ==========================================
// ৫. অ্যাকশন এক্সিকিউশন
// ==========================================
$cmdOutput = "";
$fileUpdated = null;

if ($action !== 'list') {
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        respondJson(400, [
            "status" => false,
            "error"  => "Invalid or missing IP address"
        ]);
    }

    $safeIp   = escapeshellarg($ip);
    $safeJail = escapeshellarg($jail);

    switch ($action) {
        case 'ban':
            $res = runCmd("sudo fail2ban-client set $safeJail banip $safeIp");
            $cmdOutput = $res['output'];
            break;

        case 'unban':
            $res = runCmd("sudo fail2ban-client set $safeJail unbanip $safeIp");
            $cmdOutput = $res['output'];
            break;

        case 'whitelist':
            runCmd("sudo fail2ban-client set $safeJail unbanip $safeIp");
            $res = runCmd("sudo fail2ban-client set $safeJail addignoreip $safeIp");
            $cmdOutput = $res['output'];
            $fileUpdated = addIpToJailLocal($JAIL_LOCAL_PATH, $ip);
            break;

        case 'del_whitelist':
            $res = runCmd("sudo fail2ban-client set $safeJail delignoreip $safeIp");
            $cmdOutput = $res['output'];
            $fileUpdated = removeIpFromJailLocal($JAIL_LOCAL_PATH, $ip);
            break;
    }
}

// ==========================================
// ৬. রেসপন্স আউটপুট
// ==========================================
respondJson(200, [
    "status"          => true,
    "action"          => $action,
    "jail"            => $jail,
    "available_jails" => $jails,
    "ip"              => $ip !== '' ? $ip : null,
    "jail_local_sync" => $fileUpdated,
    "output"          => $cmdOutput,
    "blacklisted"     => getBannedList($jail),
    "whitelisted"     => getWhitelist($jail)
]);
