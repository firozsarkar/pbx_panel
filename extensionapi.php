<?php
header('Content-Type: application/json; charset=utf-8');

// শুধুমাত্র POST রিকোয়েস্ট গ্রহণ করবে
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

// ইনপুট ভ্যালুগুলো সংগ্রহ করা
$user_id = trim($_POST['user_id'] ?? '');
$password = trim($_POST['password'] ?? '');
$vm_password = trim($_POST['vm_password'] ?? '');
$user_context = trim($_POST['user_context'] ?? '');
$effective_caller_id_name = trim($_POST['effective_caller_id_name'] ?? '');
$effective_caller_id_number = trim($_POST['effective_caller_id_number'] ?? '');
$outbound_caller_id_name = trim($_POST['outbound_caller_id_name'] ?? '');
$outbound_caller_id_number = trim($_POST['outbound_caller_id_number'] ?? '');

$dir = trim($_POST['dir'] ?? '/etc/freeswitch/directory/default');

// প্রয়োজনীয় ফিল্ডগুলো চেক করা
if (empty($user_id) || empty($password) || empty($user_context)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Required fields missing: user_id, password, and user_context are mandatory.'
    ]);
    exit;
}

// ডিরেক্টরি পাথ ঠিক করা
if (substr($dir, -1) !== '/') {
    $dir .= '/';
}

// আপনার দেওয়া ফরম্যাট অনুযায়ী XML তৈরি
$xml_output = "<include>\n";
$xml_output .= "  <user id=\"{$user_id}\">\n";
$xml_output .= "    <params>\n";
$xml_output .= "      <param name=\"password\" value=\"{$password}\"/>\n";
$xml_output .= "      <param name=\"vm-password\" value=\"{$vm_password}\"/>\n";
$xml_output .= "    </params>\n";
$xml_output .= "    <variables>\n";
$xml_output .= "      <param name=\"user_context\" value=\"{$user_context}\"/>\n";
$xml_output .= "      <param name=\"effective_caller_id_name\" value=\"{$effective_caller_id_name}\"/>\n";
$xml_output .= "      <param name=\"effective_caller_id_number\" value=\"{$effective_caller_id_number}\"/>\n";
$xml_output .= "      <param name=\"outbound_caller_id_name\" value=\"{$outbound_caller_id_name}\"/>\n";
$xml_output .= "      <param name=\"outbound_caller_id_number\" value=\"{$outbound_caller_id_number}\"/>\n";
$xml_output .= "\n";
$xml_output .= "      <param name=\"toll_allow\" value=\"domestic,international,local\"/>\n";
$xml_output .= "      <param name=\"accountcode\" value=\"{$user_id}\"/>\n";
$xml_output .= "    </variables>\n";
$xml_output .= "  </user>\n";
$xml_output .= "</include>";

// ডিরেক্টরি না থাকলে তৈরি করা
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

$file_path = $dir . $user_id . ".xml";

// ফাইল সেভ করা
if (@file_put_contents($file_path, $xml_output)) {
    $message = "সফলভাবে ইউজার {$user_id} এর ফাইল তৈরি হয়েছে।";
    
    $reload_output = "";
    if (isset($_POST['reload_freeswitch'])) {
        // FreeSWITCH রিলোড কমান্ড
        $reload_output = shell_exec('fs_cli -x "reloadxml" 2>&1');
        $message .= " এবং FreeSWITCH রিলোড করা হয়েছে।";
    }

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'file' => $file_path,
        'reload_info' => $reload_output
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'ফাইল রাইট করা সম্ভব হয়নি। পারমিশন চেক করুন।'
    ]);
}
?>
