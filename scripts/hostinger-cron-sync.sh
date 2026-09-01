#!/bin/bash
# Wrapper exécutable via cron Hostinger (télécharge le script canonique depuis GitHub).
set -euo pipefail
R=https://raw.githubusercontent.com/fwinflo2-maker/nexus-technologies/main
curl -fsSL "$R/scripts/hostinger-sync-from-github.sh" | bash
