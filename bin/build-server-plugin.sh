#!/usr/bin/env bash
#
# Builds the Reprint Server plugin ZIP for GitHub (PHP 5.6) or WordPress.org
# (PHP 7.2). The source checkout is never rewritten; all target-specific work
# is confined to a temporary staging directory.
#
# Usage:
#   ./bin/build-server-plugin.sh
#   ./bin/build-server-plugin.sh /path/to/reprint-exporter-wp.zip
#   ./bin/build-server-plugin.sh /path/to/reprint-wordpress-org.zip wordpress-org
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUTPUT_PATH="${1:-$PROJECT_ROOT/reprint-exporter-wp.zip}"
TARGET="${2:-github}"

if [ "$#" -gt 2 ] || [[ "$TARGET" != "github" && "$TARGET" != "wordpress-org" ]]; then
    echo "Usage: $0 [output.zip] [github|wordpress-org]; observed target: $TARGET" >&2
    exit 1
fi

if [ "$TARGET" = "wordpress-org" ]; then
    MINIMUM_PHP_VERSION="7.2"
else
    MINIMUM_PHP_VERSION="5.6.20"
fi

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

if [ "$TARGET" = "github" ] && [ ! -x "$PROJECT_ROOT/tools/php56-build/vendor/bin/rector" ]; then
    echo "Error: Reprint Server build dependencies are missing." >&2
    echo "Run: composer install --no-dev --working-dir=tools/php56-build" >&2
    exit 1
fi

BUILD_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/reprint-server-build.XXXXXX")"
trap 'rm -rf "$BUILD_ROOT"' EXIT

mkdir -p \
    "$BUILD_ROOT/reprint-server-wp" \
    "$BUILD_ROOT/packages/reprint-server"

# Copy the package roots explicitly; local credentials and development metadata
# at those roots are not release inputs.
for plugin_file in index.php lib.php compat.php uninstall.php readme.txt README.md composer.json; do
    cp "$PROJECT_ROOT/reprint-server-wp/$plugin_file" "$BUILD_ROOT/reprint-server-wp/"
done
cp -R "$PROJECT_ROOT/reprint-server-wp/wordpress" "$BUILD_ROOT/reprint-server-wp/"
cp "$PROJECT_ROOT/packages/reprint-server/composer.json" "$BUILD_ROOT/packages/reprint-server/"
cp "$PROJECT_ROOT/packages/reprint-server/README.md" "$BUILD_ROOT/packages/reprint-server/"
cp -R "$PROJECT_ROOT/packages/reprint-server/src" "$BUILD_ROOT/packages/reprint-server/"
cp "$PROJECT_ROOT/LICENSE" "$BUILD_ROOT/reprint-server-wp/LICENSE"
cp "$PROJECT_ROOT/LICENSE" "$BUILD_ROOT/packages/reprint-server/LICENSE"

# Runtime dependencies are generated from the downgraded package below. Never
# carry a development vendor tree, a local secret, or locally enrolled keys
# into the release artifact.
rm -rf "$BUILD_ROOT/reprint-server-wp/vendor"
rm -f \
    "$BUILD_ROOT/reprint-server-wp/composer.lock" \
    "$BUILD_ROOT/reprint-server-wp/secret.php" \
    "$BUILD_ROOT/reprint-server-wp/public-keys.php"

if [ "$TARGET" = "wordpress-org" ]; then
    php "$PROJECT_ROOT/bin/prepare-wordpress-org-plugin.php" "$BUILD_ROOT"
fi

if [ "$TARGET" = "github" ]; then
    php "$PROJECT_ROOT/bin/downgrade-server-plugin.php" "$BUILD_ROOT"
fi

# Resolve the packaged runtime for the target’s declared PHP minimum. The
# WordPress.org build keeps the maintained PHP 7.2 source without downgrading it.
php -r '
$paths = array($argv[1], $argv[2]);
$minimum_php_version = $argv[3];
foreach ($paths as $path) {
    $contents = file_get_contents($path);
    $manifest = json_decode($contents, true);
    if (!is_array($manifest) || !isset($manifest["require"])) {
        fwrite(STDERR, "Error: could not read Composer manifest " . $path . ".\n");
        exit(1);
    }
    $manifest["require"]["php"] = ">=" . $minimum_php_version;
    if ($path === $argv[1]) {
        if (!isset($manifest["config"]) || !is_array($manifest["config"])) {
            $manifest["config"] = array();
        }
        if (!isset($manifest["config"]["platform"]) || !is_array($manifest["config"]["platform"])) {
            $manifest["config"]["platform"] = array();
        }
        $manifest["config"]["platform"]["php"] = $minimum_php_version;
    }
    file_put_contents(
        $path,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}
' \
    "$BUILD_ROOT/reprint-server-wp/composer.json" \
    "$BUILD_ROOT/packages/reprint-server/composer.json" \
    "$MINIMUM_PHP_VERSION"

sed -i.bak \
    "s/^ \* Requires PHP: .*/ * Requires PHP: $MINIMUM_PHP_VERSION/" \
    "$BUILD_ROOT/reprint-server-wp/index.php"
rm -f "$BUILD_ROOT/reprint-server-wp/index.php.bak"
sed -i.bak "s/^Requires PHP: .*/Requires PHP: $MINIMUM_PHP_VERSION/" "$BUILD_ROOT/reprint-server-wp/readme.txt"
rm -f "$BUILD_ROOT/reprint-server-wp/readme.txt.bak"

if ! grep -Fq " * Requires PHP: $MINIMUM_PHP_VERSION" "$BUILD_ROOT/reprint-server-wp/index.php"; then
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

if [ "$TARGET" = "wordpress-org" ]; then
    mv "$BUILD_ROOT/reprint-server-wp/index.php" "$BUILD_ROOT/reprint-server-wp/reprint.php"
fi

php "$PROJECT_ROOT/tests/server-plugin-package.php" "$BUILD_ROOT/reprint-server-wp" "" "$TARGET"

mkdir -p "$(dirname "$OUTPUT_PATH")"
# zip updates an existing archive in place, so remove any leftover first. The
# exclusions must be './secret.php' and './public-keys.php': a '*/secret.php'
# pattern does not match the root-level entry produced by this layout.
rm -f "$OUTPUT_PATH"
(
    cd "$BUILD_ROOT/reprint-server-wp"
    if [ "$TARGET" = "wordpress-org" ]; then
        cd "$BUILD_ROOT"
        mv reprint-server-wp reprint
        zip -qr "$OUTPUT_PATH" reprint
    else
        zip -qr "$OUTPUT_PATH" . -x './secret.php' './public-keys.php'
    fi
)

echo "Built $OUTPUT_PATH"
