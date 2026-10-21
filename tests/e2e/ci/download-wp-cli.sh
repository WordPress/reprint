#!/usr/bin/env bash
set -euo pipefail
PROJECT_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
"$PROJECT_ROOT/bin/download-verified.sh" \
    'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar' \
    'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c' \
    /tmp/wp-cli.phar
chmod +x /tmp/wp-cli.phar
