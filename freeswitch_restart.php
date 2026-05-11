<?php
// Token check
$access_token = "321";

if (!isset($_GET['token']) || $_GET['token'] !== $access_token) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Invalid Token"]);
    exit;
}

// Execute the restart command
// Note: Web server user (www-data) needs sudo permission for this to work
$output = shell_exec('sudo systemctl restart freeswitch 2>&1');

header('Content-Type: application/json');
if (is_null($output)) {
    echo json_encode([
        "status" => "success",
        "message" => "FreeSWITCH restart command executed successfully."
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Execution failed or returned output.",
        "detail" => $output
    ]);
}
?>
