#!/usr/bin/env bash
# Real MySQL 5.5 is required: modern MariaDB cannot test missing FROM_BASE64().
set -euo pipefail

container="reprint-e2e-mysql55-source"
password="e2e_mysql55_password"
port="3355"

docker run --detach --name "$container" --platform linux/amd64 \
    --env MYSQL_ROOT_PASSWORD="$password" \
    --publish "127.0.0.1:${port}:3306" mysql:5.5.62

for _attempt in $(seq 1 60); do
    if docker exec "$container" mysql --protocol=TCP --host=127.0.0.1 \
        --user=root --password="$password" --execute='SELECT VERSION()' >/dev/null 2>&1; then
        {
            echo 'E2E_MYSQL55_HOST=127.0.0.1'
            echo "E2E_MYSQL55_PORT=${port}"
            echo 'E2E_MYSQL55_USER=root'
            echo "E2E_MYSQL55_PASS=${password}"
        } >> "$GITHUB_ENV"
        exit 0
    fi
    sleep 1
done

echo 'MySQL 5.5 did not become ready within 60 seconds.' >&2
docker logs "$container" >&2 || true
exit 1
