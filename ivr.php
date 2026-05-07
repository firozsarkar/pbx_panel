<?php
/**
 * Project: VoIP IVR System
 * Location: /var/www/html/ivr.php
 * Developed by: Firoz
 */

// ডেবগিং এর জন্য (প্রয়োজন না হলে বন্ধ রাখতে পারেন)
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: text/plain');

// ১. ইনপুট ডেটা গ্রহণ (GET/POST)
$caller_id = $_REQUEST['caller_id'] ?? $_REQUEST['Caller-Caller-ID-Number'] ?? 'Unknown';
$digit     = $_REQUEST['digit']     ?? $_REQUEST['dtmf_digit'] ?? '';
$action    = $_REQUEST['action']    ?? 'welcome';

// ২. রেসপন্স ফাংশন (VoIP গেটওয়ে বা ফ্রি-সুইচ ফরমেট অনুযায়ী)
function respond($command, $data) {
    // এখানে আপনার সিস্টেমের রিকোয়ারমেন্ট অনুযায়ী আউটপুট ফরম্যাট পরিবর্তন করতে পারেন
    echo "SET_ACTION: $command\n";
    echo "VALUE: $data\n";
    exit;
}

// ৩. মেইন আইভিআর লজিক
if ($action == 'welcome' && empty($digit)) {
    // শুরুতে ওয়েলকাম ফাইল প্লে করা
    respond('PLAY', '/var/lib/freeswitch/recordings/welcome.wav');
}

// ৪. ডিটিএমএফ ইনপুট প্রসেসিং
switch ($digit) {
    case '1':
        // সাপোর্ট ডিপার্টমেন্টে কল ট্রান্সফার
        respond('TRANSFER', '101'); 
        break;

    case '2':
        // সেলস ডিপার্টমেন্টে কল ট্রান্সফার
        respond('TRANSFER', '102');
        break;

    case '3':
        // অফিস লোকেশন বা তথ্য (Text to Speech)
        $info = "Our office is located at Dhaka, Bangladesh.";
        respond('SAY', $info);
        break;

    case '9':
        // সরাসরি এজেন্ট বা অপারেটর
        respond('TRANSFER', '900');
        break;

    case '0':
        // কল ডিসকানেক্ট করা
        respond('HANGUP', 'NORMAL_CLEARING');
        break;

    default:
        // ভুল ইনপুট দিলে আবার মেনু শোনানো
        respond('PLAY', '/var/lib/freeswitch/recordings/invalid_entry.wav');
        break;
}
?>
