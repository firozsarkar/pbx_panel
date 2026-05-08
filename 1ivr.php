<?php
/**
 * FreeSWITCH IVR Generator API
 * Simple JSON API - Updated with Forward Support
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Only POST method allowed';
    echo json_encode($response);
    exit;
}

// Input Data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $response['message'] = 'Invalid JSON input';
    echo json_encode($response);
    exit;
}

$ivr_name     = $data['ivr_name'] ?? '';
$welcome_msg  = $data['welcome_msg'] ?? '/usr/share/freeswitch/sounds/custom/welcome.wav';
$invalid_msg  = $data['invalid_msg'] ?? 'ivr/ivr-invalid_extension.wav';
$timeout_sec  = (int)($data['timeout_sec'] ?? 5);
$max_failures = (int)($data['max_failures'] ?? 3);
$digit_action = $data['digit_action'] ?? [];

if (empty($ivr_name)) {
    $response['message'] = 'IVR Name is required';
    echo json_encode($response);
    exit;
}

// Generate Unique Name
$unique_id = time();
$clean_name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($ivr_name));
$final_ivr_name = $clean_name . "_" . $unique_id;

$save_directory = "/etc/freeswitch/ivr_menus/";

// ডিরেক্টরি না থাকলে তৈরি করবে
if (!is_dir($save_directory)) {
    mkdir($save_directory, 0755, true);
}

$full_path = $save_directory . $final_ivr_name . ".xml";

// XML Generation
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
                // এখানে 'mygw' এর জায়গায় আপনার গেটওয়ে নাম দিন অথবা সরাসরি ব্রিজ করুন
                // এটি কলটিকে সরাসরি গেটওয়ে দিয়ে মোবাইল নম্বরে পাঠিয়ে দেবে
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"bridge sofia/gateway/mygw/$dest\"/>\n";
                break;
            case 'queue':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"callcenter $dest\"/>\n";
                break;
            case 'ringgroup':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
                break;
            case 'voicemail':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"voicemail default \${domain} $dest\"/>\n";
                break;
            case 'repeat':
                $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n";
                break;
            case 'ivr':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"ivr $dest\"/>\n";
                break;
            case 'disa':
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"disa $dest\"/>\n";
                break;
        }
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// Save File
if (file_put_contents($full_path, $xml)) {
    // ফাইল পারমিশন ঠিক করা
    chmod($full_path, 0644);
    
    // FreeSWITCH রিলোড
    @shell_exec("fs_cli -x 'reloadxml' 2>/dev/null");
    
    $response['success'] = true;
    $response['message'] = 'IVR created successfully';
    $response['ivr_name'] = $final_ivr_name;
    $response['file_path'] = $full_path;
} else {
    $response['message'] = "Failed to save file in $save_directory. Check permission.";
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
