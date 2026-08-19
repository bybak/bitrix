#!/usr/bin/env bash
# Replace short YMH-EU / YMH-JP part numbers with MegaZip full numbers.
# Same-part expansions only (95812-06014 → 95812-06014-00).
# No full number → leave as is. Target already exists → skip, do not merge.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ $# -lt 1 ]]; then
  cat <<'EOF'
Usage:
  ./scripts/yamaha_apply_full_part_numbers.sh status
  ./scripts/yamaha_apply_full_part_numbers.sh apply --dry-run
  ./scripts/yamaha_apply_full_part_numbers.sh apply

Mapping: YMH-JP.full_part_number → YMH-EU + YMH-JP oem_parts.part_number
Old short number is kept in original_part_number.
Artifacts: storage/yamaha-full-pn/apply_report.json
EOF
  exit 1
fi

mkdir -p storage/yamaha-full-pn

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
  python /scripts/yamaha_apply_full_part_numbers.py \
    --work-dir /app/storage/yamaha-full-pn \
    "$SUBCOMMAND" \
    "$@"

say "stage: finished command=${SUBCOMMAND}"
