<?php
// এরর দেখার জন্য সাময়িকভাবে এটি অন রাখুন
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

$dir = "/etc/freeswitch/directory/default/";

$ext         = $_POST['extension'] ?? '';
$password    = $_POST['password'] ?? '';
$forward_num = $_POST['call_forward_mobile'] ?? '';
$on_busy     = $_POST['on_busy_option'] ?? 'hangup';
$on_offline  = $_POST['on_offline_option'] ?? 'hangup';

if (empty($ext)) {
    echo json_encode(["status" => "error", "message" => "Extension missing"]);
    exit;
}

$file_path = $dir . $ext . ".xml";

if (!file_exists($file_path)) {
    echo json_encode(["status" => "error", "message" => "File not found: $file_path"]);
    exit;
}

// XML লোড করা
$xml = @simplexml_load_file($file_path);
if (!$xml) {
    echo json_encode(["status" => "error", "message" => "XML Parse Error. Permission or format issue."]);
    exit;
}

// পাসওয়ার্ড আপডেট
if(isset($xml->user->params->param)) {
    foreach ($xml->user->params->param as $param) {
        if ((string)$param['name'] === 'password') {
            $param['value'] = $password;
        }
    }
}

// ভেরিয়েবল আপডেট
if (!isset($xml->user->variables)) {
    $xml->user->addChild('variables');
}
unset($xml->user->variables->variable); 

$vars = [
    'call_forward_mobile' => $forward_num,
    'on_busy_option'      => $on_busy,
    'on_offline_option'   => $on_offline
];

foreach ($vars as $name => $value) {
    $v = $xml->user->variables->addChild('variable');
    $v->addAttribute('name', $name);
    $v->addAttribute('value', $value);
}

// ফাইল সেভ করা
if ($xml->asXML($file_path)) {
    // রিলোড কমান্ড - নিশ্চিত করুন fs_cli ইন্সটল আছে
    @shell_exec("fs_cli -x 'reloadxml' 2>&1");
    echo json_encode(["status" => "success", "message" => "Extension $ext updated successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Could not save XML. Check write permissions on $file_path"]);
}
