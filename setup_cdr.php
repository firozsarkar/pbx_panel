<?php
header('Content-Type: application/json');

const DB_HOST = '103.163.246.205';
const DB_PORT = 3306;
const DB_NAME = 'register_pbx';
const DB_USER = 'register_pbx';
const DB_PASS = 'register_pbx';

$cdrDir = '/var/log/freeswitch/cdr-csv/';

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Database connection failed: " . $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit;
}

$sql = "INSERT IGNORE INTO `cdr_logs` (
    `uuid`, `bleg_uuid`, `source_file`, `caller_id`, `src_number`, `destination`,
    `context`, `start_time`, `answer_time`, `end_time`, `duration`, `billsec`,
    `hangup_cause`, `accountcode`, `read_codec`, `write_codec`
) VALUES (
    :uuid, :bleg_uuid, :source_file, :caller_id, :src_number, :destination,
    :context, :start_time, :answer_time, :end_time, :duration, :billsec,
    :hangup_cause, :accountcode, :read_codec, :write_codec
)";
$stmt = $pdo->prepare($sql);

$files = glob($cdrDir . '*.csv') ?: [];
$insertedCount = 0;
$processedFiles = 0;
$deletedFiles = 0;
$errors = [];

foreach ($files as $csvFile) {
    if (!is_readable($csvFile) || !is_writable($csvFile)) {
        $errors[] = "Permission issue on " . basename($csvFile);
        continue;
    }

    $lines = file($csvFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $errors[] = "Failed to read " . basename($csvFile);
        continue;
    }

    // ফাইল একদম খালি হলে সরাসরি ডিলিট করুন
    if (count($lines) === 0) {
        if (@unlink($csvFile)) {
            $deletedFiles++;
        }
        continue;
    }

    $processedFiles++;
    $remainingLines = [];
    $fileHasError = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $data = str_getcsv($line);
        if (empty($data) || count($data) < 3) {
            // অপর্যাপ্ত বা ভাঙা লাইন থাকলে রেখে দিন
            $remainingLines[] = $line;
            continue;
        }

        $uuid = trim($data[10] ?? '');
        if ($uuid === '') {
            // UUID না থাকলে fallback unique key তৈরি করুন
            $uuid = md5($line);
        }

        try {
            $stmt->execute([
                ':uuid'         => $uuid,
                ':bleg_uuid'    => $data[11] ?? '',
                ':source_file'  => basename($csvFile),
                ':caller_id'    => $data[0] ?? '',
                ':src_number'   => $data[1] ?? '',
                ':destination'  => $data[2] ?? '',
                ':context'      => $data[3] ?? '',
                ':start_time'   => $data[4] ?? '',
                ':answer_time'  => $data[5] ?? '',
                ':end_time'     => $data[6] ?? '',
                ':duration'     => (int)($data[7] ?? 0),
                ':billsec'      => (int)($data[8] ?? 0),
                ':hangup_cause' => $data[9] ?? '',
                ':accountcode'  => $data[12] ?? '',
                ':read_codec'   => $data[13] ?? '',
                ':write_codec'  => $data[14] ?? '',
            ]);
            if ($stmt->rowCount() > 0) {
                $insertedCount++;
            }
        } catch (Throwable $e) {
            $fileHasError = true;
            $errors[] = basename($csvFile) . " row error: " . $e->getMessage();
            $remainingLines[] = $line;
        }
    }

    // কোনো লাইন বাকি না থাকলে ফাইল ডিলিট করুন, নাহলে বাকি লাইনগুলো রেখে ফাইল আপডেট করুন
    if (!$fileHasError && count($remainingLines) === 0) {
        if (@unlink($csvFile)) {
            $deletedFiles++;
        } else {
            $errors[] = "Could not delete " . basename($csvFile);
        }
    } else {
        $content = count($remainingLines) > 0 ? implode(PHP_EOL, $remainingLines) . PHP_EOL : '';
        file_put_contents($csvFile, $content, LOCK_EX);
    }
}

echo json_encode([
    "status"          => "success",
    "processed_files" => $processedFiles,
    "inserted_calls"  => $insertedCount,
    "deleted_files"   => $deletedFiles,
    "errors"          => $errors
], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
