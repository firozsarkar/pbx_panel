<?php
// Error reporting for debugging if needed (Optional)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// কমান্ডগুলো রান করার জন্য ভেরিয়েবল
$output1 = "";
$output2 = "";

// FreeSWITCH কমান্ড দুটি এক্সিকিউট করা হচ্ছে
if (function_exists('shell_exec')) {
    $cmd1 = 'fs_cli -x "reloadxml" 2>&1';
    $cmd2 = 'fs_cli -x "sofia profile external rescan" 2>&1';

    $output1 = shell_exec($cmd1);
    $output2 = shell_exec($cmd2);
} else {
    $output1 = "Error: shell_exec() is disabled on your server.";
    $output2 = "";
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH Reload</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f8;
            padding: 20px;
        }
        .container {
            max-width: 650px;
            margin: 50px auto;
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
        .alert {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        pre {
            background-color: #272822;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-family: "Courier New", Courier, monospace;
            font-size: 14px;
        }
        .label {
            font-weight: bold;
            color: #333;
            margin-top: 15px;
            display: block;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>FreeSWITCH রিলোড সিস্টেম</h2>
    
    <div class="alert">
        <strong>সফল!</strong> FreeSWITCH কনফিগারেশন এবং প্রোফাইল রিলোড করার কাজ শুরু হয়েছে।
    </div>

    <div>
        <span class="label">> $ fs_cli -x "reloadxml"</span>
        <pre><?= htmlspecialchars($output1 ?: 'No output returned'); ?></pre>
    </div>

    <div>
        <span class="label">> $ fs_cli -x "sofia profile external rescan"</span>
        <pre><?= htmlspecialchars($output2 ?: 'No output returned'); ?></pre>
    </div>
</div>

</body>
</html>
