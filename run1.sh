#!/bin/bash

echo "===== Updating system ====="
sudo apt update -y && sudo apt upgrade -y

echo "===== Installing Apache ====="
sudo apt install apache2 -y

echo "===== Installing PHP ====="
sudo apt install php libapache2-mod-php php-cli php-common php-mysql -y

echo "===== Enabling PHP module ====="
sudo a2enmod php*

echo "===== Restarting Apache ====="
sudo systemctl restart apache2

echo "===== Setting permissions ====="
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html

echo "===== Creating test PHP file ====="
echo "<?php phpinfo(); ?>" | sudo tee /var/www/html/test.php

echo "===== Restarting services ====="
sudo systemctl restart apache2

echo "===== DONE ====="
echo "Now open: http://YOUR_SERVER_IP/test.php"
