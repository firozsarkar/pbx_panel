<?php
// ==================== FreeSWITCH Inbound Edit & Delete API (No API Key) ====================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$dir = '/etc/freeswitch/dialplan/public/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');   // list | edit | delete

// ====================== LIST ALL INBOUND ======================
if ($action === 'list') {
    $files = glob($dir . "01_inbound_*.xml");
    $inbounds = [];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        
        preg_match('/destination_number" expression="\^(.+?)\$/', $content, $did_match);
        preg_match('/name="Inbound_(.+?)"/', $content, $name_match);
        
        $type = 'unknown';
        $destination = '';

        if (strpos($content, 'application="ivr"') !== false) {
            $type = 'ivr';
            preg_match('/ivr" data="(.+?)"/', $content, $dest_match);
            $destination = $dest_match[1] ?? '';
        } elseif (strpos($content, 'transfer" data="') !== false) {
            $type = 'extension';
            preg_match('/transfer" data="(.+?) XML/', $content, $dest_match);
            $destination = $dest_match[1] ?? '';
        } elseif (strpos($content, 'bridge" data=') !== false) {
            $type = 'ringgroup';
            preg_match('/user\/(.+?)@/', $content, $dest_match);
            $destination = $dest_match[1] ?? '';
        }

        $inbounds[] = [
            'file'        => basename($file),
            'did'         => $did_match[1] ?? '',
            'type'        => $type,
            'destination' => $destination,
            'created'     => date("Y-m-d H:i", filemtime($file))
        ];
    }

    echo json_encode([
        'status' => 'success',
        'total'  => count($inbounds),
        'data'   => $inbounds
    ]);
    exit;
}

// ====================== DELETE ======================
elseif ($action === 'delete') {
    $did_number = trim($input['did_number'] ?? '');

    if (empty($did_number)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'DID Number required']);
        exit;
    }

    $file_name = "01_inbound_" . preg_replace('/[^0-9]/', '', $did_number) . ".xml";
    $file_path = $dir . $file_name;

    if (file_exists($file_path)) {
        if (unlink($file_path)) {
            shell_exec('fs_cli -x "reloadxml" 2>&1');
            echo json_encode([
                'status'  => 'success',
                'message' => "Inbound route for DID {$did_number} has been deleted successfully"
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete file']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'File not found for this DID']);
    }
    exit;
}

// ====================== EDIT ======================
elseif ($action === 'edit') {
    $did_number     = trim($input['did_number'] ?? '');
    $transfer_type  = trim($input['transfer_type'] ?? '');
    $destination    = trim($input['destination'] ?? '');
    $caller_id_name = trim($input['caller_id_name'] ?? '');

    if (empty($did_number) || empty($transfer_type) || empty($destination)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'did_number, transfer_type and destination are required']);
        exit;
    }

    $file_name = "01_inbound_" . preg_replace('/[^0-9]/', '', $did_number) . ".xml";
    $file_path = $dir . $file_name;

    if (!file_exists($file_path)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Inbound route not found for this DID']);
        exit;
    }

    // Generate Updated XML
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
    } elseif ($transfer_type === 'extension') {
        $xml .= '      <action application="transfer" data="' . $destination . ' XML default"/>' . "\n";
    } elseif ($transfer_type === 'ringgroup') {
        $xml .= '      <action application="bridge" data="user/' . $destination . '@$${domain}"/>' . "\n";
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid transfer_type']);
        exit;
    }

    $xml .= '    </condition>' . "\n" .
            '  </extension>' . "\n" .
            '</include>' . "\n";

    if (file_put_contents($file_path, $xml)) {
        shell_exec('fs_cli -x "reloadxml" 2>&1');
        echo json_encode([
            'status'      => 'success',
            'message'     => 'Inbound route updated successfully',
            'did'         => $did_number,
            'type'        => $transfer_type,
            'destination' => $destination
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save updated file']);
    }
    exit;
}

// Invalid Action
else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action. Allowed actions: list, edit, delete']);
}
?>
