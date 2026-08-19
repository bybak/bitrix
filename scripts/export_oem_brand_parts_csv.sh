#!/usr/bin/env bash
# Export Husqvarna + KTM parts CSV from remotors oem_db.
# Output: storage/exports/oem_parts_Husqvarna_HUM.csv , oem_parts_KTM_KTM.csv
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

OUT_DIR="${OUT_DIR:-storage/exports}"
DELIMITER="${DELIMITER:-;}"
BRANDS="${BRANDS:-}"

mkdir -p "${OUT_DIR}"

cmd=(
  docker compose run --rm --no-deps
  -v "${ROOT}/scripts:/scripts:ro"
  -v "${ROOT}/storage:/app/storage"
  -e OEM_DATABASE_DSN=postgresql://oem_user:oem_password@oem_db:5432/oem_catalog
  oem_backend
  python /scripts/export_oem_brand_parts_csv.py
  --out-dir /app/storage/exports
)

if [[ -n "${BRANDS}" ]]; then
  cmd+=(--brands "${BRANDS}")
fi
if [[ "${DELIMITER}" != ";" ]]; then
  cmd+=(--delimiter "${DELIMITER}")
fi

"${cmd[@]}"

echo "Files in ${OUT_DIR}:"
ls -lh "${OUT_DIR}"/oem_parts_*.csv 2>/dev/null || true
