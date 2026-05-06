<?php
$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $did_number = trim($_POST['did_number']);
    $extension = trim($_POST['extension']);
    $dir = '/etc/freeswitch/dialplan/public/';

    // প্রয়োজনীয় ফিল্ড যাচাই
    if (!empty($did_number) && !empty($extension)) {

        // আপনার দেওয়া সঠিক XML স্ট্রাকচার 
        $xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
        "<include>\n" .
        "  <extension name=\"Inbound_Calls\">\n" .
        "    <condition field=\"destination_number\" expression=\"^{$did_number}$\">\n" .
        "      <action application=\"set\" data=\"domain_name=\${domain}\"/>\n" .
        "      <action application=\"transfer\" data=\"{$extension} XML default\"/>\n" .
        "    </condition>\n" .
        "  </extension>\n" .
        "</include>";

        // ফোল্ডার না থাকলে তৈরি করা
        if (!file_exists($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file_path = $dir . "inbound_" . $did_number . ".xml";

        // ফাইল সেভ করা
        if (@file_put_contents($file_path, $xml_output)) {
            $message = "ইনবাউন্ড ফাইলটি সফলভাবে তৈরি হয়েছে: <b>{$file_path}</b>";

            // FreeSWITCH রিলোড করার অপশন
            if (isset($_POST['reload_freeswitch'])) {
                $output = shell_exec('fs_cli -x "reloadxml" 2>&1');
                $message .= " এবং FreeSWITCH রিলোড করা হয়েছে।";
            }
        } else {
            $error = "ফাইলটি সেভ করা যায়নি! ফোল্ডার পারমিশন (Permissions) চেক করুন।";
        }
    } else {
        $error = "দয়া করে ইনবাউন্ড নম্বর এবং এক্সটেনশন সঠিকভাবে পূরণ করুন।";
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>FreeSWITCH Inbound Dialplan Manager</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #121212; color: #e0e0e0; padding: 20px; }
        .container { max-width: 600px; margin: 30px auto; background: #1e1e1e; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3); border: 1px solid #d4af37; }
        h2 { text-align: center; color: #d4af37; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: bold; color: #d4af37; }
        input { width: 100%; padding: 10px; background-color: #2a2a2a; border: 1px solid #444; color: #e0e0e0; border-radius: 4px; box-sizing: border-box; }
        input:focus { border-color: #d4af37; outline: none; }
        .buttons-group { display: flex; gap: 10px; margin-top: 20px; }
        button { flex: 1; padding: 12px; color: #121212; border: none; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .btn-save { background-color: #d4af37; }
        .btn-save:hover { background-color: #aa8c2c; }
        .btn-reload { background-color: #00bcd4; color: #121212; }
        .btn-reload:hover { background-color: #00838f; }
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 4px; background: rgba(76, 175, 80, 0.2); color: #81c784; border: 1px solid #4caf50; }
        .error { padding: 12px; margin-bottom: 20px; border-radius: 4px; background: rgba(244, 67, 54, 0.2); color: #e57373; border: 1px solid #f44336; }
    </style>
</head>
<body>

<div class="container">
    <h2>FreeSWITCH Inbound Manager</h2>
    
    <?php if (!empty($message)): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="did_number">DID / Inbound Number (যেমন: 09617171305):</label>
            <input type="text" id="did_number" name="did_number" placeholder="09617171305" required>
        </div>
        <div class="form-group">
            <label for="extension">Destination Extension (যেমন: 1001):</label>
            <input type="text" id="extension" name="extension" placeholder="1001" required>
        </div>
        
        <div class="buttons-group">
            <button type="submit" class="btn-save" name="save_inbound">Save Inbound</button>
            <button type="submit" class="btn-reload" name="reload_freeswitch">Save & Reload</button>
        </div>
    </form>
</div>

</body>
</html>

</body>
</html>
