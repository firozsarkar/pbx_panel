<?php
// নিরাপত্তার জন্য একটি সিম্পল পাসওয়ার্ড চেক (অপশনাল কিন্তু জরুরি)
$secret = "12345"; // এটি আপনার ইচ্ছেমতো পরিবর্তন করুন
if ($_GET['auth'] !== $secret) {
    die("Access Denied: Invalid Auth Key");
}

$csvFile = '/var/log/freeswitch/cdr-csv/Master.csv';

if (!file_exists($csvFile)) {
    die("CDR File not found.");
}

// ফাইলটি রিড করা
$data = array_map('str_getcsv', file($csvFile));
$data = array_reverse($data); // নতুন কলগুলো আগে দেখাবে

// শুধু নির্দিষ্ট সংখ্যক কল দেখানোর লিমিট
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$displayData = array_slice($data, 0, $limit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Call Logs</title>
    <style>
        body { font-family: sans-serif; font-size: 14px; background: #f4f4f4; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #333; color: #fff; }
        tr:nth-child(even) { background: #f9f9f9; }
        .status-answered { color: green; font-weight: bold; }
        .status-failed { color: red; }
    </style>
</head>
<body>

<h3>FreeSWITCH Call Detail Records</h3>
<table>
    <thead>
        <tr>
            <th>Date & Time</th>
            <th>Caller ID</th>
            <th>Destination</th>
            <th>Duration</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($displayData as $row): ?>
        <tr>
            <td><?php echo $row[6] ?? 'N/A'; ?></td> <!-- Start Time -->
            <td><?php echo $row[1] ?? 'N/A'; ?></td> <!-- Caller -->
            <td><?php echo $row[2] ?? 'N/A'; ?></td> <!-- Destination -->
            <td><?php echo ($row[10] ?? 0) . " sec"; ?></td> <!-- Billsec -->
            <td class="<?php echo ($row[11] == 'NORMAL_CLEARING') ? 'status-answered' : 'status-failed'; ?>">
                <?php echo $row[11] ?? 'UNKNOWN'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
