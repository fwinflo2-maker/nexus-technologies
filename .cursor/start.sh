#!/usr/bin/env bash
# Per-boot reconciliation: bring MySQL up before the service terminals start.
# Robust against cold snapshot restores: the SysV init script only pings for
# ~30s, which can time out on first-boot disk I/O, so we poll for readiness
# ourselves and re-issue the start command across several attempts.
set -uo pipefail

ensure_mysql() {
  # The runtime dir lives on tmpfs and is empty on a fresh boot.
  sudo mkdir -p /var/run/mysqld
  sudo chown mysql:mysql /var/run/mysqld 2>/dev/null || true

  # Defensive: ensure the container/FUSE-compatible InnoDB settings exist even
  # if this boot's base image predates the install script writing them.
  if [ ! -f /etc/mysql/mysql.conf.d/zz-cloud-agent.cnf ]; then
    sudo tee /etc/mysql/mysql.conf.d/zz-cloud-agent.cnf >/dev/null <<'CNF'
[mysqld]
innodb_use_native_aio = 0
innodb_flush_method = fsync
CNF
  fi

  for attempt in 1 2 3 4; do
    sudo service mysql start >/dev/null 2>&1 || true
    for _ in $(seq 1 45); do
      if sudo mysqladmin ping --silent 2>/dev/null; then
        echo "MySQL is up (attempt ${attempt})."
        return 0
      fi
      sleep 1
    done
    echo "MySQL not ready after attempt ${attempt}; retrying..."
  done
  return 1
}

echo "==> Ensuring MySQL is running"
if ensure_mysql; then
  exit 0
fi

echo "MySQL did not become ready in time." >&2
sudo tail -n 30 /var/log/mysql/error.log 2>/dev/null || true
exit 1
