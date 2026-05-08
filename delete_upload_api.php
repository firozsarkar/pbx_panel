<?php
header('Content-Type: application/json');

$response = [
    "status" => "error",
    "message" => ""
];

// ফাইলের পাথ (যেখানে ফাইলগুলো জমা আছে)
$target_dir = "/usr/share/freeswitch/sounds/en/us/callie/custom/";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_name'])) {
    
    // সিকিউরিটির জন্য ফাইলের নাম ক্লিন করা (যাতে কেউ ../ ব্যবহার করে অন্য ফাইল ডিলিট না করতে পারে)
    $file_name = basename($_POST['file_name']); 
    $file_path = $target_dir . $file_name;

    // ফাইলটি আছে কি না চেক করা
    if (file_exists($file_path)) {
        if (unlink($file_path)) {
            $response["status"] = "success";
            $response["message"] = "ফাইলটি সফলভাবে মুছে ফেলা হয়েছে।";
        } else {
            $response["message"] = "ফাইলটি ডিলিট করতে সার্ভারে সমস্যা হচ্ছে।";
        }
    } else {
        $response["message"] = "এই নামের কোনো ফাইল সার্ভারে পাওয়া যায়নি।";
    }
} else {
    $response["message"] = "Invalid Request. ফাইলের নাম পাঠানো হয়নি।";
}

echo json_encode($response);
