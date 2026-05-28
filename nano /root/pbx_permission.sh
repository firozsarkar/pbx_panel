# ১. কাস্টম সাউন্ড ফাইল ডিলিট করার ফোল্ডার
mkdir /usr/share/freeswitch/sounds/en/us/callie/custom/
chown -R www-data:www-data /usr/share/freeswitch/sounds/en/us/callie/custom/
chmod -R 775 /usr/share/freeswitch/sounds/en/us/callie/custom/

# ২. CDR CSV লগ ফাইল পড়ার পারমিশন
chown www-data:www-data /var/log/freeswitch/cdr-csv/Master.csv
chmod 664 /var/log/freeswitch/cdr-csv/Master.csv

# ৩. SIP Directory (ইউজার ক্রিয়েশন ফোল্ডার)
chown -R www-data:www-data /etc/freeswitch/directory/
chmod -R 775 /etc/freeswitch/directory/

# ৪. SIP Profiles External (গেটওয়ে ক্রিয়েশন ফোল্ডার)
chown -R www-data:www-data /etc/freeswitch/sip_profiles/
chmod -R 775 /etc/freeswitch/sip_profiles/

# ৫. Dialplan Public (ইনবাউন্ড ও আউটবাউন্ড রাউট ফোল্ডার)
chown -R www-data:www-data /etc/freeswitch/dialplan/
chmod -R 775 /etc/freeswitch/dialplan/

# ৬. IVR Menus ফোল্ডার
mkdir -p /etc/freeswitch/ivr_menus
chown -R www-data:www-data /etc/freeswitch/ivr_menus/
chmod -R 775 /etc/freeswitch/ivr_menus/
