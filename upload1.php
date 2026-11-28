<?php
$message = "";
$error = "";

$target_dir = "/usr/share/freeswitch/sounds/en/us/callie/";

if (!file_exists($target_dir)) {
    if (!@mkdir($target_dir, 0775, true)) {
        $error = "টার্গেট ফোল্ডার তৈরি করা যাচ্ছে না।";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES["audio_file"])) {
        $error = "ফাইল ডেটা সার্ভারে পৌঁছায়নি (সম্ভবত post_max_size বা সার্ভার লিমিট ছাড়িয়ে গেছে)।";
    } else {
        $upload_err = $_FILES["audio_file"]["error"];

        if ($upload_err === UPLOAD_ERR_NO_FILE) {
            $error = "দয়া করে একটি ফাইল সিলেক্ট করুন।";
        } elseif ($upload_err !== UPLOAD_ERR_OK) {
            $err_map = [
                UPLOAD_ERR_INI_SIZE   => "ফাইলটি সার্ভারের upload_max_filesize লিমিটের চেয়ে বড়।",
                UPLOAD_ERR_FORM_SIZE  => "ফাইলটি ফর্মের MAX_FILE_SIZE লিমিটের চেয়ে বড়।",
                UPLOAD_ERR_PARTIAL    => "ফাইলটি সম্পূর্ণ আপলোড হয়নি।",
                UPLOAD_ERR_NO_TMP_DIR => "সার্ভারে temporary ফোল্ডার নেই।",
                UPLOAD_ERR_CANT_WRITE => "ডিস্কে ফাইল লিখতে সমস্যা হচ্ছে (Disk full বা permission)।",
                UPLOAD_ERR_EXTENSION  => "একটি PHP extension আপলোড বন্ধ করেছে।"
            ];
            $error = ($err_map[$upload_err] ?? "Upload error code: " . $upload_err);
        } else {
            $raw_name = basename($_FILES["audio_file"]["name"]);
            $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $raw_name);
            $target_file = $target_dir . $safe_name;
            $fileType = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
            $allowed_types = ["wav", "mp3", "ogg"];

            if (!in_array($fileType, $allowed_types)) {
                $error = "শুধুমাত্র WAV, MP3 এবং OGG ফাইল আপলোড করা যাবে।";
            } elseif ($_FILES["audio_file"]["size"] > 5000000) {
                $error = "ফাইলটি অনেক বড়! ৫ এমবির নিচে ফাইল দিন।";
            } elseif (!is_writable($target_dir)) {
                $error = "টার্গেট ফোল্ডারে লেখার অনুমতি নেই: " . htmlspecialchars($target_dir);
            } elseif (move_uploaded_file($_FILES["audio_file"]["tmp_name"], $target_file)) {
                @chmod($target_file, 0664);
                $message = "ফাইলটি সফলভাবে আপলোড হয়েছে: <b>" . htmlspecialchars($safe_name) . "</b>";
            } else {
                $error = "move_uploaded_file ব্যর্থ হয়েছে। ডিস্ক স্পেস বা tmp ফোল্ডার চেক করুন।";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Voice File Uploader</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #121212; color: #e0e0e0; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .upload-container { background: #1e1e1e; padding: 30px; border-radius: 12px; box-shadow: 0 8px 20px rgba(212, 175, 55, 0.2); border: 1px solid #d4af37; width: 400px; text-align: center; }
        h2 { color: #d4af37; margin-bottom: 20px; }
        .custom-file-upload { border: 2px dashed #444; display: inline-block; padding: 20px; cursor: pointer; width: 100%; box-sizing: border-box; border-radius: 8px; transition: 0.3s; }
        .custom-file-upload:hover { border-color: #d4af37; }
        input[type="file"] { display: none; }
        .btn-upload { background-color: #d4af37; color: #121212; border: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; cursor: pointer; margin-top: 20px; width: 100%; font-size: 16px; }
        .btn-upload:hover { background-color: #aa8c2c; }
        .alert { color: #81c784; background: rgba(76, 175, 80, 0.1); padding: 10px; border-radius: 5px; margin-bottom: 15px; border: 1px solid #4caf50; }
        .error { color: #e57373; background: rgba(244, 67, 54, 0.1); padding: 10px; border-radius: 5px; margin-bottom: 15px; border: 1px solid #f44336; }
    </style>
</head>
<body>

<div class="upload-container">
    <h2>Voice Uploader</h2>

    <?php if ($message) echo "<div class='alert'>$message</div>"; ?>
    <?php if ($error) echo "<div class='error'>" . htmlspecialchars($error) . "</div>"; ?>

    <form action="" method="post" enctype="multipart/form-data">
        <label class="custom-file-upload">
            <input type="file" name="audio_file" id="audio_file" accept=".wav,.mp3,.ogg,audio/*">
            <span id="file-name">ক্লিক করে ফাইল সিলেক্ট করুন</span>
        </label>
        <button type="submit" name="submit" class="btn-upload">Upload Voice</button>
    </form>
</div>

<script>
    document.getElementById('audio_file').onchange = function () {
        if (this.files && this.files[0]) {
            document.getElementById('file-name').textContent = this.files[0].name;
        }
    };
</script>

</body>
</html>
