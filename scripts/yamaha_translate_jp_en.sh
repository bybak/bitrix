#!/usr/bin/env bash
# Yamaha JP→EN translation pipeline (YMH-EU / YMH-JP).
# Does NOT write to DB until: apply  (use apply --dry-run first).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ $# -lt 1 ]]; then
  cat <<'EOF'
Usage:
  ./scripts/yamaha_translate_jp_en.sh extract
  ./scripts/yamaha_translate_jp_en.sh build-map
  ./scripts/yamaha_translate_jp_en.sh translate --backend argos
  ./scripts/yamaha_translate_jp_en.sh retry-leftovers --backend argos
  ./scripts/yamaha_translate_jp_en.sh translate --backend mymemory
  ./scripts/yamaha_translate_jp_en.sh translate --backend file --file /app/storage/yamaha-jp-en/manual.json
  ./scripts/yamaha_translate_jp_en.sh status
  ./scripts/yamaha_translate_jp_en.sh apply --dry-run
  ./scripts/yamaha_translate_jp_en.sh apply

Artifacts: storage/yamaha-jp-en/
EOF
  exit 1
fi

mkdir -p storage/yamaha-jp-en

SUBCOMMAND="$1"
shift || true

backend="argos"
prev=""
for arg in "$@"; do
  if [[ "$prev" == "--backend" ]]; then
    backend="$arg"
  fi
  prev="$arg"
done

ts() { date '+%H:%M:%S'; }
say() { echo "[$(ts)] $*"; }

say "stage: start command=${SUBCOMMAND} backend=${backend:-n/a}"
say "stage: project=${ROOT}"

common=(
  docker compose run --rm --no-deps
  -e PYTHONUNBUFFERED=1
  -v "${ROOT}/scripts:/scripts:ro"
  -v "${ROOT}/storage:/app/storage"
  -e OEM_YAMAHA_DATABASE_DSN=postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog
  oem_backend
)

py=(
  /scripts/yamaha_translate_jp_en.py
  --work-dir /app/storage/yamaha-jp-en
  --dict /scripts/yamaha_jp_en_dict.json
  "$SUBCOMMAND"
)

if [[ "$SUBCOMMAND" == "translate" || "$SUBCOMMAND" == "retry-leftovers" ]] && [[ "$backend" == "argos" ]]; then
  say "stage: docker compose run oem_backend"
  say "stage: pip install argostranslate (может занять 1–3 мин, это нормально)"
  say "stage: затем ja→en модель / перевод с прогрессом"
  # $0 placeholder so "$@" inside -c starts at real args
  "${common[@]}" bash -c '
    set -e
    echo "[$(date +%H:%M:%S)] container: pip install argostranslate…"
    pip install --progress-bar on argostranslate
    echo "[$(date +%H:%M:%S)] container: pip done — starting python"
    exec python "$@"
  ' _ "${py[@]}" "$@"
else
  say "stage: docker compose run oem_backend → python ${SUBCOMMAND}"
  "${common[@]}" python "${py[@]}" "$@"
fi

say "stage: finished command=${SUBCOMMAND}"
