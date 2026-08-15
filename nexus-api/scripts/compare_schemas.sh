#!/usr/bin/env bash
# NEXUS — Équivalence full_schema.sql <-> migrate.sh (§5, §6).
#
# Installe la base par les deux chemins, dans deux bases distinctes, puis
# compare la structure via information_schema :
#   tables, colonnes, types, nullabilité, valeurs par défaut, ENUM,
#   index (unicité, ordre) et clés étrangères.
#
# Sortie : tableau PASS/FAIL + diff détaillé. Code 1 à la moindre différence.
#
# Usage : bash scripts/compare_schemas.sh [hôte] [utilisateur] [motdepasse]

set -uo pipefail

HOST="${1:-127.0.0.1}"
USER="${2:-nexus}"
# `${3:-defaut}` remplacerait aussi une chaîne VIDE fournie explicitement par
# le défaut — or « pas de mot de passe » est un cas légitime (conteneur CI
# lancé avec MYSQL_ALLOW_EMPTY_PASSWORD). `${3-defaut}`, sans les deux-points,
# ne substitue que si l'argument est ABSENT.
PASS="${3-nexus_dev_pw}"

DB_MIG="${DB_MIG:-nexus_ref}"
DB_FULL="${DB_FULL:-nexus_full}"

DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Mot de passe VIDE = « pas de mot de passe », et non « mot de passe vide ».
# `mysql -p""` envoie une chaîne vide comme mot de passe, ce que MySQL refuse
# (ERROR 1045) au lieu de se connecter sans authentification par mot de passe.
# L'option doit alors être omise entièrement — c'est le cas du conteneur
# mysql:8.0 de la CI, lancé avec MYSQL_ALLOW_EMPTY_PASSWORD.
if [[ -n "$PASS" ]]; then
  MYSQL=(mysql -h"$HOST" -P3306 -u"$USER" -p"$PASS")
else
  MYSQL=(mysql -h"$HOST" -P3306 -u"$USER")
fi
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# Liste des fichiers SQL : lue depuis database/migrations.manifest
# (source de vérité unique, partagée avec database/migrate.sh).
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
if [[ ${#FILES[@]} -eq 0 ]]; then
  echo "ERREUR : aucune migration listée dans $MANIFEST" >&2
  exit 1
fi

echo "NEXUS — Comparaison des deux modes d'installation"
echo "================================================="
echo

# ── Chemin 1 : migrations ───────────────────────────────────────────────────
echo "==> [1/2] Installation par migrate.sh -> \`$DB_MIG\`"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_MIG\`;
                  CREATE DATABASE \`$DB_MIG\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
MIG_ERR=0
for f in "${FILES[@]}"; do
  if ! sed 's/CREATE DATABASE[^;]*;//Ig; s/USE `\?nexus`\?[[:space:]]*;//Ig' "$DIR/$f" \
      | "${MYSQL[@]}" "$DB_MIG" 2>>"$TMP/mig.err"; then
    MIG_ERR=1
  fi
done
grep -v "Using a password" "$TMP/mig.err" 2>/dev/null | grep -i error && MIG_ERR=1
[ "$MIG_ERR" -eq 0 ] && echo "    OK" || echo "    ERREURS (voir ci-dessus)"

# ── Chemin 2 : full_schema.sql ──────────────────────────────────────────────
echo "==> [2/2] Installation par full_schema.sql -> \`$DB_FULL\`"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_FULL\`;
                  CREATE DATABASE \`$DB_FULL\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
FULL_ERR=0
if ! "${MYSQL[@]}" "$DB_FULL" < "$DIR/database/full_schema.sql" 2>"$TMP/full.err"; then
  FULL_ERR=1
fi
grep -v "Using a password" "$TMP/full.err" 2>/dev/null | grep -i error && FULL_ERR=1
[ "$FULL_ERR" -eq 0 ] && echo "    OK" || echo "    ERREURS (voir ci-dessus)"
echo

# ── Extractions structurelles ───────────────────────────────────────────────
dump_tables() {
  "${MYSQL[@]}" -sN -e "
    SELECT table_name FROM information_schema.tables
    WHERE table_schema='$1' ORDER BY table_name;" 2>/dev/null
}

dump_columns() {
  "${MYSQL[@]}" -sN -e "
    SELECT CONCAT_WS('|', table_name, column_name, column_type, is_nullable,
                     COALESCE(column_default,'~'), COALESCE(extra,''))
    FROM information_schema.columns
    WHERE table_schema='$1' ORDER BY table_name, column_name;" 2>/dev/null
}

dump_indexes() {
  "${MYSQL[@]}" -sN -e "
    SELECT CONCAT_WS('|', table_name, index_name, non_unique,
                     GROUP_CONCAT(column_name ORDER BY seq_in_index))
    FROM information_schema.statistics
    WHERE table_schema='$1'
    GROUP BY table_name, index_name, non_unique
    ORDER BY table_name, index_name;" 2>/dev/null
}

dump_fks() {
  "${MYSQL[@]}" -sN -e "
    SELECT CONCAT_WS('|', k.table_name, k.column_name,
                     k.referenced_table_name, k.referenced_column_name,
                     r.delete_rule, r.update_rule)
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_name = k.constraint_name
     AND r.constraint_schema = k.table_schema
    WHERE k.table_schema='$1' AND k.referenced_table_name IS NOT NULL
    ORDER BY k.table_name, k.column_name;" 2>/dev/null
}

for kind in tables columns indexes fks; do
  "dump_$kind" "$DB_MIG"  > "$TMP/$kind.mig"
  "dump_$kind" "$DB_FULL" > "$TMP/$kind.full"
done

status_of() {
  diff -q "$TMP/$1.mig" "$TMP/$1.full" >/dev/null 2>&1 && echo "MATCH" || echo "DIFF"
}

T_TABLES=$(status_of tables)
T_COLS=$(status_of columns)
T_IDX=$(status_of indexes)
T_FK=$(status_of fks)

N_TABLES=$(wc -l < "$TMP/tables.mig")
N_COLS=$(wc -l < "$TMP/columns.mig")
N_IDX=$(wc -l < "$TMP/indexes.mig")
N_FK=$(wc -l < "$TMP/fks.mig")
N_UNIQ=$(awk -F'|' '$3==0' "$TMP/indexes.mig" | wc -l)
N_ENUM=$(awk -F'|' '$3 ~ /^enum/' "$TMP/columns.mig" | wc -l)

echo "RÉSULTATS"
echo "---------"
printf "  %-32s %s\n" "MIGRATION INSTALLATION" "$([ "$MIG_ERR" -eq 0 ] && echo PASS || echo FAIL)"
printf "  %-32s %s\n" "FULL SCHEMA INSTALLATION" "$([ "$FULL_ERR" -eq 0 ] && echo PASS || echo FAIL)"
printf "  %-32s %s (%s)\n" "TABLE COUNT" "$T_TABLES" "$N_TABLES"
printf "  %-32s %s (%s colonnes)\n" "COLUMN STRUCTURE" "$T_COLS" "$N_COLS"
printf "  %-32s %s (%s)\n" "INDEXES" "$T_IDX" "$N_IDX"
printf "  %-32s %s (%s)\n" "FOREIGN KEYS" "$T_FK" "$N_FK"
printf "  %-32s %s (%s colonnes ENUM)\n" "ENUMS" "$T_COLS" "$N_ENUM"
printf "  %-32s %s\n" "UNIQUE CONSTRAINTS" "$N_UNIQ"
echo

FAILED=0
[ "$MIG_ERR" -ne 0 ] && FAILED=1
[ "$FULL_ERR" -ne 0 ] && FAILED=1

# Deux bases VIDES sont trivialement identiques : sans ce garde-fou, un échec
# de connexion produit « MATCH (0) » partout et un rapport rassurant. Une
# installation qui ne crée aucune table est un échec, pas une équivalence.
if [ "$N_TABLES" -eq 0 ]; then
  FAILED=1
  echo "ERREUR : aucune table installée — comparaison sans objet."
  echo "         (deux bases vides sont identiques : ce n'est pas une équivalence)"
  echo
fi
for kind in tables columns indexes fks; do
  if ! diff -q "$TMP/$kind.mig" "$TMP/$kind.full" >/dev/null 2>&1; then
    FAILED=1
    echo "DIFFÉRENCE — $kind (< migrations | > full_schema) :"
    diff "$TMP/$kind.mig" "$TMP/$kind.full" | head -40
    echo
  fi
done

if [ "$FAILED" -eq 0 ]; then
  echo "SCHEMA EQUIVALENCE               PASS"
  echo
  echo "Les deux modes d'installation produisent une structure identique."
else
  echo "SCHEMA EQUIVALENCE               FAIL"
fi

exit "$FAILED"
