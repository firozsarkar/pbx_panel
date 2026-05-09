<?php
header('Content-Type: application/json');

// পাথ চেক করুন
$csvFile = '/var/log/freeswitch/cdr-csv/Master.csv';

if (!file_exists($csvFile)) {
    echo json_encode(["error" => "Log file not found"]);
    exit;
}

// ফাইলটি পড়া
$lines = file($csvFile);
$results = [];

foreach ($lines as $line) {
    // CSV লাইনকে অ্যারেতে কনভার্ট করা
    $data = str_getcsv($line);

    // আপনার লগের ইনডেক্স অনুযায়ী ম্যাপিং
    $results[] = [
        "caller_id"      => $data[0] ?? '',
        "src_number"     => $data[1] ?? '',
        "destination"    => $data[2] ?? '',
        "context"        => $data[3] ?? '',
        "start_time"     => $data[4] ?? '',
        "answer_time"    => $data[5] ?? '',
        "end_time"       => $data[6] ?? '',
        "duration"       => $data[7] ?? 0,
        "billsec"        => $data[8] ?? 0,
        "hangup_cause"   => $data[9] ?? '',
        "uuid"           => $data[10] ?? '',
        "bleg_uuid"      => $data[11] ?? '',
        "read_codec"     => $data[13] ?? '',
        "write_codec"    => $data[14] ?? ''
    ];
}

// সবশেষ কলগুলো আগে দেখানোর জন্য (Latest first)
$results = array_reverse($results);

// লিমিট সেট করা (WHMCS এর জন্য হয়তো শেষ ৫০-১০০টি কল যথেষ্ট)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$output = array_slice($results, 0, $limit);

echo json_encode([
    "status" => "success",
    "count"  => count($output),
    "calls"  => $output
], JSON_PRETTY_PRINT);
