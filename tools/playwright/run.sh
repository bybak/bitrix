#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TOOLS_DIR="$ROOT_DIR/tools/playwright"

IMAGE_NAME="mf-playwright:local"

cmd="${1:-}"
shift || true

case "$cmd" in
  build)
    docker build -t "$IMAGE_NAME" "$TOOLS_DIR"
    ;;

  compare)
    docker run --rm \
      -v "$TOOLS_DIR/out:/out" \
      --add-host=host.docker.internal:host-gateway \
      "$IMAGE_NAME" \
      node /work/tools/playwright/compare.mjs "$@"
    ;;

  *)
    echo "Usage:"
    echo "  tools/playwright/run.sh build"
    echo "  tools/playwright/run.sh compare --a <urlA> --b <urlB> --selector <css> --out <dir> [--viewport <w>x<h>]"
    exit 2
    ;;
esac

