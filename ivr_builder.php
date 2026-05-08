<?php
/**
 * Advanced FreeSWITCH IVR XML Generator
 * Target: /etc/freeswitch/ivr_menus/serial_ivr.xml
 */

$save_path = "/etc/freeswitch/ivr_menus/serial_ivr.xml";
$status = "";

if (isset($_POST['submit_ivr'])) {
    // ফর্ম ডেটা রিসিভ করা
    $ivr_name = "serial_ivr"; // আপনার রিকোয়েস্ট অনুযায়ী ফিক্সড নাম
    $greet_long = $_POST['welcome_msg'];
    $invalid_sound = $_POST['invalid_msg'];
    $timeout = $_POST['timeout_sec'] * 1000; // Milliseconds
    $max_failures = $_POST['max_failures'];

    // XML স্ট্রাকচার শুরু
    $xml = "<include>\n";
    $xml .= "  <menu name=\"$ivr_name\"\n";
    $xml .= "      greet-long=\"$greet_long\"\n";
    $xml .= "      invalid-sound=\"$invalid_sound\"\n";
    $xml .= "      timeout=\"$timeout\"\n";
    $xml .= "      max-failures=\"$max_failures\">\n";

    // প্রতিটি ডিজিটের জন্য লজিক তৈরি (0-9)
    foreach ($_POST['digit_action'] as $digit => $data) {
        $type = $data['type'];
        $dest = $data['dest'];

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
                    $xml .= "    <entry action=\"menu-exec-app\" digits=\"$digit\" param=\"voicemail default \$${domain} $dest\"/>\n";
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

    // ফাইল সেভ করা
    if (file_put_contents($save_path, $xml)) {
        // FreeSWITCH কে রিলোড দেওয়ার কমান্ড (ঐচ্ছিক)
        shell_exec("fs_cli -x 'reloadxml'");
        $status = "<div class='alert alert-success'>XML Created Successfully at $save_path and XML Reloaded!</div>";
    } else {
        $status = "<div class='alert alert-danger'>Error: Cannot write to $save_path. Check Permissions!</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>FreeSWITCH IVR Generator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <style>
        body { background: #121212; color: #e0e0e0; padding: 20px; }
        .card { background: #1e1e1e; border: 1px solid #333; color: white; margin-bottom: 20px; }
        .form-control, .form-select { background: #2d2d2d; border: 1px solid #444; color: white; }
        .btn-primary { background: #00adb5; border: none; font-weight: bold; }
        h2 { color: #00adb5; }
    </style>
</head>
<body>
<div class="container shadow-lg p-4">
    <h2><i class="bi bi-cpu"></i> Advanced FreeSWITCH IVR Builder</h2>
    <p class="text-muted">এটি অটোমেটিক <b>serial_ivr.xml</b> ফাইল তৈরি করবে।</p>
    <hr>
    
    <?php echo $status; ?>

    <form method="POST">
        <div class="row">
            <!-- Basic Config -->
            <div class="col-md-6">
                <div class="card p-3">
                    <h5>General Settings</h5>
                    <label>Welcome Greeting (Full Path)</label>
                    <input type="text" name="welcome_msg" class="form-control mb-2" placeholder="/usr/share/freeswitch/sounds/custom/welcome.wav" required>
                    
                    <label>Invalid Input Sound</label>
                    <input type="text" name="invalid_msg" class="form-control mb-2" placeholder="ivr/ivr-invalid_extension.wav">
                    
                    <div class="row">
                        <div class="col"><label>Timeout (Sec)</label><input type="number" name="timeout_sec" class="form-control" value="5"></div>
                        <div class="col"><label>Max Retries</label><input type="number" name="max_failures" class="form-control" value="3"></div>
                    </div>
                </div>
            </div>

            <!-- Features -->
            <div class="col-md-6">
                <div class="card p-3">
                    <h5>Quick Info (Available Features)</h5>
                    <ul class="small text-info">
                        <li>Queue / Ring Group / Follow Me</li>
                        <li>DISA / Callback / Voicemail</li>
                        <li>Time Condition (Managed via Dialplan)</li>
                        <li>Call Recording (Managed via Dialplan)</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Digit Mapping -->
        <div class="card p-3">
            <h5>Digit Mapping & Routing</h5>
            <table class="table table-dark small">
                <thead>
                    <tr><th>Digit</th><th>Action Type</th><th>Destination (Extension/Queue/Number)</th></tr>
                </thead>
                <tbody>
                    <?php 
                    $options = [
                        'extension' => 'Transfer to Extension',
                        'queue'     => 'Send to Queue',
                        'ringgroup' => 'Ring Group',
                        'voicemail' => 'Voicemail',
                        'disa'      => 'DISA Access',
                        'repeat'    => 'Repeat Menu'
                    ];
                    for($i=0; $i<=9; $i++): ?>
                    <tr>
                        <td><strong>Button <?= $i ?></strong></td>
                        <td>
                            <select name="digit_action[<?= $i ?>][type]" class="form-select form-select-sm">
                                <option value="">-- No Action --</option>
                                <?php foreach($options as $val => $label) echo "<option value='$val'>$label</option>"; ?>
                            </select>
                        </td>
                        <td><input type="text" name="digit_action[<?= $i ?>][dest]" class="form-control form-control-sm" placeholder="Target"></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <button type="submit" name="submit_ivr" class="btn btn-primary w-100 p-3 mt-3">CREATE XML & SUBMIT</button>
    </form>
</div>
</body>
</html>
