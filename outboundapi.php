<?php
// JSON Header
header('Content-Type: application/json; charset=utf-8');

// শুধুমাত্র POST রিকোয়েস্ট গ্রহণ করবে
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

// ইনপুট ডেটা গ্রহণ (টার্মিনাল বা ফর্ম থেকে)
$ext_name = isset($_POST['extension_name']) ? trim($_POST['extension_name']) : ''; // উদাহরণ: 100
$gw_num   = isset($_POST['gateway_number']) ? trim($_POST['gateway_number']) : '';  // উদাহরণ: 09617401201

// ফিল্ড চেক
if (empty($ext_name) || empty($gw_num)) {
    echo json_encode(['status' => 'error', 'message' => 'extension_name এবং gateway_number অবশ্যই দিতে হবে।']);
    exit;
}

// ফাইল পাথ (আপনার ডিরেক্টরি অনুযায়ী)
$dir = '/etc/freeswitch/dialplan/public/';
$file_path = $dir . "outbound_{$ext_name}.xml";

// XML ফরম্যাট - আপনার দেওয়া সব প্যারামিটারসহ
$xml_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml_content .= "<include>\n";
$xml_content .= "  <extension name=\"{$ext_name}_outbound\">\n";
// ১. কন্ডিশন এক্সপ্রেশন (Caller ID এর জন্য)
$xml_content .= "    <condition field=\"caller_id_number\" expression=\"^{$ext_name}$\"/>\n";
$xml_content .= "    <condition field=\"destination_number\" expression=\"^(\d+)$\">\n";
// ২. কার্যকর কলার আইডি সেট
$xml_content .= "      <action application=\"set\" data=\"effective_caller_id_number={$gw_num}\"/>\n";
// ৩. আউটবাউন্ড কলার আইডি সেট
$xml_content .= "      <action application=\"set\" data=\"outbound_caller_id_number={$gw_num}\"/>\n";
// ৪. ইউজার কনটেক্সট সেট
$xml_content .= "      <action application=\"set\" data=\"user_context={$gw_num}\"/>\n";
// ৫. হ্যাংআপ আফটার ব্রিজ সেট
$xml_content .= "      <action application=\"set\" data=\"hangup_after_bridge=true\"/>\n";
// ৬. ব্রিজ ডায়ালপ্ল্যান
$xml_content .= "      <action application=\"bridge\" data=\"sofia/gateway/{$gw_num}/$1\"/>\n";
$xml_content .= "    </condition>\n";
$xml_content .= "  </extension>\n";
$xml_content .= "</include>";

// ফাইল সেভ করা
if (@file_put_contents($file_path, $xml_content)) {
    // FreeSWITCH রিলোড
    shell_exec('fs_cli -x "reloadxml"');

    echo json_encode([
        'status' => 'success',
        'message' => "ফাইল তৈরি হয়েছে: outbound_{$ext_name}.xml",
        'parameters' => [
            'expression' => "^{$ext_name}$",
            'effective_caller_id' => $gw_num,
            'outbound_caller_id' => $gw_num,
            'user_context' => $gw_num,
            'hangup_after_bridge' => 'true'
        ]
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'ফাইল রাইট পারমিশন নেই!']);
}
?>
