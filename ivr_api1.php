<?php
header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['ivr_name'])) {
    echo json_encode(['success' => false, 'message' => 'ivr_name required']);
    exit;
}

$ivr_name     = preg_replace('/[^a-zA-Z0-9_]/', '', $data['ivr_name']) . '_' . time();
$welcome      = $data['welcome_msg'] ?? '/usr/share/freeswitch/sounds/en/us/callie/custom/1_host1.wav';
$invalid      = $data['invalid_msg'] ?? 'ivr/ivr-invalid_entry.wav';
$timeout      = isset($data['timeout_sec']) ? (int)$data['timeout_sec'] * 1000 : 10000;
$max_failures = isset($data['max_failures']) ? (int)$data['max_failures'] : 3;

$xml = "<include>\n";
$xml .= "  <menu name=\"{$ivr_name}\"\n";
$xml .= "        greet-long=\"{$welcome}\"\n";
$xml .= "        greet-short=\"{$welcome}\"\n";
$xml .= "        invalid-sound=\"{$invalid}\"\n";
$xml .= "        timeout=\"{$timeout}\"\n";
$xml .= "        inter-digit-timeout=\"2000\"\n";
$xml .= "        max-failures=\"{$max_failures}\"\n";
$xml .= "        digit-len=\"1\">\n\n";

$actions = $data['digit_actions'] ?? [];

foreach ($actions as $digit => $act) {
    $type = strtolower($act['type'] ?? '');
    $dest = trim($act['destination'] ?? '');

    if (empty($dest)) continue;

    if ($type === 'direct_number') {
        $gw = trim($act['gateway'] ?? '09617401201');
        $cid = trim($act['callerid'] ?? '09617171950');

        $xml .= "    <!-- Digit {$digit} - Direct Call -->\n";
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"set effective_caller_id_number={$cid}\"/>\n";
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"set absolute_codec_string=PCMA,PCMU\"/>\n";
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"bridge {absolute_codec_string=PCMA,PCMU}sofia/gateway/{$gw}/{$dest}\"/>\n";

    } elseif (in_array($type, ['extension','ring_group'])) {
        $xml .= "    <entry action=\"menu-exec-app\" digits=\"{$digit}\" param=\"transfer {$dest} XML default\"/>\n";

    } elseif ($type === 'ivr') {
        $target = preg_replace('/[^a-zA-Z0-9_]/', '', $dest);
        $xml .= "    <entry action=\"menu-sub\" digits=\"{$digit}\" param=\"{$target}\"/>\n";
    }
}

$xml .= "  </menu>\n";
$xml .= "</include>";

$file_path = "/etc/freeswitch/ivr_menus/{$ivr_name}.xml";

if (!is_dir('/etc/freeswitch/ivr_menus')) {
    mkdir('/etc/freeswitch/ivr_menus', 0777, true);
}

if (file_put_contents($file_path, $xml)) {
    exec("fs_cli -x 'reloadxml' > /dev/null 2>&1");
    echo json_encode([
        'success' => true,
        'ivr_name' => $ivr_name,
        'message' => 'Simple & Clean like your brother\'s version'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Save failed']);
}
?>
