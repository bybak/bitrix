#!/usr/bin/env bash
# Snapshot Polaris / Polaris (Canada) PartStream trees from streamsdemo Power.
# Same approach as Arctic Cat ARC / ARC_CDN: two separate SQLite files, structure only.
# Does not crawl/parse details and does not import into PostgreSQL.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PYTHONUNBUFFERED=1

APPKEY="${OEM_PARTSTREAM_APPKEY:-lKCIzRNMxtbQA7q0ZL4j}"
ARIV="${OEM_PARTSTREAM_ARIV:-http://streamsdemo.arinet.com/Power}"
ARIL="${OEM_PARTSTREAM_ARIL:-en-US}"

ROOT_US="${ROOT_US:-POL}"
ROOT_CDN="${ROOT_CDN:-POL_CDN}"
SNAPSHOT_US="${SNAPSHOT_US:-storage/polaris-snapshot-pol.db}"
SNAPSHOT_CDN="${SNAPSHOT_CDN:-storage/polaris-snapshot-polcdn.db}"
CONCURRENCY="${SNAPSHOT_CONCURRENCY:-12}"
PHASE="${1:-help}"

run_ps_cli() {
  docker compose exec -T \
    -e OEM_PARTSTREAM_APPKEY="${APPKEY}" \
    -e OEM_PARTSTREAM_ARIV="${ARIV}" \
    -e OEM_PARTSTREAM_ARIL="${ARIL}" \
    oem_backend python -m app.cli "$@"
}

run_snapshot() {
  local roots="$1"
  local snapshot="$2"
  shift 2
  mkdir -p storage
  run_ps_cli snapshot-remotors-v3 \
    --roots "${roots}" \
    --snapshot "${snapshot}" \
    --concurrency "${CONCURRENCY}" \
    "$@"
}

summarize_snapshot() {
  local snapshot="$1"
  if [[ ! -f "${snapshot}" ]]; then
    echo "missing: ${snapshot}"
    return 1
  fi
  python3 - "${snapshot}" <<'PY'
import sqlite3
import sys

path = sys.argv[1]
con = sqlite3.connect(path)
con.row_factory = sqlite3.Row
print(f"file={path}")
for table in ("api_nodes", "catalog_variants", "catalog_assemblies", "scan_errors"):
    try:
        n = con.execute(f"SELECT COUNT(*) AS c FROM {table}").fetchone()["c"]
    except sqlite3.DatabaseError as exc:
        print(f"  {table}: ERROR {exc}")
        continue
    print(f"  {table}: {n}")
meta = dict(con.execute("SELECT key, value FROM meta").fetchall()) if con.execute(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='meta'"
).fetchone() else {}
for key in ("arib_codes", "completed_arib", "scan_status", "scanned_at"):
    if key in meta:
        print(f"  meta.{key}: {meta[key]}")
rels = con.execute(
    "SELECT rel, COUNT(*) AS c FROM api_nodes GROUP BY rel ORDER BY c DESC"
).fetchall()
if rels:
    print("  rels:")
    for row in rels:
        print(f"    {row['rel'] or '-'}: {row['c']}")
PY
}

case "${PHASE}" in
  list-roots)
    # One GetAssembly call per candidate — not a snapshot walk.
    docker compose exec -T \
      -e OEM_PARTSTREAM_APPKEY="${APPKEY}" \
      -e OEM_PARTSTREAM_ARIV="${ARIV}" \
      -e OEM_PARTSTREAM_ARIL="${ARIL}" \
      oem_backend python - <<'PY'
from app.remotors_v3.client import list_children, make_client

candidates = [
    "POL", "POL_CDN", "POLCDN", "POLC", "POLCAN", "POLARIS",
    "POL-CDN", "POLCA",
]
print("PartStream Power — Polaris root probe")
with make_client() as client:
    for code in candidates:
        try:
            kids = list_children(client, code)
        except Exception as exc:
            print(f"  {code:12} ERROR {exc}")
            continue
        titles = []
        for child in kids[:8]:
            titles.append((child.get("data") or "").strip() or "-")
        extra = "" if len(kids) <= 8 else f" … +{len(kids) - 8}"
        print(f"  {code:12} children={len(kids):4}  {', '.join(titles)}{extra}")
PY
    ;;
  snapshot-us)
    run_snapshot "${ROOT_US}" "${SNAPSHOT_US}"
    ;;
  snapshot-cdn)
    run_snapshot "${ROOT_CDN}" "${SNAPSHOT_CDN}"
    ;;
  snapshot-us-resume)
    run_snapshot "${ROOT_US}" "${SNAPSHOT_US}" --resume
    ;;
  snapshot-cdn-resume)
    run_snapshot "${ROOT_CDN}" "${SNAPSHOT_CDN}" --resume
    ;;
  summarize)
    summarize_snapshot "${SNAPSHOT_US}" || true
    echo
    summarize_snapshot "${SNAPSHOT_CDN}" || true
    ;;
  compare)
    python3 "${ROOT}/scripts/oem-polaris-compare-snapshots.py" \
      --us "${SNAPSHOT_US}" \
      --cdn "${SNAPSHOT_CDN}"
    ;;
  help|*)
    cat <<EOF
Usage: $0 {list-roots|snapshot-us|snapshot-cdn|snapshot-us-resume|snapshot-cdn-resume|summarize|compare}

Source: ${ARIV}
Creds:  appkey=${APPKEY}  aril=${ARIL}

Typical flow (structure only, two separate files):
  bash scripts/oem-polaris-snapshot.sh list-roots
  # confirm ARI codes, then:
  bash scripts/oem-polaris-snapshot.sh snapshot-us
  bash scripts/oem-polaris-snapshot.sh snapshot-cdn
  bash scripts/oem-polaris-snapshot.sh summarize
  bash scripts/oem-polaris-snapshot.sh compare

Two snapshots can run in parallel (different files / terminals).
If a walk stops: snapshot-us-resume / snapshot-cdn-resume.
On 429/WAF: SNAPSHOT_CONCURRENCY=4 or 8.

Env:
  ROOT_US                 default ${ROOT_US}
  ROOT_CDN                default ${ROOT_CDN}
  SNAPSHOT_US             default ${SNAPSHOT_US}
  SNAPSHOT_CDN            default ${SNAPSHOT_CDN}
  SNAPSHOT_CONCURRENCY    default ${CONCURRENCY}
  OEM_PARTSTREAM_APPKEY / OEM_PARTSTREAM_ARIV / OEM_PARTSTREAM_ARIL
EOF
    ;;
esac
