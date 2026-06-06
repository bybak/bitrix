#!/usr/bin/env bash
# Очистка mf_stock_import_missing: записи старше 7 дней (UF_LAST_SEEN).
# Запускать 1 раз в сутки после импортов складов, не из mf_update_supplier_stock.php.
set -euo pipefail

CONTAINER=bitrix_php
IN_CONTAINER="/var/www/html/tools/mf_stock_import_missing_purge.php"
LOG_PREFIX="[mf_stock_import_missing_purge]"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "$LOG_PREFIX ERROR: container $CONTAINER is not running" >&2
  exit 2
fi

if ! docker exec -i "$CONTAINER" sh -c "test -f \"$IN_CONTAINER\""; then
  echo "$LOG_PREFIX ERROR: script missing: $IN_CONTAINER" >&2
  exit 3
fi

exec docker exec -i "$CONTAINER" php "$IN_CONTAINER" --days=7 --ensure-index "$@"
