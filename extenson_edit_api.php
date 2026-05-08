<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
    exit;
}

$extension = trim($_POST['extension'] ?? '');
$password  = trim($_POST['password'] ?? '');
$forward   = trim($_POST['call_forward_mobile'] ?? '');
$on_busy   = $_POST['on_busy_option'] ?? 'hangup';
$on_off    = $_POST['on_offline_option'] ?? 'hangup';
$gateway   = "your_gateway_name"; // আপনার ট্রাঙ্ক বা গেটওয়ের নাম এখানে দিন

if (empty($extension)) exit(json_encode(['status' => 'error', 'message' => 'Missing extension']));

// কল ফরওয়ার্ডিং লজিক অনুযায়ী ডিয়াল স্ট্রিং তৈরি
$dial_string = "{presence_id=\${dialed_user}@\${dialed_domain}}user/\${dialed_user}";

if (!empty($forward)) {
    // যদি ফরওয়ার্ড নাম্বার থাকে, তবে ফেলওভার লজিক সেট করা
    // ব্যস্ত থাকলে বা অফলাইন থাকলে গেটওয়ে দিয়ে ফরওয়ার্ড হবে
    $dial_string = "{presence_id=\${dialed_user}@\${dialed_domain},continue_on_fail=true}user/\${dialed_user},sofia/gateway/$gateway/$forward";
}

$xml_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<include>
  <user id=\"$extension\" cacheable=\"false\">
    <params>
      <param name=\"password\" value=\"$password\"/>
      <param name=\"vm-password\" value=\"1234\"/>
      <!-- ডাইরেক্ট এক্সটেনশনের ভেতরেই কল লজিক -->
      <param name=\"dial-string\" value=\"$dial_string\"/>
    </params>
    <variables>
      <variable name=\"toll_allow\" value=\"domestic,international,local\"/>
      <variable name=\"accountcode\" value=\"$extension\"/>
      <variable name=\"user_context\" value=\"default\"/>
      <variable name=\"call_forward_mobile\" value=\"$forward\"/>
      <variable name=\"on_busy_option\" value=\"$on_busy\"/>
      <variable name=\"on_offline_option\" value=\"$on_off\"/>
    </variables>
  </user>
</include>";

$dir = "/etc/freeswitch/directory/default/";
$file_path = $dir . $extension . ".xml";

if (file_put_contents($file_path, $xml_content)) {
    shell_exec('fs_cli -x "reloadxml"');
    echo json_encode(['status' => 'success', 'message' => 'Extension updated inside XML']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
}
