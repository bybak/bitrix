#!/usr/bin/env bash
# Import Arctic Cat PartStream snapshot (ARC only) into arctic_db.
# Does not crawl/parse details — structure only.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PYTHONUNBUFFERED=1

SNAPSHOT_ARC="${SNAPSHOT_ARC:-storage/arctic-snapshot-arc.db}"
PHASE="${1:-help}"

run_cli() {
  docker compose exec -T oem_backend python -m app.cli "$@"
}

case "${PHASE}" in
  up)
    docker compose up -d arctic_db oem_registry_db oem_backend
    ;;
  migrate-registry)
    run_cli migrate-registry
    docker compose restart oem_backend
    ;;
  import-arc)
    # Fresh load of ARC into arctic_db (truncates arctic catalog tables).
    run_cli import-remotors-structure --snapshot "${SNAPSHOT_ARC}" --no-resume
    ;;
  seed-crawl)
    run_cli seed-remotors-crawl --db-code arctic
    ;;
  help|*)
    cat <<EOF
Usage: $0 {up|migrate-registry|import-arc|seed-crawl}

Typical first load:
  bash scripts/oem-arctic-import.sh up
  bash scripts/oem-arctic-import.sh migrate-registry
  bash scripts/oem-arctic-import.sh import-arc

Env:
  SNAPSHOT_ARC  default storage/arctic-snapshot-arc.db
EOF
    ;;
esac
