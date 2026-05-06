<?php
header('Content: 'Content-Type: application/json; charset=utf-8';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

$extension = trim($_POST['extension'] ?? '');
$gateway = trim($_POST['gateway'] ?? '');
$dir = trim($_POST['dir'] ?? '/etc/freeswitch/dialplan/default/');

if (empty($extension) || empty($gateway)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please provide all required fields: extension, gateway.'
    ]);
    exit;
}

// Ensure path ends with slash
if (substr($dir, -1) !== '/') {
    $dir .= '/';
}

// FreeSWITCH Outbound Dialplan XML format
// Removing the strict caller ID condition to avoid matching failures
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n  <extension name=\"outbound_{$extension}\">\n    <condition field=\"destination_number\" expression=\"^(\d+)$\">\n      <action application=\"set\" data=\"effective_caller_id_number={$extension}\"/>\n      <action application=\"bridge\" data=\"sofia/gateway/{$gateway}/$1\"/>\n    </condition>\n  </extension>\n</include>";

// Check directory and create if not exists
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

$file_path = $dir . "outbound_" . $extension . ".xml";

// Save configuration file
if (@file_put_contents($file_path, $xml_output)) {
    $message = "সফলভাবে আউটবাউন্ড রুল ফাইলটি '{$file_path}'-এ তৈরি হয়েছে!";
    
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
