<?php
header('Content-Type: application/json');

// ১. ইনপুট ডাটা গ্রহণ করা
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// ২. ডাটা থেকে ভেরিয়েবল সেট করা
$ivr_name = preg_replace('/[^a-zA-Z0-9_]/', '', $data['ivr_name']) . '_' . time();
$welcome_msg = $data['welcome_msg'];
$invalid_msg = $data['invalid_msg'] ?: 'ivr/ivr-invalid_extension.wav';
$timeout = ($data['timeout_sec'] ?: 5) * 1000; // সেকেন্ডকে মিলিসেকেন্ডে রূপান্তর
$max_failures = $data['max_failures'] ?: 3;
$digit_actions = $data['digit_action'] ?: [];

// ৩. XML তৈরি করা
$xml = "<include>\n";
$xml .= "  <menu name=\"$ivr_name\"\n";
$xml .= "        greet-long=\"$welcome_msg\"\n";
$xml .= "        invalid-sound=\"$invalid_msg\"\n";
$xml .= "        timeout=\"$timeout\"\n";
$xml .= "        max-failures=\"$max_failures\">\n";

// ৪. ডিজিট অনুযায়ী অ্যাকশন জেনারেট করা
foreach ($digit_actions as $digit => $action) {
    $type = $action['type'];
    $dest = $action['dest'];

    if ($type === 'forward') {
        // মোবাইল নম্বর হলে নির্দিষ্ট গেটওয়ে ব্যবহার করে ব্রিজ করা হবে
        $gateway = "09617401201"; // আপনার দেওয়া গেটওয়ে
        $param = "bridge sofia/gateway/$gateway/$dest";
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"$param\"/>\n";
    } 
    elseif ($type === 'extension') {
        // এক্সটেনশন হলে সরাসরি ট্রান্সফার
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
    }
    elseif ($type === 'repeat') {
        // মেনু রিপিট করার জন্য
        $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n";
    }
    // অন্য কোনো টাইপ থাকলে এখানে যোগ করা যাবে
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// ৫. ফাইল সেভ করা (FreeSWITCH IVR ডিরেক্টরিতে)
$file_path = "/etc/freeswitch/ivr_menus/$ivr_name.xml";
$save = file_put_contents($file_path, $xml);

if ($save) {
    // FreeSWITCH কে রিলোড করার কমান্ড (ঐচ্ছিক - যদি আপনার পারমিশন থাকে)
    exec("fs_cli -x 'reloadxml'");
    
    echo json_encode([
        'success' => true,
        'ivr_name' => $ivr_name,
        'message' => 'IVR created with Gateway routing'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write XML file']);
}
