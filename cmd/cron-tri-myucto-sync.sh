#!/usr/bin/env bash
#  cron-tri-myucto-sync.sh — pull zrcadla MyÚčta (klienti, projekty, faktury)
#  crontab:
#    */5 * * * *  /var/www/office/cmd/cron-tri-myucto-sync.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-tri-myucto-sync.php" "$@" \
    >> "$LOG_DIR/tri-myucto-sync-$(date +%Y-%m-%d).log" 2>&1
