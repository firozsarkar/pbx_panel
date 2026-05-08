<?php
$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $did_number   = trim($_POST['did_number']);
    $transfer_type = trim($_POST['transfer_type']);   // ivr | extension | ringgroup
    $destination   = trim($_POST['destination']);     // IVR name / Extension / Ringgroup number
    $dir = '/etc/freeswitch/dialplan/public/';

    if (!empty($did_number) && !empty($destination)) {

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<include>' . "\n" .
               '  <extension name="Inbound_' . preg_replace('/[^0-9]/', '', $did_number) . '">' . "\n" .
               '    <condition field="destination_number" expression="^' . preg_quote($did_number, '/') . '$">' . "\n" .
               '      <action application="answer"/>' . "\n" .
               '      <action application="sleep" data="1000"/>' . "\n";

        // ==================== Transfer Logic ====================
        if ($transfer_type === 'ivr') {
            $xml .= '      <action application="ivr" data="' . htmlspecialchars($destination) . '"/>' . "\n";
        } 
        elseif ($transfer_type === 'extension') {
            $xml .= '      <action application="transfer" data="' . $destination . ' XML default"/>' . "\n";
        } 
        elseif ($transfer_type === 'ringgroup') {
            // Ring Group এর জন্য সরাসরি bridge (সবাই একসাথে রিং হবে)
            $xml .= '      <action application="bridge" data="user/' . $destination . '@$${domain}"/>' . "\n";
            // অথবা একাধিক extension দিলে: user/1001@$${domain},user/1002@$${domain}
        }

        $xml .= '    </condition>' . "\n" .
                '  </extension>' . "\n" .
                '</include>' . "\n";

        // ফোল্ডার তৈরি
        if (!file_exists($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file_path = $dir . "01_inbound_" . preg_replace('/[^0-9]/', '', $did_number) . ".xml";

        if (@file_put_contents($file_path, $xml)) {
            $message = "✅ ইনবাউন্ড ফাইল সফলভাবে তৈরি হয়েছে: <b>{$file_path}</b><br>";
            $message .= "Type: <b>" . strtoupper($transfer_type) . "</b> → {$destination}";

            if (isset($_POST['reload_freeswitch'])) {
                $output = shell_exec('fs_cli -x "reloadxml" 2>&1');
                if (strpos($output, '+OK') !== false || empty($output)) {
                    $message .= "<br>✅ FreeSWITCH reloadxml সফল হয়েছে।";
                } else {
                    $message .= "<br>⚠️ Reload করা হয়েছে, চেক করুন: " . htmlspecialchars($output);
                }
            }
        } else {
            $error = "❌ ফাইল সেভ করা যায়নি। Permission চেক করুন (/etc/freeswitch/dialplan/public/)";
        }
    } else {
        $error = "❌ DID Number এবং Destination পূরণ করুন।";
    }
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>FreeSWITCH Inbound Manager (API Mode)</title>
    <style>
        body { font-family: Arial, sans-serif; background:#121212; color:#e0e0e0; padding:20px; }
        .container { max-width:700px; margin:30px auto; background:#1e1e1e; padding:30px; border-radius:10px; 
                     box-shadow:0 4px 20px rgba(212,175,55,0.3); border:1px solid #d4af37; }
        h2 { text-align:center; color:#d4af37; }
        label { display:block; margin:12px 0 6px; font-weight:bold; color:#d4af37; }
        input, select { width:100%; padding:12px; background:#2a2a2a; border:1px solid #444; color:#e0e0e0; 
                        border-radius:5px; box-sizing:border-box; font-size:16px; }
        input:focus, select:focus { border-color:#d4af37; }
        .buttons-group { display:flex; gap:12px; margin-top:25px; }
        button { flex:1; padding:14px; font-size:16px; font-weight:bold; border:none; border-radius:5px; cursor:pointer; }
        .btn-save { background:#d4af37; color:#121212; }
        .btn-save:hover { background:#c19a2f; }
        .btn-reload { background:#00bcd4; color:#121212; }
        .alert { padding:14px; margin:15px 0; border-radius:5px; }
        .success { background:rgba(76,175,80,0.2); color:#81c784; border:1px solid #4caf50; }
        .error { background:rgba(244,67,54,0.2); color:#e57373; border:1px solid #f44336; }
    </style>
</head>
<body>
<div class="container">
    <h2>FreeSWITCH Inbound Manager (Multi Type)</h2>

    <?php if ($message): ?>
        <div class="alert success"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <label>DID / Inbound Number</label>
        <input type="text" name="did_number" placeholder="09617171305" required value="<?= htmlspecialchars($_POST['did_number'] ?? '') ?>">

        <label>Transfer Type</label>
        <select name="transfer_type" required>
            <option value="ivr">IVR</option>
            <option value="extension">Extension / User</option>
            <option value="ringgroup">Ring Group</option>
        </select>

        <label>Destination</label>
        <input type="text" name="destination" placeholder="Sales_IVR_1778238578 অথবা 1001 অথবা 2001" required 
               value="<?= htmlspecialchars($_POST['destination'] ?? '') ?>">

        <div class="buttons-group">
            <button type="submit" class="btn-save" name="save_inbound">Save Inbound</button>
            <button type="submit" class="btn-reload" name="reload_freeswitch">Save & Reload FreeSWITCH</button>
        </div>
    </form>

    <small style="color:#888; display:block; margin-top:20px;">
        IVR → IVR menu name<br>
        Extension → 1001, 1002 ইত্যাদি<br>
        Ring Group → ring group extension number (যেমন 2001)
    </small>
</div>
</body>
</html>
