#!/usr/bin/env bash
# Polar image crawl: 3 detached workers × 16 threads (Docker VM ~48GiB).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

WORKERS="${WORKERS:-3}"
CONCURRENCY="${CONCURRENCY:-16}"
APPKEY="${OEM_PARTSTREAM_APPKEY:-lKCIzRNMxtbQA7q0ZL4j}"
ARIV="${OEM_PARTSTREAM_ARIV:-http://streamsdemo.arinet.com/Power}"
ARIL="${OEM_PARTSTREAM_ARIL:-en-US}"

echo "[polaris-images] stopping previous crawlers"
docker compose exec -T oem_backend sh -c '
for d in /proc/[0-9]*; do
  cmd=$(tr "\0" " " < "$d/cmdline" 2>/dev/null || true)
  case "$cmd" in
    *crawl-remotors-details*|*polaris-worker-*) kill -9 "${d#/proc/}" 2>/dev/null || true ;;
  esac
done
' || true
sleep 2

echo "[polaris-images] reset error/running -> pending"
docker compose exec -T polaris_db psql -U polaris_user -d polaris_catalog -v ON_ERROR_STOP=1 -c "
UPDATE oem_details_pages
SET image_status = 'pending', updated_at = now()
WHERE image_status IN ('error', 'running');
"

for ((i = 0; i < WORKERS; i++)); do
  cat > "storage/polaris-worker-${i}.sh" <<EOF
#!/bin/sh
echo "[polaris-worker-${i}] supervisor start \$(date -u +%Y-%m-%dT%H:%M:%SZ) workers=${WORKERS} concurrency=${CONCURRENCY}" >> /app/storage/polaris-crawl-images-w${i}.log
while true; do
  python -u -m app.cli crawl-remotors-details \\
    --phase images --db-code polaris \\
    --worker-id ${i} --workers ${WORKERS} --concurrency ${CONCURRENCY}
  code=\$?
  echo "[polaris-worker-${i}] python exit \$code at \$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /app/storage/polaris-crawl-images-w${i}.log
  if [ "\$code" = "0" ]; then
    echo "[polaris-worker-${i}] queue empty, stop supervisor" >> /app/storage/polaris-crawl-images-w${i}.log
    break
  fi
  if [ "\$code" = "137" ]; then
    echo "[polaris-worker-${i}] SIGKILL/OOM — wait 30s" >> /app/storage/polaris-crawl-images-w${i}.log
    sleep 30
  else
    sleep 5
  fi
done
EOF
  chmod +x "storage/polaris-worker-${i}.sh"
  docker compose exec -d -T \
    -e OEM_PARTSTREAM_APPKEY="${APPKEY}" \
    -e OEM_PARTSTREAM_ARIV="${ARIV}" \
    -e OEM_PARTSTREAM_ARIL="${ARIL}" \
    -e PYTHONUNBUFFERED=1 \
    oem_backend \
    /app/storage/polaris-worker-${i}.sh
  echo "[polaris-images] worker ${i}/${WORKERS} x${CONCURRENCY} -> storage/polaris-crawl-images-w${i}.log"
  if ((i == 0 && WORKERS > 1)); then
    sleep 3
  fi
done

echo
echo "Watch:"
echo "  tail -f storage/polaris-crawl-images-w0.log"
echo "  tail -f storage/polaris-crawl-images-w1.log"
echo "  tail -f storage/polaris-crawl-images-w2.log"
echo "Stop: docker compose restart oem_backend"
echo "Do NOT restart oem_backend while crawl is running."
