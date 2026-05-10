<?php
header('Content-Type: application/json');

// ১. ইনপুট ডাটা গ্রহণ করা
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// ২. ডাটা থেকে ভেরিয়েবল সেট করা
// রিকোয়েস্ট থেকে gateway নম্বর নেওয়া হবে, না থাকলে ডিফল্ট একটা থাকবে
$gateway = isset($data['gateway']) ? $data['gateway'] : "09617401201"; 

$ivr_name = preg_replace('/[^a-zA-Z0-9_]/', '', $data['ivr_name']) . '_' . time();
$welcome_msg = $data['welcome_msg'];
$invalid_msg = isset($data['invalid_msg']) ? $data['invalid_msg'] : 'ivr/ivr-invalid_extension.wav';
$timeout = (isset($data['timeout_sec']) ? $data['timeout_sec'] : 5) * 1000;
$max_failures = isset($data['max_failures']) ? $data['max_failures'] : 3;
$digit_actions = isset($data['digit_action']) ? $data['digit_action'] : [];

// ৩. XML তৈরি করা
$xml = "<include>\n";
$xml .= "  <menu name=\"$ivr_name\"\n";
$xml .= "        greet-long=\"$welcome_msg\"\n";
$xml .= "        invalid-sound=\"$invalid_msg\"\n";
$xml .= "        timeout=\"$timeout\"\n";
$xml .= "        max-failures=\"$max_failures\">\n";

// ৪. ডিজিট অনুযায়ী অ্যাকশন জেনারেট করা
foreach ($digit_actions as $digit => $action) {
    $type = $action['type'];
    $dest = $action['dest'];

    if ($type === 'forward') {
        // রিকোয়েস্ট থেকে পাওয়া $gateway এখানে ব্যবহার হবে
        $param = "bridge sofia/gateway/$gateway/$dest";
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"$param\"/>\n";
    } 
    elseif ($type === 'extension') {
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
    }
    elseif ($type === 'repeat') {
        $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n";
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// ৫. ফাইল সেভ করা
$file_path = "/etc/freeswitch/ivr_menus/$ivr_name.xml";
$save = @file_put_contents($file_path, $xml);

if ($save) {
    // FreeSWITCH রিলোড কমান্ড
    exec("fs_cli -x 'reloadxml'");
    
    echo json_encode([
        'success' => true,
        'ivr_name' => $ivr_name,
        'gateway_used' => $gateway,
        'message' => 'IVR created successfully via API gateway'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write XML file. Check permissions.']);
}
