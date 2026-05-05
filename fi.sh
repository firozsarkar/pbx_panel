#!/bin/bash

set -e

BASE="/var/www/html/"

echo "==> Creating directory..."
mkdir -p $BASE
cd $BASE

echo "==> Downloading package..."
wget -O freeswitch-system.zip https://github.com/rozsarkar/pbx_panel/raw/refs/heads/main/freeswitch-extension-management-system.zip

echo "==> Installing unzip if not installed..."
apt-get update -y && apt-get install -y unzip

echo "==> Extracting files..."
unzip -o freeswitch-system.zip

echo "==> Moving main file..."
cp fs_manager.php /var/www/html/fs_manager.php

echo "==> Loading FreeSWITCH mod_event_socket..."
fs_cli -x "load mod_event_socket" || true

echo "==> Setting permissions..."
chmod -R 775 /etc/freeswitch/directory/default
chown -R www-data:freeswitch /etc/freeswitch/directory/default

echo "==> Done!"
