<?php
// File-based database or you can replace this with MySQL
$dataFile = 'trunks.json';

if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([]));
}

$trunks = json_decode(file_get_contents($dataFile), true);

// Handle Form Actions (Add, Delete, Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $newTrunk = [
                'id' => uniqid(),
                'name' => trim($_POST['name']),
                'realm' => trim($_POST['realm']),
                'username' => trim($_POST['username']),
                'password' => trim($_POST['password']),
                'status' => 'Unknown'
            ];
            $trunks[] = $newTrunk;
            file_put_contents($dataFile, json_encode($trunks, JSON_PRETTY_PRINT));
            header('Location: trunks.php');
            exit;
        } 
        elseif ($_POST['action'] === 'delete') {
            $id = $_POST['id'];
            $trunks = array_filter($trunks, function($item) use ($id) {
                return $item['id'] !== $id;
            });
            file_put_contents($dataFile, json_encode(array_values($trunks), JSON_PRETTY_PRINT));
            header('Location: trunks.php');
            exit;
        }
    }
}

// Function to check actual FreeSWITCH Trunk Status using fs_cli
function checkFreeSwitchStatus($gatewayName) {
    // FreeSWITCH command line execution
    // $command = "fs_cli -x 'sofia status gateway " . escapeshellarg($gatewayName) . "'";
    // $output = shell_exec($command);
    
    // For demonstration, we simulate the status check randomly or based on name
    return (rand(0, 1) === 1) ? '<span class="badge bg-success">UP</span>' : '<span class="badge bg-danger">DOWN</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH Trunk Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f7f6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.1);
        }
        .card-header {
            background-color: #2c3e50;
            color: #ffffff;
            border-top-left-radius: 10px !important;
            border-top-right-radius: 10px !important;
        }
        .table-hover tbody tr:hover {
            background-color: #e9ecef;
        }
    </style>
</head>
<body class="py-5">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>FreeSWITCH Trunk Management</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTrunkModal">+ Add New Trunk</button>
    </div>

    <div class="row g-4">
        <div class="col-md-8">
            <div class="card glass-card shadow-sm border-0 h-100">
                <div class="card-header border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Active Trunks & Status</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Realm / IP</th>
                                    <th>Username</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($trunks)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No trunks configured yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($trunks as $trunk): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($trunk['name']); ?></strong></td>
                                            <td><?= htmlspecialchars($trunk['realm']); ?></td>
                                            <td><?= htmlspecialchars($trunk['username']); ?></td>
                                            <td>
                                                <?= checkFreeSwitchStatus($trunk['name']); ?>
                                            </td>
                                            <td>
                                                <form action="trunks.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $trunk['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this trunk?');">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card glass-card shadow-sm border-0 mb-4">
                <div class="card-header border-0 py-3">
                    <h5 class="mb-0">System Summary</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                            Total Trunks
                            <span class="badge bg-primary rounded-pill"><?= count($trunks); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                            Active Calls
                            <span class="badge bg-success rounded-pill">0</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addTrunkModal" tabindex="-1" aria-labelledby="addTrunkModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTrunkModalLabel">Add New Gateway / Trunk</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="trunks.php" method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Gateway Name</label>
                        <input type="text" class="form-control" name="name" placeholder="e.g., sip_provider_gw" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Realm (IP/Domain)</label>
                        <input type="text" class="form-control" name="realm" placeholder="192.168.1.100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" placeholder="SIP Username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" placeholder="SIP Password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Trunk</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
