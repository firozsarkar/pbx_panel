#!/bin/bash

cd /var/www/html/ || exit

echo "== Pulling PBX Panel from GitHub =="

# যদি আগে থেকে folder থাকে তাহলে remove
rm -rf pbx_panel

git clone https://github.com/firozsarkar/pbx_panel.git

cd pbx_panel || exit

echo "== Setting permissions =="

# Web folder permission
chown -R www-data:www-data /var/www/html/pbx_panel
chmod -R 755 /var/www/html/pbx_panel

echo "== FreeSWITCH permissions apply =="

# FreeSWITCH directories
chown -R www-data:www-data /etc/freeswitch/dialplan/public/
chmod -R 755 /etc/freeswitch/dialplan/public/

chown -R www-data:www-data /etc/freeswitch/ivr_menus/
chmod -R 755 /etc/freeswitch/ivr_menus/

chown -R www-data:www-data /etc/freeswitch/sip_profiles/external/
chmod -R 755 /etc/freeswitch/sip_profiles/external/

chown -R www-data:www-data /etc/freeswitch/directory/default/
chmod -R 755 /etc/freeswitch/directory/default/

echo "== Reloading FreeSWITCH XML =="

fs_cli -x "reloadxml" 2>/dev/null

echo "DONE ✔ PBX Panel installed and permissions fixed"
