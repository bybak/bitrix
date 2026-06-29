#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "[wipe] stopping oem services..."
docker compose stop oem_backend oem_db 2>/dev/null || true

VOLUME="$(docker volume ls --format '{{.Name}}' | grep oem_pg_data | head -1 || true)"
if [[ -n "${VOLUME}" ]]; then
  echo "[wipe] removing postgres volume: ${VOLUME}"
  docker compose rm -f oem_backend oem_db 2>/dev/null || true
  docker volume rm "${VOLUME}" || true
else
  echo "[wipe] postgres volume not found (already clean)"
fi

echo "[wipe] removing storage artifacts..."
rm -rf storage/oem-diagrams/remotors storage/remotors-html
rm -f storage/remotors-snapshot*.db storage/remotors-details-crawl.db
find storage/oem-diagrams -mindepth 1 -maxdepth 1 -type d ! -name remotors -exec rm -rf {} + 2>/dev/null || true

echo "[wipe] starting fresh postgres..."
docker compose up -d oem_db
echo "[wipe] waiting for postgres..."
for i in $(seq 1 60); do
  if docker compose exec -T oem_db pg_isready -U oem_user -d oem_catalog >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

docker compose up -d oem_backend
echo "[wipe] done — fresh schema on new volume"
