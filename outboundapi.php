<?php
// JSON Output Header
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

// ইনপুট গ্রহণ
$extension_name = isset($_POST['extension_name']) ? trim($_POST['extension_name']) : ''; // উদাহরণ: 100
$gateway_number = isset($_POST['gateway_number']) ? trim($_POST['gateway_number']) : ''; // উদাহরণ: 09617401201

// প্রয়োজনীয় ফিল্ড যাচাই
if (empty($extension_name) || empty($gateway_number)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'extension_name and gateway_number are required.'
    ]);
    exit;
}

// ডিরেক্টরি পাথ
$dir = '/etc/freeswitch/dialplan/public/';

// XML Structure (আপনার দেওয়া ইনক্লুড ফরমেট অনুযায়ী)
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml_output .= "<include>\n";
$xml_output .= "  <extension name=\"{$extension_name}_force_outbound\">\n";
$xml_output .= "    <condition field=\"caller_id_number\" expression=\"^{$extension_name}$\"/>\n";
$xml_output .= "    <condition field=\"destination_number\" expression=\"^(\d+)$\">\n";
$xml_output .= "      <action application=\"set\" data=\"effective_caller_id_number={$gateway_number}\"/>\n";
$xml_output .= "      <action application=\"set\" data=\"outbound_caller_id_number={$gateway_number}\"/>\n";
$xml_output .= "      <action application=\"set\" data=\"user_context={$gateway_number}\"/>\n";
$xml_output .= "      <action application=\"set\" data=\"hangup_after_bridge=true\"/>\n";
$xml_output .= "      <action application=\"bridge\" data=\"sofia/gateway/{$gateway_number}/$1\"/>\n";
$xml_output .= "    </condition>\n";
$xml_output .= "  </extension>\n";
$xml_output .= "</include>";

// ডিরেক্টরি চেক এবং তৈরি করা
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

// ফাইল পাথ তৈরি
$file_path = $dir . "outbound_" . $extension_name . ".xml";

// ফাইল সেভ করা
if (@file_put_contents($file_path, $xml_output)) {
    // FreeSWITCH রিলোড করা
    $output = shell_exec('fs_cli -x "reloadxml"');

    echo json_encode([
        'status' => 'success',
        'file_path' => $file_path,
        'message' => "Extension {$extension_name} এর জন্য আউটবাউন্ড রুল তৈরি হয়েছে এবং XML রিলোড করা হয়েছে।"
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'ফাইল সেভ করা সম্ভব হয়নি। পারমিশন চেক করুন।'
    ]);
}
?>
