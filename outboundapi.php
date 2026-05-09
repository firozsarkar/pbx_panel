<?php
// ফাইল ডিরেক্টরি সেটআপ
$dir = '/etc/freeswitch/dialplan/public/';
$json_file = 'extensions.json'; // আপনার এক্সটেনশন লিস্টের জন্য

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] == 'generate') {
    header('Content-Type: application/json');
    
    $ext_name = $_POST['ext_name'] ?? '';
    $caller_id = $_POST['caller_id'] ?? ''; // যেমন: 100
    $gateway_num = $_POST['gateway_num'] ?? ''; // যেমন: 09617401201

    if (!$ext_name || !$caller_id || !$gateway_num) {
        echo json_encode(['status' => 'error', 'message' => 'সবগুলো ফিল্ড পূরণ করুন!']);
        exit;
    }

    // আপনার দেওয়া নির্দিষ্ট XML ফরম্যাট
    $xml_output = "<include>\n";
    $xml_output .= "  <extension name=\"{$ext_name}\" continue=\"false\">\n";
    $xml_output .= "    <condition field=\"caller_id_number\" expression=\"^{$caller_id}$\"/>\n";
    $xml_output .= "    <condition field=\"destination_number\" expression=\"^(\d+)$\">\n";
    $xml_output .= "      <action application="set" data=\"effective_caller_id_number={$gateway_num}\"/>\n";
    $xml_output .= "      <action application="set" data=\"outbound_caller_id_number={$gateway_num}\"/>\n";
    $xml_output .= "      <action application="set" data=\"user_context={$gateway_num}\"/>\n";
    $xml_output .= "      <action application="set" data=\"hangup_after_bridge=true\"/>\n";
    $xml_output .= "      <action application=\"bridge\" data=\"sofia/gateway/{$gateway_num}/$1\"/>\n";
    $xml_output .= "    </condition>\n";
    $xml_output .= "  </extension>\n";
    $xml_output .= "</include>";

    $file_path = $dir . $ext_name . ".xml";

    if (@file_put_contents($file_path, $xml_output)) {
        shell_exec('fs_cli -x "reloadxml"');
        echo json_encode(['status' => 'success', 'message' => "ডায়ালপ্ল্যান তৈরি এবং রিলোড সফল হয়েছে!"]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ফাইল রাইট পারমিশন নেই!']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>VIP PBX Outbound Configurator</title>
    <style>
        body { background: #0d0d0d; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .container { width: 400px; background: #121212; padding: 30px; border-radius: 8px; border: 1px solid #2a2a2a; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h2 { color: #d4af37; text-align: center; margin-bottom: 25px; text-transform: uppercase; font-size: 1.2rem; }
        label { display: block; margin-bottom: 8px; font-size: 12px; color: #888; text-transform: uppercase; }
        input, select { width: 100%; padding: 12px; margin-bottom: 20px; background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 4px; color: #fff; box-sizing: border-box; }
        input:focus { border-color: #d4af37; outline: none; }
        button { width: 100%; padding: 14px; background: #d4af37; border: none; border-radius: 4px; color: #000; font-weight: bold; cursor: pointer; text-transform: uppercase; transition: 0.3s; }
        button:hover { background: #e5c158; }
        #msg { margin-top: 15px; padding: 10px; border-radius: 4px; display: none; text-align: center; font-size: 14px; }
        .success { background: rgba(212, 175, 55, 0.1); color: #d4af37; border: 1px solid #d4af37; }
        .error { background: rgba(255, 0, 0, 0.1); color: #ff4444; border: 1px solid #ff4444; }
    </style>
</head>
<body>

<div class="container">
    <h2>Outbound Dialplan Setup</h2>
    <form id="setupForm">
        <label>Extension Name (XML File Name)</label>
        <input type="text" name="ext_name" placeholder="e.g. force_outbound_100" required>

        <label>Select Extension (Caller ID)</label>
        <select name="caller_id" required>
            <option value="">-- Select Extension --</option>
            <option value="100">100</option>
            <option value="101">101</option>
            <option value="102">102</option>
            <!-- এখানে চাইলে PHP দিয়ে লুপ চালিয়ে আপনার JSON ডাটা দেখাতে পারেন -->
        </select>

        <label>Gateway / Outbound Number</label>
        <input type="text" name="gateway_num" placeholder="e.g. 09617401201" required>

        <button type="submit">Create Dialplan</button>
    </form>
    <div id="msg"></div>
</div>

<script>
document.getElementById('setupForm').onsubmit = function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const msgDiv = document.getElementById('msg');
    
    fetch('?action=generate', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        msgDiv.style.display = 'block';
        msgDiv.className = data.status;
        msgDiv.innerText = data.message;
    })
    .catch(err => {
        msgDiv.style.display = 'block';
        msgDiv.className = 'error';
        msgDiv.innerText = 'সার্ভারে কানেক্ট করা যাচ্ছে না!';
    });
};
</script>

</body>
</html>
