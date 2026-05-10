<?php
header('Content-Type: application/json');

// ইনপুট নেওয়া
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['ivr_name'])) {
    echo json_encode(['success' => false, 'message' => 'ivr_name is required']);
    exit;
}

// ==================== ভেরিয়েবল নেওয়া ====================
$ivr_name       = preg_replace('/[^a-zA-Z0-9_]/', '', $data['ivr_name']) . '_' . time();
$welcome_msg    = $data['welcome_msg'] ?? '/usr/share/freeswitch/sounds/en/us/callie/custom/7_host1.wav';
$invalid_msg    = $data['invalid_msg'] ?? 'ivr/ivr-invalid_entry.wav';
$exit_msg       = $data['exit_msg'] ?? 'voicemail/vm-goodbye.wav';
$timeout        = isset($data['timeout_sec']) ? (int)$data['timeout_sec'] * 1000 : 10000;
$max_failures   = isset($data['max_failures']) ? (int)$data['max_failures'] : 3;
$digit_actions  = $data['digit_actions'] ?? [];

// ==================== XML তৈরি ====================
$xml = "<include>\n";
$xml .= "  <menu name=\"{$ivr_name}\"\n";
$xml .= "        greet-long=\"{$welcome_msg}\"\n";
$xml .= "        greet-short=\"{$welcome_msg}\"\n";
$xml .= "        invalid-sound=\"{$invalid_msg}\"\n";
$xml .= "        exit-sound=\"{$exit_msg}\"\n";
$xml .= "        timeout=\"{$timeout}\"\n";
$xml .= "        inter-digit-timeout=\"2000\"\n";
$xml .= "        max-failures=\"{$max_failures}\"\n";
$xml .= "        max-timeouts=\"3\"\n";
$xml .= "        digit-len=\"1\">\n\n";

foreach ($digit_actions as $digit => $action) {
    $type = $action['type'] ?? '';
    $dest = trim($action['destination'] ?? '');

    if (empty($type) || empty($dest)) continue;

    switch ($type) {
        
        case 'extension':
            $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"transfer {$dest} XML default\"/>\n";
            break;

        case 'ring_group':
            $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"transfer {$dest} XML default\"/>\n";
            break;

        case 'direct_number':
            $gateway = trim($action['gateway'] ?? 'default');
            $codec   = (!empty($action['codec']) && $action['codec'] === 'G729') 
                       ? '{absolute_codec_string=G729}' 
                       : '';
            $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"bridge {$codec}sofia/gateway/{$gateway}/{$dest}\"/>\n";
            break;

        case 'ivr':
            $target_ivr = preg_replace('/[^a-zA-Z0-9_]/', '', $dest);
            $xml .= "    <entry action=\"menu-sub\" digits=\"{$digit}\" param=\"{$target_ivr}\"/>\n";
            break;

        case 'repeat':
            $xml .= "    <entry action=\"menu-top\" digits=\"{$digit}\"/>\n";
            break;

        case 'exit':
            $xml .= "    <entry action=\"menu-exit\" digits=\"{$digit}\"/>\n";
            break;
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// ==================== ফাইল সেভ ====================
$file_path = "/etc/freeswitch/ivr_menus/{$ivr_name}.xml";

if (!is_dir('/etc/freeswitch/ivr_menus')) {
    mkdir('/etc/freeswitch/ivr_menus', 0777, true);
}

if (file_put_contents($file_path, $xml)) {
    exec("fs_cli -x 'reloadxml' > /dev/null 2>&1");
    
    echo json_encode([
        'success'  => true,
        'ivr_name' => $ivr_name,
        'file'     => $file_path,
        'message'  => 'IVR Created Successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save XML file. Check permission.'
    ]);
}
?>
