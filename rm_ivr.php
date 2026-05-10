<?php
// IVR নাম বা আইডি নেওয়া হচ্ছে
$ivr_name = isset($_GET['ivr']) ? $_GET['ivr'] : '';

if (empty($ivr_name)) {
    die("Error: IVR name is missing. Usage: ?ivr=my_ivr_menu");
}

// ১. IVR ফাইলের পাথ (আপনার সার্ভারের কনফিগারেশন অনুযায়ী চেক করুন)
// সাধারণত এই পাথে থাকে: /etc/freeswitch/ivr_menus/
$ivr_file = "/etc/freeswitch/ivr_menus/" . $ivr_name . ".xml";

if (file_exists($ivr_file)) {
    // ২. ফাইলটি ডিলিট করা
    if (unlink($ivr_file)) {
        echo "IVR File $ivr_name.xml deleted successfully.<br>";
        
        // ৩. FreeSWITCH কে কনফিগারেশন রিলোড করতে বলা
        // IVR আপডেট বা ডিলিট করলে মেমরি আপডেট করার জন্য reloadxml দিতে হয়
        $output = shell_exec("/usr/bin/fs_cli -x 'reloadxml'");
        
        echo "FreeSWITCH XML reloaded. IVR '$ivr_name' is permanently gone.";
    } else {
        echo "Error: Could not delete the IVR file. Check folder permissions.";
    }
} else {
    // যদি ফাইল না পাওয়া যায়, তবে হতে পারে এটি ivr.conf.xml এর ভেতরে লেখা
    echo "Error: IVR file not found at $ivr_file. If your IVR is inside ivr.conf.xml, you must remove it manually.";
}
?>
