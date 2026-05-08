<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

// ইনপুট ডেটা গ্রহণ
$extension   = trim($_POST['extension'] ?? '');
$password    = trim($_POST['password'] ?? '');
$vm_password = trim($_POST['vm_password'] ?? '1234');
$forward_num = trim($_POST['call_forward_mobile'] ?? '');
$on_busy     = trim($_POST['on_busy_option'] ?? 'hangup');
$on_offline  = trim($_POST['on_offline_option'] ?? 'hangup');
$dir         = trim($_POST['dir'] ?? '/etc/freeswitch/directory/default');

// এক্সটেনশন এবং পাসওয়ার্ড বাধ্যতামূলক
if (empty($extension) || empty($password)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'এক্সটেনশন এবং পাসওয়ার্ড অবশ্যই দিতে হবে।'
    ]);
    exit;
}

if (substr($dir, -1) !== '/') { $dir .= '/'; }
$file_path = $dir . $extension . ".xml";

// XML ফরম্যাট তৈরি (এডিট/আপডেট স্টাইল)
// এখানে variables সেকশনে আপনার দেওয়া কল ফরওয়ার্ডিং অপশনগুলো যোগ করা হয়েছে
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml_output .= "<include>\n";
$xml_output .= "  <user id=\"{$extension}\">\n";
$xml_output .= "    <params>\n";
$xml_output .= "      <param name=\"password\" value=\"{$password}\"/>\n";
$xml_output .= "      <param name=\"vm-password\" value=\"{$vm_password}\"/>\n";
$xml_output .= "    </params>\n";
$xml_output .= "    <variables>\n";
$xml_output .= "      <variable name=\"toll_allow\" value=\"domestic,international,local\"/>\n";
$xml_output .= "      <variable name=\"accountcode\" value=\"{$extension}\"/>\n";
$xml_output .= "      <variable name=\"user_context\" value=\"default\"/>\n";
$xml_output .= "      <variable name=\"call_forward_mobile\" value=\"{$forward_num}\"/>\n";
$xml_output .= "      <variable name=\"on_busy_option\" value=\"{$on_busy}\"/>\n";
$xml_output .= "      <variable name=\"on_offline_option\" value=\"{$on_offline}\"/>\n";
$xml_output .= "    </variables>\n";
$xml_output .= "  </user>\n";
$xml_output .= "</include>";

// ডিরেক্টরি চেক
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

// ফাইল সেভ করা (এটি আগের ফাইল থাকলে তার ওপর ওভাররাইট করবে অর্থাৎ আপডেট হবে)
if (@file_put_contents($file_path, $xml_output)) {
    $message = "এক্সটেনশন {$extension} সফলভাবে আপডেট হয়েছে!";
    
    // FreeSWITCH রিলোড
    $reload_output = "";
    if (isset($_POST['reload_freeswitch']) || true) { // ডিফল্টভাবে রিলোড হবে
        $reload_output = shell_exec('fs_cli -x "reloadxml" 2>&1');
        $message .= " (FreeSWITCH Reloaded)";
    }

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'file' => $file_path,
        'reload_log' => $reload_output
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'ফাইল রাইট করা যায়নি। পারমিশন চেক করুন: chown www-data:www-data ' . $dir
    ]);
}
?>
