<?php
// File-based database
$dir = __DIR__ . '/';
$dataFile = $dir . 'trunks.json';

// স্বয়ংক্রিয়ভাবে পারমিশন চেক ও ফিক্স করার অংশ
if (!is_dir($dir) || !is_writable($dir)) {
    @chmod($dir, 0775);
}

if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
    @chmod($dataFile, 0664);
} else if (!is_writable($dataFile)) {
    @chmod($dataFile, 0664);
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
        foreach ($trunks as $key => $trunk) {
            if ($trunk['id'] === $id) {
                $trunks[$key]['name'] = trim($_POST['name']);
                $trunks[$key]['realm'] = trim($_POST['realm']);
                $trunks[$key]['username'] = trim($_POST['username']);
                if (!empty($_POST['password'])) {
                    $trunks[$key]['password'] = trim($_POST['password']);
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
    $innerCmd = "sofia status gateway " . $gatewayName;
    $cmd = "fs_cli -x " . escapeshellarg($innerCmd) . " 2>&1";
    $output = shell_exec($cmd);

    if ($output === null || trim($output) === '') {
        return '<span class="badge bg-secondary" title="No response from fs_cli">Offline / Timeout</span>';
    }

    // স্ট্যাটাস ম্যাচিং লজিক
    if (stripos($output, 'State: REGED') !== false || stripos($output, 'Status: UP') !== false) {
        return '<span class="badge bg-success">REGISTERED (UP)</span>';
    } elseif (stripos($output, 'State: NOREG') !== false || stripos($output, 'Status: DOWN') !== false || stripos($output, 'Invalid Gateway') !== false) {
        return '<span class="badge bg-danger">NOT REGISTERED (DOWN)</span>';
    }
    
    return '<span class="badge bg-warning text-dark">Checking...</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH Trunk Management Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .navbar-custom {
            background-color: #0f172a;
        }
    </style>
</head>
<body class="pb-5">

    <nav class="navbar navbar-dark navbar-custom shadow-sm mb-4">
        <div class="container d-flex justify-content-between align-items-center">
            <span class="navbar-brand mb-0 h1 fw-bold">FreeSWITCH Trunk Dashboard</span>
            <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addTrunkModal">+ Add New Trunk</button>
        </div>
    </nav>

    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8 col-md-12">
                <div class="card dashboard-card border-0">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold mb-4">Active Gateways / Trunks</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-uppercase fs-7">
                                    <tr>
                                        <th>Gateway Name</th>
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
                                                No trunk configurations found. Click "+ Add New Trunk" to begin.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($trunks as $trunk): ?>
                                            <tr>
                                                <td class="fw-semibold text-primary"><?= htmlspecialchars($trunk['name']); ?></td>
                                                <td><?= htmlspecialchars($trunk['realm']); ?></td>
                                                <td><?= htmlspecialchars($trunk['username']); ?></td>
                                                <td><?= getFreeswitchStatus($trunk['name']); ?></td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-secondary me-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editTrunkModal<?= htmlspecialchars($trunk['id']); ?>">Edit</button>
                                                    
                                                    <form action="" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= htmlspecialchars($trunk['id']); ?>">
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Are you sure you want to delete this trunk?');">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>

                                            <div class="modal fade" id="editTrunkModal<?= htmlspecialchars($trunk['id']); ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Modify Trunk Parameters</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form action="" method="POST">
                                                            <input type="hidden" name="action" value="edit">
                                                            <input type="hidden" name="id" value="<?= htmlspecialchars($trunk['id']); ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Gateway Name</label>
                                                                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($trunk['name']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Realm (SIP Server Domain / IP)</label>
                                                                    <input type="text" class="form-control" name="realm" value="<?= htmlspecialchars($trunk['realm']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Username / Auth ID</label>
                                                                    <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($trunk['username']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Password <small class="text-muted">(Leave empty to preserve existing)</small></label>
                                                                    <input type="password" class="form-control" name="password" placeholder="••••••••">
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Dismiss</button>
                                                                <button type="submit" class="btn btn-success">Update Configurations</button>
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
                <div class="card dashboard-card border-0 h-100">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold mb-4">Core Statistics</h5>
                        <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded-3">
                            <span class="text-secondary fw-medium">Configured Monitored Trunks</span>
                            <span class="badge bg-primary px-3 py-2 fs-6 rounded-pill"><?= count($trunks); ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded-3">
                            <span class="text-secondary fw-medium">Active Concurrent Channels</span>
                            <span class="badge bg-success px-3 py-2 fs-6 rounded-pill">
                                <?php
                                $channelsOutput = shell_exec("fs_cli -x 'show channels count' 2>&1");
                                echo preg_replace('/[^0-9]/', '', $channelsOutput) ?: '0';
                                ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-3">
                            <span class="text-secondary fw-medium">Telephony Engine Build</span>
                            <span class="text-dark font-monospace small">
                                <?php
                                $verOutput = shell_exec("fs_cli -x 'version' 2>&1");
                                echo $verOutput ? substr(trim($verOutput), 0, 22) . '...' : 'Operational';
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
                    <h5 class="modal-title fw-bold">Provision New VoIP Gateway</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Gateway Name</label>
                            <input type="text" class="form-control" name="name" placeholder="e.g., ip_trunk_096" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Realm (Host domain / IP Address)</label>
                            <input type="text" class="form-control" name="realm" placeholder="sip.provider.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control" name="username" placeholder="Authentication Identity String" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Commit Gateway Setup</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
