<?php
// Configuration
$freeswitch_dir = '/usr/local/freeswitch/conf/directory/default/';

// Handle Actions (Add, Edit, Delete)
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'add' || $action === 'edit') {
            $extension = trim($_POST['extension']);
            $password = trim($_POST['password']);
            $caller_id_name = trim($_POST['caller_id_name']);
            $email = trim($_POST['email']);

            if (!empty($extension) && !empty($password)) {
                $filename = $freeswitch_dir . $extension . '.xml';

                // XML Content for FreeSWITCH
                $xml_content = "\n" .
                    "<include>\n" .
                    "  <user id=\"{$extension}\">\n" .
                    "    <params>\n" .
                    "      <param name=\"password\" value=\"{$password}\"/>\n" .
                    "      <param name=\"vm-password\" value=\"{$password}\"/>\n" .
                    "    </params>\n" .
                    "    <variables>\n" .
                    "      <variable name=\"toll_allow\" value=\"domestic,international,local\"/>\n" .
                    "      <variable name=\"accountcode\" value=\"{$extension}\"/>\n" .
                    "      <variable name=\"user_context\" value=\"default\"/>\n" .
                    "      <variable name=\"effective_caller_id_name\" value=\"{$caller_id_name}\"/>\n" .
                    "      <variable name=\"effective_caller_id_number\" value=\"{$extension}\"/>\n" .
                    "      <variable name=\"outbound_caller_id_name\" value=\"{$caller_id_name}\"/>\n" .
                    "      <variable name=\"outbound_caller_id_number\" value=\"{$extension}\"/>\n" .
                    "    </variables>\n" .
                    "  </user>\n" .
                    "</include>\n";

                if (file_put_contents($filename, $xml_content) !== false) {
                    // Auto reload FreeSWITCH XML
                    shell_exec('fs_cli -x "reloadxml"');
                    $message = "Extension {$extension} successfully saved and reloaded!";
                } else {
                    $error = "Failed to save the file. Please check directory permissions.";
                }
            } else {
                $error = "Extension and Password cannot be empty.";
            }
        } elseif ($action === 'delete') {
            $extension = trim($_POST['extension']);
            $filename = $freeswitch_dir . $extension . '.xml';
            if (file_exists($filename)) {
                unlink($filename);
                shell_exec('fs_cli -x "reloadxml"');
                $message = "Extension {$extension} deleted successfully!";
            } else {
                $error = "Extension file not found.";
            }
        }
    }
}

// Get list of extensions
$extensions = [];
if (is_dir($freeswitch_dir)) {
    if ($dh = opendir($freeswitch_dir)) {
        while (($file = readdir($dh)) !== false) {
            if ($file != "." && $file != "..") {
                $ext_id = pathinfo($file, PATHINFO_FILENAME);
                if (is_numeric($ext_id)) {
                    $extensions[] = $ext_id;
                }
            }
        }
        closedir($dh);
    }
}

// Get Trunk and Sofia Status
$sofia_status = shell_exec('fs_cli -x "sofia status"');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH Extension Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0f172a; color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { background-color: #1e293b; border: 1px solid #334155; }
        .table { color: #e2e8f0; }
        .table th { border-color: #334155; }
        .table td { border-color: #475569; }
        .form-control, .form-select { background-color: #0f172a; border-color: #334155; color: #f8fafc; }
        .form-control:focus, .form-select:focus { background-color: #0f172a; color: #f8fafc; border-color: #475569; box-shadow: none; }
    </style>
</head>
<body class="py-4">
    <div class="container">
        <h1 class="mb-4 text-center">FreeSWITCH Control Panel</h1>

        <?php if ($message): ?>
            <div class="alert alert-success" role="alert"><?= $message ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= $error ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card p-4 h-100">
                    <h4 class="mb-3">Add / Edit Extension</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Extension Number</label>
                            <input type="text" class="form-control" name="extension" required placeholder="e.g. 1001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">SIP Password</label>
                            <input type="text" class="form-control" name="password" required placeholder="e.g. secret123">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Caller ID Name</label>
                            <input type="text" class="form-control" name="caller_id_name" value="Firoz PBX">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" placeholder="user@example.com">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save Extension</button>
                    </form>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card p-4 h-100">
                    <h4 class="mb-3">Delete Extension</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <div class="mb-3">
                            <label class="form-label">Select Extension to Delete</label>
                            <select class="form-select" name="extension" required>
                                <option value="">Choose...</option>
                                <?php foreach ($extensions as $ext): ?>
                                    <option value="<?= $ext ?>"><?= $ext ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to delete this extension?');">Delete Extension</button>
                    </form>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card p-4 h-100">
                    <h4 class="mb-3">Sofia Gateway Status</h4>
                    <pre class="bg-dark text-light p-3 rounded" style="max-height: 250px; overflow-y: auto; font-size: 0.85rem;"><?= htmlspecialchars($sofia_status) ?></pre>
                </div>
            </div>
        </div>

        <div class="card mt-4 p-4">
            <h4 class="mb-3">Active Extensions List</h4>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Extension Number</th>
                            <th>Status / Profile</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($extensions)): ?>
                            <tr>
                                <td colspan="2" class="text-center">No extensions found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($extensions as $ext): ?>
                                <tr>
                                    <td><strong><?= $ext ?></strong></td>
                                    <td><span class="badge bg-success">Configured & Loaded</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
