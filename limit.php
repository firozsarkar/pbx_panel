<?php
// Token check
$access_token = "321";
if (!isset($_GET['token']) || $_GET['token'] !== $access_token) {
    header('Content-Type: application/json');
    echo json_encode(["error" => "Invalid Token"]);
    exit;
}

// CPU Usage calculation
$load = sys_getloadavg();
$cpu_usage = $load[0] . "%";

// RAM Usage calculation
$free = shell_exec('free -m');
$free = (string)trim($free);
$free_arr = explode("\n", $free);
$mem = explode(" ", $free_arr[1]);
$mem = array_filter($mem);
$mem = array_values($mem);

$total_ram = $mem[1] . " MB";
$used_ram = $mem[2] . " MB";
$free_ram = $mem[3] . " MB";

// Disk Space calculation
$disk_total = round(disk_total_space("/") / (1024 * 1024 * 1024), 2) . " GB";
$disk_free = round(disk_free_space("/") / (1024 * 1024 * 1024), 2) . " GB";
$disk_used = round(($disk_total - $disk_free), 2) . " GB";

// JSON Response
$response = [
    "status" => "success",
    "cpu_load" => $cpu_usage,
    "ram" => [
        "total" => $total_ram,
        "used" => $used_ram,
        "free" => $free_ram
    ],
    "disk" => [
        "total" => $disk_total,
        "used" => $disk_used,
        "free" => $disk_free
    ]
];

header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
?>
