<?php
// Security Key (Apnar pochondo moto change kore nin)
$secret_key = "123";

// User theke data neya
$ip = $_GET['ip'] ?? '';
$jail = $_GET['jail'] ?? 'freeswitch';
$key = $_GET['key'] ?? '';

// Basic validation
if ($key !== $secret_key) {
    die("Error: Unauthorized Access.");
}

if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    die("Error: Invalid IP address.");
}

// Fail2ban command execution
// PHP ke 'sudo' diye command chalanor permission thakto hobe (visudo te configure kora lage)
$command = "sudo fail2ban-client set " . escapeshellarg($jail) . " unbanip " . escapeshellarg($ip);
exec($command, $output, $return_var);

if ($return_var === 0) {
    echo "Success: IP $ip has been unbanned from $jail.";
} else {
    echo "Error: Could not unban IP. Check permissions or jail name.";
}
?>
