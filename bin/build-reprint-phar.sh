#!/usr/bin/env bash
#
# Builds reprint.phar — a self-contained, lean archive of the import client
# with all its runtime dependencies baked in.
#
# Uses Box (box-project/box) for GZ compression and PHP comment stripping
# (line-number-preserving, so stack traces stay accurate).
#
# Usage:
#   ./bin/build-reprint-phar.sh            # outputs reprint.phar in project root
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# ── Preflight checks ──────────────────────────────────────────────

if ! command -v php >/dev/null 2>&1; then
    echo "Error: php not found in PATH" >&2
    exit 1
fi

# Verify the submodule is initialised
if [ ! -f "$PROJECT_ROOT/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/parser/class-wp-parser.php" ]; then
    echo "Error: sqlite-database-integration submodule not initialised." >&2
    echo "Run: git submodule update --init" >&2
    exit 1
fi

# Verify composer deps are installed (--no-dev is fine)
if [ ! -f "$PROJECT_ROOT/vendor/autoload.php" ]; then
    echo "Error: vendor/ not found. Run: composer install --no-dev" >&2
    exit 1
fi

# Box does not follow Composer's local package symlinks into the PHAR. Replace
# them with mirrors for the build, then restore the development install.
local_package_names=(
    wp-php-toolkit/reprint-server
    wp-php-toolkit/reprint-client
)
local_packages_are_symlinked=false
if [ -L "$PROJECT_ROOT/vendor/wp-php-toolkit/reprint-server" ] ||
    [ -L "$PROJECT_ROOT/vendor/wp-php-toolkit/reprint-client" ]; then
    if ! command -v composer >/dev/null 2>&1; then
        echo "Error: composer not found in PATH." >&2
        exit 1
    fi
    local_packages_are_symlinked=true
    trap '
        if $local_packages_are_symlinked; then
            composer reinstall --no-interaction "${local_package_names[@]}"
        fi
    ' EXIT
    COMPOSER_MIRROR_PATH_REPOS=1 composer reinstall --no-interaction "${local_package_names[@]}"
fi

# ── Locate or download Box ────────────────────────────────────────

BOX_PHAR="$PROJECT_ROOT/box.phar"
if ! [ -f "$BOX_PHAR" ]; then
    echo "Downloading box.phar …"
    BOX_PHAR_DOWNLOAD="$BOX_PHAR.download"
    rm -f "$BOX_PHAR_DOWNLOAD"
    curl --fail --silent --show-error --location \
        --retry 5 --retry-delay 1 --retry-all-errors --connect-timeout 15 \
        "https://github.com/box-project/box/releases/latest/download/box.phar" \
        -o "$BOX_PHAR_DOWNLOAD"
    mv "$BOX_PHAR_DOWNLOAD" "$BOX_PHAR"
    chmod +x "$BOX_PHAR"
fi

# ── Compute version ──────────────────────────────────────────────
#
# Tagged commits  → "v0.1.8"
# Trunk commits   → "v0.1.8-trunk"  (latest tag + "-trunk")
#
cd "$PROJECT_ROOT"

if git describe --exact-match --tags HEAD >/dev/null 2>&1; then
    VERSION=$(git describe --exact-match --tags HEAD)
else
    LATEST_TAG=$(git tag -l 'v*' --sort=-v:refname | head -1)
    VERSION="${LATEST_TAG:-v0.0.0}-trunk"
fi
echo "$VERSION" > packages/reprint-client/src/VERSION
echo "Version: $VERSION"

# ── Build ─────────────────────────────────────────────────────────

rm -f reprint.phar

php -d phar.readonly=0 "$BOX_PHAR" compile

if $local_packages_are_symlinked; then
    composer reinstall --no-interaction "${local_package_names[@]}"
    local_packages_are_symlinked=false
fi

SIZE=$(du -h reprint.phar | cut -f1)
echo ""
echo "Output: reprint.phar ($SIZE)"
