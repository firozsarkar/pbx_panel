<?php
header('Content-Type: application/json');

/**
 * FreeSwitch Extension Editor API
 * ডিরেক্টরি পাথ চেক করে নিন। সাধারণত এটি /etc/freeswitch/directory/default/ এ থাকে।
 */
$dir = "/etc/freeswitch/directory/default/";

// প্যানেল থেকে পাঠানো ডেটা রিসিভ করা
$ext         = $_POST['extension'] ?? '';
$password    = $_POST['password'] ?? '';
$forward_num = $_POST['call_forward_mobile'] ?? '';
$on_busy     = $_POST['on_busy_option'] ?? 'hangup';
$on_offline  = $_POST['on_offline_option'] ?? 'hangup';

if (empty($ext)) {
    echo json_encode(["status" => "error", "message" => "Extension number is required"]);
    exit;
}

$file_path = $dir . $ext . ".xml";

if (!file_exists($file_path)) {
    echo json_encode(["status" => "error", "message" => "Extension file $ext.xml not found in $dir"]);
    exit;
}

// XML ফাইল লোড করা
$xml = simplexml_load_file($file_path);

if ($xml === false) {
    echo json_encode(["status" => "error", "message" => "Failed to parse XML file"]);
    exit;
}

// ১. পাসওয়ার্ড আপডেট করা (Param Section)
foreach ($xml->user->params->param as $param) {
    if ((string)$param['name'] === 'password') {
        $param['value'] = $password;
    }
}

// ২. কল ফিচার ভেরিয়েবল আপডেট করা (Variables Section)
// যদি variables ট্যাগ না থাকে তবে তৈরি করা
if (!isset($xml->user->variables)) {
    $xml->user->addChild('variables');
}

// আগের সব কাস্টম ভেরিয়েবল পরিষ্কার করে নতুন করে সেট করা (যাতে ডুপ্লিকেট না হয়)
unset($xml->user->variables->variable);

$vars = [
    'call_forward_mobile' => $forward_num,
    'on_busy_option'      => $on_busy,
    'on_offline_option'   => $on_offline,
    'is_forward_enabled'  => (!empty($forward_num)) ? 'true' : 'false'
];

foreach ($vars as $name => $value) {
    $variable = $xml->user->variables->addChild('variable');
    $variable->addAttribute('name', $name);
    $variable->addAttribute('value', $value);
}

// ফাইলটি সেভ করা
if ($xml->asXML($file_path)) {
    
    // ৩. FreeSwitch রিলোড দেওয়া (যাতে সাথে সাথে কাজ করে)
    // www-data ইউজারকে fs_cli চালানোর পারমিশন থাকতে হবে
    shell_exec("fs_cli -x 'reloadxml'");
    
    echo json_encode([
        "status" => "success",
        "message" => "Extension $ext updated successfully",
        "updates" => [
            "password" => "Updated",
            "forwarding" => $forward_num,
            "busy_action" => $on_busy
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Permission denied! Cannot write to $file_path"]);
}
