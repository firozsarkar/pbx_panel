<?php
/**
 * Advanced FreeSWITCH IVR XML Generator
 * Beautiful & Professional Version
 */

$status_msg = "";

if (isset($_POST['submit_ivr'])) {
    
    // Unique IVR Name Generation
    $unique_id = time();
    $user_name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($_POST['ivr_name']));
    $final_ivr_name = $user_name . "_" . $unique_id;
   
    $save_directory = "/etc/freeswitch/ivr_menus/";
    $file_name = $final_ivr_name . ".xml";
    $full_path = $save_directory . $file_name;

    // XML Content Generation
    $xml = "<include>\n";
    $xml .= "  <menu name=\"{$final_ivr_name}\"\n";
    $xml .= "        greet-long=\"{$_POST['welcome_msg']}\"\n";
    $xml .= "        invalid-sound=\"{$_POST['invalid_msg']}\"\n";
    $xml .= "        timeout=\"" . ((int)$_POST['timeout_sec'] * 1000) . "\"\n";
    $xml .= "        max-failures=\"{$_POST['max_failures']}\">\n";

    foreach ($_POST['digit_action'] as $digit => $data) {
        $type = $data['type'] ?? '';
        $dest = trim($data['dest'] ?? '');

        if (!empty($type) && !empty($dest) || $type === 'repeat') {
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

    // Save File
    if (file_put_contents($full_path, $xml)) {
        @shell_exec("fs_cli -x 'reloadxml' 2>/dev/null");
        
        $status_msg = "
        <div class='alert alert-success shadow-lg'>
            <h4><i class='bi bi-check-circle-fill'></i> IVR সফলভাবে তৈরি হয়েছে!</h4>
            <hr>
            <p><strong>IVR Name:</strong> <span class='badge bg-primary fs-5'>$final_ivr_name</span></p>
            <p><strong>File Path:</strong> <code>$full_path</code></p>
            <p class='mb-0'><strong>নোট:</strong> উপরের IVR Name কপি করে Inbound Route এ Destination হিসেবে ব্যবহার করুন।</p>
        </div>";
    } else {
        $status_msg = "<div class='alert alert-danger'>❌ ফাইল সেভ করতে সমস্যা হয়েছে!<br>চালান: <code>chmod 777 $save_directory</code></div>";
    }
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH Dynamic IVR Builder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #f1f5f9;
            min-height: 100vh;
            padding: 30px 0;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        }
        .form-control, .form-select {
            background: #0f172a;
            border: 1px solid #475569;
            color: white;
        }
        .form-control:focus, .form-select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.2);
            color: white;
        }
        .digit-table th {
            color: #38bdf8;
        }
        h2 span {
            color: #38bdf8;
            font-weight: 700;
        }
        .btn-primary {
            background: linear-gradient(to right, #38bdf8, #0284c8);
            border: none;
            padding: 14px 0;
            font-size: 1.15rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="container">

    <h2 class="text-center mb-5">
        <i class="bi bi-telephone-inbound-fill"></i> PBX <span>IVR Builder</span>
    </h2>

    <?php echo $status_msg; ?>

    <form method="POST">
        <div class="row g-4">

            <!-- Main Settings -->
            <div class="col-lg-7">
                <div class="card p-4">
                    <h5 class="text-info border-bottom pb-3 mb-4">
                        <i class="bi bi-gear-fill"></i> মূল সেটিংস
                    </h5>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">IVR নাম দিন</label>
                        <input type="text" name="ivr_name" class="form-control" placeholder="Sales_IVR" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Welcome Message (Full Path)</label>
                        <input type="text" name="welcome_msg" class="form-control" 
                               placeholder="/usr/share/freeswitch/sounds/custom/welcome.wav" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Invalid Message</label>
                            <input type="text" name="invalid_msg" class="form-control" 
                                   placeholder="ivr/ivr-invalid_extension.wav">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Timeout (সেকেন্ড)</label>
                            <input type="number" name="timeout_sec" class="form-control" value="5" min="1">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Max Retries</label>
                            <input type="number" name="max_failures" class="form-control" value="3" min="1">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Features -->
            <div class="col-lg-5">
                <div class="card p-4 h-100">
                    <h5 class="text-warning border-bottom pb-3 mb-4">
                        <i class="bi bi-lightbulb-fill"></i> সুবিধাসমূহ
                    </h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Extension, Queue, Ring Group</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Voicemail Support</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> DISA Support</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Repeat Menu</li>
                    </ul>
                </div>
            </div>

        </div>

        <!-- Digit Menu -->
        <div class="card p-4 mt-4">
            <h5 class="text-info border-bottom pb-3 mb-4">
                <i class="bi bi-grid-fill"></i> ডিজিট ম্যাপিং (০-৯)
            </h5>

            <table class="table table-dark table-hover digit-table">
                <thead>
                    <tr>
                        <th width="15%">বাটন</th>
                        <th width="40%">অ্যাকশন</th>
                        <th>গন্তব্য (Destination)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $options = [
                        'extension' => 'Extension Transfer',
                        'queue'     => 'Call Queue',
                        'ringgroup' => 'Ring Group',
                        'voicemail' => 'Voicemail',
                        'disa'      => 'DISA',
                        'repeat'    => 'Repeat Menu'
                    ];

                    for($i = 0; $i <= 9; $i++): ?>
                    <tr>
                        <td><span class="badge bg-secondary fs-5 px-3 py-2">Digit <?= $i ?></span></td>
                        <td>
                            <select name="digit_action[<?= $i ?>][type]" class="form-select">
                                <option value="">-- Select Action --</option>
                                <?php foreach($options as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="digit_action[<?= $i ?>][dest]" 
                                   class="form-control" placeholder="101 অথবা sales_queue">
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <button type="submit" name="submit_ivr" class="btn btn-primary btn-lg w-100 mt-4">
                <i class="bi bi-file-earmark-arrow-down"></i> IVR ফাইল তৈরি করুন
            </button>
        </div>
    </form>
</div>
</body>
</html>
