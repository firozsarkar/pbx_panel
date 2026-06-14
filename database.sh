#!/bin/bash

# স্ক্রিপ্ট কোনো কারণে ফেইল করলে যেন স্টপ হয়ে যায়
set -e

echo "==========================================="
echo "   PBX Database Auto-Installer Script      "
echo "==========================================="

# ১. সিস্টেম আপডেট এবং মারিয়াডিবি ইনস্টলেশন
echo "[+] Updating system packages..."
apt update && apt upgrade -y

echo "[+] Installing MariaDB Server and Client..."
apt install mariadb-server mariadb-client -y

# ২. ডাটাবেস সার্ভিস স্টার্ট ও এনাবল করা
echo "[+] Starting and configuring MariaDB service..."
systemctl start mariadb
systemctl enable mariadb

# ৩. ডাটাবেস রুট পাসওয়ার্ড সেটআপ (পাসওয়ার্ডটি মনে রাখবেন)
DB_ROOT_PASS="PBX_Secure_Pass_2026"

echo "[+] Securing MariaDB installation..."
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';"
mysql -u root -p"${DB_ROOT_PASS}" -e "DELETE FROM mysql.user WHERE User='';"
mysql -u root -p"${DB_ROOT_PASS}" -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
mysql -u root -p"${DB_ROOT_PASS}" -e "DROP DATABASE IF EXISTS test;"
mysql -u root -p"${DB_ROOT_PASS}" -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
mysql -u root -p"${DB_ROOT_PASS}" -e "FLUSH PRIVILEGES;"

# ৪. PBX ডেটাবেস এবং টেবিল তৈরি করা
echo "[+] Creating PBX Database and Tables..."

mysql -u root -p"${DB_ROOT_PASS}" <<EOF
CREATE DATABASE IF NOT LIMITED fs_pbx_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fs_pbx_db;

-- ১. টেন্যান্ট বা কোম্পানি টেবিল
CREATE TABLE IF NOT EXISTS \`tenants\` (
  \`id\` INT AUTO_INCREMENT PRIMARY KEY,
  \`company_name\` VARCHAR(100) NOT NULL,
  \`tenant_prefix\` VARCHAR(10) NOT NULL UNIQUE,
  \`status\` TINYINT(1) DEFAULT 1,
  \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ২. এক্সটেনশন বা ইউজার টেবিল
CREATE TABLE IF NOT EXISTS \`extensions\` (
  \`id\` INT AUTO_INCREMENT PRIMARY KEY,
  \`tenant_id\` INT NOT NULL,
  \`user_id\` VARCHAR(20) NOT NULL,
  \`password\` VARCHAR(100) NOT NULL,
  \`vm_password\` VARCHAR(20) DEFAULT NULL,
  \`user_context\` VARCHAR(50) NOT NULL DEFAULT 'default',
  \`effective_caller_id_name\` VARCHAR(100) DEFAULT NULL,
  \`effective_caller_id_number\` VARCHAR(20) DEFAULT NULL,
  \`outbound_caller_id_name\` VARCHAR(100) DEFAULT NULL,
  \`outbound_caller_id_number\` VARCHAR(20) DEFAULT NULL,
  \`toll_allow\` VARCHAR(100) DEFAULT 'domestic,international,local',
  \`status\` TINYINT(1) DEFAULT 1,
  \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY \`unique_user_tenant\` (\`tenant_id\`, \`user_id\`),
  FOREIGN KEY (\`tenant_id\`) REFERENCES \`tenants\`(\`id\`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ৩. কাস্টমার ব্যালেন্স (বিলিং) টেবিল
CREATE TABLE IF NOT EXISTS \`wallets\` (
  \`id\` INT AUTO_INCREMENT PRIMARY KEY,
  \`tenant_id\` INT NOT NULL,
  \`balance\` DECIMAL(10,4) DEFAULT 0.0000,
  \`updated_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (\`tenant_id\`) REFERENCES \`tenants\`(\`id\`) ON DELETE CASCADE
) ENGINE=InnoDB;

EOF

echo "==========================================="
echo "  SUCCESS: PBX Database setup is complete!  "
echo "==========================================="
echo "Database Name: fs_pbx_db"
echo "MySQL Root Password: ${DB_ROOT_PASS}"
echo "==========================================="
