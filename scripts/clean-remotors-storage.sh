#!/bin/bash
# Remove Remotors pipeline artifacts from storage/, keep snapshot + diagram images.
#
# Usage:
#   bash scripts/clean-remotors-storage.sh
#   SNAPSHOT_KEEP=remotors-snapshot-20260624_1606.db bash scripts/clean-remotors-storage.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/storage"

KEEP_SNAPSHOT="${SNAPSHOT_KEEP:-remotors-snapshot-20260624_1606.db}"
REMOVED=0

echo "=== Clean storage (keep snapshot + oem-diagrams) ==="
echo "Keep snapshot: $KEEP_SNAPSHOT"
echo ""

for path in *; do
  [ -e "$path" ] || continue
  case "$path" in
    oem-diagrams)
      echo "  keep  $path/"
      ;;
    "$KEEP_SNAPSHOT"|"${KEEP_SNAPSHOT}"-shm|"${KEEP_SNAPSHOT}"-wal)
      echo "  keep  $path"
      ;;
    .gitkeep|.DS_Store)
      echo "  keep  $path"
      ;;
    *)
      echo "  rm    $path"
      rm -rf "$path"
      REMOVED=$((REMOVED + 1))
      ;;
  esac
done

echo ""
echo "Removed $REMOVED items. Snapshot and oem-diagrams/ preserved."
