<?php
// FreeSWITCH লাইভ কল দেখার স্ক্রিপ্ট
header('Content-Type: text/plain');

echo "--- Live Active Calls ---\n";
// fs_cli এর মাধ্যমে কল লিস্ট নিয়ে আসা
$output = shell_exec('fs_cli -x "show calls"');

if ($output) {
    echo $output;
} else {
    echo "No active calls found or permission denied.";
}
?>
