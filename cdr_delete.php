<?php
header('Content-Type: application/json; charset=utf-8');

// চাইলে নিজের মতো একটি সিক্রেট কী সেট করুন: http://38.60.235.146/cdr_delete.php?key=YOUR_SECRET_KEY
$secretKey = 'CHANGE_THIS_SECRET_KEY';
if ($secretKey !== 'CHANGE_THIS_SECRET_KEY') {
    $providedKey = $_GET['key'] ?? '';
    if (!hash_equals($secretKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Forbidden: invalid key"], JSON_PRETTY_PRINT);
        exit;
    }
}

$csvFile = '/var/log/freeswitch/cdr-csv/Master.csv';
$dirPath = dirname($csvFile);

if (!file_exists($csvFile) || !is_readable($csvFile)) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Log file not found or not readable"], JSON_PRETTY_PRINT);
    exit;
}

if (!is_writable($dirPath) || !is_writable($csvFile)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Permission denied: web user cannot modify CDR directory or file"], JSON_PRETTY_PRINT);
    exit;
}

// সার্ভারের টাইমজোন অনুযায়ী ২ ঘণ্টার কাটঅফ টাইমস্ট্যাম্প
$cutoffTimestamp = time() - (2 * 3600);

$fp = fopen($csvFile, 'c+b');
if (!$fp) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to open log file"], JSON_PRETTY_PRINT);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to acquire file lock"], JSON_PRETTY_PRINT);
    exit;
}

$tmpFile = $dirPath . '/Master.csv.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
$tmpFp = fopen($tmpFile, 'wb');
if (!$tmpFp) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to create temporary file"], JSON_PRETTY_PRINT);
    exit;
}

$totalLines = 0;
$keptLines = 0;
$deletedLines = 0;
$unparsedLines = 0;

rewind($fp);
while (($line = fgets($fp)) !== false) {
    $trimmed = trim($line);
    if ($trimmed === '') {
        continue;
    }
    $totalLines++;

    $data = str_getcsv($trimmed, ',', '"', '\\');
    $timeStr = $data[4] ?? '';
    $ts = ($timeStr !== '') ? strtotime($timeStr) : false;

    if ($ts === false) {
        // টাইম পার্স না হলে নিরাপত্তার স্বার্থে লাইনটি রেখে দেওয়া হবে
        fwrite($tmpFp, $trimmed . PHP_EOL);
        $keptLines++;
        $unparsedLines++;
        continue;
    }

    if ($ts >= $cutoffTimestamp) {
        fwrite($tmpFp, $trimmed . PHP_EOL);
        $keptLines++;
    } else {
        $deletedLines++;
    }
}

fflush($tmpFp);
fclose($tmpFp);

// মূল ফাইলটি নতুন ফিল্টার করা কন্টেন্ট দিয়ে প্রতিস্থাপন
if (!rename($tmpFile, $csvFile)) {
    @unlink($tmpFile);
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to replace Master.csv"], JSON_PRETTY_PRINT);
    exit;
}

// মূল ফাইলের মালিকানা ও পারমিশন ঠিক রাখা
@chown($csvFile, 'freeswitch');
@chgrp($csvFile, 'freeswitch');
@chmod($csvFile, 0660);

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
    "status"         => "success",
    "retention"      => "last_2_hours",
    "cutoff_time"    => date('Y-m-d H:i:s', $cutoffTimestamp),
    "total_processed"=> $totalLines,
    "kept_logs"      => $keptLines,
    "deleted_logs"   => $deletedLines,
    "unparsed_kept"  => $unparsedLines
], JSON_PRETTY_PRINT);
