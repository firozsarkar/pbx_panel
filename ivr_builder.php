<?php
/**
 * Advanced FreeSWITCH IVR XML Generator (Unique Serial Mode)
 * Improved & Beautified Version
 */

$status_msg = "";

if (isset($_POST['submit_ivr'])) {
    
    $unique_id = time();
    $user_name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($_POST['ivr_name']));
    $final_ivr_name = $user_name . "_" . $unique_id;
   
    $save_directory = "/etc/freeswitch/ivr_menus/";
    $file_name = $final_ivr_name . ".xml";
    $full_path = $save_directory . $file_name;

    // XML Generation
    $xml = "<include>\n";
    $xml .= "  <menu name=\"{$final_ivr_name}\"\n";
    $xml .= "        greet-long=\"{$_POST['welcome_msg']}\"\n";
    $xml .= "        invalid-sound=\"{$_POST['invalid_msg']}\"\n";
    $xml .= "        timeout=\"" . ((int)$_POST['timeout_sec'] * 1000) . "\"\n";
    $xml .= "        max-failures=\"{$_POST['max_failures']}\">\n";

    foreach ($_POST['digit_action'] as $digit => $data) {
        $type = $data['type'] ?? '';
        $dest = trim($data['dest'] ?? '');

        if (!empty($type) && !empty($dest)) {
            switch ($type) {
                case 'extension':
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
                    break;
                case 'queue':
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"callcenter $dest\"/>\n";
                    break;
                case 'ringgroup':
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n";
                    break;
                case 'voicemail':
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"voicemail default \${domain} $dest\"/>\n";
                    break;
                case 'repeat':
                    $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n";
                    break;
                case 'disa':
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"disa $dest\"/>\n";
                    break;
            }
        }
    }

    $xml .= "  </menu>\n";
    $xml .= "</include>";

    // Save file
    if (file_put_contents($full_path, $xml)) {
        shell_exec("fs_cli -x 'reloadxml' 2>/dev/null");
        
        $status_msg = "
        <div class='alert alert-success shadow-lg border-0'>
            <h4 class='mb-3'><i class='bi bi-check-circle-fill'></i> IVR Created Successfully!</h4>
            <p><strong>IVR Name:</strong> <span class='badge bg-primary fs-5'>$final_ivr_name</span></p>
            <p><strong>File Path:</strong> <code class='text-light'>$full_path</code></p>
            <hr>
            <p class='mb-0'><em>উপরের <b>IVR Name</b> কপি করে Inbound Route-এ Destination হিসেবে ব্যবহার করুন।</em></p>
        </div>";
    } else {
        $status_msg = "<div class='alert alert-danger shadow'><strong>❌ Error:</strong> Permission Denied!<br>Run: <code>chmod 777 $save_directory</code></div>";
    }
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH IVR Builder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #f8fafc;
            min-height: 100vh;
            padding: 40px 0;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        .form-control, .form-select {
            background: #0f172a;
            border: 1px solid #475569;
            color: white;
            border-radius: 10px;
        }
        .form-control:focus, .form-select:focus {
            background: #0f172a;
            border-color: #38bdf8;
            box-shadow: 0 0 0 0.2rem rgba(56, 189, 248, 0.25);
            color: white;
        }
        .digit-table th {
            color: #38bdf8;
            font-weight: 600;
        }
        .badge {
            font-size: 1rem;
        }
        h2 span {
            color: #38bdf8;
            font-weight: 700;
        }
        .btn-primary {
            background: linear-gradient(90deg, #38bdf8, #0ea5e9);
            border: none;
            padding: 14px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 12px;
        }
        .alert {
            border-radius: 12px;
        }
    </style>
</head>
<body>
<div class="container">

    <h2 class="text-center mb-5 display-5">
        <i class="bi bi-telephone-fill"></i> PBX <span>IVR Builder</span>
    </h2>

    <?php echo $status_msg; ?>

    <form method="POST">
        <div class="row g-4">
            
            <!-- Main Configuration -->
            <div class="col-lg-7">
                <div class="card p-4">
                    <h5 class="text-info border-bottom pb-3 mb-4">
                        <i class="bi bi-gear-fill"></i> Main Configurations
                    </h5>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">IVR Name (e.g. Sales_Menu)</label>
                        <input type="text" name="ivr_name" class="form-control" placeholder="Customer_Support" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Welcome Greeting (Full Path)</label>
                        <input type="text" name="welcome_msg" class="form-control" 
                               placeholder="/usr/share/freeswitch/sounds/custom/welcome.wav" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Invalid Input Message</label>
                            <input type="text" name="invalid_msg" class="form-control" 
                                   placeholder="ivr/ivr-invalid_extension.wav">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Timeout (Seconds)</label>
                            <input type="number" name="timeout_sec" class="form-control" value="5" min="1">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Max Retries</label>
                            <input type="number" name="max_failures" class="form-control" value="3" min="1">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Features -->
            <div class="col-lg-5">
                <div class="card p-4 h-100">
                    <h5 class="text-warning border-bottom pb-3 mb-4">
                        <i class="bi bi-star-fill"></i> Available Features
                    </h5>
                    <ul class="list-unstyled mt-2">
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Queue & Ring Group Support</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Extension Transfer & Voicemail</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Time Condition Ready</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> DISA & Callback Support</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Auto Call Recording</li>
                    </ul>
                    <p class="text-muted small mt-auto">
                        <em>প্রতিবার নতুন করে ফাইল তৈরি হয় — ডেটা লসের ভয় নেই।</em>
                    </p>
                </div>
            </div>

        </div>

        <!-- Digit Mapping -->
        <div class="card p-4 mt-4">
            <h5 class="text-info border-bottom pb-3 mb-4">
                <i class="bi bi-grid-3x3-gap"></i> Interactive Menu (Key Press 0-9)
            </h5>

            <table class="table table-dark table-hover digit-table align-middle">
                <thead>
                    <tr>
                        <th width="15%">Button</th>
                        <th width="40%">Action</th>
                        <th>Destination (Number / ID)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $options = [
                        'extension' => 'Extension Transfer',
                        'queue'     => 'Call Queue',
                        'ringgroup' => 'Ring Group',
                        'voicemail' => 'Voicemail',
                        'disa'      => 'DISA Access',
                        'repeat'    => 'Repeat Menu'
                    ];

                    for($i = 0; $i <= 9; $i++): ?>
                    <tr>
                        <td>
                            <span class="badge bg-secondary fs-5 px-3 py-2">Digit <?= $i ?></span>
                        </td>
                        <td>
                            <select name="digit_action[<?= $i ?>][type]" class="form-select">
                                <option value="">-- No Action --</option>
                                <?php foreach($options as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="digit_action[<?= $i ?>][dest]" 
                                   class="form-control" placeholder="e.g. 101 or support_queue">
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <button type="submit" name="submit_ivr" class="btn btn-primary btn-lg w-100 mt-4">
                <i class="bi bi-file-earmark-code"></i> GENERATE IVR FILE
            </button>
        </div>
    </form>
</div>
</body>
</html>
