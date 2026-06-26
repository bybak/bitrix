#!/bin/bash
# Read-only audit: local OEM DB vs Remotors/ARI API (no crawl, no DB/image writes).
# Can take many hours — run in tmux/screen.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose"
if [ -f docker-compose.prod.yml ]; then
  COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
fi

STAMP="$(date +%Y%m%d_%H%M)"
OUT="/app/storage/remotors-audit-${STAMP}.json"
LOG="/app/storage/remotors-audit-${STAMP}.log"

$COMPOSE up -d oem_db oem_backend

# oem_backend copies app/ into the image at build time; without a bind mount or rebuild
# exec runs stale code and the audit looks frozen after queueing all brands.
if ! $COMPOSE exec -T oem_backend grep -q "walking tree" /app/app/importers/remotors_audit.py 2>/dev/null; then
  echo "Rebuilding oem_backend (audit script updated in image)..."
  $COMPOSE build oem_backend
  $COMPOSE up -d oem_backend
fi

echo "=== Remotors catalog audit (read-only) ==="
echo "Report: storage/remotors-audit-${STAMP}.json"
echo "Log:    storage/remotors-audit-${STAMP}.log"
echo ""

$COMPOSE exec -T oem_backend python -m app.cli audit-remotors-catalog \
  --output "$OUT" \
  --log "$LOG"

echo ""
echo "=== Done ==="
