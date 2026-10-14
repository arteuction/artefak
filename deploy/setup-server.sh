#!/usr/bin/env bash
# Phase 158 — One-time VPS provisioning for euction.artefak.eu
# Ubuntu 24.04 LTS, 1GB RAM, 1 CPU, 25GB NVMe
# Run as root once after first login.
set -euo pipefail

APP_USER="arteuction"
APP_DIR="/var/www/arteuction"
PHP_VERSION="8.4"

echo "==> Installing system packages"
apt-get update -qq
apt-get install -y --no-install-recommends \
    git curl unzip mariadb-server php${PHP_VERSION}-cli \
    php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl \
    php${PHP_VERSION}-gd php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-intl php${PHP_VERSION}-pdo php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-pcntl php${PHP_VERSION}-opcache \
    zram-tools libjemalloc2 supervisor

echo "==> Installing FrankenPHP (replaces Nginx + PHP-FPM)"
FRANKENPHP_VERSION="1.3.5"
curl -fsSL "https://github.com/dunglas/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-linux-x86_64" \
    -o /usr/local/bin/frankenphp
chmod +x /usr/local/bin/frankenphp

echo "==> Installing Composer"
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "==> Creating application user and directory"
useradd --system --home "${APP_DIR}" --shell /bin/bash "${APP_USER}" || true
mkdir -p "${APP_DIR}"
chown "${APP_USER}:${APP_USER}" "${APP_DIR}"

echo "==> Configuring MariaDB"
cp /etc/arteuction/deploy/mariadb.cnf /etc/mysql/conf.d/arteuction.cnf
systemctl restart mariadb

# Create database and user
mysql -e "CREATE DATABASE IF NOT EXISTS arteuction CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'arteuction'@'localhost' IDENTIFIED BY '${DB_PASSWORD:?DB_PASSWORD must be set}';"
mysql -e "GRANT ALL PRIVILEGES ON arteuction.* TO 'arteuction'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "==> Configuring ZRAM"
cp /etc/arteuction/deploy/zramswap /etc/default/zramswap
systemctl enable --now zramswap

echo "==> Creating swap file (2GB)"
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw,pri=10 0 0' >> /etc/fstab
fi

echo "==> Applying kernel memory tuning"
cp /etc/arteuction/deploy/sysctl-arteuction.conf /etc/sysctl.d/99-arteuction.conf
sysctl --system

echo "==> Installing systemd units"
cp /etc/arteuction/deploy/systemd/*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable arteuction-octane arteuction-reverb arteuction-queue

echo "==> Removing snapd (not needed on headless server)"
apt-get purge -y --auto-remove snapd 2>/dev/null || true

echo ""
echo "==> Server provisioning complete."
echo "    Next: clone repo to ${APP_DIR}, copy .env, run deploy.sh"
