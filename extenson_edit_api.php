<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// ইনপুট ডেটা
$extension   = trim($_POST['extension'] ?? '');
$password    = trim($_POST['password'] ?? '');
$vm_password = trim($_POST['vm_password'] ?? '1234');
$forward_num = trim($_POST['call_forward_mobile'] ?? '');
$on_busy     = trim($_POST['on_busy_option'] ?? 'hangup');
$on_offline  = trim($_POST['on_offline_option'] ?? 'hangup');
$dir         = trim($_POST['dir'] ?? '/etc/freeswitch/directory/default');

if (empty($extension) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Extension and Password required.']);
    exit;
}

if (substr($dir, -1) !== '/') { $dir .= '/'; }
$file_path = $dir . $extension . ".xml";

// সরাসরি XML স্ট্রিং তৈরি (এতে কোনো লাইব্রেরি লাগবে না)
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml_output .= "<include>\n";
$xml_output .= "  <user id=\"{$extension}\">\n";
$xml_output .= "    <params>\n";
$xml_output .= "      <param name=\"password\" value=\"{$password}\"/>\n";
$xml_output .= "      <param name=\"vm-password\" value=\"{$vm_password}\"/>\n";
$xml_output .= "    </params>\n";
$xml_output .= "    <variables>\n";
$xml_output .= "      <variable name=\"toll_allow\" value=\"domestic,international,local\"/>\n";
$xml_output .= "      <variable name=\"accountcode\" value=\"{$extension}\"/>\n";
$xml_output .= "      <variable name=\"user_context\" value=\"default\"/>\n";
$xml_output .= "      <variable name=\"call_forward_mobile\" value=\"{$forward_num}\"/>\n";
$xml_output .= "      <variable name=\"on_busy_option\" value=\"{$on_busy}\"/>\n";
$xml_output .= "      <variable name=\"on_offline_option\" value=\"{$on_offline}\"/>\n";
$xml_output .= "    </variables>\n";
$xml_output .= "  </user>\n";
$xml_output .= "</include>";

// ফাইল সেভ করা
if (@file_put_contents($file_path, $xml_output)) {
    // FreeSWITCH রিলোড
    $reload_output = shell_exec('fs_cli -x "reloadxml" 2>&1');
    
    echo json_encode([
        'status' => 'success',
        'message' => "Extension $extension updated and FreeSwitch reloaded.",
        'reload_log' => trim($reload_output)
    ]);
} else {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Permission denied. Run: chown www-data:www-data ' . $dir
    ]);
}
?>
