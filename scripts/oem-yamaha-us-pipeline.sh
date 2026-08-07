#!/usr/bin/env bash
# DEPRECATED: yamaha-motor.com US pipeline.
# Use scripts/oem-yamaha-ps-pipeline.sh (PartStream YAM + YAMMR → YMH-US).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "DEPRECATED: oem-yamaha-us-pipeline.sh (yamaha-motor.com)." >&2
echo "Redirecting to oem-yamaha-ps-pipeline.sh (PartStream YAM/YAMMR)." >&2
echo "" >&2

exec bash "${ROOT}/scripts/oem-yamaha-ps-pipeline.sh" "$@"
