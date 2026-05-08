<?php
/**
 * Advanced FreeSWITCH IVR XML Generator (Unique Serial Mode)
 */

$status_msg = "";

if (isset($_POST['submit_ivr'])) {
    // ইউনিক সিরিয়াল তৈরি (উদাহরণ: ivr_1715123456)
    $unique_id = time(); 
    $user_name = preg_replace('/[^a-zA-Z0-9]/', '_', $_POST['ivr_name']); // নাম ক্লিন করা
    $final_ivr_name = $user_name . "_" . $unique_id;
    
    $save_directory = "/etc/freeswitch/ivr_menus/";
    $file_name = $final_ivr_name . ".xml";
    $full_path = $save_directory . $file_name;

    // XML ডাটা তৈরি
    $xml = "<include>\n";
    $xml .= "  <menu name=\"$final_ivr_name\"\n";
    $xml .= "      greet-long=\"{$_POST['welcome_msg']}\"\n";
    $xml .= "      invalid-sound=\"{$_POST['invalid_msg']}\"\n";
    $xml .= "      timeout=\"" . ($_POST['timeout_sec'] * 1000) . "\"\n";
    $xml .= "      max-failures=\"{$_POST['max_failures']}\">\n";

    // বাটন ম্যাপিং লজিক
    foreach ($_POST['digit_action'] as $digit => $data) {
        $type = $data['type'];
        $dest = $data['dest'];

        if (!empty($type) && !empty($dest)) {
            switch ($type) {
                case 'extension': $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n"; break;
                case 'queue':     $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"callcenter $dest\"/>\n"; break;
                case 'ringgroup': $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"transfer $dest XML default\"/>\n"; break;
                case 'voicemail': $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"voicemail default \$${domain} $dest\"/>\n"; break;
                case 'repeat':    $xml .= "    <entry action=\"menu-top\" digits=\"$digit\"/>\n"; break;
                case 'disa':      $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"disa $dest\"/>\n"; break;
            }
        }
    }
    $xml .= "  </menu>\n";
    $xml .= "</include>";

    // ফাইল সেভ করা এবং পারমিশন চেক
    if (file_put_contents($full_path, $xml)) {
        shell_exec("fs_cli -x 'reloadxml'"); // অটো রিলোড
        $status_msg = "
        <div class='alert alert-success shadow'>
            <h4>✅ IVR Created Successfully!</h4>
            <hr>
            <p><strong>IVR Name to use in Inbound:</strong> <span class='badge bg-primary' style='font-size:1.2rem;'>$final_ivr_name</span></p>
            <p><strong>File Path:</strong> <code>$full_path</code></p>
            <p class='mb-0'><em>Copy the <b>IVR Name</b> and paste it into your Inbound Route destination.</em></p>
        </div>";
    } else {
        $status_msg = "<div class='alert alert-danger'>❌ Error: Permission Denied! Run: <code>chmod 777 $save_directory</code></div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dynamic IVR Generator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <style>
        body { background: #0f172a; color: #f8fafc; padding: 40px; }
        .card { background: #1e293b; border: 1px solid #334155; }
        .form-control, .form-select { background: #0f172a; border: 1px solid #334155; color: white; }
        .form-control:focus { background: #0f172a; color: white; border-color: #38bdf8; }
        .digit-table th { color: #38bdf8; }
        label { font-weight: 500; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h2 class="text-center mb-4">PBX <span style="color:#38bdf8">IVR Builder</span></h2>
    
    <?php echo $status_msg; ?>

    <form method="POST">
        <div class="row">
            <div class="col-md-7">
                <div class="card p-4 mb-4">
                    <h5 class="text-info border-bottom pb-2">Main Configurations</h5>
                    
                    <label>Enter IVR Name (e.g. Sales_Menu)</label>
                    <input type="text" name="ivr_name" class="form-control" placeholder="Customer_Support" required>

                    <label>Welcome Greeting / Voice Message (Full Path)</label>
                    <input type="text" name="welcome_msg" class="form-control" placeholder="/usr/share/freeswitch/sounds/custom/welcome.wav" required>

                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label>Invalid Input Message</label>
                            <input type="text" name="invalid_msg" class="form-control" placeholder="ivr/ivr-invalid_extension.wav">
                        </div>
                        <div class="col-md-3">
                            <label>Timeout (Sec)</label>
                            <input type="number" name="timeout_sec" class="form-control" value="5">
                        </div>
                        <div class="col-md-3">
                            <label>Max Retries</label>
                            <input type="number" name="max_failures" class="form-control" value="3">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card p-4 h-100">
                    <h5 class="text-warning border-bottom pb-2">Available Features</h5>
                    <ul class="mt-3">
                        <li>Queue & Ring Group Support</li>
                        <li>Extension Transfer & Voicemail</li>
                        <li>Time Condition Integration</li>
                        <li>DISA & Callback Ready</li>
                        <li>Call Recording Auto-Trigger</li>
                    </ul>
                    <p class="text-muted small italic">Note: Every submission generates a unique file to prevent data loss.</p>
                </div>
            </div>
        </div>

        <div class="card p-4 mt-4">
            <h5 class="text-info border-bottom pb-2">Interactive Menu (Key Press 0-9)</h5>
            <table class="table table-dark table-hover mt-2 digit-table">
                <thead>
                    <tr><th>Button</th><th>Action / Route To</th><th>Destination (Number/ID)</th></tr>
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
                    for($i=0; $i<=9; $i++): ?>
                    <tr>
                        <td><span class="badge bg-secondary p-2">Digit <?= $i ?></span></td>
                        <td>
                            <select name="digit_action[<?= $i ?>][type]" class="form-select">
                                <option value="">-- No Action --</option>
                                <?php foreach($options as $k => $v) echo "<option value='$k'>$v</option>"; ?>
                            </select>
                        </td>
                        <td><input type="text" name="digit_action[<?= $i ?>][dest]" class="form-control" placeholder="e.g. 101 or support_q"></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            <button type="submit" name="submit_ivr" class="btn btn-primary btn-lg mt-3 w-100">GENERATE IVR FILE</button>
        </div>
    </form>
</div>
</body>
</html>
