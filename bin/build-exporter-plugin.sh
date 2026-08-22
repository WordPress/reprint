#!/usr/bin/env bash
# Legacy build entry point. Use build-server-plugin.sh for new integrations.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec "$SCRIPT_DIR/build-server-plugin.sh" "$@"
