#!/usr/bin/env bash
# Per-boot reconciliation: bring MySQL up before the service terminals start.
set -euo pipefail

echo "==> Starting MySQL"
sudo service mysql start || true

echo "==> Waiting for MySQL readiness"
for _ in $(seq 1 30); do
  if sudo mysqladmin ping --silent 2>/dev/null; then
    echo "MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "MySQL did not become ready in time." >&2
exit 1
