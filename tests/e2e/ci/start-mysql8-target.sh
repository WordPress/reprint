#!/usr/bin/env bash
# Starts the separate Oracle MySQL 8 target used by the cross-engine E2E test.
set -euo pipefail

container="reprint-e2e-mysql8-target"
root_password="e2e_mysql8_root_password"
port="3308"

docker run --detach --name "$container" \
    --env MYSQL_ROOT_PASSWORD="$root_password" \
    --env MYSQL_ROOT_HOST="%" \
    --publish "127.0.0.1:${port}:3306" \
    mysql:8.0

# The image first starts a socket-only server to initialize the database, then
# shuts it down. Use TCP so readiness waits for the final server, not that
# temporary server disappearing between this probe and the version query.
ready=0
for _attempt in $(seq 1 60); do
    if docker exec \
        --env MYSQL_PWD="$root_password" \
        "$container" \
        mysql --protocol=TCP --host=127.0.0.1 \
        --user=root --execute='SELECT 1' >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done

if [ "$ready" -ne 1 ]; then
    echo "Oracle MySQL 8 did not become ready within 60 seconds." >&2
    docker logs "$container" >&2 || true
    exit 1
fi

version="$({
    docker exec \
        --env MYSQL_PWD="$root_password" \
        "$container" \
        mysql --protocol=TCP --host=127.0.0.1 \
        --user=root --batch --skip-column-names --execute='SELECT VERSION()'
} 2>/dev/null)"
if [[ "$version" != 8.0.* ]] || [[ "$version" == *MariaDB* ]]; then
    echo "Expected Oracle MySQL 8.0, got ${version}." >&2
    exit 1
fi

{
    echo "E2E_MYSQL8_HOST=127.0.0.1"
    echo "E2E_MYSQL8_PORT=${port}"
    echo "E2E_MYSQL8_USER=root"
    echo "E2E_MYSQL8_PASS=${root_password}"
} >> "$GITHUB_ENV"

echo "Oracle MySQL ${version} is ready on port ${port}."
