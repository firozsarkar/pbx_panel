#!/bin/bash

# নিশ্চিত করা হচ্ছে স্ক্রিপ্টটি রুট (root) ইউজার দিয়ে রান করা হচ্ছে
if [ "$EUID" -ne 0 ]; then
  echo "অনুগ্রহ করে স্ক্রিপ্টটি root ইউজার হিসেবে অথবা sudo দিয়ে রান করুন।"
  exit 1
fi

echo "=========================================="
echo "১. সিস্টেম আপডেট এবং PHP 8.2 রিপোজিটরি যোগ করা হচ্ছে..."
echo "=========================================="
apt update -y
apt install software-properties-common ca-certificates lsb-release apt-transport-https curl -y
add-apt-repository ppa:ondrej/php -y
apt update -y

echo "=========================================="
echo "২. Apache এবং PHP 8.2 ইনস্টল করা হচ্ছে..."
echo "=========================================="
apt install apache2 libapache2-mod-php8.2 php8.2-cli php8.2-common php8.2-curl php8.2-xml php8.2-mbstring -y

# Apache-তে PHP 8.2 অ্যাক্টিভ করা
a2enmod php8.2
systemctl restart apache2

echo "=========================================="
echo "৩. FreeSWITCH ফোল্ডার ও পারমিশন সেটআপ শুরু হচ্ছে..."
echo "=========================================="

# কাস্টম সাউন্ড ফাইল ফোল্ডার
echo "-> সাউন্ড ফোল্ডার পারমিশন দেওয়া হচ্ছে..."
mkdir -p /usr/share/freeswitch/sounds/en/us/callie/custom/
chown -R www-data:www-data /usr/share/freeswitch/sounds/en/us/callie/custom/
chmod -R 775 /usr/share/freeswitch/sounds/en/us/callie/custom/

# CDR CSV লগ ফাইল
echo "-> CDR CSV লগ ফাইল পারমিশন দেওয়া হচ্ছে..."
mkdir -p /var/log/freeswitch/cdr-csv/
touch /var/log/freeswitch/cdr-csv/Master.csv
chown www-data:www-data /var/log/freeswitch/cdr-csv/Master.csv
chmod 664 /var/log/freeswitch/cdr-csv/Master.csv

# SIP Directory (ইউজার ক্রিয়েশন)
echo "-> SIP Directory পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/directory/
chown -R www-data:www-data /etc/freeswitch/directory/
chmod -R 775 /etc/freeswitch/directory/

# SIP Profiles External (গেটওয়ে ক্রিয়েশন)
echo "-> SIP Profiles পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/sip_profiles/
chown -R www-data:www-data /etc/freeswitch/sip_profiles/
chmod -R 775 /etc/freeswitch/sip_profiles/

# Dialplan Public (ইনবাউন্ড ও আউটবাউন্ড রাউট)
echo "-> Dialplan পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/dialplan/
chown -R www-data:www-data /etc/freeswitch/dialplan/
chmod -R 775 /etc/freeswitch/dialplan/

# IVR Menus ফোল্ডার তৈরি ও পারমিশন
echo "-> IVR Menus ফোল্ডার তৈরি ও পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/ivr_menus
chown -R www-data:www-data /etc/freeswitch/ivr_menus/
chmod -R 775 /etc/freeswitch/ivr_menus/

echo "=========================================="
echo "৪. Sudoers কনফিগারেশন করা হচ্ছে..."
echo "=========================================="
SUDOERS_FILE="/etc/sudoers.d/freeswitch_web"
cat << 'EOF' > $SUDOERS_FILE
# FreeSWITCH and Fail2ban permissions for Web Server (www-data)
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client *
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart freeswitch
www-data ALL=(ALL) NOPASSWD: /usr/bin/fs_cli *
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/setup_freeswitch.sh
EOF

# সুডো ফাইলের পারমিশন ফিক্স করা
chmod 0440 $SUDOERS_FILE

# এই স্ক্রিপ্টটিকেই কপি করে /usr/local/bin/ এ রেখে দেওয়া হচ্ছে যাতে পরে PHP থেকে কল করা যায়
cat << 'EOF' > /usr/local/bin/setup_freeswitch.sh
#!/bin/bash
# কাস্টম সাউন্ড ফাইল ফোল্ডার পারমিশন
chown -R www-data:www-data /usr/share/freeswitch/sounds/en/us/callie/custom/
chmod -R 775 /usr/share/freeswitch/sounds/en/us/callie/custom/

# CDR CSV লগ ফাইল পারমিশন
touch /var/log/freeswitch/cdr-csv/Master.csv
chown www-data:www-data /var/log/freeswitch/cdr-csv/Master.csv
chmod 664 /var/log/freeswitch/cdr-csv/Master.csv

# SIP Directory পারমিশন
chown -R www-data:www-data /etc/freeswitch/directory/
chmod -R 775 /etc/freeswitch/directory/

# SIP Profiles পারমিশন
chown -R www-data:www-data /etc/freeswitch/sip_profiles/
chmod -R 775 /etc/freeswitch/sip_profiles/

# Dialplan পারমিশন
chown -R www-data:www-data /etc/freeswitch/dialplan/
chmod -R 775 /etc/freeswitch/dialplan/

# IVR Menus পারমিশন
mkdir -p /etc/freeswitch/ivr_menus
chown -R www-data:www-data /etc/freeswitch/ivr_menus/
chmod -R 775 /etc/freeswitch/ivr_menus/

echo "FreeSWITCH Permissions Updated Successfully By Web Server!"
EOF

chmod +x /usr/local/bin/setup_freeswitch.sh

echo "=========================================="
echo "৫. index.php ফাইল তৈরি এবং ওয়েব ডিরেক্টরি পারমিশন সেট করা হচ্ছে..."
echo "=========================================="
rm -f /var/www/html/index.html # ডিফল্ট ফাইল ডিলিট করা হচ্ছে

cat << 'EOF' > /var/www/html/index.php
<?php
echo "<h2>FreeSWITCH Setup & Permission Automation</h2>";
echo "<p>Running background script as root via sudo...</p>";

// রুট প্রিভিলেজ সহ ব্যাকগ্রাউন্ড স্ক্রিপ্ট রান করা
$command = 'sudo /usr/local/bin/setup_freeswitch.sh 2>&1';
$output = shell_exec($command);

echo "<h3>Execution Result:</h3>";
echo "<pre>$output</pre>";
?>
EOF

# ওয়েব ফোল্ডারের ওনারশিপ সেট করা
chown -R www-data:www-data /var/www/html/
chmod -R 755 /var/www/html/

echo "=========================================="
echo "সবকিছু সফলভাবে ইনস্টল ও সেটআপ সম্পন্ন হয়েছে!"
echo "এখন ব্রাউজারে আপনার IP ভিজিট করুন: http://your-server-ip/index.php"
echo "=========================================="
