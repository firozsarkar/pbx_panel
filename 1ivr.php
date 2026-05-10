<?php
/**
 * FreeSWITCH IVR Generator API - v3.0
 * Updated for: Extension, Ring Group, Direct Number (Gateway), and Sub-IVR
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

// ইউনিক নাম এবং ডিরেক্টরি সেটআপ
$clean_name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($ivr_name));
$final_ivr_name = $clean_name; // অথবা $clean_name . "_" . time() দিতে পারেন

$save_directory = "/etc/freeswitch/ivr_menus/";
if (!is_dir($save_directory)) {
    mkdir($save_directory, 0755, true);
}

$full_path = $save_directory . $final_ivr_name . ".xml";

// XML জেনারেশন
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
            case 'ringgroup':
                // Extension এবং Ring Group সাধারণত একই ট্রান্সফার লজিক ফলো করে
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
                break;

            case 'direct_number':
                // ডিরেক্ট নাম্বারের জন্য গেটওয়ে এবং কলার আইডি সেটআপ
                $gateway = $action['gateway'] ?? 'default_gateway';
                $caller_id = $action['caller_id'] ?? '';
                
                if (!empty($caller_id)) {
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"set effective_caller_id_number=$caller_id\"/>\n";
                }
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"bridge {absolute_codec_string=PCMA,PCMU}sofia/gateway/$gateway/$dest\"/>\n";
                break;

            case 'sub_ivr':
                // অন্য কোন IVR XML ID তে ট্রান্সফার
                $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"ivr $dest\"/>\n";
                break;

            case 'repeat':
                $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n";
                break;
        }
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// ফাইল সেভ এবং রিলোড
if (file_put_contents($full_path, $xml)) {
    chmod($full_path, 0644);
    
    // রিলোড কমান্ড
    @shell_exec("fs_cli -x 'reloadxml' 2>/dev/null");
    
    $response['success'] = true;
    $response['message'] = 'IVR XML generated successfully';
    $response['ivr_name'] = $final_ivr_name;
    $response['file_path'] = $full_path;
} else {
    $response['message'] = 'Failed to write file. Check directory permissions.';
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
