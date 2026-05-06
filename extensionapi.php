<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

$extension = trim($_POST['extension'] ?? '');
$password = trim($_POST['password'] ?? '');
$vm_password = trim($_POST['vm_password'] ?? '');
$dir = trim($_POST['dir'] ?? '/etc/freeswitch/directory/default');

if (empty($extension) || empty($password)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide all required fields: extension, password.'
    ]);
    exit;
}

// Ensure path ends with slash
if (substr($dir, -1) !== '/') {
    $dir .= '/';
}

// FreeSWITCH Directory/Extension XML format
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n  <user id=\"{$extension}\">\n    <params>\n      <param name=\"password\" value=\"{$password}\"/>\n      <param name=\"vm-password\" value=\"{$vm_password}\"/>\n    </params>\n    <variables>\n      <param name=\"toll_allow\" value=\"domestic,international,local\"/>\n      <param name=\"accountcode\" value=\"{$extension}\"/>\n      <param name=\"user_context\" value=\"default\"/>\n    </variables>\n  </user>\n</include>";

// Check directory and create if not exists
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

$file_path = $dir . $extension . ".xml";

// Save configuration file
if (@file_put_contents($file_path, $xml_output)) {
    $message = "সফলভাবে ফাইলটি '{$file_path}'-এ তৈরি হয়েছে!";
    
    $reload_output = "";
    if (isset($_POST['reload_freeswitch'])) {
        $reload_output = shell_exec('fs_cli -x "reloadxml" 2>&1');
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
