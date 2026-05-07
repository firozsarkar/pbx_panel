<?php
/**
 * Advanced Dynamic IVR System
 * Manual Configuration like FreePBX
 * Author: Firoz
 */

// ডেবগিং এবং এরর হ্যান্ডলিং
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ডাটাবেজ কনফিগারেশন (আপনার তথ্য দিয়ে পরিবর্তন করুন)
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'voip_db';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// টেবিল না থাকলে তৈরি করে নিবে (প্রথমবার চালানোর জন্য)
$conn->query("CREATE TABLE IF NOT EXISTS ivr_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_name VARCHAR(100),
    announcement VARCHAR(255),
    timeout INT DEFAULT 5,
    direct_dial TINYINT DEFAULT 1,
    invalid_retries INT DEFAULT 3,
    digit_map TEXT
)");

// ১. অ্যাডমিন প্যানেল লজিক: ডাটা সেভ করা
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_ivr'])) {
    $menu_name = $_POST['menu_name'];
    $announcement = $_POST['announcement'];
    $timeout = $_POST['timeout'];
    $digit_map = json_encode($_POST['digits']);

    // আগের ডেটা ডিলিট করে নতুনটা আপডেট করা (সহজ রাখার জন্য)
    $conn->query("TRUNCATE TABLE ivr_settings");
    $stmt = $conn->prepare("INSERT INTO ivr_settings (menu_name, announcement, timeout, digit_map) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $menu_name, $announcement, $timeout, $digit_map);
    $stmt->execute();
    $status = "IVR Configuration Saved Successfully!";
}

// ২. বর্তমান সেটিংস লোড করা
$res = $conn->query("SELECT * FROM ivr_settings LIMIT 1");
$config = $res->fetch_assoc();
$saved_digits = json_decode($config['digit_map'] ?? '{}', true);

// ৩. VoIP রিকোয়েস্ট হ্যান্ডলিং (যদি URL-এ ?digit=1 থাকে)
if (isset($_GET['digit']) || isset($_GET['dtmf_digit'])) {
    header('Content-Type: text/plain');
    $input = $_GET['digit'] ?? $_GET['dtmf_digit'];
    
    if (isset($saved_digits[$input])) {
        echo "ACTION: TRANSFER\n";
        echo "DESTINATION: " . $saved_digits[$input] . "\n";
    } else {
        echo "ACTION: PLAY\n";
        echo "FILE: " . $config['announcement'] . "\n";
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VIP IVR Builder</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #1a1a2e; color: #fff; padding: 40px; }
        .container { max-width: 800px; background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); padding: 30px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.2); box-shadow: 0 8px 32px 0 rgba(0,0,0,0.37); }
        h2 { border-bottom: 1px solid #444; padding-bottom: 10px; color: #00d2ff; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input, select { width: 100%; padding: 10px; border-radius: 5px; border: none; background: #16213e; color: #fff; box-sizing: border-box; }
        .digit-row { display: flex; gap: 15px; margin-bottom: 10px; align-items: center; }
        .digit-box { width: 60px; text-align: center; background: #0f3460; font-weight: bold; }
        button { background: #00d2ff; color: #1a1a2e; border: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        button:hover { background: #0099cc; }
        .status { color: #00ff88; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="container">
    <h2><i class="fas fa-phone-alt"></i> Manual IVR Configuration</h2>
    <?php if(isset($status)) echo "<p class='status'>$status</p>"; ?>

    <form method="post">
        <div class="form-group">
            <label>IVR Menu Name</label>
            <input type="text" name="menu_name" value="<?php echo $config['menu_name'] ?? 'Main IVR'; ?>" required>
        </div>

        <div class="form-group">
            <label>Announcement Audio Path (wav/mp3)</label>
            <input type="text" name="announcement" value="<?php echo $config['announcement'] ?? ''; ?>" placeholder="/var/lib/freeswitch/recordings/welcome.wav">
        </div>

        <div class="form-group">
            <label>Timeout (Seconds)</label>
            <input type="number" name="timeout" value="<?php echo $config['timeout'] ?? 5; ?>">
        </div>

        <h3>Digit Mapping (0-9)</h3>
        <?php for($i=0; $i<=9; $i++): ?>
        <div class="digit-row">
            <div class="digit-box">Digit <?php echo $i; ?></div>
            <input type="text" name="digits[<?php echo $i; ?>]" value="<?php echo $saved_digits[$i] ?? ''; ?>" placeholder="Target Extension or Destination">
        </div>
        <?php endfor; ?>

        <button type="submit" name="save_ivr">Save & Update IVR</button>
    </form>
</div>

</body>
</html>
