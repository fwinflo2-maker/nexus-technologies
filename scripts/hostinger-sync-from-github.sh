#!/bin/bash
# Synchronise les fichiers Nexus depuis GitHub (branche main) vers public_html Hostinger.
# Ne touche jamais .env, uploads/, vendor/ production.

set -euo pipefail

BASE="${NEXUS_WEB_ROOT:-$HOME/domains/nexustechnologies.cloud/public_html}"
REPO="${NEXUS_GITHUB_RAW:-https://raw.githubusercontent.com/fwinflo2-maker/nexus-technologies/main}"
MANIFEST="${NEXUS_SYNC_MANIFEST:-$(dirname "$0")/hostinger-sync.manifest}"

if [[ ! -f "$MANIFEST" ]]; then
  MANIFEST="$(mktemp)"
  curl -fsSL "$REPO/scripts/hostinger-sync.manifest" -o "$MANIFEST"
  trap 'rm -f "$MANIFEST"' EXIT
fi

while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line%%#*}"
  line="$(echo "$line" | xargs)"
  [[ -z "$line" ]] && continue

  dest_path="${line#htdocs/}"
  dest="$BASE/$dest_path"
  mkdir -p "$(dirname "$dest")"
  curl -fsSL "$REPO/$line" -o "$dest"
  echo "synced: $line"
done < "$MANIFEST"

# Migration idempotente provider_customers (ignore si déjà appliquée).
if [[ -f "$BASE/api-app/scripts/apply_provider_customers_migration.php" ]]; then
  php "$BASE/api-app/scripts/apply_provider_customers_migration.php" || true
fi

# Migration idempotente cashramp_integration (ignore si déjà appliquée).
if [[ -f "$BASE/api-app/scripts/apply_cashramp_integration_migration.php" ]]; then
  php "$BASE/api-app/scripts/apply_cashramp_integration_migration.php" || true
fi

# Purge cache Hostinger / LiteSpeed si disponible.
if command -v cache-purge >/dev/null 2>&1; then
  cache-purge "$BASE" || true
fi

echo "Hostinger sync complete."
