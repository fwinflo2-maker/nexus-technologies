#!/usr/bin/env bash
# NEXUS — Démarre le sandbox pawaPay (protocole wire réel, signatures RFC-9421).
#
#   bash scripts/provider_sandbox/run.sh [port] [mode]
#
#   port : défaut 8901
#   mode : instant (COMPLETED immédiat) | async (ACCEPTED puis flip manuel)
#
# Le webhook Nexus doit pointer vers ce serveur :
#   PAWAPAY_HARNESS_CALLBACK_URL=http://127.0.0.1:8080/api/providers/webhook/pawapay
#
# Côté Nexus, l'adaptateur joint ce serveur via :
#   PROVIDER_PAWAPAY_SANDBOX_BASE_URL=http://127.0.0.1:8901

set -euo pipefail

PORT="${1:-8901}"
MODE="${2:-instant}"

DIR="$(cd "$(dirname "$0")" && pwd)"

export PAWAPAY_HARNESS_MODE="$MODE"
export PAWAPAY_HARNESS_TOKEN="${PAWAPAY_HARNESS_TOKEN:-harness_test_token}"
export PAWAPAY_HARNESS_CALLBACK_URL="${PAWAPAY_HARNESS_CALLBACK_URL:-http://127.0.0.1:8080/api/providers/webhook/pawapay}"

echo "==> pawaPay sandbox harness (mode=$MODE) sur http://127.0.0.1:$PORT"
echo "    token    : $PAWAPAY_HARNESS_TOKEN"
echo "    callback : $PAWAPAY_HARNESS_CALLBACK_URL"

php -S "127.0.0.1:$PORT" "$DIR/server.php"
