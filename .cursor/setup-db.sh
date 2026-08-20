#!/usr/bin/env bash
# Bring MySQL up and guarantee the Nexus dev ('nexus') and test ('nexus_test')
# schemas exist. Shared by .cursor/install.sh (build time) and .cursor/start.sh
# (every boot). Safe to run repeatedly.
#
# Why this also runs on every boot: Cloud Agent build pods restore the snapshot
# onto a container/FUSE-backed filesystem where InnoDB's unconditional O_DIRECT
# capability probe on restored tablespace files (e.g. mysql.ibd) fails with
# "Operating system error number 22 ... Invalid argument". This cannot be
# disabled via my.cnf. A data directory *freshly initialised on the runtime
# filesystem* passes the probe, so when the restored data dir will not start we
# reinitialise it in place. This is a dev database with no durable data; the
# schema is rebuilt here.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_TABLES_EXPECTED=27

write_mysql_conf() {
  sudo tee /etc/mysql/mysql.conf.d/zz-cloud-agent.cnf >/dev/null <<'CNF'
[mysqld]
innodb_use_native_aio = 0
innodb_flush_method = fsync
CNF
}

try_start_mysql() {
  local attempts="${1:-4}"
  for attempt in $(seq 1 "$attempts"); do
    sudo service mysql start >/dev/null 2>&1 || true
    for i in $(seq 1 45); do
      if sudo mysqladmin ping --silent 2>/dev/null; then
        return 0
      fi
      # If mysqld has already exited (e.g. InnoDB aborted on an unreadable data
      # dir), stop waiting the full timeout and move on to the next attempt.
      if [ "$i" -ge 3 ] && ! pgrep -x mysqld >/dev/null 2>&1; then
        break
      fi
      sleep 1
    done
    echo "    MySQL not ready after attempt ${attempt}; retrying..."
  done
  return 1
}

reinit_mysql() {
  echo "==> Reinitialising a fresh MySQL data directory"
  sudo service mysql stop >/dev/null 2>&1 || true
  sudo pkill -9 -x mysqld 2>/dev/null || true
  sleep 2
  sudo rm -rf /var/lib/mysql
  sudo mkdir -p /var/lib/mysql /var/run/mysqld
  sudo chown -R mysql:mysql /var/lib/mysql /var/run/mysqld
  sudo mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
}

restore_debian_maint() {
  # The init script stops/pings MySQL as debian-sys-maint using the password in
  # /etc/mysql/debian.cnf. A fresh --initialize does not create that account.
  local pw
  pw=$(sudo awk -F' *= *' '/^password/{print $2; exit}' /etc/mysql/debian.cnf 2>/dev/null || true)
  [ -n "${pw:-}" ] || return 0
  sudo mysql <<SQL || true
CREATE USER IF NOT EXISTS 'debian-sys-maint'@'localhost' IDENTIFIED WITH mysql_native_password BY '${pw}';
ALTER USER 'debian-sys-maint'@'localhost' IDENTIFIED WITH mysql_native_password BY '${pw}';
GRANT ALL PRIVILEGES ON *.* TO 'debian-sys-maint'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
}

provision_users() {
  # root over TCP resolves to root@localhost via reverse DNS; give both an empty
  # native password so the dev-default (XAMPP-style) config connects, and add
  # the documented application user for the canonical migrate.sh path.
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
}

build_schema() {
  # setup_test_db.php applies schema.sql + every migration in the manifest via
  # PDO (neutralising CREATE DATABASE/USE) — the reliable, engine-agnostic
  # installer (migrate.sh skips migrations that omit `USE nexus;`). The dev DB is
  # only (re)built when incomplete, so existing dev data survives re-runs; the
  # throwaway test DB is always rebuilt.
  local dev_tables
  cd "$ROOT/nexus-api"
  dev_tables=$(mysql -h127.0.0.1 -uroot -sN \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='nexus'" 2>/dev/null || echo 0)
  if [ "${dev_tables:-0}" -lt "$DB_TABLES_EXPECTED" ]; then
    DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS='' \
      DB_TEST_NAME=nexus DB_NAME=nexus php scripts/setup_test_db.php
  else
    echo "    dev 'nexus' already has ${dev_tables} tables — skipping rebuild."
  fi
  DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS='' \
    DB_TEST_NAME=nexus_test php scripts/setup_test_db.php
  cd "$ROOT"
}

echo "==> Configuring MySQL (container/FUSE-safe InnoDB settings)"
write_mysql_conf
sudo mkdir -p /var/run/mysqld
sudo chown mysql:mysql /var/run/mysqld 2>/dev/null || true

echo "==> Starting MySQL"
if ! try_start_mysql 2; then
  reinit_mysql
  if ! try_start_mysql 4; then
    echo "MySQL did not become ready even after reinitialising." >&2
    sudo tail -n 40 /var/log/mysql/error.log 2>/dev/null || true
    exit 1
  fi
fi

echo "==> Restoring Debian maintenance account"
restore_debian_maint
echo "==> Configuring MySQL users"
provision_users
echo "==> Ensuring database schema (dev 'nexus' + 'nexus_test')"
build_schema
echo "==> Database ready."
