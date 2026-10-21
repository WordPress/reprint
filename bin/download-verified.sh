#!/usr/bin/env bash
# Download one recorded artifact, checking its SHA-256 before replacing the cache.
set -euo pipefail

url="$1"
expected_sha256="$2"
destination="$3"

file_sha256() {
    php -r 'echo hash_file("sha256", $argv[1]);' "$1"
}

if [ -f "$destination" ] && [ "$(file_sha256 "$destination")" = "$expected_sha256" ]; then
    exit 0
fi

download="$(mktemp "${destination}.download.XXXXXX")"
trap 'rm -f "$download"' EXIT
curl --fail --silent --show-error --location \
    --retry 5 --retry-delay 1 --retry-all-errors --connect-timeout 15 \
    "$url" -o "$download"
actual_sha256="$(file_sha256 "$download")"
if [ "$actual_sha256" != "$expected_sha256" ]; then
    echo "SHA-256 mismatch for $url: expected $expected_sha256, received $actual_sha256." >&2
    exit 1
fi
mv "$download" "$destination"
