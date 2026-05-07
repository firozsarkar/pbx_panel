<?php
$message = "";
$error = "";

// অডিও ফাইল কোথায় জমা হবে (পাথ পরিবর্তন করতে পারেন)
$target_dir = "/usr/share/freeswitch/sounds/en/us/callie/custom/";

// ফোল্ডার না থাকলে তৈরি করা
if (!file_exists($target_dir)) {
    @mkdir($target_dir, 0775, true);
    @chown($target_dir, 'www-data');
}

if (isset($_POST['submit'])) {
    $target_file = $target_dir . basename($_FILES["audio_file"]["name"]);
    $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // শুধুমাত্র অডিও ফাইল এলাউ করা
    $allowed_types = array("wav", "mp3", "ogg");

    if (empty($_FILES["audio_file"]["name"])) {
        $error = "দয়া করে একটি ফাইল সিলেক্ট করুন।";
    } elseif (!in_array($fileType, $allowed_types)) {
        $error = "শুধুমাত্র WAV, MP3 এবং OGG ফাইল আপলোড করা যাবে।";
    } elseif ($_FILES["audio_file"]["size"] > 5000000) { // ৫ এমবি লিমিট
        $error = "ফাইলটি অনেক বড়! ৫ এমবির নিচে ফাইল দিন।";
    } else {
        if (move_uploaded_file($_FILES["audio_file"]["tmp_name"], $target_file)) {
            // ফাইলের পারমিশন ঠিক করা
            @chmod($target_file, 0664);
            @chown($target_file, 'www-data');
            $message = "ফাইলটি সফলভাবে আপলোড হয়েছে: <b>" . basename($_FILES["audio_file"]["name"]) . "</b>";
        } else {
            $error = "ফাইল আপলোড করতে সমস্যা হয়েছে। ফোল্ডার পারমিশন চেক করুন।";
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
    <?php if ($error) echo "<div class='error'>$error</div>"; ?>

    <form action="" method="post" enctype="multipart/form-data">
        <label class="custom-file-upload">
            <input type="file" name="audio_file" id="audio_file" accept="audio/*">
            <span id="file-name">ক্লিক করে ফাইল সিলেক্ট করুন</span>
        </label>
        <button type="submit" name="submit" class="btn-upload">Upload Voice</button>
    </form>
</div>

<script>
    document.getElementById('audio_file').onchange = function () {
        document.getElementById('file-name').innerHTML = this.files[0].name;
    };
</script>

</body>
</html>
