<?php
header('Content-Type: application/json; charset=utf-8');

$extension = $_GET['extension'] ?? $_POST['extension'] ?? '';

if (empty($extension)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'এক্সটেনশন নম্বর প্রদান করুন (যেমন: status_extension.php?extension=1000)'
    ]);
    exit;
}

// FreeSWITCH এর মাধ্যমে এক্সটেনশন স্ট্যাটাস চেক করা
$command = 'fs_cli -x "sofia status profile internal reg ' . escapeshellarg($extension) . '" 2>&1';
$output = shell_exec($command);

if ($output === null) {
    echo json_encode([
        'status' => 'error',
        'message' => 'FreeSWITCH কমান্ড চালানো যায়নি!'
    ]);
} else {
    $status = 'UNKNOWN';
    if (stripos($output, 'Registration') !== false && stripos($output, 'registered') !== false) {
        $status = 'REGISTERED';
    } else {
        $status = 'UNREGISTERED';
    }

    echo json_encode([
        'status' => 'success',
        'extension' => $extension,
        'state' => $status,
        'details' => trim($output)
    ]);
}
?>
