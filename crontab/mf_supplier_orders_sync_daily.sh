#!/usr/bin/env bash
# Ежедневный sync заказов поставщиков (Bitrix, внутри bitrix_php).
set -euo pipefail

CONTAINER=bitrix_php
IN_CONTAINER="/var/www/html/tools/mf_supplier_orders_sync.php"
LOG_PREFIX="[mf_supplier_orders_sync]"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "$LOG_PREFIX ERROR: container $CONTAINER is not running" >&2
  exit 2
fi

if ! docker exec -i "$CONTAINER" sh -c "test -f \"$IN_CONTAINER\""; then
  echo "$LOG_PREFIX ERROR: script missing: $IN_CONTAINER" >&2
  exit 3
fi

exec docker exec -i "$CONTAINER" php "$IN_CONTAINER"
