<?php
header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['ivr_name'])) {
    echo json_encode(['success' => false, 'message' => 'ivr_name required']);
    exit;
}

$ivr_name      = preg_replace('/[^a-zA-Z0-9_]/', '', $data['ivr_name']) . '_' . time();
$welcome_msg   = $data['welcome_msg'] ?? '/usr/share/freeswitch/sounds/en/us/callie/custom/1_host1.wav';
$invalid_msg   = $data['invalid_msg'] ?? 'ivr/ivr-invalid_entry.wav';
$exit_msg      = $data['exit_msg'] ?? 'voicemail/vm-goodbye.wav';
$timeout       = isset($data['timeout_sec']) ? (int)$data['timeout_sec'] * 1000 : 10000;
$max_failures  = isset($data['max_failures']) ? (int)$data['max_failures'] : 3;

$digit_actions = $data['digit_actions'] ?? [];

$xml = "<include>\n";
$xml .= "  <menu name=\"{$ivr_name}\"\n";
$xml .= "        greet-long=\"{$welcome_msg}\"\n";
$xml .= "        greet-short=\"{$welcome_msg}\"\n";
$xml .= "        invalid-sound=\"{$invalid_msg}\"\n";
$xml .= "        exit-sound=\"{$exit_msg}\"\n";
$xml .= "        timeout=\"{$timeout}\"\n";
$xml .= "        inter-digit-timeout=\"2000\"\n";
$xml .= "        max-failures=\"{$max_failures}\"\n";
$xml .= "        digit-len=\"1\">\n\n";

foreach ($digit_actions as $digit => $action) {
    $type = strtolower($action['type'] ?? '');
    $dest = trim($action['destination'] ?? '');

    if (empty($dest)) continue;

    if ($type === 'direct_number') {
        $gateway   = trim($action['gateway'] ?? '09617401201');
        $callerid  = trim($action['callerid'] ?? '09617171950');
        $codec     = strtoupper($action['codec'] ?? 'PCMA');   // PCMA/PCMU by default (stable)

        $xml .= "    <!-- Digit {$digit} : Direct Number -->\n";
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"set effective_caller_id_number={$callerid}\"/>\n";
        
        if ($codec === 'G729') {
            $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"set absolute_codec_string=G729,PCMU\"/>\n";
            $bridge = "bridge {absolute_codec_string=G729,PCMU}sofia/gateway/{$gateway}/{$dest}";
        } else {
            $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"set absolute_codec_string=PCMA,PCMU\"/>\n";
            $bridge = "bridge {absolute_codec_string=PCMA,PCMU}sofia/gateway/{$gateway}/{$dest}";
        }
        
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"{$bridge}\"/>\n";

    } elseif (in_array($type, ['extension', 'ring_group'])) {
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"transfer {$dest} XML default\"/>\n";

    } elseif ($type === 'ivr') {
        $target = preg_replace('/[^a-zA-Z0-9_]/', '', $dest);
        $xml .= "    <entry action=\"menu-sub\" digits=\"{$digit}\" param=\"{$target}\"/>\n";

    } elseif ($type === 'repeat') {
        $xml .= "    <entry action=\"menu-top\" digits=\"{$digit}\"/>\n";
    } elseif ($type === 'exit') {
        $xml .= "    <entry action=\"menu-exit\" digits=\"{$digit}\"/>\n";
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

// Save file
$file_path = "/etc/freeswitch/ivr_menus/{$ivr_name}.xml";
if (!is_dir(dirname($file_path))) mkdir(dirname($file_path), 0777, true);

if (file_put_contents($file_path, $xml)) {
    exec("fs_cli -x 'reloadxml' > /dev/null 2>&1");
    echo json_encode([
        'success' => true,
        'ivr_name' => $ivr_name,
        'message' => 'Created like your brother\'s working XML'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save XML']);
}
?>
