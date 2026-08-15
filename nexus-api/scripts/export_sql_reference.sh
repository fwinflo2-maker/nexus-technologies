#!/usr/bin/env bash
# NEXUS — Export SQL de référence vers database/sql/ (§8, §9, §10).
#
# Produit les fichiers SQL de référence du projet à partir de la base
# RÉELLEMENT installée dans l'environnement, jamais d'un contenu écrit à la
# main :
#
#   database/sql/nexus_schema.sql   structure seule (aucune donnée)
#   database/sql/nexus_seed.sql     données de référence (jeux de démo)
#   database/sql/nexus_full.sql     structure + données de référence
#
# La base source est reconstruite par le runner de migrations, comme le fait
# build_full_schema.sh : le SQL exporté décrit donc exactement ce que le
# dépôt sait installer, et non l'état accidentel d'un poste de travail.
#
# Usage : bash scripts/export_sql_reference.sh [hôte] [utilisateur] [motdepasse]

set -euo pipefail

HOST="${1:-127.0.0.1}"
USER="${2:-nexus}"
# `${3-defaut}` (sans deux-points) : une chaîne vide EXPLICITE est un cas
# légitime — serveur sans mot de passe — et ne doit pas être remplacée par le
# défaut. Même piège que celui corrigé dans compare_schemas.sh.
PASS="${3-nexus_dev_pw}"

BUILD_DB="${BUILD_DB:-nexus_sqlref}"

DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="$DIR/database/sql"

# Mot de passe vide = option omise, sinon MySQL renvoie ERROR 1045.
if [[ -n "$PASS" ]]; then
  MYSQL=(mysql -h"$HOST" -P3306 -u"$USER" -p"$PASS")
  DUMP=(mysqldump -h"$HOST" -P3306 -u"$USER" -p"$PASS")
else
  MYSQL=(mysql -h"$HOST" -P3306 -u"$USER")
  DUMP=(mysqldump -h"$HOST" -P3306 -u"$USER")
fi

mkdir -p "$OUT_DIR"

# ── Reconstruction de la base de référence ───────────────────────────────────
MANIFEST="$DIR/database/migrations.manifest"
if [[ ! -f "$MANIFEST" ]]; then
  echo "ERREUR : manifeste introuvable : $MANIFEST" >&2
  exit 1
fi

FILES=("database/schema.sql")
while IFS= read -r line; do
  line="${line%%#*}"
  line="$(echo "$line" | xargs)"
  [[ -z "$line" ]] && continue
  FILES+=("database/migrations/$line")
done < "$MANIFEST"

echo "==> Reconstruction de \`$BUILD_DB\` via les migrations"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$BUILD_DB\`;
                  CREATE DATABASE \`$BUILD_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

for f in "${FILES[@]}"; do
  sed 's/CREATE DATABASE[^;]*;//Ig; s/USE `\?nexus`\?[[:space:]]*;//Ig' "$DIR/$f" \
    | "${MYSQL[@]}" "$BUILD_DB"
done
echo "    ${#FILES[@]} fichier(s) appliqué(s)"

# MariaDB rend les colonnes JSON sous leur forme interne
# (`longtext … CHECK (json_valid(…))`), que MySQL 8 ne reconnaît pas comme un
# type JSON. On renormalise, exactement comme build_full_schema.sh.
normalize_json() {
  sed -E 's/`([a-z_]+)` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin (DEFAULT NULL|NOT NULL) CHECK \(json_valid\(`\1`\)\)/`\1` json \2/g'
}

header() {
  cat <<HEADER
-- =============================================================================
-- NEXUS — $1
--
-- Fichier GÉNÉRÉ depuis la base réellement installée par le runner de
-- migrations. Ne pas éditer à la main.
--   Régénérer : bash scripts/export_sql_reference.sh
--
-- $2
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

HEADER
}

footer() {
  printf '\nSET FOREIGN_KEY_CHECKS = 1;\n'
}

# ── 1. Structure seule ───────────────────────────────────────────────────────
echo "==> database/sql/nexus_schema.sql (structure)"
{
  header "SCHÉMA DE RÉFÉRENCE (structure seule)" \
         "Aucune donnée : ni compte, ni solde, ni transaction, ni credential."
  "${DUMP[@]}" --no-data --skip-comments --skip-set-charset \
    --skip-add-drop-table --single-transaction \
    --routines=FALSE --triggers=FALSE "$BUILD_DB" \
    | sed 's/ AUTO_INCREMENT=[0-9]*//g' | normalize_json
  footer
} > "$OUT_DIR/nexus_schema.sql"

# ── 2. Données de référence ──────────────────────────────────────────────────
# Les seeds sont des jeux de DÉMONSTRATION : ils ne doivent jamais être joués
# en production (§15). Le fichier le rappelle explicitement.
echo "==> database/sql/nexus_seed.sql (données de référence)"
{
  cat <<'SEEDHEADER'
-- =============================================================================
-- NEXUS — DONNÉES DE RÉFÉRENCE (DÉMONSTRATION UNIQUEMENT)
--
-- Fichier GÉNÉRÉ : concaténation de database/seeds/.
--   Régénérer : bash scripts/export_sql_reference.sh
--
-- AVERTISSEMENT (§15) : ces jeux sont des données de DÉMONSTRATION. Ils ne
-- doivent JAMAIS être chargés dans un environnement de production. Toute
-- donnée issue de ce fichier appartient à l'environnement « sandbox ».
-- =============================================================================

SET NAMES utf8mb4;

SEEDHEADER
  for seed in "$DIR"/database/seeds/*.sql; do
    [[ -e "$seed" ]] || continue
    printf -- '-- ─── %s ───\n' "$(basename "$seed")"
    cat "$seed"
    printf '\n'
  done
} > "$OUT_DIR/nexus_seed.sql"

# ── 3. Structure + données de référence ──────────────────────────────────────
echo "==> database/sql/nexus_full.sql (structure + référence)"
{
  header "BASE COMPLÈTE (structure + données de référence)" \
          "Les données incluses sont des jeux de DÉMONSTRATION (§15)."
  "${DUMP[@]}" --no-data --skip-comments --skip-set-charset \
    --skip-add-drop-table --single-transaction \
    --routines=FALSE --triggers=FALSE "$BUILD_DB" \
    | sed 's/ AUTO_INCREMENT=[0-9]*//g' | normalize_json
  printf '\n-- ─── Données de référence (démonstration) ───\n'
  for seed in "$DIR"/database/seeds/*.sql; do
    [[ -e "$seed" ]] || continue
    printf -- '-- %s\n' "$(basename "$seed")"
    cat "$seed"
    printf '\n'
  done
  footer
} > "$OUT_DIR/nexus_full.sql"

TABLES=$("${MYSQL[@]}" -sN -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BUILD_DB';")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$BUILD_DB\`;"

echo "==> Terminé : $TABLES tables exportées vers database/sql/"
