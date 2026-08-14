#!/usr/bin/env bash
# NEXUS — Génération de database/full_schema.sql (§1).
#
# Le fichier complet n'est pas écrit à la main : il est *dérivé* de la base
# reconstruite par le runner de migrations, puis vérifié par comparaison. C'est
# la seule façon de garantir que `full_schema.sql` et `migrate.sh` ne divergent
# pas (§5).
#
# Usage : bash scripts/build_full_schema.sh [hôte] [utilisateur] [motdepasse]

set -euo pipefail

HOST="${1:-127.0.0.1}"
USER="${2:-nexus}"
PASS="${3:-nexus_dev_pw}"
BUILD_DB="${BUILD_DB:-nexus_ref}"

DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$DIR/database/full_schema.sql"

MYSQL=(mysql -h"$HOST" -P3306 -u"$USER" -p"$PASS")

# Ordre canonique, identique à migrate.sh.
FILES=(
  "database/schema.sql"
  "database/migrations/2026_08_10_oauth_phone.sql"
  "database/migrations/2026_08_10_dashboard.sql"
  "database/migrations/2026_08_10_notifications.sql"
  "database/migrations/2026_08_10_payment_accounts.sql"
  "database/migrations/2026_08_10_provider_credentials.sql"
  "database/migrations/2026_08_10_quotes.sql"
  "database/migrations/2026_08_10_kyc_origins.sql"
  "database/migrations/2026_08_10_wallet_core.sql"
  "database/migrations/2026_08_11_add_hold_operation_type.sql"
  "database/migrations/2026_08_12_add_expires_at_to_wallet_operations.sql"
  "database/migrations/2026_08_14_transfer_execution.sql"
  "database/migrations/2026_08_14_business_suite.sql"
)

echo "==> Reconstruction de \`$BUILD_DB\` via les migrations"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$BUILD_DB\`;
                  CREATE DATABASE \`$BUILD_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

for f in "${FILES[@]}"; do
  # Les scripts ciblent la base `nexus` : on neutralise CREATE DATABASE / USE.
  sed 's/CREATE DATABASE[^;]*;//Ig; s/USE `\?nexus`\?[[:space:]]*;//Ig' "$DIR/$f" \
    | "${MYSQL[@]}" "$BUILD_DB"
  echo "    appliqué : $(basename "$f")"
done

echo "==> Export de la structure vers database/full_schema.sql"

{
  cat <<'HEADER'
-- =============================================================================
-- NEXUS — SCHÉMA COMPLET (structure seule)
--
-- Ce fichier permet de reconstruire l'intégralité de la base sur une instance
-- vierge, sans jouer les migrations une par une :
--
--     DROP DATABASE IF EXISTS nexus;
--     CREATE DATABASE nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--     mysql nexus < database/full_schema.sql
--
-- IMPORTANT
--   * Fichier GÉNÉRÉ — ne pas éditer à la main.
--     Régénérer avec : bash scripts/build_full_schema.sh
--   * STRUCTURE UNIQUEMENT : aucune donnée métier, aucun solde, aucune
--     transaction, aucun provider actif. Les jeux de démonstration vivent
--     dans database/seeds/ et ne doivent jamais être joués en production.
--   * Équivalence avec le runner de migrations vérifiée par
--     scripts/compare_schemas.sh (tables, colonnes, types, index, clés
--     étrangères, ENUM, valeurs par défaut, nullabilité).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

HEADER

  mysqldump -h"$HOST" -P3306 -u"$USER" -p"$PASS" \
    --no-data \
    --skip-comments \
    --skip-set-charset \
    --skip-add-drop-table \
    --single-transaction \
    --routines=FALSE \
    --triggers=FALSE \
    "$BUILD_DB" \
    | sed 's/ AUTO_INCREMENT=[0-9]*//g'

  cat <<'FOOTER'

SET FOREIGN_KEY_CHECKS = 1;
FOOTER
} > "$OUT"

TABLES=$("${MYSQL[@]}" -sN -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BUILD_DB';")

echo "==> Terminé : $OUT ($TABLES tables)"
