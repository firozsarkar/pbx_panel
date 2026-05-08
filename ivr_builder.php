<?php
/**
 * FreeSWITCH IVR Generator - Easy Mode (With Default Values)
 */

$status_msg = "";

if (isset($_POST['submit_ivr'])) {
    
    $unique_id = time();
    $user_name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($_POST['ivr_name']));
    $final_ivr_name = $user_name . "_" . $unique_id;
   
    $save_directory = "/etc/freeswitch/ivr_menus/";
    $file_name = $final_ivr_name . ".xml";
    $full_path = $save_directory . $file_name;

    $xml = "<include>\n";
    $xml .= "  <menu name=\"{$final_ivr_name}\"\n";
    $xml .= "        greet-long=\"{$_POST['welcome_msg']}\"\n";
    $xml .= "        invalid-sound=\"{$_POST['invalid_msg']}\"\n";
    $xml .= "        timeout=\"" . ((int)$_POST['timeout_sec'] * 1000) . "\"\n";
    $xml .= "        max-failures=\"{$_POST['max_failures']}\">\n";

    foreach ($_POST['digit_action'] as $digit => $data) {
        $type = $data['type'] ?? '';
        $dest = trim($data['dest'] ?? '');

        if (!empty($type)) {
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

    if (file_put_contents($full_path, $xml)) {
        @shell_exec("fs_cli -x 'reloadxml' 2>/dev/null");
        $status_msg = "
        <div class='alert alert-success shadow-lg'>
            <h4>✅ IVR সফলভাবে তৈরি হয়েছে!</h4>
            <p><strong>IVR Name:</strong> <span class='badge bg-primary fs-5'>$final_ivr_name</span></p>
            <p><strong>File:</strong> <code>$full_path</code></p>
        </div>";
    } else {
        $status_msg = "<div class='alert alert-danger'>❌ Permission Error! chmod 777 /etc/freeswitch/ivr_menus/</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Easy IVR Builder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #0f172a; color: #f1f5f9; padding: 30px 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 15px; }
        .form-control, .form-select { background: #0f172a; color: white; border: 1px solid #475569; }
        .form-control:focus, .form-select:focus { border-color: #38bdf8; color: white; }
    </style>
</head>
<body>
<div class="container">

    <h2 class="text-center mb-4">Easy FreeSWITCH IVR Builder</h2>

    <?php echo $status_msg; ?>

    <form method="POST">
        <div class="row g-4">

            <div class="col-lg-7">
                <div class="card p-4">
                    <h5 class="text-info mb-3">মূল সেটিংস</h5>
                    
                    <div class="mb-3">
                        <label>IVR নাম</label>
                        <input type="text" name="ivr_name" class="form-control" value="Customer_Support" required>
                    </div>

                    <div class="mb-3">
                        <label>Welcome Message</label>
                        <input type="text" name="welcome_msg" class="form-control" 
                               value="/usr/share/freeswitch/sounds/custom/welcome.wav" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label>Invalid Message</label>
                            <input type="text" name="invalid_msg" class="form-control" value="ivr/ivr-invalid_extension.wav">
                        </div>
                        <div class="col-md-3">
                            <label>Timeout</label>
                            <input type="number" name="timeout_sec" class="form-control" value="5">
                        </div>
                        <div class="col-md-3">
                            <label>Max Retries</label>
                            <input type="number" name="max_failures" class="form-control" value="3">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card p-4">
                    <h5 class="text-warning">ডিফল্ট উদাহরণ</h5>
                    <small class="text-muted">নিচের টেবিলে কিছু ডিফল্ট অপশন রাখা হয়েছে। প্রয়োজন অনুযায়ী পরিবর্তন করো।</small>
                </div>
            </div>

        </div>

        <!-- Digit Table -->
        <div class="card p-4 mt-4">
            <h5 class="text-info mb-3">বাটন সেটিংস (০-৯)</h5>
            <table class="table table-dark table-hover">
                <thead>
                    <tr>
                        <th>বাটন</th>
                        <th>অ্যাকশন</th>
                        <th>গন্তব্য</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $default = [
                        1 => ['type' => 'extension', 'dest' => '101'],
                        2 => ['type' => 'extension', 'dest' => '102'],
                        3 => ['type' => 'queue',     'dest' => 'support_q'],
                        0 => ['type' => 'repeat',    'dest' => '']
                    ];

                    for($i = 0; $i <= 9; $i++):
                        $pre_type = $default[$i]['type'] ?? '';
                        $pre_dest = $default[$i]['dest'] ?? '';
                    ?>
                    <tr>
                        <td><span class="badge bg-secondary fs-5">Digit <?= $i ?></span></td>
                        <td>
                            <select name="digit_action[<?= $i ?>][type]" class="form-select">
                                <option value="">-- No Action --</option>
                                <option value="extension" <?= $pre_type=='extension'?'selected':'' ?>>Extension Transfer</option>
                                <option value="queue" <?= $pre_type=='queue'?'selected':'' ?>>Call Queue</option>
                                <option value="ringgroup" <?= $pre_type=='ringgroup'?'selected':'' ?>>Ring Group</option>
                                <option value="voicemail" <?= $pre_type=='voicemail'?'selected':'' ?>>Voicemail</option>
                                <option value="disa" <?= $pre_type=='disa'?'selected':'' ?>>DISA</option>
                                <option value="repeat" <?= $pre_type=='repeat'?'selected':'' ?>>Repeat Menu</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="digit_action[<?= $i ?>][dest]" 
                                   class="form-control" value="<?= htmlspecialchars($pre_dest) ?>" 
                                   placeholder="101 or queue_name">
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <button type="submit" name="submit_ivr" class="btn btn-primary btn-lg w-100 mt-3">
                <i class="bi bi-magic"></i> IVR ফাইল তৈরি করুন
            </button>
        </div>
    </form>
</div>
</body>
</html>
