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

    // FreeSWITCH Gateway XML format
    $xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n  <gateway name=\"{$gateway_name}\">\n    <param name=\"username\" value=\"{$username}\"/>\n    <param name=\"realm\" value=\"{$realm}\"/>\n    <param name=\"password\" value=\"{$password}\"/>\n    <param name=\"proxy\" value=\"{$proxy}\"/>\n    <param name=\"register\" value=\"true\"/>\n    <param name=\"retry-seconds\" value=\"30\"/>\n  </gateway>\n</include>";

    $dir = "/etc/freeswitch/sip_profiles/external/";
    
    // Check and create directory if not exists
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file_path = $dir . $gateway_name . ".xml";

    // Save to server
    if (file_put_contents($file_path, $xml_output)) {
        $message = "সফলভাবে '{$gateway_name}.xml' ফাইলটি FreeSWITCH ডিরেক্টরিতে তৈরি হয়েছে!";
        
        // Handle Reload Action
        if (isset($_POST['reload_freeswitch'])) {
            // Run shell command to rescan FreeSWITCH
            $output = shell_exec('fs_cli -x "sofia profile external rescan" 2>&1');
            $message .= " (FreeSWITCH রিলোড করা হয়েছে)";
        }
    } else {
        $error = "ফাইলটি সংরক্ষণ করা যায়নি। অনুগ্রহ করে নিশ্চিত করুন যে আপনার ওয়েব সার্ভার (www-data) এর FreeSWITCH ডিরেক্টরিতে লেখার পারমিশন আছে।";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FreeSWITCH Gateway Manager</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f8; padding: 20px; }
        .container { max-width: 650px; margin: 30px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: bold; color: #444; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .buttons-group { display: flex; gap: 10px; margin-top: 15px; }
        button { flex: 1; padding: 12px; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; transition: 0.3s; }
        .btn-save { background-color: #28a745; }
        .btn-save:hover { background-color: #218838; }
        .btn-reload { background-color: #007bff; }
        .btn-reload:hover { background-color: #0069d9; }
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; font-size: 14px; }
        .error { padding: 12px; margin-bottom: 20px; border-radius: 4px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    <h2>FreeSWITCH Gateway Generator</h2>
    
    <?php if (!empty($message)): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="gateway_name">Gateway Name (e.g. voip_provider_1):</label>
            <input type="text" id="gateway_name" name="gateway_name" required>
        </div>
        <div class="form-group">
            <label for="username">Username / Auth User:</label>
            <input type="text" id="username" name="username" required>
        </div>
        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group">
            <label for="realm">Realm / Server IP:</label>
            <input type="text" id="realm" name="realm" placeholder="192.168.1.1 or sip.domain.com" required>
        </div>
        <div class="form-group">
            <label for="proxy">Outbound Proxy / Gateway IP (Optional):</label>
            <input type="text" id="proxy" name="proxy" placeholder="Leave blank if same as Realm">
        </div>
        <div class="form-group">
            <label for="port">Port:</label>
            <input type="text" id="port" name="port" value="5060">
        </div>
        
        <div class="buttons-group">
            <button type="submit" class="btn-save" name="save_gateway">Save Gateway</button>
            <button type="submit" class="btn-reload" name="reload_freeswitch">Save & Reload FreeSWITCH</button>
        </div>
    </form>
</div>

</body>
</html>
