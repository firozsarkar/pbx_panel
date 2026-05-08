<?php
// ==================== FreeSWITCH Inbound API for WHMCS ====================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$dir = '/etc/freeswitch/dialplan/public/';

// API Key Security (খুব জরুরি)
$API_KEY = 'a';   // ← এটা অবশ্যই পরিবর্তন করবেন

// Input
$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// API Key Check
if (!isset($_SERVER['HTTP_X_API_KEY']) || $_SERVER['HTTP_X_API_KEY'] !== $API_KEY) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing API Key']);
    exit;
}

$did_number     = trim($input['did_number'] ?? '');
$transfer_type  = trim($input['transfer_type'] ?? '');   // ivr, extension, ringgroup
$destination    = trim($input['destination'] ?? '');
$caller_id_name = trim($input['caller_id_name'] ?? '');  // Optional
$description    = trim($input['description'] ?? '');

$response = [];

if (empty($did_number) || empty($transfer_type) || empty($destination)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'did_number, transfer_type এবং destination আবশ্যক'
    ]);
    exit;
}

// XML Generation
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
       '<include>' . "\n" .
       '  <extension name="Inbound_' . preg_replace('/[^0-9A-Za-z]/', '', $did_number) . '">' . "\n" .
       '    <condition field="destination_number" expression="^' . preg_quote($did_number, '/') . '$">' . "\n" .
       '      <action application="answer"/>' . "\n" .
       '      <action application="sleep" data="800"/>' . "\n";

if (!empty($caller_id_name)) {
    $xml .= '      <action application="set" data="effective_caller_id_name=' . htmlspecialchars($caller_id_name) . '"/>' . "\n";
}

if ($transfer_type === 'ivr') {
    $xml .= '      <action application="ivr" data="' . htmlspecialchars($destination) . '"/>' . "\n";
} 
elseif ($transfer_type === 'extension') {
    $xml .= '      <action application="transfer" data="' . $destination . ' XML default"/>' . "\n";
} 
elseif ($transfer_type === 'ringgroup') {
    $xml .= '      <action application="bridge" data="user/' . $destination . '@$${domain}"/>' . "\n";
} 
else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid transfer_type']);
    exit;
}

$xml .= '    </condition>' . "\n" .
        '  </extension>' . "\n" .
        '</include>' . "\n";

// Save File
if (!file_exists($dir)) {
    mkdir($dir, 0775, true);
}

$file_name = "01_inbound_" . preg_replace('/[^0-9]/', '', $did_number) . ".xml";
$file_path = $dir . $file_name;

if (file_put_contents($file_path, $xml)) {
    // Reload FreeSWITCH
    $reload_output = shell_exec('fs_cli -x "reloadxml" 2>&1');

    echo json_encode([
        'status'      => 'success',
        'message'     => 'Inbound route created successfully',
        'file'        => $file_path,
        'did'         => $did_number,
        'type'        => $transfer_type,
        'destination' => $destination,
        'reload'      => strpos($reload_output, '+OK') !== false ? 'success' : 'warning'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to save XML file. Check permission.'
    ]);
}
?>
