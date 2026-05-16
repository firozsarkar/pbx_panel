#!/bin/bash

echo "=================================="
echo " PBX PANEL FULL PERMISSION SETUP "
echo "=================================="

# WEB ROOT
chmod 755 /var/www
chmod 755 /var/www/html

# PANEL OWNERSHIP
chown -R www-data:www-data /var/www/html/pbx_panel

# DIRECTORY PERMISSION
find /var/www/html/pbx_panel -type d -exec chmod 755 {} \;

# FILE PERMISSION
find /var/www/html/pbx_panel -type f -exec chmod 644 {} \;

# SHELL SCRIPT EXECUTE
find /var/www/html/pbx_panel -name "*.sh" -exec chmod +x {} \;

# PYTHON SCRIPT EXECUTE
find /var/www/html/pbx_panel -name "*.py" -exec chmod +x {} \;

# NODE SCRIPT EXECUTE
find /var/www/html/pbx_panel -name "*.js" -exec chmod 644 {} \;

# PHP FILES
find /var/www/html/pbx_panel -name "*.php" -exec chmod 644 {} \;

# XML FILES
find /var/www/html/pbx_panel -name "*.xml" -exec chmod 644 {} \;

# JSON FILES
find /var/www/html/pbx_panel -name "*.json" -exec chmod 644 {} \;

# LOG FILES
find /var/www/html/pbx_panel -name "*.log" -exec chmod 664 {} \;

# STORAGE FOLDERS
mkdir -p /var/www/html/pbx_panel/storage
mkdir -p /var/www/html/pbx_panel/cache
mkdir -p /var/www/html/pbx_panel/logs
mkdir -p /var/www/html/pbx_panel/uploads
mkdir -p /var/www/html/pbx_panel/temp
mkdir -p /var/www/html/pbx_panel/tmp

chmod -R 775 /var/www/html/pbx_panel/storage
chmod -R 775 /var/www/html/pbx_panel/cache
chmod -R 775 /var/www/html/pbx_panel/logs
chmod -R 775 /var/www/html/pbx_panel/uploads
chmod -R 775 /var/www/html/pbx_panel/temp
chmod -R 775 /var/www/html/pbx_panel/tmp

chown -R www-data:www-data /var/www/html/pbx_panel/storage
chown -R www-data:www-data /var/www/html/pbx_panel/cache
chown -R www-data:www-data /var/www/html/pbx_panel/logs
chown -R www-data:www-data /var/www/html/pbx_panel/uploads
chown -R www-data:www-data /var/www/html/pbx_panel/temp
chown -R www-data:www-data /var/www/html/pbx_panel/tmp

# FREESWITCH CONFIG
chown -R freeswitch:www-data /etc/freeswitch
chmod -R 775 /etc/freeswitch

# DIALPLAN
chmod -R 775 /etc/freeswitch/dialplan
chmod -R 775 /etc/freeswitch/directory
chmod -R 775 /etc/freeswitch/sip_profiles
chmod -R 775 /etc/freeswitch/ivr_menus

# SOUND FILES
chmod -R 775 /usr/share/freeswitch/sounds

# RECORDINGS
mkdir -p /usr/local/freeswitch/recordings

chown -R freeswitch:www-data /usr/local/freeswitch/recordings
chmod -R 775 /usr/local/freeswitch/recordings

# FS_CLI
chmod +x /usr/bin/fs_cli

# ESL SOCKET
chmod 640 /etc/freeswitch/autoload_configs/event_socket.conf.xml

# CRON FILES
chmod 644 /etc/crontab
chmod -R 755 /etc/cron.d
chmod -R 755 /etc/cron.daily

# APACHE / NGINX LOG ACCESS
chmod -R 755 /var/log/apache2
chmod -R 755 /var/log/nginx

# PHP SESSION
chmod -R 777 /var/lib/php/sessions

# SYSTEMD
chmod 644 /lib/systemd/system/freeswitch.service

# LUA SCRIPTS
find /usr/share/freeswitch/scripts -name "*.lua" -exec chmod 755 {} \;

# BASH EXECUTION
chmod +x /bin/bash

# RELOAD FREESWITCH
systemctl restart freeswitch

echo "=================================="
echo " PERMISSION SETUP COMPLETED "
echo "=================================="
