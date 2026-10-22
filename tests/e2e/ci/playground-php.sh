#!/usr/bin/env bash
# Wrapper that invokes PHP through WordPress Playground's WASM runtime.
# Used by e2e tests when PHP_BINARY points here instead of the system `php`.
#
# By default this delegates to @wp-playground/cli php so the CI still covers
# the public CLI path. When WP_MYSQL_PARSER_EXTENSION=1 is set, or when
# PLAYGROUND_PHP_USE_WASM_RUNNER=1 is set, it uses the npm-installed
# @php-wasm/node runner because the CLI does not expose arbitrary external PHP
# extensions.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

if [ "${WP_MYSQL_PARSER_EXTENSION:-}" = "1" ] || [ "${PLAYGROUND_PHP_USE_WASM_RUNNER:-}" = "1" ]; then
    if ! node --experimental-wasm-jspi -e 'process.exit(typeof WebAssembly.Suspending === "function" && typeof WebAssembly.promising === "function" ? 0 : 1)' >/dev/null 2>&1; then
        echo 'Playground PHP needs JSPI support. Use Node.js 24 or newer before running the harness.' >&2
        exit 1
    fi
    exec node --experimental-wasm-jspi "$SCRIPT_DIR/php-wasm-runner.mjs" "$@"
fi

PLAYGROUND_CLI="$SCRIPT_DIR/../node_modules/.bin/wp-playground-cli"
if [ ! -x "$PLAYGROUND_CLI" ]; then
    echo 'Playground CLI is not installed. Run npm ci in tests/e2e first.' >&2
    exit 1
fi

MOUNT_ARGS=()

# Auto-mount any paths referenced in the arguments
for arg in "$@"; do
    case "$arg" in
        /*)
            # Extract the directory from the path
            dir=$(dirname "$arg")
            # Mount the top-level directory to avoid too many mounts
            top=$(echo "$dir" | cut -d/ -f1-3)
            if [ -d "$top" ] || [ -f "$arg" ]; then
                MOUNT_ARGS+=("--mount=${top}:${top}")
            fi
            ;;
    esac
done

# Always mount common paths
[ -d /tmp ] && MOUNT_ARGS+=("--mount=/tmp:/tmp")
[ -d /srv ] && MOUNT_ARGS+=("--mount=/srv:/srv")

# Deduplicate mount args
UNIQUE_MOUNTS=($(printf '%s\n' "${MOUNT_ARGS[@]}" | sort -u))

exec "$PLAYGROUND_CLI" php "${UNIQUE_MOUNTS[@]}" -- "$@"
