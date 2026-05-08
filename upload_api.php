<?php
header('Content-Type: application/json');

$response = [
    "status" => "error",
    "message" => "",
    "file_name" => ""
];

// অডিও ফাইল সেভ করার পাথ
$target_dir = "/usr/share/freeswitch/sounds/en/us/callie/custom/";

// ফোল্ডার না থাকলে তৈরি করা
if (!file_exists($target_dir)) {
    if (!@mkdir($target_dir, 0775, true)) {
        $response["message"] = "Directory creation failed. Check permissions.";
        echo json_encode($response);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['audio_file'])) {
    
    $original_name = basename($_FILES["audio_file"]["name"]);
    $fileType = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed_types = array("wav", "mp3", "ogg");

    // ১. ফাইল টাইপ চেক
    if (!in_array($fileType, $allowed_types)) {
        $response["message"] = "শুধুমাত্র WAV, MP3 এবং OGG ফাইল আপলোড করা যাবে।";
    } 
    // ২. সাইজ চেক (৫ এমবি)
    elseif ($_FILES["audio_file"]["size"] > 5000000) {
        $response["message"] = "ফাইলটি অনেক বড়! ৫ এমবির নিচে ফাইল দিন।";
    } 
    else {
        // --- অটো রিনেম লজিক (সিরিয়াল নম্বর) ---
        $files = glob($target_dir . "*." . $fileType);
        $next_serial = count($files) + 1;
        
        // নতুন ফাইলের নাম: serial_original-name.ext
        $new_file_name = $next_serial . "_" . $original_name;
        $target_file = $target_dir . $new_file_name;

        // ফাইল মুভ করা
        if (move_uploaded_file($_FILES["audio_file"]["tmp_name"], $target_file)) {
            @chmod($target_file, 0664);
            
            $response["status"] = "success";
            $response["message"] = "ফাইলটি সফলভাবে আপলোড হয়েছে।";
            $response["file_name"] = $new_file_name; // এটি আপনি ডাটাবেসে সেভ করবেন
        } else {
            $response["message"] = "সার্ভারে ফাইল সেভ করতে সমস্যা হয়েছে।";
        }
    }
} else {
    $response["message"] = "Invalid Request.";
}

// JSON আউটপুট
echo json_encode($response);
