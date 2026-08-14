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

MIGRATIONS=(
  # 0.2 — Auth étendue (Google OAuth + téléphone)
  "2026_08_10_oauth_phone.sql"
  # 0.3 — Dashboard (états de solde sur wallets)
  "2026_08_10_dashboard.sql"
  # 0.4 — Notifications + comptes de paiement
  "2026_08_10_notifications.sql"
  "2026_08_10_payment_accounts.sql"
  # 0.5 — Credentials providers (chiffrées)
  "2026_08_10_provider_credentials.sql"
  # 0.6 — Quotes + KYC résidence/origines
  "2026_08_10_quotes.sql"
  "2026_08_10_kyc_origins.sql"
  # 0.7 — Wallet Core (ledger double-entrée, holds, idempotence, fx cache)
  "2026_08_10_wallet_core.sql"
  # 0.8 — Hold lifecycle + expiration des opérations
  "2026_08_11_add_hold_operation_type.sql"
  "2026_08_12_add_expires_at_to_wallet_operations.sql"
  # 0.9 — Transfer execution (traçabilité quote/route/montants)
  "2026_08_14_transfer_execution.sql"
  # 0.10 — Business suite (bénéficiaires, paiements, équipe, réconciliation)
  "2026_08_14_business_suite.sql"
)

for m in "${MIGRATIONS[@]}"; do
  echo "==> Migration $m"
  "${MYSQL[@]}" < "$DIR/migrations/$m"
done

echo "==> Terminé : schéma + ${#MIGRATIONS[@]} migrations appliquées."
