<?php
// নিরাপত্তা নিশ্চিত করতে একটি সিক্রেট টোকেন ব্যবহার করুন
$auth_token = "123"; 

if ($_GET['token'] !== $auth_token) {
    die("Unauthorized Access!");
}

$extension = $_GET['ext'];

if (!$extension) {
    die("Please provide an extension number. Example: ?ext=1000");
}

// FreeSWITCH এক্সটেনশন ফাইলের পাথ
$file_path = "/etc/freeswitch/directory/default/" . $extension . ".xml";

if (file_exists($file_path)) {
    if (unlink($file_path)) {
        // ফাইল ডিলিট হওয়ার পর FreeSWITCH রিলোড করা জরুরি
        $reload = shell_exec('fs_cli -x "reloadxml"');
        
        echo json_encode([
            "status" => "success",
            "message" => "Extension $extension deleted and XML reloaded.",
            "fs_response" => $reload
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Could not delete file. Check permissions."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Extension $extension.xml not found at $file_path"]);
}
?>
