#!/usr/bin/env bash
# Yamaha USA via PartStream (YAM PowerSport + YAMMR Marine) → YMH-US.
# Does not touch YMH-JP / YMH-EU.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PYTHONUNBUFFERED=1

SNAPSHOT="${SNAPSHOT:-storage/yamaha-ps-snapshot-v1.db}"
SIDECAR="${SIDECAR:-storage/yamaha-ps-details-crawl.db}"
PHASE="${1:-help}"
BRAND="${BRAND:-all}"
LIMIT_ASSEMBLIES="${LIMIT_ASSEMBLIES:-}"
LIMIT="${LIMIT:-}"
HTML_CONCURRENCY="${HTML_CONCURRENCY:-4}"
IMAGE_CONCURRENCY="${IMAGE_CONCURRENCY:-2}"
PARSE_CONCURRENCY="${PARSE_CONCURRENCY:-8}"
SNAPSHOT_DELAY_MS="${SNAPSHOT_DELAY_MS:-200}"
SNAPSHOT_JITTER_MS="${SNAPSHOT_JITTER_MS:-150}"

# Optional: --brand YAM|YAMMR|all after phase
shift || true
while [[ $# -gt 0 ]]; do
  case "$1" in
    --brand)
      BRAND="${2:-}"
      shift 2
      ;;
    *)
      echo "Unknown arg: $1" >&2
      exit 1
      ;;
  esac
done

snapshot_limit_args=()
crawl_limit_args=()
if [[ -n "${LIMIT_ASSEMBLIES}" ]]; then
  snapshot_limit_args=(--limit-assemblies "${LIMIT_ASSEMBLIES}")
fi
if [[ -n "${LIMIT}" ]]; then
  crawl_limit_args=(--limit "${LIMIT}")
fi

run_cli() {
  docker compose exec -T oem_backend python -m app.cli "$@"
}

run_snapshot() {
  local resume="${1:-}"
  local -a cmd=(
    snapshot-yamaha-ps
    --snapshot "${SNAPSHOT}"
    --brand "${BRAND}"
    --delay-ms "${SNAPSHOT_DELAY_MS}"
    --jitter-ms "${SNAPSHOT_JITTER_MS}"
  )
  if [[ "${resume}" == "resume" ]]; then
    cmd+=(--resume)
  fi
  if ((${#snapshot_limit_args[@]} > 0)); then
    cmd+=("${snapshot_limit_args[@]}")
  fi
  run_cli "${cmd[@]}"
}

run_crawl() {
  local phase="$1"
  local conc="${HTML_CONCURRENCY}"
  if [[ "${phase}" == "images" ]]; then
    conc="${IMAGE_CONCURRENCY}"
  fi
  local -a cmd=(
    crawl-yamaha-ps-details
    --phase "${phase}"
    --sidecar "${SIDECAR}"
    --concurrency "${conc}"
  )
  if ((${#crawl_limit_args[@]} > 0)); then
    cmd+=("${crawl_limit_args[@]}")
  fi
  run_cli "${cmd[@]}"
}

run_parse() {
  local -a cmd=(
    parse-yamaha-ps-details
    --sidecar "${SIDECAR}"
    --concurrency "${PARSE_CONCURRENCY}"
  )
  if ((${#crawl_limit_args[@]} > 0)); then
    cmd+=("${crawl_limit_args[@]}")
  fi
  run_cli "${cmd[@]}"
}

case "${PHASE}" in
  wipe|reset)
    run_cli reset-yamaha-ps --snapshot "${SNAPSHOT}"
    ;;
  snapshot)
    run_snapshot
    ;;
  snapshot-resume)
    run_snapshot resume
    ;;
  import|import-structure)
    run_cli import-yamaha-ps-structure --no-resume --snapshot "${SNAPSHOT}"
    ;;
  import-resume)
    run_cli import-yamaha-ps-structure --snapshot "${SNAPSHOT}"
    ;;
  seed-crawl)
    run_cli seed-yamaha-ps-crawl --sidecar "${SIDECAR}"
    ;;
  crawl-html)
    run_cli seed-yamaha-ps-crawl --sidecar "${SIDECAR}" || true
    run_crawl html
    ;;
  crawl-images)
    run_crawl images
    ;;
  parse)
    run_parse
    ;;
  all)
    run_cli reset-yamaha-ps --snapshot "${SNAPSHOT}"
    run_snapshot
    run_cli import-yamaha-ps-structure --no-resume --snapshot "${SNAPSHOT}"
    run_cli seed-yamaha-ps-crawl --sidecar "${SIDECAR}"
    run_crawl html
    run_crawl images
    run_parse
    ;;
  help|*)
    cat <<EOF
Usage: $0 {wipe|snapshot|snapshot-resume|import|seed-crawl|crawl-html|crawl-images|parse|all} [--brand YAM|YAMMR|all]

Yamaha USA PartStream pipeline (root YMH-US):
  Yamaha PowerSport  ← brand YAM
  Yamaha Marine      ← brand YAMMR

Phases:
  wipe             Purge YMH-US PG + local PartStream/legacy US assets (JP/EU safe)
  snapshot         GetAssembly DFS → SQLite
  snapshot-resume  Continue snapshot (skip completed brands)
  import           Load snapshot into yamaha PostgreSQL (purge YMH-US first)
  seed-crawl       Build crawl sidecar from PG assemblies
  crawl-html       GetDetails HTML
  crawl-images     Diagram PNGs
  parse            HTML → parts/hotspots
  all              wipe → snapshot → import → crawl-html → crawl-images → parse

Env:
  BRAND                 default all (${BRAND})
  SNAPSHOT              default ${SNAPSHOT}
  SIDECAR               default ${SIDECAR}
  LIMIT_ASSEMBLIES      cap snapshot
  LIMIT                 cap crawl/parse
  HTML_CONCURRENCY      default 4
  IMAGE_CONCURRENCY     default 2
  PARSE_CONCURRENCY     default 8
  SNAPSHOT_DELAY_MS     default 200
  SNAPSHOT_JITTER_MS    default 150

Registry: after smoke, ensure YMH-US is active:
  docker compose exec -T oem_backend python -m app.cli migrate-registry
  (applies migrations_registry/003_yamaha_us_active.sql)
EOF
    exit 1
    ;;
esac
