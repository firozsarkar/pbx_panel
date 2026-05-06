<?php
$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $extension_name = trim($_POST['extension_name']);
    $gateway_name = trim($_POST['gateway_name']);
    $dir = trim($_POST['dir']);

    // Ensure path ends with slash
    if (substr($dir, -1) !== '/') {
        $dir .= '/';
    }

    // Dialplan XML format based on user's required structure
    $xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n  <extension name=\"{$extension_name}\">\n    <condition field=\"destination_number\" expression=\"^(\d+)￼1\">\n      <action application=\"bridge\" data=\"sofia/gateway/{$gateway_name}/$1\"/>\n    </condition>\n  </extension>\n</include>";

    // Check directory and create if not exists
    if (!file_exists($dir)) {
        @mkdir($dir, 0775, true);
    }

    $file_path = $dir . $extension_name . "_" . $gateway_name . "_outbound.xml";

    // Save configuration file
    if (@file_put_contents($file_path, $xml_output)) {
        $message = "সফলভাবে '{$file_path}' ফাইলটি তৈরি হয়েছে!";
        
        if (isset($_POST['reload_freeswitch'])) {
            $output = shell_exec('fs_cli -x "reloadxml" 2>&1');
            $message .= " (FreeSWITCH রিলোড করা হয়েছে)";
        }
    } else {
        $error = "ফাইলটি সেভ করা যায়নি! ফোল্ডার পারমিশন (Permissions) চেক করুন।";
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>FreeSWITCH Outbound Dialplan Manager</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f8; padding: 20px; }
        .container { max-width: 650px; margin: 30px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: bold; color: #444; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .buttons-group { display: flex; gap: 10px; margin-top: 20px; }
        button { flex: 1; padding: 12px; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; transition: 0.3s; }
        .btn-save { background-color: #28a745; }
        .btn-save:hover { background-color: #218838; }
        .btn-reload { background-color: #007bff; }
        .btn-reload:hover { background-color: #0069d9; }
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { padding: 12px; margin-bottom: 20px; border-radius: 4px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .path-helper { font-size: 12px; color: #666; margin-top: 3px; }
    </style>
</head>
<body>

<div class="container">
    <h2>FreeSWITCH Outbound Dialplan Manager</h2>
    
    <?php if (!empty($message)): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="dir">Outbound Dialplan Path:</label>
            <input type="text" id="dir" name="dir" value="/etc/freeswitch/dialplan/public" required>
            <div class="path-helper">সাধারণত /etc/freeswitch/dialplan/public হয়।</div>
        </div>
        <div class="form-group">
            <label for="extension_name">Extension Name:</label>
            <input type="text" id="extension_name" name="extension_name" placeholder="outbound_custom" required>
        </div>
        <div class="form-group">
            <label for="gateway_name">Gateway Name:</label>
            <input type="text" id="gateway_name" name="gateway_name" placeholder="voip_provider_1" required>
        </div>
        
        <div class="buttons-group">
            <button type="submit" class="btn-save" name="save_outbound">Save Outbound</button>
            <button type="submit" class="btn-reload" name="reload_freeswitch">Save & Reload FreeSWITCH</button>
        </div>
    </form>
</div>

</body>
</html>
