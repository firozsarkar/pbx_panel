<?php
header('Content-Type: application/json; charset=utf-8');

// গেটওয়ে এর নাম GET বা POST মেথডের মাধ্যমে গ্রহণ করা হবে
$gateway_name = $_GET['gateway'] ?? $_POST['gateway'] ?? '';

if (empty($gateway_name)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'গেটওয়ে নাম প্রদান করুন (যেমন: status_gateway.php?gateway=your_gateway)'
    ]);
    exit;
}

// FreeSWITCH এর মাধ্যমে গেটওয়ে স্ট্যাটাস চেক করা
$command = 'fs_cli -x "sofia status gateway ' . escapeshellarg($gateway_name) . '" 2>&1';
$output = shell_exec($command);

if ($output === null) {
    echo json_encode([
        'status' => 'error',
        'message' => 'FreeSWITCH কমান্ড চালানো যায়নি!'
    ]);
} else {
    // স্ট্যাটাস নির্ধারণ করা (UP বা DOWN)
    $status = 'UNKNOWN';
    if (stripos($output, 'state: UP') !== false || stripos($output, 'UP') !== false) {
        $status = 'UP';
    } elseif (stripos($output, 'state: DOWN') !== false || stripos($output, 'DOWN') !== false) {
        $status = 'DOWN';
    }

    echo json_encode([
        'status' => 'success',
        'gateway' => $gateway_name,
        'state' => $status,
        'details' => trim($output)
    ]);
}
?>
