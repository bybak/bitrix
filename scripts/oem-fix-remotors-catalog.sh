#!/bin/bash
# Remotors OEM catalog: fix existing DB + plan gap re-crawl.
# Run from project root (where docker-compose.yml lives).
#
# Usage:
#   bash scripts/oem-fix-remotors-catalog.sh          # dry-run + ask before apply
#   bash scripts/oem-fix-remotors-catalog.sh --apply   # apply without dry-run / prompt
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose"
if [ -f docker-compose.prod.yml ]; then
  COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
fi

APPLY=0
if [ "${1:-}" = "--apply" ]; then
  APPLY=1
fi

echo "=== 0. Rebuild + backup OEM PostgreSQL ==="
mkdir -p storage
$COMPOSE up -d --build oem_backend oem_db
$COMPOSE exec -T oem_db pg_dump -U oem_user -d oem_catalog -Fc --no-owner --no-acl \
  > "storage/oem_catalog_before_fix_$(date +%Y%m%d_%H%M).dump"
echo "Backup written to storage/"

echo ""
echo "=== 1. Diagnose ==="
$COMPOSE exec -T oem_backend python -m app.cli diagnose-remotors \
  --output /app/storage/remotors-diagnose.json
echo "OK: storage/remotors-diagnose.json"

if [ "$APPLY" = "1" ]; then
  echo ""
  echo "=== 2. Apply fix (skip dry-run) ==="
  $COMPOSE exec -T oem_backend python -m app.cli fix-remotors-catalog --apply
  echo "OK: fix applied"
else
  echo ""
  echo "=== 2. Fix catalog (dry-run) ==="
  $COMPOSE exec -T oem_backend python -m app.cli fix-remotors-catalog
  echo "OK: dry-run finished"

  echo ""
  echo "=== 3. Apply fix ==="
  read -r -p "Apply DB fixes? [y/N] " ans
  if [ "$ans" = "y" ] || [ "$ans" = "Y" ]; then
    $COMPOSE exec -T oem_backend python -m app.cli fix-remotors-catalog --apply
    echo "OK: fix applied"
  else
    echo "Skipped apply."
  fi
fi

echo ""
echo "=== Plan gap re-crawl (variant mode, after duplicate-variant fix) ==="
echo "Tip: run duplicate-variant merge first if UI shows twin variants:"
echo "  docker compose exec -T oem_backend python -m app.cli fix-remotors-duplicate-variants --apply"
$COMPOSE exec -T oem_backend python -m app.cli plan-recrawl-remotors \
  --mode variant \
  --output /app/storage/remotors-recrawl-variants.sh
chmod +x storage/remotors-recrawl-variants.sh
echo "OK: storage/remotors-recrawl-variants.sh"

echo ""
echo "=== Done ==="
echo "Gap re-crawl (local, when ready):"
echo "  bash storage/remotors-recrawl-variants.sh 2>&1 | tee -a storage/remotors-recrawl-variants.log"
