#!/bin/bash

# নিশ্চিত করা হচ্ছে স্ক্রিপ্টটি রুট (root) ইউজার বা sudo দিয়ে রান করা হচ্ছে
if [ "$EUID" -ne 0 ]; then
  echo "❌ ভুল: অনুগ্রহ করে স্ক্রিপ্টটি root ইউজার হিসেবে অথবা sudo দিয়ে রান করুন।"
  exit 1
fi

echo "🚀 FreeSWITCH এবং Web Server (www-data) পারমিশন সেট করা শুরু হচ্ছে..."

# ==========================================
# ১. ফোল্ডার তৈরি এবং পারমিশন/ওনারশিপ সেটআপ
# ==========================================

# কাস্টম সাউন্ড ফাইল ফোল্ডার (mkdir -p ব্যবহার করা হয়েছে এবং cd বাদ দেওয়া হয়েছে)
echo "-> সাউন্ড ফোল্ডার পারমিশন দেওয়া হচ্ছে..."
mkdir -p /usr/share/freeswitch/sounds/en/us/callie/custom/
chown -R www-data:www-data /usr/share/freeswitch/sounds/en/us/callie/custom/
chmod -R 775 /usr/share/freeswitch/sounds/en/us/callie/custom/

# CDR CSV লগ ফাইল (ফাইল না থাকলে খালি ফাইল তৈরি করে পারমিশন দেবে)
echo "-> CDR CSV লগ ফাইল পারমিশন দেওয়া হচ্ছে..."
mkdir -p /var/log/freeswitch/cdr-csv/
touch /var/log/freeswitch/cdr-csv/Master.csv
chown www-data:www-data /var/log/freeswitch/cdr-csv/Master.csv
chmod 664 /var/log/freeswitch/cdr-csv/Master.csv

# SIP Directory (ইউজার ক্রিয়েশন)
echo "-> SIP Directory পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/directory/
chown -R www-data:www-data /etc/freeswitch/directory/
chmod -R 775 /etc/freeswitch/directory/

# SIP Profiles External (গেটওয়ে ক্রিয়েশন)
echo "-> SIP Profiles পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/sip_profiles/
chown -R www-data:www-data /etc/freeswitch/sip_profiles/
chmod -R 775 /etc/freeswitch/sip_profiles/

# Dialplan Public (ইনবাউন্ড ও আউটবাউন্ড রাউট)
echo "-> Dialplan পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/dialplan/
chown -R www-data:www-data /etc/freeswitch/dialplan/
chmod -R 775 /etc/freeswitch/dialplan/

# IVR Menus ফোল্ডার তৈরি ও পারমিশন
echo "-> IVR Menus ফোল্ডার তৈরি ও পারমিশন দেওয়া হচ্ছে..."
mkdir -p /etc/freeswitch/ivr_menus
chown -R www-data:www-data /etc/freeswitch/ivr_menus/
chmod -R 775 /etc/freeswitch/ivr_menus/

# ==========================================
# ২. ডাইনামিক সুডো (Sudoers) কনফিগারেশন
# ==========================================
echo "-> সিস্টেম কমান্ডগুলোর আসল পাথ (Path) খোঁজা হচ্ছে..."

# কমান্ডগুলোর অরিজিনাল পাথ লিনাক্স থেকে স্বয়ংক্রিয়ভাবে বের করা হচ্ছে
FAIL2BAN_PATH=$(which fail2ban-client 2>/dev/null || echo "/usr/bin/fail2ban-client")
SYSTEMCTL_PATH=$(which systemctl 2>/dev/null || echo "/bin/systemctl")
FSCLI_PATH=$(which fs_cli 2>/dev/null || echo "/usr/bin/fs_cli")

echo "-> Sudoers ফাইলে পারমিশন রাইট করা হচ্ছে..."
SUDOERS_FILE="/etc/sudoers.d/freeswitch_web"

# নতুন ফাইল জেনারেট করা
cat << EOF > $SUDOERS_FILE
# FreeSWITCH and Fail2ban permissions for Web Server (www-data)
www-data ALL=(ALL) NOPASSWD: $FAIL2BAN_PATH *
www-data ALL=(ALL) NOPASSWD: $SYSTEMCTL_PATH restart freeswitch
www-data ALL=(ALL) NOPASSWD: $FSCLI_PATH *
EOF

# সুডো ফাইলের সিকিউরিটি পারমিশন ফিক্স করা (এটি লিনাক্সের জন্য বাধ্যতামূলক)
chmod 0440 $SUDOERS_FILE

echo "=========================================="
echo "✅ সফল! সব পারমিশন এবং সুডো কনফিগারেশন সেট হয়েছে।"
echo "=========================================="
