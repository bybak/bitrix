#!/usr/bin/env bash
# Прогрев Redis-кэша списка брендов для /bitrix/admin/mf_catalog_export.php (селект «Бренд»).
set -euo pipefail

CONTAINER=bitrix_php
LOG_PREFIX="[ce_brand_cache]"

if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER"; then
	exec docker exec -i "$CONTAINER" php /var/www/html/mf_refresh_ce_brand_choices_cache.php "$@"
else
	echo "$LOG_PREFIX docker container $CONTAINER not running — run PHP on host:" >&2
	PHP_SCRIPT="$(cd "$(dirname "$0")/.." && pwd)/www/mf_refresh_ce_brand_choices_cache.php"
	if [[ ! -f "$PHP_SCRIPT" ]]; then
		echo "$LOG_PREFIX missing $PHP_SCRIPT" >&2
		exit 2
	fi
	exec php "$PHP_SCRIPT" "$@"
fi
