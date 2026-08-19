#!/usr/bin/env bash
# Enrich Yamaha YMH-JP parts via MegaZip (Japan warehouse RUB price, full PN, name_ru).
# Skips replacements («Замена»). Brand must be Yamaha.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ $# -lt 1 ]]; then
  cat <<'EOF'
Usage:
  ./scripts/yamaha_megazip_enrich.sh ensure-schema
  ./scripts/yamaha_megazip_enrich.sh status
  ./scripts/yamaha_megazip_enrich.sh fetch --part 95827-10050
  ./scripts/yamaha_megazip_enrich.sh fetch --workers 8
  ./scripts/yamaha_megazip_enrich.sh fetch --limit 50 --workers 8
  ./scripts/yamaha_megazip_enrich.sh fetch --retry-errors

Source: https://www.megazip.ru/search?q=...
Artifacts: storage/yamaha-megazip/
EOF
  exit 1
fi

mkdir -p storage/yamaha-megazip

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
  python /scripts/yamaha_megazip_enrich.py \
    --work-dir /app/storage/yamaha-megazip \
    "$SUBCOMMAND" \
    "$@"

say "stage: finished command=${SUBCOMMAND}"
