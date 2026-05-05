<?php
/**
 * FreeSWITCH Trunk Manager - Single File App
 * Author: Expert PHP + Linux Admin Implementation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =========================
   CONFIG
========================= */
$dataFile = __DIR__ . '/trunks.json';

/* =========================
   AUTO INIT JSON FILE
========================= */
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
}
@chmod($dataFile, 0666);
@chmod(__DIR__, 0775);

/* =========================
   HELPERS
========================= */
function load_trunks($file) {
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_trunks($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    @chmod($file, 0666);
}

function safe_exec($cmd) {
    $output = shell_exec($cmd . " 2>&1");
    return $output ?: '';
}

/* =========================
   FREE SWITCH STATUS
========================= */
function gateway_status($name) {
    $cmd = 'fs_cli -x ' . escapeshellarg("sofia status gateway $name");
    $out = safe_exec($cmd);

    if (stripos($out, 'UP') !== false) return ['UP', 'success'];
    if (stripos($out, 'DOWN') !== false) return ['DOWN', 'danger'];

    return ['UNKNOWN', 'secondary'];
}

function fs_version() {
    return trim(safe_exec("fs_cli -x 'version'"));
}

function fs_channels() {
    $out = safe_exec("fs_cli -x 'show channels count'");
    if (preg_match('/\d+/', $out, $m)) return (int)$m[0];
    return 0;
}

/* =========================
   LOAD DATA
========================= */
$trunks = load_trunks($dataFile);

/* =========================
   ACTION HANDLER
========================= */
$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $trunks[] = [
        'id' => uniqid(),
        'name' => trim($_POST['name']),
        'realm' => trim($_POST['realm']),
        'username' => trim($_POST['username']),
        'password' => trim($_POST['password'])
    ];
    save_trunks($dataFile, $trunks);
    header("Location: trunks.php");
    exit;
}

if ($action === 'delete') {
    $id = $_POST['id'];
    $trunks = array_filter($trunks, fn($t) => $t['id'] !== $id);
    save_trunks($dataFile, array_values($trunks));
    header("Location: trunks.php");
    exit;
}

if ($action === 'update') {
    foreach ($trunks as &$t) {
        if ($t['id'] === $_POST['id']) {
            $t['name'] = $_POST['name'];
            $t['realm'] = $_POST['realm'];
            $t['username'] = $_POST['username'];
            $t['password'] = $_POST['password'];
        }
    }
    save_trunks($dataFile, $trunks);
    header("Location: trunks.php");
    exit;
}

/* =========================
   EDIT DATA
========================= */
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($trunks as $t) {
        if ($t['id'] === $_GET['edit']) $edit = $t;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>FreeSWITCH Trunk Manager</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
    background: linear-gradient(135deg, #0f172a, #1e293b);
    color: #fff;
}
.glass {
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 20px;
    border: 1px solid rgba(255,255,255,0.1);
}
.table {
    color: #fff;
}
input, select {
    background: rgba(255,255,255,0.1) !important;
    color: #fff !important;
    border: none !important;
}
</style>
</head>

<body class="p-4">

<div class="container">

    <h2 class="mb-3">📡 FreeSWITCH Trunk Manager</h2>

    <!-- ENGINE STATS -->
    <div class="glass mb-4">
        <div class="row">
            <div class="col-md-4">
                <strong>FS Version:</strong><br>
                <?= htmlspecialchars(fs_version()) ?>
            </div>
            <div class="col-md-4">
                <strong>Active Channels:</strong><br>
                <?= fs_channels() ?>
            </div>
            <div class="col-md-4">
                <strong>Status:</strong><br>
                <span class="badge bg-success">Running</span>
            </div>
        </div>
    </div>

    <!-- FORM -->
    <div class="glass mb-4">
        <h5><?= $edit ? "Edit Trunk" : "Add New Trunk" ?></h5>

        <form method="POST">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
            <?php if ($edit): ?>
                <input type="hidden" name="id" value="<?= $edit['id'] ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-3">
                    <input class="form-control" name="name" placeholder="Gateway Name"
                           value="<?= $edit['name'] ?? '' ?>" required>
                </div>

                <div class="col-md-3">
                    <input class="form-control" name="realm" placeholder="Realm / IP"
                           value="<?= $edit['realm'] ?? '' ?>" required>
                </div>

                <div class="col-md-3">
                    <input class="form-control" name="username" placeholder="Username"
                           value="<?= $edit['username'] ?? '' ?>" required>
                </div>

                <div class="col-md-3">
                    <input class="form-control" name="password" placeholder="Password"
                           value="<?= $edit['password'] ?? '' ?>" required>
                </div>
            </div>

            <button class="btn btn-primary mt-3">
                <?= $edit ? "Update" : "Add" ?> Trunk
            </button>
        </form>
    </div>

    <!-- TABLE -->
    <div class="glass">
        <h5>Gateway List</h5>

        <table class="table table-hover mt-3">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Realm</th>
                    <th>Username</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($trunks as $t): ?>
                <?php $st = gateway_status($t['name']); ?>
                <tr>
                    <td><?= htmlspecialchars($t['name']) ?></td>
                    <td><?= htmlspecialchars($t['realm']) ?></td>
                    <td><?= htmlspecialchars($t['username']) ?></td>

                    <td>
                        <span class="badge bg-<?= $st[1] ?>">
                            <?= $st[0] ?>
                        </span>
                    </td>

                    <td>
                        <a class="btn btn-sm btn-warning"
                           href="?edit=<?= $t['id'] ?>">Edit</a>

                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete?')">
                                Delete
                            </button>
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
