#!/usr/bin/env bash
# Idempotent bootstrap for the Nexus Technologies dev environment (Cloud Agent).
# Prepares PHP + Composer + MySQL + Node dependencies and the database schema.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> System packages (PHP 8.3 + extensions, MySQL server, tools)"
sudo apt-get update -y
sudo apt-get install -y --no-install-recommends \
  php-cli php-mysql php-bcmath php-mbstring php-xml php-curl \
  mysql-server unzip curl ca-certificates

echo "==> Composer"
if ! command -v composer >/dev/null 2>&1; then
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

echo "==> Start MySQL and wait for readiness"
# The SysV init script only pings for ~30s and can time out on cold snapshot
# disk I/O, so poll for readiness ourselves and re-issue start across attempts.
sudo mkdir -p /var/run/mysqld
sudo chown mysql:mysql /var/run/mysqld 2>/dev/null || true
mysql_ready=0
for attempt in 1 2 3 4; do
  sudo service mysql start >/dev/null 2>&1 || true
  for _ in $(seq 1 45); do
    if sudo mysqladmin ping --silent 2>/dev/null; then
      mysql_ready=1
      break
    fi
    sleep 1
  done
  [ "$mysql_ready" = 1 ] && break
  echo "    MySQL not ready after attempt ${attempt}; retrying..."
done
if [ "$mysql_ready" != 1 ]; then
  echo "MySQL did not become ready." >&2
  sudo tail -n 30 /var/log/mysql/error.log 2>/dev/null || true
  exit 1
fi

echo "==> Configure MySQL users (dev defaults: root/empty + nexus/nexus_dev_pw)"
# root over TCP resolves to root@localhost via reverse DNS; give both an empty
# native password so the dev-default (XAMPP-style) config connects, and add the
# documented application user for the canonical migrate.sh path.
sudo mysql <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY '';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'nexus'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY 'nexus_dev_pw';
GRANT ALL PRIVILEGES ON *.* TO 'nexus'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'nexus'@'localhost' IDENTIFIED WITH mysql_native_password BY 'nexus_dev_pw';
GRANT ALL PRIVILEGES ON *.* TO 'nexus'@'localhost' WITH GRANT OPTION;
CREATE DATABASE IF NOT EXISTS nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
SQL

echo "==> Composer dependencies (nexus-api)"
( cd "$ROOT/nexus-api" && composer install --no-interaction --no-progress )

echo "==> Database schema (dev 'nexus' + 'nexus_test')"
# setup_test_db.php applies schema.sql + every migration in the manifest through
# PDO, neutralising CREATE DATABASE/USE. It is the reliable, engine-agnostic
# installer (migrate.sh skips migrations that omit `USE nexus;`). The dev DB is
# only (re)built when its schema is incomplete, so existing dev data survives
# re-runs; the throwaway test DB is always rebuilt.
cd "$ROOT/nexus-api"
DEV_TABLES=$(mysql -h127.0.0.1 -uroot -sN \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='nexus'" 2>/dev/null || echo 0)
if [ "${DEV_TABLES:-0}" -lt 27 ]; then
  DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS='' \
    DB_TEST_NAME=nexus DB_NAME=nexus php scripts/setup_test_db.php
else
  echo "    dev 'nexus' already has ${DEV_TABLES} tables — skipping rebuild."
fi
DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS='' \
  DB_TEST_NAME=nexus_test php scripts/setup_test_db.php
cd "$ROOT"

echo "==> Node dependencies (nexus-frontend, agents)"
( cd "$ROOT/nexus-frontend" && npm ci )
( cd "$ROOT/agents" && npm ci )

echo "==> Install complete."
