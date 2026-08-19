#!/usr/bin/env bash
# Enrich Yamaha YMH-JP parts via IMPEX Japan (price JPY, weight, full part number).
# Does not touch part_number / name — only additive impex_* columns.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ $# -lt 1 ]]; then
  cat <<'EOF'
Usage:
  ./scripts/yamaha_impex_enrich.sh ensure-schema
  ./scripts/yamaha_impex_enrich.sh status
  ./scripts/yamaha_impex_enrich.sh fetch --workers 8
  ./scripts/yamaha_impex_enrich.sh fetch --workers 12
  ./scripts/yamaha_impex_enrich.sh fetch --part 90201-12362
  ./scripts/yamaha_impex_enrich.sh fetch --limit 100 --workers 8
  ./scripts/yamaha_impex_enrich.sh fetch --dry-run --limit 5
  ./scripts/yamaha_impex_enrich.sh fetch --retry-errors --workers 8
  ./scripts/yamaha_impex_enrich.sh apply-cache

Default: workers=8 + adaptive throttle (Impex отдаёт 503 при 32–64).
НЕ ставьте 64 — сайт банит. Resume-safe; 503 автоматически переочередятся.
EOF
  exit 1
fi

mkdir -p storage/yamaha-impex

SUBCOMMAND="$1"
shift || true

ts() { date '+%H:%M:%S'; }
say() { echo "[$(ts)] $*"; }

say "stage: start command=${SUBCOMMAND}"
say "stage: project=${ROOT}"
say "stage: docker compose run oem_backend → python ${SUBCOMMAND}"

docker compose run --rm --no-deps \
  -e PYTHONUNBUFFERED=1 \
  -v "${ROOT}/scripts:/scripts:ro" \
  -v "${ROOT}/storage:/app/storage" \
  -e OEM_YAMAHA_DATABASE_DSN=postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog \
  oem_backend \
  python /scripts/yamaha_impex_enrich.py \
    --work-dir /app/storage/yamaha-impex \
    "$SUBCOMMAND" \
    "$@"

say "stage: finished command=${SUBCOMMAND}"
