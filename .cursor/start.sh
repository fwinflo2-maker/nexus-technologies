#!/usr/bin/env bash
# Per-boot reconciliation: bring MySQL up and ensure the schema exists before
# the service terminals start.
#
# On Cloud Agent build pods the snapshot-restored MySQL data directory can be
# unreadable by InnoDB (unconditional O_DIRECT probe fails on the container/FUSE
# filesystem). setup-db.sh reinitialises a fresh data directory in that case and
# rebuilds the schema, so this must run on every boot — not just at install.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
bash "$ROOT/.cursor/setup-db.sh"
