<?php
/**
 * FreeSWITCH IVR Generator API - v2.0
 * Supports: Extension, Forwarding, Queue, VM, Sub-IVR, etc.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$response = [
    'success' => false,
    'message' => '',
    'ivr_name' => '',
    'file_path' => ''
];

// ১. মেথড চেক
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Only POST method allowed';
    echo json_encode($response);
    exit;
}

// ২. ইনপুট ডাটা গ্রহণ
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $response['message'] = 'Invalid JSON data received';
    echo json_encode($response);
    exit;
}

$ivr_name     = $data['ivr_name'] ?? '';
$welcome_msg  = $data['welcome_msg'] ?? 'ivr/ivr-welcome.wav';
$invalid_msg  = $data['invalid_msg'] ?? 'ivr/ivr-invalid_extension.wav';
$timeout_sec  = (int)($data['timeout_sec'] ?? 5);
$max_failures = (int)($data['max_failures'] ?? 3);
$digit_action = $data['digit_action'] ?? [];

if (empty($ivr_name)) {
    $response['message'] = 'IVR Name is required';
    echo json_encode($response);
    exit;
}

// ৩. ইউনিক নাম এবং ডিরেক্টরি সেটআপ
$unique_id = time();
$clean_name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($ivr_name));
$final_ivr_name = $clean_name . "_" . $unique_id;

$save_directory = "/etc/freeswitch/ivr_menus/";
if (!is_dir($save_directory)) {
    mkdir($save_directory, 0755, true);
}

$full_path = $save_directory . $final_ivr_name . ".xml";

// ৪. XML জেনারেশন লজিক
$xml = "<include>\n";
$xml .= "  <menu name=\"{$final_ivr_name}\"\n";
$xml .= "        greet-long=\"{$welcome_msg}\"\n";
$xml .= "        invalid-sound=\"{$invalid_msg}\"\n";
$xml .= "        timeout=\"" . ($timeout_sec * 1000) . "\"\n";
$xml .= "        max-failures=\"{$max_failures}\">\n";

foreach ($digit_action as $digit => $action) {
    $type = $action['type'] ?? '';
    $dest = trim($action['dest'] ?? '');

    if (!empty($type) && $dest !== '') {
        switch ($type) {
            case 'extension':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
                break;
                
            case 'forward':
                // মোবাইল নম্বরে কল পাঠানোর জন্য ব্রিজ লজিক
                // মনে রাখবেন: 'mygw' এর জায়গায় আপনার গেটওয়ে নাম ব্যবহার করবেন
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"bridge sofia/gateway/mygw/$dest\"/>\n";
                break;
                
            case 'queue':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"callcenter $dest\"/>\n";
                break;
                
            case 'voicemail':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"voicemail default \${domain} $dest\"/>\n";
                break;
                
            case 'ivr':
                // সাব-আইভিআর এ কল ট্রান্সফার
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"ivr $dest\"/>\n";
                break;
                
            case 'repeat':
                $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n";
                break;
                
            case 'ringgroup':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
                break;
        }
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// ৫. ফাইল সেভ এবং রিলোড
if (file_put_contents($full_path, $xml)) {
    // পারমিশন ফিক্স
    chmod($full_path, 0644);
    chown($full_path, 'www-data'); // আপনার সার্ভার অনুযায়ী এটি পরিবর্তন হতে পারে
    
    // FreeSWITCH কে নতুন XML সম্পর্কে জানানো
    @shell_exec("fs_cli -x 'reloadxml' 2>/dev/null");
    
    $response['success'] = true;
    $response['message'] = 'IVR XML generated and FreeSWITCH reloaded';
    $response['ivr_name'] = $final_ivr_name;
    $response['file_path'] = $full_path;
} else {
    $response['message'] = 'Permission Denied! Cannot write to ' . $save_directory;
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
