#!/bin/bash

# =========================
# CONFIG
# =========================
WEB_DIR="/var/www/html"
REPO_URL="https://github.com/firozsarkar/pbx_panel.git"
TEMP_DIR="pbx_panel"

FS_DIR="/etc/freeswitch"
USER="www-data"
GROUP="www-data"

# =========================
echo "===== STARTING PBX INSTALL ====="

cd $WEB_DIR || exit 1

echo "[1] Removing old files (if any)..."
rm -rf $TEMP_DIR

echo "[2] Cloning repository..."
git clone $REPO_URL

if [ ! -d "$WEB_DIR/$TEMP_DIR" ]; then
    echo "Clone failed!"
    exit 1
fi

echo "[3] Moving files to root web directory..."
cp -r $WEB_DIR/$TEMP_DIR/. $WEB_DIR/
rm -rf $WEB_DIR/$TEMP_DIR

echo "[4] Setting permissions for web root..."
chown -R $USER:$GROUP $WEB_DIR
chmod -R 755 $WEB_DIR

echo "[5] Setting FreeSWITCH permissions..."

FS_PATHS=(
"$FS_DIR/dialplan/public/"
"$FS_DIR/ivr_menus/"
"$FS_DIR/sip_profiles/external/"
"$FS_DIR/directory/default/"
)

for path in "${FS_PATHS[@]}"; do
    if [ -d "$path" ]; then
        chown -R $USER:$GROUP $path
        chmod -R 755 $path
        echo "OK: $path"
    else
        echo "SKIP (not found): $path"
    fi
done

echo "[6] Reloading FreeSWITCH XML..."
fs_cli -x "reloadxml" >/dev/null 2>&1

echo "===== INSTALL COMPLETE SUCCESSFULLY ====="
