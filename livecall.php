<?php
/**
 * FreeSWITCH Live Calls JSON API for WHMCS
 * Output format: JSON
 */

header('Content-Type: application/json');

// fs_cli থেকে ডাটা সংগ্রহ
// 'show calls as json' সরাসরি ব্যবহার করা যায় না বলে আমরা আউটপুট পার্স করছি
$output = shell_exec('fs_cli -x "show calls"');

if (!$output) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No active calls or permission denied',
        'calls' => [],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// আউটপুট লাইন বাই লাইন ভাগ করা
$lines = explode("\n", trim($output));

$calls = [];
$headers = [];

if (count($lines) > 0) {
    // প্রথম লাইন থেকে হেডার সংগ্রহ (uuid, direction, created, etc.)
    $headers = str_getcsv($lines[0], ",");
    
    // ডাটা পার্স করা (প্রথম ও শেষ লাইন বাদ দিয়ে)
    for ($i = 1; $i < count($lines); $i++) {
        // যদি লাইনে কমা থাকে এবং এটি টোটাল কাউন্ট লাইন না হয়
        if (strpos($lines[$i], ',') !== false && !strpos($lines[$i], 'total')) {
            $row = str_getcsv($lines[$i], ",");
            if (count($row) == count($headers)) {
                $calls[] = array_combine($headers, $row);
            }
        }
    }
}

// ফাইনাল রেসপন্স
$response = [
    'status' => 'success',
    'total_calls' => count($calls),
    'data' => $calls,
    'last_updated' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT);
