<?php
// File-based database
$dataFile = __DIR__ . '/trunks.json';
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
}

$trunks = json_decode(file_get_contents($dataFile), true) ?: [];

// Form Handling (Add, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $newTrunk = [
            'id' => uniqid(),
            'name' => trim($_POST['name']),
            'realm' => trim($_POST['realm']),
            'username' => trim($_POST['username']),
            'password' => trim($_POST['password'])
        ];
        $trunks[] = $newTrunk;
        file_put_contents($dataFile, json_encode($trunks, JSON_PRETTY_PRINT));
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'edit') {
        $id = $_POST['id'];
        foreach ($trunks as &$trunk) {
            if ($trunk['id'] === $id) {
                $trunk['name'] = trim($_POST['name']);
                $trunk['realm'] = trim($_POST['realm']);
                $trunk['username'] = trim($_POST['username']);
                if (!empty($_POST['password'])) {
                    $trunk['password'] = trim($_POST['password']);
                }
                break;
            }
        }
        file_put_contents($dataFile, json_encode($trunks, JSON_PRETTY_PRINT));
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'delete') {
        $id = $_POST['id'];
        $trunks = array_filter($trunks, function ($item) use ($id) {
            return $item['id'] !== $id;
        });
        file_put_contents($dataFile, json_encode(array_values($trunks), JSON_PRETTY_PRINT));
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Function to fetch the true registration status of the FreeSWITCH gateway
function getFreeswitchStatus($gatewayName) {
    // FreeSWITCH CLI command to get gateway status
    $cmd = "fs_cli -x 'sofia status gateway " . escapeshellarg($gatewayName) . "'";
    $output = shell_exec($cmd);

    if ($output === null) {
        return '<span class="badge bg-secondary">Offline / Timeout</span>';
    }

    // Check for 'REGED' (Registered) or 'UP' status in the CLI response
    if (stripos($output, 'State: REGED') !== false || stripos($output, 'UP') !== false) {
        return '<span class="badge bg-success">REGISTERED (UP)</span>';
    } elseif (stripos($output, 'NOREG') !== false || stripos($output, 'DOWN') !== false) {
        return '<span class="badge bg-danger">NOT REGISTERED (DOWN)</span>';
    }
    
    return '<span class="badge bg-warning text-dark">Unknown / No Gateway Found</span>';
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .navbar-custom {
            background-color: #1e293b;
        }
    </style>
</head>
<body class="pb-5">

    <nav class="navbar navbar-dark navbar-custom shadow-sm mb-4">
        <div class="container d-flex justify-content-between align-items-center">
            <span class="navbar-brand mb-0 h1 fw-bold">FreeSWITCH Trunk Manager</span>
            <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addTrunkModal">+ Add New Trunk</button>
        </div>
    </nav>

    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8 col-md-12">
                <div class="card glass-card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold mb-4">Configured Trunks</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-uppercase fs-7">
                                    <tr>
                                        <th>Name</th>
                                        <th>Realm</th>
                                        <th>Username</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($trunks)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-5">
                                                No trunk configurations found. Click "Add New Trunk" to begin.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($trunks as $trunk): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($trunk['name']); ?></td>
                                                <td><?= htmlspecialchars($trunk['realm']); ?></td>
                                                <td><?= htmlspecialchars($trunk['username']); ?></td>
                                                <td><?= getFreeswitchStatus($trunk['name']); ?></td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-secondary me-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editTrunkModal<?= $trunk['id']; ?>">Edit</button>
                                                    <form action="" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $trunk['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Are you sure you want to delete this trunk?');">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>

                                            <div class="modal fade" id="editTrunkModal<?= $trunk['id']; ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Trunk: <?= htmlspecialchars($trunk['name']); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form action="" method="POST">
                                                            <input type="hidden" name="action" value="edit">
                                                            <input type="hidden" name="id" value="<?= $trunk['id']; ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Gateway Name</label>
                                                                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($trunk['name']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Realm (IP/Domain)</label>
                                                                    <input type="text" class="form-control" name="realm" value="<?= htmlspecialchars($trunk['realm']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Username</label>
                                                                    <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($trunk['username']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Password <small class="text-muted">(Leave blank to keep unchanged)</small></label>
                                                                    <input type="password" class="form-control" name="password" placeholder="New Password">
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                <button type="submit" class="btn btn-success">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-12">
                <div class="card glass-card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold mb-4">System Summary</h5>
                        <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded-3">
                            <span>Total Trunks</span>
                            <span class="badge bg-primary px-3 py-2 fs-6 rounded-pill"><?= count($trunks); ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded-3">
                            <span>Active Channels</span>
                            <span class="badge bg-success px-3 py-2 fs-6 rounded-pill">
                                <?php
                                $channelsOutput = shell_exec("fs_cli -x 'show channels count'");
                                echo preg_replace('/[^0-9]/', '', $channelsOutput) ?: '0';
                                ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-3">
                            <span>Engine Status</span>
                            <span class="text-muted small">
                                <?php
                                $verOutput = shell_exec("fs_cli -x 'version'");
                                echo $verOutput ? substr($verOutput, 0, 25) . '...' : 'Running';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addTrunkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Gateway / Trunk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Gateway Name</label>
                            <input type="text" class="form-control" name="name" placeholder="e.g., sip_gw_1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Realm (IP or Domain)</label>
                            <input type="text" class="form-control" name="realm" placeholder="192.168.0.5" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" placeholder="User or Auth ID" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Trunk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
