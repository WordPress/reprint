#!/usr/bin/env bash
# Starts all services and runs E2E tests inside the Docker container.
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.2}"
FPM_SOCKET="/run/php/e2e.sock"

echo "=== Starting services ==="

# MariaDB
mysqld_safe &
for i in $(seq 1 30); do
    if mysqladmin ping --silent 2>/dev/null; then break; fi
    sleep 1
done

# Create DB users
mysql <<'SQL'
CREATE USER IF NOT EXISTS 'e2e_admin'@'127.0.0.1' IDENTIFIED BY 'e2e_password';
GRANT ALL PRIVILEGES ON *.* TO 'e2e_admin'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'e2e_admin'@'localhost' IDENTIFIED BY 'e2e_password';
GRANT ALL PRIVILEGES ON *.* TO 'e2e_admin'@'localhost' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw';
CREATE USER IF NOT EXISTS 'e2e_restricted'@'127.0.0.1' IDENTIFIED BY 'e2e_restricted_pw';
FLUSH PRIVILEGES;
SQL

# PHP-FPM
mkdir -p /run/php
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/e2e.conf" <<EOF
[e2e]
user = nginx
group = nginx
listen = ${FPM_SOCKET}
listen.owner = nginx
listen.group = nginx
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 120
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 50M
php_admin_value[error_reporting] = E_ALL
php_admin_value[display_errors] = Off
php_admin_value[log_errors] = On
php_admin_value[error_log] = /tmp/php-e2e-errors.log
php_admin_value[user_ini.cache_ttl] = 0
php_admin_value[realpath_cache_ttl] = 0
env[SITE_EXPORT_TEST_MODE] = 1
EOF
rm -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
"php-fpm${PHP_VERSION}" --nodaemonize &

# Wait for FPM socket
for i in $(seq 1 30); do
    if [ -S "$FPM_SOCKET" ]; then break; fi
    sleep 0.5
done

# Nginx — generate configs from site-registry.json
REGISTRY="/app/tests/e2e/site-registry.json"
SITE_ROOT=$(jq -r '.siteRoot' "$REGISTRY")
mkdir -p "$SITE_ROOT"
chown nginx:nginx "$SITE_ROOT"

cat > /etc/nginx/nginx.conf <<'NGINXCONF'
user nginx;
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log;
events { worker_connections 768; }
http {
    sendfile on;
    tcp_nopush on;
    types_hash_max_size 2048;
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log /var/log/nginx/access.log;
    client_max_body_size 50m;
    include /etc/nginx/conf.d/*.conf;
}
NGINXCONF

rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf

# Standard sites — serve from WordPress root so that index.php
# bootstraps WordPress and the site-export plugin handles the request.
jq -r '.sites | to_entries[] | select((.value.nginx // "standard") == "standard") | "\(.key) \(.value.port)"' "$REGISTRY" | while read site port; do
    cat > "/etc/nginx/conf.d/e2e-${site}.conf" <<VHOST
server {
    listen 127.0.0.1:${port};
    root ${SITE_ROOT}/${site};
    index index.php;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \\.php\$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param SITE_EXPORT_TEST_MODE "1";
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
    }
}
VHOST
done

# Database cursor header limit sites
jq -r '.sites | to_entries[] | select(.value.nginx == "cursor-header-limit") | "\(.key) \(.value.port)"' "$REGISTRY" | while read site port; do
    cat > "/etc/nginx/conf.d/e2e-${site}.conf" <<VHOST
server {
    listen 127.0.0.1:${port};
    root ${SITE_ROOT}/${site};
    index index.php;

    # Let nginx parse the request, then deliberately return 431 at the
    # 8191-byte X-Export-Cursor threshold used by this test.
    large_client_header_buffers 4 64k;
    if (\$http_x_export_cursor ~ "^.{8191,}\$") {
        return 431;
    }

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param SITE_EXPORT_TEST_MODE "1";
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
    }
}
VHOST
done

# Redirect sites
jq -r '.sites | to_entries[] | select(.value.nginx == "redirect") | "\(.key) \(.value.port) \(.value.redirectTo)"' "$REGISTRY" | while read site port target; do
    cat > "/etc/nginx/conf.d/e2e-${site}.conf" <<VHOST
server {
    listen 127.0.0.1:${port};
    location / { return 301 http://127.0.0.1:${target}\$request_uri; }
}
VHOST
done

# Buffered sites
jq -r '.sites | to_entries[] | select(.value.nginx == "buffered") | "\(.key) \(.value.port)"' "$REGISTRY" | while read site port; do
    cat > "/etc/nginx/conf.d/e2e-${site}.conf" <<VHOST
server {
    listen 127.0.0.1:${port};
    root ${SITE_ROOT}/${site};
    index index.php;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \\.php\$ {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param SITE_EXPORT_TEST_MODE "1";
        fastcgi_read_timeout 120s;
        fastcgi_buffering on;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 8 128k;
    }
}
VHOST
done

nginx -t
nginx

echo "=== All services running ==="

# Run tests. Any args passed to the container are forwarded to vitest, so you
# can target specific files, e.g.:
#   docker run --rm reprint-e2e tests/import-51-only-remap-managed-composition.test.js
# With no args, the full suite runs (CI default).
cd /app/tests/e2e
echo "=== Running E2E tests ==="
npx vitest run "$@"
