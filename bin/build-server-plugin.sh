#!/usr/bin/env bash
#
# Builds the PHP 5.6-compatible Reprint Server WordPress plugin ZIP from the typed
# source tree. The source checkout is never rewritten; all downgrade work is
# confined to a temporary staging directory.
#
# Usage:
#   ./bin/build-server-plugin.sh
#   ./bin/build-server-plugin.sh /path/to/reprint-exporter-wp.zip
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUTPUT_PATH="${1:-$PROJECT_ROOT/reprint-exporter-wp.zip}"

case "$OUTPUT_PATH" in
    /*) ;;
    *) OUTPUT_PATH="$(pwd)/$OUTPUT_PATH" ;;
esac

for command_name in php composer zip; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Error: $command_name not found in PATH." >&2
        exit 1
    fi
done

if [ ! -x "$PROJECT_ROOT/tools/php56-build/vendor/bin/rector" ]; then
    echo "Error: Reprint Server build dependencies are missing." >&2
    echo "Run: composer install --no-dev --working-dir=tools/php56-build" >&2
    exit 1
fi

BUILD_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/reprint-server-build.XXXXXX")"
trap 'rm -rf "$BUILD_ROOT"' EXIT

mkdir -p \
    "$BUILD_ROOT/reprint-server-wp" \
    "$BUILD_ROOT/packages/reprint-server"

cp -R "$PROJECT_ROOT/reprint-server-wp/." "$BUILD_ROOT/reprint-server-wp/"
cp -R "$PROJECT_ROOT/packages/reprint-server/." "$BUILD_ROOT/packages/reprint-server/"

# Runtime dependencies are generated from the downgraded package below. Never
# carry a development vendor tree or a local secret into the release artifact.
rm -rf "$BUILD_ROOT/reprint-server-wp/vendor"
rm -f \
    "$BUILD_ROOT/reprint-server-wp/composer.lock" \
    "$BUILD_ROOT/reprint-server-wp/secret.php"

php "$PROJECT_ROOT/bin/downgrade-server-plugin.php" "$BUILD_ROOT"

# The Composer packages describe the typed source requirement. Only the
# generated plugin distribution advertises and resolves for PHP 5.6.
php -r '
$paths = array($argv[1], $argv[2]);
foreach ($paths as $path) {
    $contents = file_get_contents($path);
    $manifest = json_decode($contents, true);
    if (!is_array($manifest) || !isset($manifest["require"])) {
        fwrite(STDERR, "Error: could not read Composer manifest " . $path . ".\n");
        exit(1);
    }
    $manifest["require"]["php"] = ">=5.6.20";
    if ($path === $argv[1]) {
        if (!isset($manifest["config"]) || !is_array($manifest["config"])) {
            $manifest["config"] = array();
        }
        if (!isset($manifest["config"]["platform"]) || !is_array($manifest["config"]["platform"])) {
            $manifest["config"]["platform"] = array();
        }
        $manifest["config"]["platform"]["php"] = "5.6.20";
    }
    file_put_contents(
        $path,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}
' \
    "$BUILD_ROOT/reprint-server-wp/composer.json" \
    "$BUILD_ROOT/packages/reprint-server/composer.json"

sed -i.bak \
    's/^ \* Requires PHP: .*/ * Requires PHP: 5.6.20/' \
    "$BUILD_ROOT/reprint-server-wp/index.php"
rm -f "$BUILD_ROOT/reprint-server-wp/index.php.bak"

if ! grep -Fq ' * Requires PHP: 5.6.20' "$BUILD_ROOT/reprint-server-wp/index.php"; then
    echo "Error: failed to set the generated plugin PHP requirement." >&2
    exit 1
fi

COMPOSER_DISABLE_NETWORK=1 COMPOSER_MIRROR_PATH_REPOS=1 composer update \
    --no-dev \
    --no-audit \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --working-dir="$BUILD_ROOT/reprint-server-wp"

mkdir -p "$(dirname "$OUTPUT_PATH")"
# zip updates an existing archive in place, so remove any leftover first. The
# exclusion must be './secret.php': a '*/secret.php' pattern does not match the
# root-level entry produced by this layout.
rm -f "$OUTPUT_PATH"
(
    cd "$BUILD_ROOT/reprint-server-wp"
    zip -qr "$OUTPUT_PATH" . -x './secret.php'
)

echo "Built $OUTPUT_PATH"
