<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

$gateway_name = trim($_POST['gateway_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$proxy = trim($_POST['proxy'] ?? '');
$realm = trim($_POST['realm'] ?? '');
$port = trim($_POST['port'] ?? '');
$dir = trim($_POST['dir'] ?? '/etc/freeswitch/sip_profiles/external');

if (empty($gateway_name) || empty($username) || empty($password) || empty($realm)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide all required fields: gateway_name, username, password, realm.'
    ]);
    exit;
}

// Ensure path ends with slash
if (substr($dir, -1) !== '/') {
    $dir .= '/';
}

// FreeSWITCH Gateway XML format
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n <gateway name=\"{$gateway_name}\">\n <param name=\"username\" value=\"{$username}\"/>\n <param name=\"realm\" value=\"{$realm}\"/>\n <param name=\"password\" value=\"{$password}\"/>\n <param name=\"proxy\" value=\"{$proxy}\"/>\n <param name=\"register\" value=\"true\"/>\n <param name=\"retry-seconds\" value=\"30\"/>\n </gateway>\n</include>";

// Check directory and create if not exists
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

$file_path = $dir . $gateway_name . ".xml";

// Save configuration file
if (@file_put_contents($file_path, $xml_output)) {
    $message = "সফলভাবে ফাইলটি '{$file_path}'-এ তৈরি হয়েছে!";
    
    $reload_output = "";
    if (isset($_POST['reload_freeswitch'])) {
        $reload_output = shell_exec('fs_cli -x "sofia profile external rescan" 2>&1');
        $message .= " (FreeSWITCH রিলোড করা হয়েছে)";
    }

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'reload_output' => $reload_output
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'ফাইলটি সেভ করা যায়নি! ফোল্ডার পারমিশন (Permissions) চেক করুন।'
    ]);
}
?>
