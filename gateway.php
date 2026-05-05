<?php
$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gateway_name = trim($_POST['gateway_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $proxy = trim($_POST['proxy']);
    $realm = trim($_POST['realm']);
    $port = trim($_POST['port']);
    $dir = trim($_POST['dir']);

    // Ensure path ends with slash
    if (substr($dir, -1) !== '/') {
        $dir .= '/';
    }

    // FreeSWITCH Gateway XML format
    $xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n  <gateway name=\"{$gateway_name}\">\n    <param name=\"username\" value=\"{$username}\"/>\n    <param name=\"realm\" value=\"{$realm}\"/>\n    <param name=\"password\" value=\"{$password}\"/>\n    <param name=\"proxy\" value=\"{$proxy}\"/>\n    <param name=\"register\" value=\"true\"/>\n    <param name=\"retry-seconds\" value=\"30\"/>\n  </gateway>\n</include>";

    // Check directory and create if not exists
    if (!file_exists($dir)) {
        @mkdir($dir, 0775, true);
    }

    $file_path = $dir . $gateway_name . ".xml";

    // Save configuration file
    if (@file_put_contents($file_path, $xml_output)) {
        $message = "সফলভাবে ফাইলটি '{$file_path}'-এ তৈরি হয়েছে!";
        
        if (isset($_POST['reload_freeswitch'])) {
            $output = shell_exec('fs_cli -x "sofia profile external rescan" 2>&1');
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
    <title>FreeSWITCH Gateway Manager</title>
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
    <h2>FreeSWITCH Gateway Manager</h2>
    
    <?php if (!empty($message)): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="dir">FreeSWITCH sip_profiles/external Path:</label>
            <input type="text" id="dir" name="dir" value="/etc/freeswitch/sip_profiles/external" required>
            <div class="path-helper">সাধারণত /etc/freeswitch/sip_profiles/external বা /usr/local/freeswitch/conf/sip_profiles/external হয়।</div>
        </div>
        <div class="form-group">
            <label for="gateway_name">Gateway Name:</label>
            <input type="text" id="gateway_name" name="gateway_name" placeholder="voip_provider_1" required>
        </div>
        <div class="form-group">
            <label for="username">Username / Auth User:</label>
            <input type="text" id="username" name="username" placeholder="username" required>
        </div>
        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group">
            <label for="realm">Realm / Gateway IP:</label>
            <input type="text" id="realm" name="realm" placeholder="sip.domain.com বা 192.168.1.100" required>
        </div>
        <div class="form-group">
            <label for="proxy">Outbound Proxy (Optional):</label>
            <input type="text" id="proxy" name="proxy" placeholder="ফাঁকা রাখতে পারেন">
        </div>
        <div class="form-group">
            <label for="port">Port:</label>
            <input type="text" id="port" name="port" value="5060">
        </div>
        
        <div class="buttons-group">
            <button type="submit" class="btn-save" name="save_gateway">Save</button>
            <button type="submit" class="btn-reload" name="reload_freeswitch">Save & Reload FreeSWITCH</button>
        </div>
    </form>
</div>

</body>
</html>
