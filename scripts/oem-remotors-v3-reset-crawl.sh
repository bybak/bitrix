#!/usr/bin/env bash
# Reset HTML/image/parse progress in PostgreSQL (after deleting storage/remotors-html).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "[reset-crawl] stopping workers..."
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

echo "[reset-crawl] resetting oem_details_pages..."
docker compose exec -T oem_db psql -U oem_user -d oem_catalog -c "
ALTER TABLE oem_details_pages ADD COLUMN IF NOT EXISTS image_url TEXT;
UPDATE oem_details_pages SET
  html_status = 'pending',
  html_path = NULL,
  html_hash = NULL,
  image_url = NULL,
  image_status = 'pending',
  image_path = NULL,
  parse_status = 'pending',
  error_message = NULL,
  fetched_at = NULL,
  parsed_at = NULL,
  updated_at = now();
"

echo "[reset-crawl] counts:"
docker compose exec -T oem_db psql -U oem_user -d oem_catalog -c "
SELECT html_status, COUNT(*) FROM oem_details_pages GROUP BY html_status ORDER BY 1;
"

rm -f storage/remotors-details-crawl.db storage/remotors-details-crawl.db-shm storage/remotors-details-crawl.db-wal 2>/dev/null || true
echo "[reset-crawl] done (delete storage/remotors-html manually if not already)"
