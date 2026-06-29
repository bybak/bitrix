#!/usr/bin/env bash
# Parallel offline HTML parse — output in THIS terminal.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PYTHONUNBUFFERED=1

WORKERS="${WORKERS:-4}"
CONCURRENCY="${CONCURRENCY:-2}"

if ((WORKERS > 12)); then
  echo "[parse-parallel] WARNING: WORKERS=${WORKERS} is high — use 4-8 unless you know why"
fi

pids=()

stop_workers() {
  echo "[parse-parallel] stopping workers..."
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
        if 'parse-remotors-details' in cmd and 'python -c' not in cmd:
            os.kill(pid, signal.SIGTERM)
    except (ProcessLookupError, PermissionError, FileNotFoundError, ValueError):
        pass
" 2>/dev/null || true
}

run_worker() {
  local worker_id="$1"
  docker compose exec -T oem_backend python -m app.cli parse-remotors-details \
    --worker-id "${worker_id}" \
    --workers "${WORKERS}" \
    --concurrency "${CONCURRENCY}" 2>&1 | while IFS= read -r line; do
      printf '[w%s] %s\n' "${worker_id}" "${line}"
    done
}

echo "[parse-parallel] workers=${WORKERS} concurrency=${CONCURRENCY} (~$((WORKERS * CONCURRENCY)) parallel) (Ctrl+C to stop all)"
trap 'stop_workers; exit 130' INT TERM

for ((i = 0; i < WORKERS; i++)); do
  run_worker "${i}" &
  pids+=("$!")
  echo "[parse-parallel] started worker ${i}/${WORKERS} host_pid=$!"
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
  echo "[parse-parallel] one or more workers failed"
  exit 1
fi

echo "[parse-parallel] all workers finished"
