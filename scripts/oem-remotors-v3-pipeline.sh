#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PYTHONUNBUFFERED=1

SNAPSHOT="${SNAPSHOT:-storage/remotors-snapshot-v3.db}"
SIDECAR="${SIDECAR:-storage/remotors-details-crawl.db}"
PHASE="${1:-all}"
LIMIT_ASSEMBLIES="${LIMIT_ASSEMBLIES:-}"
LIMIT="${LIMIT:-}"

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
  local -a cmd=(snapshot-remotors-v3 --snapshot "${SNAPSHOT}")
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
  local -a cmd=(crawl-remotors-details --phase "${phase}" --sidecar "${SIDECAR}")
  if ((${#crawl_limit_args[@]} > 0)); then
    cmd+=("${crawl_limit_args[@]}")
  fi
  run_cli "${cmd[@]}"
}

run_parse() {
  local -a cmd=(parse-remotors-details --sidecar "${SIDECAR}")
  if ((${#crawl_limit_args[@]} > 0)); then
    cmd+=("${crawl_limit_args[@]}")
  fi
  run_cli "${cmd[@]}"
}

case "${PHASE}" in
  wipe)
    bash scripts/oem-remotors-v3-wipe.sh
    ;;
  init-db)
    bash scripts/oem-remotors-v3-wipe.sh
    ;;
  snapshot)
    run_snapshot
    ;;
  snapshot-resume)
    run_snapshot resume
    ;;
  import-structure)
    run_cli import-remotors-structure --snapshot "${SNAPSHOT}"
    ;;
  seed-crawl)
    run_cli seed-remotors-crawl --sidecar "${SIDECAR}"
    ;;
  crawl-html)
    run_cli seed-remotors-crawl --sidecar "${SIDECAR}" || true
    run_crawl html
    ;;
  crawl-images)
    run_crawl images
    ;;
  parse)
    run_parse
    ;;
  verify)
    run_cli verify-remotors-v3 --snapshot "${SNAPSHOT}"
    ;;
  all)
    bash scripts/oem-remotors-v3-wipe.sh
    run_snapshot
    run_cli import-remotors-structure --snapshot "${SNAPSHOT}"
    run_cli seed-remotors-crawl --sidecar "${SIDECAR}"
    run_crawl html
    run_crawl images
    run_parse
    run_cli verify-remotors-v3 --snapshot "${SNAPSHOT}"
    ;;
  *)
    echo "Usage: $0 {wipe|init-db|snapshot|snapshot-resume|import-structure|seed-crawl|crawl-html|crawl-images|parse|verify|all}"
    echo "Env: SNAPSHOT, SIDECAR, LIMIT_ASSEMBLIES, LIMIT"
    exit 1
    ;;
esac
