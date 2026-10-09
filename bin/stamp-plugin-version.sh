#!/usr/bin/env bash
#
# Stamps a version string into the WordPress plugin header, the
# namespaced Reprint Server VERSION constant, and the readme Stable tag.
#
# Usage:
#   ./bin/stamp-plugin-version.sh 0.2.0        # release
#   ./bin/stamp-plugin-version.sh 0.3.0-dev    # dev bump
#
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <version>" >&2
    exit 1
fi

VERSION="$1"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

INDEX="$PROJECT_ROOT/reprint-server-wp/index.php"
LIB="$PROJECT_ROOT/reprint-server-wp/lib.php"
README="$PROJECT_ROOT/reprint-server-wp/readme.txt"

# Match any version string (with or without -dev suffix) in both locations.
# Use a temp-file suffix for sed -i portability (macOS vs GNU).
sed -i.bak "s/Version: [0-9][0-9.]*\(-dev\)\{0,1\}/Version: $VERSION/" "$INDEX" && rm -f "$INDEX.bak"
sed -i.bak "/define(__NAMESPACE__/s/'[0-9][0-9.]*\(-dev\)\{0,1\}'/'$VERSION'/" "$LIB" && rm -f "$LIB.bak"

sed -i.bak "s/^Stable tag: .*/Stable tag: $VERSION/" "$README" && rm -f "$README.bak"

# Verify the stamp took effect — fail loudly if it didn't.
if ! grep -q "Version: $VERSION" "$INDEX"; then
    echo "Error: failed to stamp version in index.php" >&2
    exit 1
fi
if ! grep -Fq "define(__NAMESPACE__ . '\\\\VERSION', '$VERSION');" "$LIB"; then
    echo "Error: failed to stamp version in lib.php" >&2
    exit 1
fi

if ! grep -Fxq "Stable tag: $VERSION" "$README"; then
    echo "Error: failed to stamp version in readme.txt" >&2
    exit 1
fi

echo "Stamped plugin version: $VERSION"
