#!/usr/bin/env bash
# Export Yamaha YMH-JP parts CSV from yamaha_db (MegaZip enrichment).
# Output:
#   storage/exports/oem_parts_Yamaha_YMH-JP.csv
#   storage/exports/oem_parts_Yamaha_YMH-JP_with_price.csv  (ONLY_WITH_PRICE=1)
# Columns: Артикул;Наименование;Бренд;Цена
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

OUT_DIR="${OUT_DIR:-storage/exports}"
DELIMITER="${DELIMITER:-;}"
ONLY_WITH_PRICE="${ONLY_WITH_PRICE:-0}"

mkdir -p "${OUT_DIR}"

cmd=(
  docker compose run --rm --no-deps
  -e PYTHONUNBUFFERED=1
  -v "${ROOT}/scripts:/scripts:ro"
  -v "${ROOT}/storage:/app/storage"
  -e OEM_YAMAHA_DATABASE_DSN=postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog
  oem_backend
  python /scripts/export_yamaha_jp_parts_csv.py
  --out-dir /app/storage/exports
  --delimiter "${DELIMITER}"
)

if [[ "${ONLY_WITH_PRICE}" == "1" || "${ONLY_WITH_PRICE}" == "true" ]]; then
  cmd+=(--only-with-price --out-name oem_parts_Yamaha_YMH-JP_with_price.csv)
else
  cmd+=(--out-name oem_parts_Yamaha_YMH-JP.csv)
fi

"${cmd[@]}"

echo "Files in ${OUT_DIR}:"
ls -lh "${OUT_DIR}"/oem_parts_Yamaha*.csv 2>/dev/null || true
