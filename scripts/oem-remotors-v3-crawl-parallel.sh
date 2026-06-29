#!/usr/bin/env bash
# Parallel Remotors HTML/image crawl — output in THIS terminal.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PYTHONUNBUFFERED=1

PHASE="${1:-html}"
WORKERS="${WORKERS:-4}"
CONCURRENCY="${CONCURRENCY:-6}"

if [[ "${PHASE}" != "html" && "${PHASE}" != "images" ]]; then
  echo "Usage: $0 {html|images}"
  echo "Env: WORKERS (default 4), CONCURRENCY (default 6, parallel HTTP per worker)"
  echo "     Recommended: WORKERS=6 CONCURRENCY=8  (~48 parallel requests)"
  exit 1
fi

if ((WORKERS > 8)); then
  echo "[crawl-parallel] WARNING: WORKERS=${WORKERS} is high — use 4-8 unless you know why"
fi

total_parallel=$((WORKERS * CONCURRENCY))
if ((total_parallel > 64)); then
  echo "[crawl-parallel] WARNING: ${WORKERS}x${CONCURRENCY}=${total_parallel} parallel requests — API may throttle"
fi

pids=()

stop_workers() {
  echo "[crawl-parallel] stopping workers..."
  for pid in "${pids[@]:-}"; do
    kill "${pid}" 2>/dev/null || true
  done
  docker compose exec -T oem_backend python -c "
import glob, os, signal
for pid_dir in glob.glob('/proc/[0-9]*'):
    try:
        pid = int(os.path.basename(pid_dir))
        with open(pid_dir + '/cmdline', 'rb') as fh:
            cmd = fh.read().replace(b'\\0', b' ').decode('utf-8', 'replace')
        if 'crawl-remotors-details' in cmd and 'python -c' not in cmd:
            os.kill(pid, signal.SIGTERM)
    except (ProcessLookupError, PermissionError, FileNotFoundError, ValueError):
        pass
" 2>/dev/null || true
}

run_worker() {
  local worker_id="$1"
  docker compose exec -T oem_backend python -m app.cli crawl-remotors-details \
    --phase "${PHASE}" \
    --worker-id "${worker_id}" \
    --workers "${WORKERS}" \
    --concurrency "${CONCURRENCY}" 2>&1 | while IFS= read -r line; do
      printf '[w%s] %s\n' "${worker_id}" "${line}"
    done
}

echo "[crawl-parallel] phase=${PHASE} workers=${WORKERS} concurrency=${CONCURRENCY} (~$((WORKERS * CONCURRENCY)) parallel) (Ctrl+C to stop all)"
trap 'stop_workers; exit 130' INT TERM

for ((i = 0; i < WORKERS; i++)); do
  run_worker "${i}" &
  pids+=("$!")
  echo "[crawl-parallel] started worker ${i}/${WORKERS} host_pid=$!"
  if ((i + 1 < WORKERS)); then
    sleep 2
  fi
done

fail=0
for pid in "${pids[@]}"; do
  if ! wait "${pid}"; then
    fail=1
  fi
done

if ((fail)); then
  echo "[crawl-parallel] one or more workers failed"
  exit 1
fi

echo "[crawl-parallel] all workers finished"
