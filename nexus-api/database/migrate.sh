#!/usr/bin/env bash
# NEXUS — Migration runner (ordre de version canonique).
#
# Usage :  bash database/migrate.sh [mysql_host] [mysql_user] [mysql_pass]
# Défaut : 127.0.0.1 / nexus / nexus_dev_pw  (configurable)
#
# Applique le schéma de base puis toutes les migrations en ordre de version.
# Chaque migration est idempotente : réexécuter ce script est sans effet.
# Usage :  bash database/migrate.sh [mysql_host] [mysql_user] [mysql_pass]
# Défaut : 127.0.0.1 / nexus / nexus_dev_pw  (configurable)
#
# Applique le schéma de base puis toutes les migrations en ordre de version.
# Chaque migration est idempotente : réexécuter ce script est sans effet.

set -euo pipefail

HOST="${1:-127.0.0.1}"
USER="${2:-nexus}"
PASS="${3:-nexus_dev_pw}"

MYSQL=(mysql -h"$HOST" -P3306 -u"$USER" -p"$PASS")

DIR="$(cd "$(dirname "$0")" && pwd)"

echo "==> Schéma de base (schema.sql)"
"${MYSQL[@]}" < "$DIR/schema.sql"

# Liste des migrations : lue depuis database/migrations.manifest
# (source de vérité unique, partagée avec build_full_schema.sh et compare_schemas.sh).
MANIFEST="$DIR/migrations.manifest"
if [[ ! -f "$MANIFEST" ]]; then
  echo "ERREUR : manifeste introuvable : $MANIFEST" >&2
  exit 1
fi
MIGRATIONS=()
while IFS= read -r line; do
  line="${line%%#*}"
  line="$(echo "$line" | xargs)"
  [[ -z "$line" ]] && continue
  MIGRATIONS+=("$line")
done < "$MANIFEST"
if [[ ${#MIGRATIONS[@]} -eq 0 ]]; then
  echo "ERREUR : aucune migration listée dans $MANIFEST" >&2
  exit 1
fi

for m in "${MIGRATIONS[@]}"; do
  echo "==> Migration $m"
  "${MYSQL[@]}" < "$DIR/migrations/$m"
done

echo "==> Terminé : schéma + ${#MIGRATIONS[@]} migrations appliquées."
echo
echo "Structure seule : aucune donnée de démonstration n'a été insérée."
echo "Jeux de démo (SANDBOX UNIQUEMENT, jamais en production) :"
echo "  mysql ... < database/seeds/demo_fx_rates.sql"
echo "  (echo 'SET @NEXUS_ALLOW_DEMO_SEED = 1;'; cat database/seeds/demo_payment_accounts.sql) | mysql ..."
