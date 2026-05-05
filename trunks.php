<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dataFile = __DIR__ . "/trunks.json";

/* ================= INIT ================= */
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
}
chmod($dataFile, 0666);

/* ================= HELPERS ================= */

function run_fs($cmd) {
    return trim(shell_exec("fs_cli -x " . escapeshellarg($cmd) . " 2>&1"));
}

function get_gateways() {
    $out = run_fs("show gateways");
    return $out;
}

/* SMART gateway status check */
function gateway_status($gw) {
    $out = run_fs("sofia status gateway " . $gw);

    if (stripos($out, "REGED") !== false) return ["UP", "success"];
    if (stripos($out, "UNREGED") !== false) return ["DOWN", "danger"];
    if (stripos($out, "FAIL") !== false) return ["DOWN", "danger"];

    return ["UNKNOWN", "secondary"];
}

function fs_version() {
    return run_fs("version");
}

function fs_channels() {
    $out = run_fs("show channels count");
    return is_numeric($out) ? $out : 0;
}

/* ================= LOAD ================= */
$trunks = json_decode(file_get_contents($dataFile), true);
if (!is_array($trunks)) $trunks = [];

/* ================= ACTIONS ================= */

if (isset($_POST['add'])) {
    $trunks[] = [
        "id" => uniqid(),
        "name" => $_POST['name'],
        "realm" => $_POST['realm'],
        "username" => $_POST['username'],
        "password" => $_POST['password']
    ];
    file_put_contents($dataFile, json_encode($trunks, JSON_PRETTY_PRINT));
    header("Location: trunks.php");
    exit;
}

if (isset($_POST['delete'])) {
    $id = $_POST['id'];
    $trunks = array_values(array_filter($trunks, fn($t) => $t['id'] !== $id));
    file_put_contents($dataFile, json_encode($trunks, JSON_PRETTY_PRINT));
    header("Location: trunks.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>FreeSWITCH Trunks</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
    background:#0b1220;
    color:white;
}
.card {
    background: rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.1);
}
input {
    background:#111 !important;
    color:#fff !important;
    border:none !important;
}
</style>
</head>

<body class="p-4">

<div class="container">

<h3>FreeSWITCH Trunk Manager</h3>

<!-- STATS -->
<div class="row mb-3">
<div class="col-md-4">
<div class="card p-3">
FS Version:<br>
<b><?= fs_version(); ?></b>
</div>
</div>

<div class="col-md-4">
<div class="card p-3">
Active Channels:<br>
<b><?= fs_channels(); ?></b>
</div>
</div>
</div>

<!-- ADD FORM -->
<div class="card p-3 mb-4">
<form method="POST">
<div class="row">
<div class="col">
<input class="form-control" name="name" placeholder="Gateway Name" required>
</div>
<div class="col">
<input class="form-control" name="realm" placeholder="IP / Realm" required>
</div>
<div class="col">
<input class="form-control" name="username" placeholder="Username" required>
</div>
<div class="col">
<input class="form-control" name="password" placeholder="Password" required>
</div>
</div>

<button class="btn btn-primary mt-3" name="add">Add Trunk</button>
</form>
</div>

<!-- TABLE -->
<div class="card p-3">
<table class="table table-dark table-hover">
<thead>
<tr>
<th>Name</th>
<th>Realm</th>
<th>User</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>

<tbody>
<?php foreach ($trunks as $t): 
$status = gateway_status($t['name']);
?>
<tr>
<td><?= htmlspecialchars($t['name']) ?></td>
<td><?= htmlspecialchars($t['realm']) ?></td>
<td><?= htmlspecialchars($t['username']) ?></td>

<td>
<span class="badge bg-<?= $status[1] ?>">
<?= $status[0] ?>
</span>
</td>

<td>
<form method="POST">
<input type="hidden" name="id" value="<?= $t['id'] ?>">
<button class="btn btn-danger btn-sm" name="delete">Delete</button>
</form>
</td>

</tr>
<?php endforeach; ?>
</tbody>

</table>
</div>

</div>

</body>
</html>
