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

echo "==> Composer dependencies (nexus-api)"
( cd "$ROOT/nexus-api" && composer install --no-interaction --no-progress )

echo "==> MySQL + database schema"
# Shared with .cursor/start.sh: starts MySQL (reinitialising the data dir if a
# snapshot-restored one is unusable on this filesystem) and builds the schema.
bash "$ROOT/.cursor/setup-db.sh"

echo "==> Node dependencies (nexus-frontend, agents)"
( cd "$ROOT/nexus-frontend" && npm ci )
( cd "$ROOT/agents" && npm ci )

echo "==> Install complete."
