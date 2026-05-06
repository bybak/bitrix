#!/usr/bin/env bash
# Импорт остатков/цен в Bitrix из CSV PilotMoto (внутри контейнера путь — см. volume в docker-compose).
set -euo pipefail

CONTAINER=bitrix_php
IN_CONTAINER_FILE=/var/www/html/_catalog_scrape/pilotmoto/stock_and_price.csv
LOG_PREFIX="[pilotmoto_bitrix]"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "$LOG_PREFIX ERROR: container $CONTAINER is not running" >&2
  exit 2
fi

if ! docker exec -i "$CONTAINER" sh -c "test -f \"$IN_CONTAINER_FILE\""; then
  echo "$LOG_PREFIX ERROR: file missing in container: $IN_CONTAINER_FILE" >&2
  exit 3
fi

exec docker exec -i "$CONTAINER" php /var/www/html/mf_update_supplier_stock.php \
  --apply \
  --warehouse-code=PilotMoto \
  --warehouse-title=PilotMoto \
  --file="$IN_CONTAINER_FILE" \
  --encoding=utf-8 \
  --price=Y \
  --recalc-base=Y \
  --sync-missing=Y
