#!/bin/bash
# Remotors OEM: close the gap with remotors.com (minimal-time strategy).
#
# Run from project root in tmux/screen — crawls can take days.
#
# Usage:
#   bash scripts/oem-sync-remotors.sh              # prep + generate scripts (no crawl)
#   bash scripts/oem-sync-remotors.sh --apply-keys # also backfill assembly keys in DB
#   bash scripts/oem-sync-remotors.sh --phase crawl-brp   # start BRP full crawl (tmux)
#   bash scripts/oem-sync-remotors.sh --phase crawl-ktm   # KTM+HUM+LNX full crawl
#   bash scripts/oem-sync-remotors.sh --phase variants    # targeted variant repair
#   bash scripts/oem-sync-remotors.sh --phase verify      # audit only
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose"
if [ -f docker-compose.prod.yml ]; then
  COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
fi

PHASE="${1:-prep}"
APPLY_KEYS=0
if [ "${1:-}" = "--apply-keys" ]; then
  PHASE="prep"
  APPLY_KEYS=1
  shift || true
fi
if [ "${1:-}" = "--phase" ]; then
  PHASE="${2:-prep}"
fi

mkdir -p storage
STAMP="$(date +%Y%m%d_%H%M)"

ensure_backend() {
  $COMPOSE up -d oem_db oem_backend
  if ! $COMPOSE exec -T oem_backend grep -q "assembly_compare_key" /app/app/importers/remotors_catalog.py 2>/dev/null; then
    echo "Rebuilding oem_backend (code updated)..."
    $COMPOSE build oem_backend
    $COMPOSE up -d oem_backend
  fi
}

case "$PHASE" in
  prep)
    ensure_backend
    echo "=== Remotors sync: prep ==="
    echo ""
    echo "--- Backup ---"
    $COMPOSE exec -T oem_db pg_dump -U oem_user -d oem_catalog -Fc --no-owner --no-acl \
      > "storage/oem_catalog_before_sync_${STAMP}.dump"
    echo "Backup: storage/oem_catalog_before_sync_${STAMP}.dump"
    echo ""
    echo "--- Diagnose ---"
    $COMPOSE exec -T oem_backend python -m app.cli diagnose-remotors \
      --output "/app/storage/remotors-diagnose-${STAMP}.json"
    echo "Report: storage/remotors-diagnose-${STAMP}.json"
    echo ""
    echo "--- Backfill assembly keys (dry-run) ---"
    $COMPOSE exec -T oem_backend python -m app.cli backfill-remotors-assembly-keys
    if [ "$APPLY_KEYS" = "1" ]; then
      echo ""
      echo "--- Backfill assembly keys (apply) ---"
      $COMPOSE exec -T oem_backend python -m app.cli backfill-remotors-assembly-keys --apply
    fi
    echo ""
    echo "--- Plan: under-crawled variants (<=15 assemblies) ---"
    $COMPOSE exec -T oem_backend python -m app.cli plan-recrawl-remotors \
      --mode under \
      --max-assemblies 15 \
      --limit 10000 \
      --output /app/storage/remotors-recrawl-under15.sh
    chmod +x storage/remotors-recrawl-under15.sh
    echo "Script: storage/remotors-recrawl-under15.sh"
    echo ""
    echo "--- Generate full-crawl helper scripts ---"
    cat > storage/remotors-crawl-brp.sh <<'EOF'
#!/bin/bash
# Full BRP tree: Can-Am + Sea-Doo + Ski-Doo (+ Lynx via LNX is separate).
# Adds missing ~1200 models and deepens assemblies. Use tmux.
# --no-images = 3-5x faster; run crawl again later without --no-images for PNGs.
set -euo pipefail
COMPOSE="docker compose"
[ -f docker-compose.prod.yml ] && COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
$COMPOSE exec -T oem_backend python -m app.cli crawl-remotors \
  --brands BRP \
  --confirm-full-crawl \
  --force \
  --no-images
EOF
    cat > storage/remotors-crawl-ktm-hum-lnx.sh <<'EOF'
#!/bin/bash
set -euo pipefail
COMPOSE="docker compose"
[ -f docker-compose.prod.yml ] && COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
$COMPOSE exec -T oem_backend python -m app.cli crawl-remotors \
  --brands KTM,HUM,LNX \
  --confirm-full-crawl \
  --force \
  --no-images
EOF
    chmod +x storage/remotors-crawl-brp.sh storage/remotors-crawl-ktm-hum-lnx.sh
    echo "Scripts:"
    echo "  storage/remotors-crawl-brp.sh"
    echo "  storage/remotors-crawl-ktm-hum-lnx.sh"
    echo ""
    echo "=== Next steps (in order) ==="
    echo "1. bash scripts/oem-sync-remotors.sh --apply-keys"
    echo "2. tmux new -s remotors-brp && bash storage/remotors-crawl-brp.sh 2>&1 | tee storage/remotors-crawl-brp.log"
    echo "3. tmux new -s remotors-ktm && bash storage/remotors-crawl-ktm-hum-lnx.sh 2>&1 | tee storage/remotors-crawl-ktm.log"
    echo "4. bash storage/remotors-recrawl-under15.sh 2>&1 | tee storage/remotors-recrawl-under15.log"
    echo "5. docker compose exec -T oem_backend python -m app.cli fix-remotors-duplicate-variants --apply"
    echo "6. bash scripts/oem-sync-remotors.sh --phase verify"
    ;;

  crawl-brp)
    ensure_backend
    echo "=== Full BRP crawl (no images, --force) — use tmux ==="
    bash storage/remotors-crawl-brp.sh 2>&1 | tee -a "storage/remotors-crawl-brp-${STAMP}.log"
    ;;

  crawl-ktm)
    ensure_backend
    echo "=== KTM+HUM+LNX full crawl — use tmux ==="
    bash storage/remotors-crawl-ktm-hum-lnx.sh 2>&1 | tee -a "storage/remotors-crawl-ktm-${STAMP}.log"
    ;;

  variants)
    ensure_backend
    if [ ! -x storage/remotors-recrawl-under15.sh ]; then
      echo "Run prep first: bash scripts/oem-sync-remotors.sh"
      exit 1
    fi
    echo "=== Under-crawled variant repair ==="
    bash storage/remotors-recrawl-under15.sh 2>&1 | tee -a "storage/remotors-recrawl-under15-${STAMP}.log"
    ;;

  verify)
    ensure_backend
    echo "=== Duplicate variant cleanup ==="
    $COMPOSE exec -T oem_backend python -m app.cli fix-remotors-duplicate-variants --apply
    echo ""
    echo "=== Audit vs Remotors API (~1.5h) ==="
    OUT="/app/storage/remotors-audit-${STAMP}.json"
    LOG="/app/storage/remotors-audit-${STAMP}.log"
    $COMPOSE exec -T oem_backend python -m app.cli audit-remotors-catalog \
      --output "$OUT" --log "$LOG"
    echo ""
    echo "Report: storage/remotors-audit-${STAMP}.json"
    echo "Check totals.delta (models/variants/assemblies) and matched_assemblies_pct per brand."
    ;;

  *)
    echo "Unknown phase: $PHASE"
    echo "Phases: prep, crawl-brp, crawl-ktm, variants, verify"
    exit 1
    ;;
esac
