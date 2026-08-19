#!/usr/bin/env bash
# Collect MegaZip «Замена» mappings for Yamaha YMH-JP unique part numbers.
# Local SQLite + CSV: Номер;Замены (comma-separated).
# Resumable. Does not modify oem_parts.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ $# -lt 1 ]]; then
  cat <<'EOF'
Usage:
  ./scripts/yamaha_megazip_replacements.sh fetch --part 95827-10050
  ./scripts/yamaha_megazip_replacements.sh fetch --workers 32
  ./scripts/yamaha_megazip_replacements.sh fetch --limit 100 --workers 16
  ./scripts/yamaha_megazip_replacements.sh status
  ./scripts/yamaha_megazip_replacements.sh export-csv

Logic (example https://www.megazip.ru/search?q=95827-10050):
  Only section «Запчасти Yamaha» is parsed (other brands ignored).
  «Замена» row 95827-10050-00 is a replacement for original 95817-10050-00
  → CSV: 95817-10050-00 ; 95827-10050-00
  Direct product card (no search list) → skip, no replacements.

Artifacts: storage/yamaha-megazip-replacements/
  replacements.sqlite
  yamaha_jp_replacements.csv
EOF
  exit 1
fi

mkdir -p storage/yamaha-megazip-replacements

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
  python /scripts/yamaha_megazip_replacements.py \
    --work-dir /app/storage/yamaha-megazip-replacements \
    "$SUBCOMMAND" \
    "$@"

say "stage: finished command=${SUBCOMMAND}"
