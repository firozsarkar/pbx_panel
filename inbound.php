<?php
$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $did_number = trim($_POST['did_number']);
    $extension  = trim($_POST['extension']);
    $dir = '/etc/freeswitch/dialplan/public/';

    if (!empty($did_number) && !empty($extension)) {
        
        $xml_output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
        '<include>' . "\n" .
        '    <extension name="Inbound_Calls">' . "\n" .
        '        <condition field="destination_number" expression="^' . $did_number . '$">' . "\n" .
        '            <action application="set" data="domain_name=$${domain}"/>' . "\n" .
        '            <action application="transfer" data="' . $extension . ' XML default"/>' . "\n" .
        '        </condition>' . "\n" .
        '    </extension>' . "\n" .
        '</include>' . "\n";

        // ফোল্ডার তৈরি
        if (!file_exists($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file_path = $dir . "01_inbound_" . preg_replace('/[^0-9]/', '', $did_number) . ".xml";

        if (@file_put_contents($file_path, $xml_output)) {
            $message = "✅ ইনবাউন্ড ফাইল সফলভাবে তৈরি হয়েছে: <b>{$file_path}</b>";
            
            if (isset($_POST['reload_freeswitch'])) {
                $output = shell_exec('fs_cli -x "reloadxml" 2>&1');
                if (strpos($output, 'OK') !== false || empty($output)) {
                    $message .= "<br>✅ FreeSWITCH reloadxml সফল হয়েছে।";
                } else {
                    $message .= "<br>⚠️ Reload সম্পন্ন, কিন্তু চেক করুন: " . htmlspecialchars($output);
                }
            }
        } else {
            $error = "❌ ফাইল সেভ করা যায়নি। ফোল্ডারের Permission চেক করুন (ফাইল সার্ভারে www-data বা freeswitch ইউজারের লেখার অনুমতি থাকতে হবে)।";
        }
    } else {
        $error = "❌ DID Number এবং Extension উভয়ই পূরণ করুন।";
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
        .container { max-width: 650px; margin: 30px auto; background: #1e1e1e; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(212, 175, 55, 0.3); border: 1px solid #d4af37; }
        h2 { text-align: center; color: #d4af37; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-weight: bold; color: #d4af37; }
        input { width: 100%; padding: 12px; background-color: #2a2a2a; border: 1px solid #444; color: #e0e0e0; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
        input:focus { border-color: #d4af37; }
        .buttons-group { display: flex; gap: 12px; margin-top: 25px; }
        button { flex: 1; padding: 14px; font-size: 16px; font-weight: bold; border: none; border-radius: 5px; cursor: pointer; }
        .btn-save { background-color: #d4af37; color: #121212; }
        .btn-save:hover { background-color: #c19a2f; }
        .btn-reload { background-color: #00bcd4; color: #121212; }
        .btn-reload:hover { background-color: #0097a7; }
        .alert { padding: 14px; margin: 15px 0; border-radius: 5px; }
        .success { background: rgba(76, 175, 80, 0.2); color: #81c784; border: 1px solid #4caf50; }
        .error { background: rgba(244, 67, 54, 0.2); color: #e57373; border: 1px solid #f44336; }
    </style>
</head>
<body>
<div class="container">
    <h2>FreeSWITCH Inbound Manager</h2>
    
    <?php if (!empty($message)): ?>
        <div class="alert success"><?= $message ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="did_number">DID / Inbound Number (যেমন: 09617171305)</label>
            <input type="text" id="did_number" name="did_number" placeholder="09617171305" required>
        </div>
        <div class="form-group">
            <label for="extension">Destination Extension (যেমন: 1001)</label>
            <input type="text" id="extension" name="extension" placeholder="1001" required>
        </div>
       
        <div class="buttons-group">
            <button type="submit" class="btn-save" name="save_inbound">Save Inbound</button>
            <button type="submit" class="btn-reload" name="reload_freeswitch">Save & Reload FreeSWITCH</button>
        </div>
    </form>
</div>
</body>
</html>
