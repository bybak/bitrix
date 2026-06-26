#!/bin/bash
# Remotors gap pipeline: snapshot → compare → selective sync (no full re-crawl).
#
# Usage:
#   bash scripts/oem-remotors-pipeline.sh snapshot   # ~1.5h API scan → SQLite
#   bash scripts/oem-remotors-pipeline.sh compare    # seconds, writes gaps JSON
#   bash scripts/oem-remotors-pipeline.sh sync       # structure (no images)
#   bash scripts/oem-remotors-pipeline.sh align         # global relink from snapshot (fixes thin)
#   bash scripts/oem-remotors-pipeline.sh repair-missing  # crawl variants absent locally only
#   bash scripts/oem-remotors-pipeline.sh fix-all       # dedupe → align → sync → repair-missing → compare
#   bash scripts/oem-remotors-pipeline.sh images     # download PNGs for gaps
#   bash scripts/oem-remotors-pipeline.sh dedupe     # merge duplicate variants + assemblies
#   bash scripts/oem-remotors-pipeline.sh repair-all # dedupe → repair → sync → images → verify
#   bash scripts/oem-remotors-pipeline.sh verify      # compare again after sync
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose"
if [ -f docker-compose.prod.yml ]; then
  COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
fi

STAMP="$(date +%Y%m%d_%H%M)"
SNAPSHOT="/app/storage/remotors-snapshot-${STAMP}.db"
SNAPSHOT_LOG="/app/storage/remotors-snapshot-${STAMP}.log"
GAPS="/app/storage/remotors-gaps-${STAMP}.json"
GAPS_SYNC="/app/storage/remotors-gaps-${STAMP}.sync.json"
SYNC_SCRIPT="storage/remotors-sync-gaps-${STAMP}.sh"

# Reuse latest snapshot if compare/sync without new STAMP
LATEST_SNAPSHOT="$(ls -t storage/remotors-snapshot-*.db 2>/dev/null | head -1 || true)"
LATEST_GAPS="$(ls -t storage/remotors-gaps-*.sync.json 2>/dev/null | head -1 || true)"

ensure_backend() {
  $COMPOSE up -d oem_db oem_backend
}

phase="${1:-help}"

case "$phase" in
  snapshot)
    ensure_backend
    echo "=== Snapshot Remotors API → SQLite ==="
    echo "DB:  storage/remotors-snapshot-${STAMP}.db"
    echo "Log: storage/remotors-snapshot-${STAMP}.log"
    echo "Run in tmux — takes ~1.5 hours."
    echo ""
    $COMPOSE exec -T oem_backend python -m app.cli snapshot-remotors-catalog \
      --snapshot "$SNAPSHOT" \
      --log "$SNAPSHOT_LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh compare"
    ;;

  finalize)
    ensure_backend
    DB="${2:-}"
    LOG="${3:-}"
    if [ -z "$DB" ]; then
      if [ -n "$LATEST_SNAPSHOT" ]; then
        DB="/app/storage/$(basename "$LATEST_SNAPSHOT")"
        OUT_STAMP="$(basename "$LATEST_SNAPSHOT" .db | sed 's/remotors-snapshot-//')"
        LOG="/app/storage/remotors-snapshot-${OUT_STAMP}.log"
      else
        echo "Usage: bash scripts/oem-remotors-pipeline.sh finalize [snapshot.db] [log]"
        exit 1
      fi
    fi
    if [ -z "$LOG" ]; then
      LOG="/app/storage/$(basename "$DB" .db).log"
    fi
    echo "=== Finalize snapshot (rebuild catalog_models, no API scan) ==="
    echo "DB:  $DB"
    $COMPOSE exec -T oem_backend python -m app.cli snapshot-remotors-catalog \
      --snapshot "$DB" \
      --finalize-only \
      --log "$LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh compare"
    ;;

  resume)
    ensure_backend
    DB="${2:-}"
    LOG="${3:-}"
    RETRY="${4:-}"
    if [ -z "$DB" ]; then
      if [ -n "$LATEST_SNAPSHOT" ]; then
        DB="/app/storage/$(basename "$LATEST_SNAPSHOT")"
        OUT_STAMP="$(basename "$LATEST_SNAPSHOT" .db | sed 's/remotors-snapshot-//')"
        LOG="/app/storage/remotors-snapshot-${OUT_STAMP}.log"
      else
        echo "Usage: bash scripts/oem-remotors-pipeline.sh resume [snapshot.db] [log] [retry-nodes.json]"
        exit 1
      fi
    fi
    if [ -z "$LOG" ]; then
      LOG="/app/storage/$(basename "$DB" .db).log"
    fi
    echo "=== Resume snapshot (skip completed ARI, retry errors, finalize) ==="
    echo "DB:  $DB"
    EXTRA=()
    if [ -n "$RETRY" ]; then
      if [ -f "$RETRY" ]; then
        EXTRA=(--retry-node-file "/app/storage/$(basename "$RETRY")")
      else
        EXTRA=(--retry-node-file "$RETRY")
      fi
    fi
    $COMPOSE exec -T oem_backend python -m app.cli snapshot-remotors-catalog \
      --snapshot "$DB" \
      --resume \
      --log "$LOG" \
      "${EXTRA[@]}"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh compare"
    ;;

  compare)
    ensure_backend
    DB="${2:-}"
    if [ -z "$DB" ]; then
      if [ -n "$LATEST_SNAPSHOT" ]; then
        DB="/app/storage/$(basename "$LATEST_SNAPSHOT")"
        OUT_STAMP="$(basename "$LATEST_SNAPSHOT" .db | sed 's/remotors-snapshot-//')"
        GAPS="/app/storage/remotors-gaps-${OUT_STAMP}.json"
      else
        echo "No snapshot found. Run: bash scripts/oem-remotors-pipeline.sh snapshot"
        exit 1
      fi
    else
      OUT_STAMP="$(basename "$DB" .db | sed 's/remotors-snapshot-//')"
      GAPS="/app/storage/remotors-gaps-${OUT_STAMP}.json"
      SYNC_SCRIPT="storage/remotors-sync-gaps-${OUT_STAMP}.sh"
    fi
    echo "=== Compare snapshot vs local DB ==="
    echo "Snapshot: $DB"
    $COMPOSE exec -T oem_backend python -m app.cli compare-remotors-snapshot \
      --snapshot "$DB" \
      --output "$GAPS" \
      --render-sync-script "/app/${SYNC_SCRIPT}"
    chmod +x "$SYNC_SCRIPT" 2>/dev/null || true
    echo ""
    echo "Reports:"
    echo "  storage/$(basename "$GAPS")"
    echo "  storage/$(basename "${GAPS%.json}.sync.json")"
    echo "  ${SYNC_SCRIPT}"
    ;;

  backfill-keys)
    ensure_backend
    echo "=== Ensure source_node_id indexes (CONCURRENTLY, one-time) ==="
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli ensure-oem-source-node-indexes
    echo "=== Backfill assembly external_id keys (optional, before verify) ==="
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli backfill-remotors-assembly-keys --apply
    ;;

  sync|structure)
    ensure_backend
    GAPS_FILE="${2:-}"
    if [ -z "$GAPS_FILE" ]; then
      if [ -n "$LATEST_GAPS" ]; then
        GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
      else
        echo "No .sync.json found. Run compare first."
        exit 1
      fi
    fi
    GAPS_STAMP="$(basename "$GAPS_FILE" .sync.json | sed 's/remotors-gaps-//')"
    SYNC_LOG="storage/remotors-sync-${GAPS_STAMP}.log"
    FORCE_FLAG=""
    if [ "${REMOTORS_SYNC_FORCE:-0}" = "1" ]; then
      FORCE_FLAG="--force"
    fi
    WORKERS="${REMOTORS_SYNC_WORKERS:-5}"
    echo "=== Sync structure (missing assemblies from snapshot, NO images, skip complete) ==="
    echo "Gaps: $GAPS_FILE"
    echo "Log:  $SYNC_LOG"
    echo "Workers: $WORKERS (REMOTORS_SYNC_WORKERS to override)"
    echo "Tip: REMOTORS_SYNC_FORCE=1 to re-import already complete assemblies"
    echo ""
    {
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] sync structure start gaps=$GAPS_FILE workers=$WORKERS"
      $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \
        --gaps "$GAPS_FILE" \
        --phase structure \
        --workers "$WORKERS" \
        $FORCE_FLAG
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] sync structure finished"
    } 2>&1 | tee -a "$SYNC_LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh images"
    ;;

  images)
    ensure_backend
    GAPS_FILE="${2:-}"
    if [ -z "$GAPS_FILE" ]; then
      if [ -n "$LATEST_GAPS" ]; then
        GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
      else
        echo "No .sync.json found. Run compare first."
        exit 1
      fi
    fi
    GAPS_STAMP="$(basename "$GAPS_FILE" .sync.json | sed 's/remotors-gaps-//')"
    SYNC_LOG="storage/remotors-sync-${GAPS_STAMP}-images.log"
    FORCE_FLAG=""
    if [ "${REMOTORS_SYNC_FORCE:-0}" = "1" ]; then
      FORCE_FLAG="--force"
    fi
    WORKERS="${REMOTORS_SYNC_WORKERS:-5}"
    echo "=== Sync diagram images ==="
    echo "Log: $SYNC_LOG"
    echo "Workers: $WORKERS"
    {
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] sync images start gaps=$GAPS_FILE workers=$WORKERS"
      $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \
        --gaps "$GAPS_FILE" \
        --phase images \
        --workers "$WORKERS" \
        $FORCE_FLAG
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] sync images finished"
    } 2>&1 | tee -a "$SYNC_LOG"
    ;;

  verify)
    ensure_backend
    echo "=== Post-sync: dedupe + normalize-keys + compare ==="
    bash scripts/oem-remotors-pipeline.sh dedupe
    bash scripts/oem-remotors-pipeline.sh normalize-keys
    bash scripts/oem-remotors-pipeline.sh compare
    ;;

  dedupe)
    ensure_backend
    echo "=== Dedupe duplicate variants, assemblies, and inflated part/diagram rows ==="
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli fix-remotors-duplicate-variants --apply
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli fix-remotors-duplicate-assemblies --apply
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli fix-remotors-duplicate-assembly-contents --apply
    ;;

  normalize-keys)
    ensure_backend
    echo "=== Fix assembly arib from variant brand + backfill external_id keys ==="
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli fix-remotors-assembly-arib-from-brand --apply
    $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli backfill-remotors-assembly-keys --apply
    ;;

  align)
    ensure_backend
    SNAPSHOT_DB="${2:-}"
    if [ -z "$SNAPSHOT_DB" ]; then
      if [ -n "$LATEST_SNAPSHOT" ]; then
        SNAPSHOT_DB="/app/storage/$(basename "$LATEST_SNAPSHOT")"
      else
        echo "No snapshot found. Run compare first or pass snapshot path."
        exit 1
      fi
    elif [[ "$SNAPSHOT_DB" != /app/* ]]; then
      SNAPSHOT_DB="/app/storage/$(basename "$SNAPSHOT_DB")"
    fi
    GAPS_FILE="${3:-}"
    if [ -z "$GAPS_FILE" ] && [ -n "$LATEST_GAPS" ]; then
      GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
    fi
    STAMP="$(basename "$SNAPSHOT_DB" .db | sed 's/remotors-snapshot-//')"
    ALIGN_LOG="storage/remotors-align-${STAMP}.log"
    LIMIT_FLAG=""
    if [ -n "${REMOTORS_ALIGN_LIMIT:-}" ]; then
      LIMIT_FLAG="--limit-assemblies ${REMOTORS_ALIGN_LIMIT}"
    fi
    echo "=== Global align: clone assembly contents per variant from snapshot (batched commits) ==="
    echo "Snapshot: $SNAPSHOT_DB"
    echo "Log:      $ALIGN_LOG"
    echo "Tuning:   REMOTORS_ALIGN_COMMIT_EVERY=${REMOTORS_ALIGN_COMMIT_EVERY:-200} "
    echo "          REMOTORS_ALIGN_SCAN_BATCH=${REMOTORS_ALIGN_SCAN_BATCH:-10000} "
    echo "          REMOTORS_ALIGN_PROGRESS_EVERY=${REMOTORS_ALIGN_PROGRESS_EVERY:-1000}"
    echo ""
    {
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] align start snapshot=$SNAPSHOT_DB"
      if [ -n "$GAPS_FILE" ]; then
        $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \
          --gaps "$GAPS_FILE" \
          --phase align \
          --snapshot "$SNAPSHOT_DB" \
          $LIMIT_FLAG
      else
        $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli \
          align-remotors-catalog-from-snapshot \
          --snapshot "$SNAPSHOT_DB" \
          --apply \
          ${REMOTORS_ALIGN_LIMIT:+--limit "$REMOTORS_ALIGN_LIMIT"}
      fi
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] align finished"
    } 2>&1 | tee -a "$ALIGN_LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh compare"
    ;;

  repair-missing)
    ensure_backend
    GAPS_FILE="${2:-}"
    if [ -z "$GAPS_FILE" ]; then
      if [ -n "$LATEST_GAPS" ]; then
        GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
      else
        echo "No .sync.json found. Run compare first."
        exit 1
      fi
    fi
    GAPS_STAMP="$(basename "$GAPS_FILE" .sync.json | sed 's/remotors-gaps-//')"
    REPAIR_LOG="storage/remotors-repair-missing-${GAPS_STAMP}.log"
    REPAIR_STATE="/app/storage/remotors-gaps-${GAPS_STAMP}.repair-missing-state.json"
    LIMIT_FLAG=""
    if [ -n "${REMOTORS_REPAIR_LIMIT:-}" ]; then
      LIMIT_FLAG="--limit-variants ${REMOTORS_REPAIR_LIMIT}"
    fi
    FORCE_FLAG=""
    if [ "${REMOTORS_REPAIR_FORCE:-0}" = "1" ]; then
      FORCE_FLAG="--force"
    fi
    echo "=== Repair missing_variants only (variants absent locally, resumable) ==="
    echo "Gaps:  $GAPS_FILE"
    echo "State: $REPAIR_STATE"
    echo "Log:   $REPAIR_LOG"
    echo ""
    {
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] repair-missing start gaps=$GAPS_FILE"
      $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \
        --gaps "$GAPS_FILE" \
        --phase repair-missing \
        --state "$REPAIR_STATE" \
        --no-images \
        $LIMIT_FLAG \
        $FORCE_FLAG
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] repair-missing finished"
    } 2>&1 | tee -a "$REPAIR_LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh align && bash scripts/oem-remotors-pipeline.sh sync"
    ;;

  repair)
    ensure_backend
    GAPS_FILE="${2:-}"
    if [ -z "$GAPS_FILE" ]; then
      if [ -n "$LATEST_GAPS" ]; then
        GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
      else
        echo "No .sync.json found. Run compare first."
        exit 1
      fi
    fi
    GAPS_STAMP="$(basename "$GAPS_FILE" .sync.json | sed 's/remotors-gaps-//')"
    REPAIR_LOG="storage/remotors-repair-${GAPS_STAMP}.log"
    REPAIR_STATE="/app/storage/remotors-gaps-${GAPS_STAMP}.repair-state.json"
    LIMIT_FLAG=""
    if [ -n "${REMOTORS_REPAIR_LIMIT:-}" ]; then
      LIMIT_FLAG="--limit-variants ${REMOTORS_REPAIR_LIMIT}"
    fi
    FORCE_FLAG=""
    if [ "${REMOTORS_REPAIR_FORCE:-0}" = "1" ]; then
      FORCE_FLAG="--force"
    fi
    echo "=== Repair thin/missing variants (resumable, structure only) ==="
    echo "Gaps:  $GAPS_FILE"
    echo "State: $REPAIR_STATE"
    echo "Log:   $REPAIR_LOG"
    echo "Tip: REMOTORS_REPAIR_LIMIT=100 for batch; re-run same command to resume"
    echo ""
    {
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] repair start gaps=$GAPS_FILE"
      $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \
        --gaps "$GAPS_FILE" \
        --phase repair \
        --state "$REPAIR_STATE" \
        --no-images \
        $LIMIT_FLAG \
        $FORCE_FLAG
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] repair finished"
    } 2>&1 | tee -a "$REPAIR_LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh sync && bash scripts/oem-remotors-pipeline.sh images"
    ;;

  relink)
    echo "WARNING: relink is deprecated — use 'align' instead (global, single-threaded)."
    bash scripts/oem-remotors-pipeline.sh align "${2:-}"
    ;;

  rebuild-from-snapshot)
    ensure_backend
    SNAPSHOT_DB="${2:-/app/storage/remotors-snapshot-20260624_1606.db}"
    if [[ "$SNAPSHOT_DB" != /app/* ]]; then
      SNAPSHOT_DB="/app/storage/$(basename "$SNAPSHOT_DB")"
    fi
    REBUILD_LOG="storage/remotors-rebuild-$(basename "$SNAPSHOT_DB" .db | sed 's/remotors-snapshot-//').log"
    echo "=== Rebuild catalog from snapshot (export → truncate → import → restore) ==="
    echo "Snapshot: $SNAPSHOT_DB"
    echo "Log:      $REBUILD_LOG"
    echo ""
    $COMPOSE up -d --build oem_backend
    {
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] rebuild start snapshot=$SNAPSHOT_DB"
      $COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli rebuild-remotors-catalog-from-snapshot \
        --snapshot "$SNAPSHOT_DB" \
        --phase all
      echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] rebuild finished"
    } 2>&1 | tee "$REBUILD_LOG"
    echo ""
    echo "Next: bash scripts/oem-remotors-pipeline.sh compare"
    ;;

  fix-all)
    ensure_backend
    GAPS_FILE="${2:-}"
    if [ -z "$GAPS_FILE" ] && [ -n "$LATEST_GAPS" ]; then
      GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
    fi
    echo "=== Full fix pipeline (dedupe → normalize-keys → compare → repair-missing → align → sync → dedupe → images → compare) ==="
    bash scripts/oem-remotors-pipeline.sh dedupe
    bash scripts/oem-remotors-pipeline.sh normalize-keys
    bash scripts/oem-remotors-pipeline.sh compare
    bash scripts/oem-remotors-pipeline.sh repair-missing ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh align
    bash scripts/oem-remotors-pipeline.sh sync ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh dedupe
    REMOTORS_SYNC_WORKERS="${REMOTORS_SYNC_WORKERS:-10}" bash scripts/oem-remotors-pipeline.sh images ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh compare
    ;;

  repair-all-legacy)
    ensure_backend
    GAPS_FILE="${2:-}"
    if [ -z "$GAPS_FILE" ] && [ -n "$LATEST_GAPS" ]; then
      GAPS_FILE="/app/storage/$(basename "$LATEST_GAPS")"
    fi
    echo "=== Legacy repair pipeline (dedupe → repair → relink → sync → images → verify) ==="
    bash scripts/oem-remotors-pipeline.sh dedupe
    bash scripts/oem-remotors-pipeline.sh repair ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh relink ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh sync ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh images ${GAPS_FILE:+"$GAPS_FILE"}
    bash scripts/oem-remotors-pipeline.sh verify
    ;;

  help|*)
    echo "Remotors gap pipeline:"
    echo "  bash scripts/oem-remotors-pipeline.sh snapshot    # API → SQLite (~1.5h, tmux)"
    echo "  bash scripts/oem-remotors-pipeline.sh finalize    # rebuild aggregates after crash"
    echo "  bash scripts/oem-remotors-pipeline.sh resume      # retry errors + finalize (no full re-scan)"
    echo "  bash scripts/oem-remotors-pipeline.sh compare     # SQLite vs PostgreSQL"
    echo "  bash scripts/oem-remotors-pipeline.sh dedupe      # merge duplicate variants + assemblies + parts"
    echo "  bash scripts/oem-remotors-pipeline.sh normalize-keys  # fix arib prefix + backfill external_id"
    echo "  bash scripts/oem-remotors-pipeline.sh align       # clone/link assemblies per variant from snapshot"
    echo "  bash scripts/oem-remotors-pipeline.sh sync        # import missing assemblies from snapshot"
    echo "  bash scripts/oem-remotors-pipeline.sh repair-missing  # recrawl variants absent locally"
    echo "  bash scripts/oem-remotors-pipeline.sh repair      # legacy: recrawl thin+missing (slow, mostly skips)"
    echo "  bash scripts/oem-remotors-pipeline.sh relink      # deprecated → calls align"
    echo "  bash scripts/oem-remotors-pipeline.sh backfill-keys  # optional key migration"
    echo "  bash scripts/oem-remotors-pipeline.sh images      # download missing PNGs"
    echo "  bash scripts/oem-remotors-pipeline.sh verify      # dedupe + compare again"
    echo "  bash scripts/oem-remotors-pipeline.sh rebuild-from-snapshot  # clean rebuild from SQLite snapshot"
    echo "  bash scripts/oem-remotors-pipeline.sh fix-all     # legacy dedupe/align pipeline"
    echo ""
    echo "Env:"
    echo "  REMOTORS_SYNC_WORKERS=10     parallel workers for sync/images"
    echo "  REMOTORS_ALIGN_LIMIT=1000    debug: limit align rows"
    echo "  REMOTORS_ALIGN_COMMIT_EVERY=200   DB commits per batch (clone/fill ops)"
    echo "  REMOTORS_ALIGN_SCAN_BATCH=10000   max snapshot rows scanned per transaction"
    echo "  REMOTORS_ALIGN_PROGRESS_EVERY=1000  progress log interval (rows)"
    echo "  REMOTORS_REPAIR_LIMIT=200    batch size for repair-missing (resume with same command)"
    echo "  REMOTORS_REPAIR_FORCE=1      re-crawl variants even if checkpointed"
    echo "  REMOTORS_SYNC_FORCE=1        re-import assemblies / re-download images"
    ;;
esac
