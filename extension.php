<?php
// Enable error reporting to debug blank screens
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
                if (!is_dir($freeswitch_dir)) {
                    mkdir($freeswitch_dir, 0755, true);
                }

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
                    shell_exec('sudo /usr/local/freeswitch/bin/fs_cli -x "reloadxml" 2>&1');
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
                shell_exec('sudo /usr/local/freeswitch/bin/fs_cli -x "reloadxml" 2>&1');
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
$sofia_status = shell_exec('sudo /usr/local/freeswitch/bin/fs_cli -x "sofia status" 2>&1');
if (empty($sofia_status)) {
    $sofia_status = "FreeSWITCH Sofia is either not running, or fs_cli permissions are restricted.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premium FreeSWITCH Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #0a0f1d 0%, #17243c 100%);
            --card-bg: rgba(30, 41, 59, 0.6);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-color: #38bdf8;
            --accent-gradient: linear-gradient(45deg, #38bdf8, #818cf8);
        }

        body {
            background: var(--bg-gradient);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background-attachment: fixed;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-2px);
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.5);
        }

        .form-control, .form-select {
            background-color: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--card-border);
            color: var(--text-main);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            background-color: rgba(15, 23, 42, 0.9);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
            color: var(--text-main);
        }

        .btn-premium {
            background: var(--accent-gradient);
            border: none;
            color: #ffffff;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-premium:hover {
            filter: brightness(1.2);
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(56, 189, 248, 0.4);
        }

        .btn-danger-premium {
            background: linear-gradient(45deg, #ef4444, #dc2626);
            border: none;
            color: #ffffff;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-danger-premium:hover {
            filter: brightness(1.2);
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(239, 68, 68, 0.4);
        }

        h1, h2, h3, h4 {
            letter-spacing: -0.025em;
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text-main);
            --bs-table-border-color: var(--card-border);
            --bs-table-hover-color: var(--text-main);
            --bs-table-hover-bg: rgba(255, 255, 255, 0.03);
        }

        pre {
            background: rgba(15, 23, 42, 0.7) !important;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1rem;
            color: #34d399 !important; 
            font-family: 'Courier New', Courier, monospace;
        }

        .alert-glass {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            color: #f8fafc;
            box-shadow: 0 4px 24px 0 rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body class="py-5">
    <div class="container px-4">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h1 class="fw-bold mb-1 text-white">FreeSWITCH Control Panel</h1>
                <p class="text-muted mb-0">Premium Telephony Management & Trunk Status</p>
            </div>
            <div>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill">
                    <i class="fa-solid fa-circle-nodes me-2"></i> System Status Online
                </span>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-glass d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-check text-success fs-4 me-3"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-glass d-flex align-items-center mb-4 border-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation text-danger fs-4 me-3"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <div class="glass-card p-4 h-100">
                    <h4 class="text-primary mb-4"><i class="fa-solid fa-circle-plus me-2"></i> Add/Edit Extension</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label text-muted small text-uppercase fw-bold">Extension Number</label>
                            <input type="text" class="form-control" name="extension" required placeholder="e.g., 1001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small text-uppercase fw-bold">SIP Password</label>
                            <input type="text" class="form-control" name="password" required placeholder="e.g., secret123">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small text-uppercase fw-bold">Caller ID Name</label>
                            <input type="text" class="form-control" name="caller_id_name" value="Firoz PBX">
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small text-uppercase fw-bold">Email</label>
                            <input type="email" class="form-control" name="email" placeholder="user@example.com">
                        </div>
                        <button type="submit" class="btn btn-premium w-100"><i class="fa-solid fa-save me-2"></i> Save Extension</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="glass-card p-4 h-100">
                    <h4 class="text-danger mb-4"><i class="fa-solid fa-trash-can me-2"></i> Delete Extension</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <div class="mb-4">
                            <label class="form-label text-muted small text-uppercase fw-bold">Select Extension</label>
                            <select class="form-select" name="extension" required>
                                <option value="">Choose Extension...</option>
                                <?php foreach ($extensions as $ext): ?>
                                    <option value="<?= $ext ?>">Extension <?= $ext ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger-premium w-100" onclick="return confirm('Are you sure you want to delete this extension?');">
                            <i class="fa-solid fa-circle-minus me-2"></i> Delete Extension
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-4 col-md-12">
                <div class="glass-card p-4 h-100">
                    <h4 class="text-info mb-4"><i class="fa-solid fa-server me-2"></i> Sofia Trunks & Status</h4>
                    <pre class="p-3 mb-0" style="max-height: 270px; overflow-y: auto; font-size: 0.85rem;"><?= htmlspecialchars($sofia_status) ?></pre>
                </div>
            </div>
        </div>

        <div class="glass-card mt-5 p-4">
            <h4 class="mb-4 text-white"><i class="fa-solid fa-network-wired me-2"></i> Active Extensions Directory</h4>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="text-muted small text-uppercase">Extension Number</th>
                            <th scope="col" class="text-muted small text-uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($extensions)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-folder-open fs-2 mb-3 d-block"></i>
                                    No extensions found in the directory.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($extensions as $ext): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3 me-3">
                                                <i class="fa-solid fa-hashtag"></i>
                                            </div>
                                            <span class="fw-bold fs-6"><?= $ext ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill">
                                            <i class="fa-solid fa-circle me-1" style="font-size: 8px;"></i> Registered / Active
                                        </span>
                                    </td>
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
