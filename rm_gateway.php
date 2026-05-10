<?php
// ইউজার ইনপুট চেক
$gateway = isset($_GET['geteway']) ? $_GET['geteway'] : '';

if (empty($gateway)) {
    die("Error: Gateway ID missing.");
}

// ১. গেটওয়ে ফাইলের পাথ (আপনার সার্ভারের পাথ অনুযায়ী চেক করে নিন)
// সাধারণত পাথ হয়: /etc/freeswitch/directory/default/ বা /etc/freeswitch/sip_profiles/external/
$gateway_file = "/etc/freeswitch/sip_profiles/external/" . $gateway . ".xml";

if (file_exists($gateway_file)) {
    // ২. ফাইলটি ডিলিট করা
    if (unlink($gateway_file)) {
        echo "File $gateway.xml deleted successfully.<br>";
        
        // ৩. FreeSWITCH মেমরি থেকে রিমুভ করার কমান্ড
        $fs_command = "sofia profile external killgw " . escapeshellarg($gateway);
        $output = shell_exec("/usr/bin/fs_cli -x " . escapeshellcmd($fs_command));
        
        // ৪. কনফিগারেশন রিলোড করা (অপশনাল কিন্তু ভালো)
        shell_exec("/usr/bin/fs_cli -x 'sofia profile external rescan'");
        
        echo "Gateway $gateway has been permanently removed from FreeSWITCH.";
    } else {
        echo "Error: Could not delete the XML file. Check permissions.";
    }
} else {
    echo "Error: Gateway file not found at $gateway_file";
}
?>
