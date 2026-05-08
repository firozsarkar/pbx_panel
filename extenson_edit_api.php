<?php
header('Content-Type: application/json');

// ১. FreeSwitch এর পাথ ঠিক আছে কি না চেক করুন
$dir = "/etc/freeswitch/directory/default/";

// প্যানেল থেকে পাঠানো ডেটা রিসিভ করা (POST Method)
$ext         = $_POST['extension'] ?? '';
$password    = $_POST['password'] ?? '';
$forward_num = $_POST['call_forward_mobile'] ?? '';
$on_busy     = $_POST['on_busy_option'] ?? 'hangup';
$on_offline  = $_POST['on_offline_option'] ?? 'hangup';

if (empty($ext)) {
    echo json_encode(["status" => "error", "message" => "Extension number missing!"]);
    exit;
}

$file_path = $dir . $ext . ".xml";

// ২. ফাইলটি খুঁজে দেখা
if (!file_exists($file_path)) {
    echo json_encode(["status" => "error", "message" => "XML file not found for extension $ext"]);
    exit;
}

// ৩. XML লোড করা
$xml = simplexml_load_file($file_path);
if ($xml === false) {
    echo json_encode(["status" => "error", "message" => "Could not read XML file. Check permission."]);
    exit;
}

// ৪. পাসওয়ার্ড আপডেট (প্যারামিটার সেকশন)
foreach ($xml->user->params->param as $param) {
    if ((string)$param['name'] === 'password') {
        $param['value'] = $password;
    }
}

// ৫. কল ফরওয়ার্ডিং ভেরিয়েবল আপডেট (ভেরিয়েবল সেকশন)
if (!isset($xml->user->variables)) {
    $xml->user->addChild('variables');
}
// আগের সব কাস্টম ভেরিয়েবল মুছে নতুন করে সেট করা
unset($xml->user->variables->variable);

$custom_vars = [
    'call_forward_mobile' => $forward_num,
    'on_busy_option'      => $on_busy,
    'on_offline_option'   => $on_offline
];

foreach ($custom_vars as $name => $value) {
    $v = $xml->user->variables->addChild('variable');
    $v->addAttribute('name', $name);
    $v->addAttribute('value', $value);
}

// ৬. ফাইল সেভ করা
if ($xml->asXML($file_path)) {
    // FreeSwitch রিলোড কমান্ড
    shell_exec("fs_cli -x 'reloadxml'");
    
    echo json_encode([
        "status" => "success", 
        "message" => "Extension $ext updated successfully",
        "details" => [
            "ext" => $ext,
            "pass" => "Updated",
            "forward" => $forward_num
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Permission Denied! Run: chown www-data:www-data $file_path"]);
}
