#!/usr/bin/env bash
# CI infrastructure setup for E2E tests.
# Installs and configures MariaDB, PHP FPM, and Nginx on an Ubuntu runner.
#
# Usage: setup-infrastructure.sh [php-version]
#   php-version defaults to 8.2 if not specified.
set -euo pipefail

PHP_VERSION="${1:-8.2}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REGISTRY="${SCRIPT_DIR}/../site-registry.json"
SITE_ROOT=$(jq -r '.siteRoot' "$REGISTRY")
FPM_SOCKET="/run/php/e2e.sock"
OPEN_BASEDIR_FPM_SOCKET="/run/php/e2e-open-basedir.sock"
NO_PDO_MYSQL_FPM_SOCKET="/run/php/e2e-no-pdo-mysql.sock"

echo "=== Setting up infrastructure with PHP ${PHP_VERSION} ==="

# ---------- PHP ----------
echo "=== Setting up PHP ${PHP_VERSION} ==="
# Every Launchpad-side endpoint we've relied on has had CI-killing
# outages this week:
#   - api.launchpad.net (used by `add-apt-repository`, behind launchpadlib)
#   - keyserver.ubuntu.com / keys.openpgp.org (key fetch fallbacks)
#   - ppa.launchpadcontent.net (the apt repo itself, network unreachable)
#
# We now expect the workflow to install PHP via shivammathur/setup-php,
# which uses the runner's pre-cached toolcache and doesn't go
# through Launchpad. If `php${PHP_VERSION}` isn't on PATH after that,
# it's a workflow misconfiguration rather than something this script
# should paper over.
if ! command -v "php${PHP_VERSION}" >/dev/null 2>&1; then
    echo "php${PHP_VERSION} not found on PATH — install it via shivammathur/setup-php in the workflow before calling this script." >&2
    exit 1
fi

# Make sure the 'php' CLI command uses the version we just installed
sudo update-alternatives --set php "/usr/bin/php${PHP_VERSION}"

# ---------- MariaDB ----------
echo "=== Installing MariaDB ==="
sudo apt-get install -y mariadb-server
sudo systemctl start mariadb

echo "=== Creating MySQL users ==="
sudo mysql <<'SQL'
CREATE USER IF NOT EXISTS 'e2e_admin'@'127.0.0.1' IDENTIFIED BY 'e2e_password';
GRANT ALL PRIVILEGES ON *.* TO 'e2e_admin'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'e2e_admin'@'localhost' IDENTIFIED BY 'e2e_password';
GRANT ALL PRIVILEGES ON *.* TO 'e2e_admin'@'localhost' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw';
CREATE USER IF NOT EXISTS 'e2e_restricted'@'127.0.0.1' IDENTIFIED BY 'e2e_restricted_pw';
FLUSH PRIVILEGES;
SQL

# ---------- Nginx ----------
echo "=== Installing Nginx ==="
sudo apt-get install -y nginx

# Stop nginx immediately — apt auto-starts it with the default config.
# We need it fully stopped before reconfiguring to avoid port conflicts.
sudo systemctl stop nginx

# ---------- nginx user ----------
echo "=== Creating nginx user ==="
sudo groupadd -f nginx
sudo useradd -r -g nginx -s /usr/sbin/nologin nginx 2>/dev/null || true

# ---------- PHP-FPM pool ----------
echo "=== Configuring PHP-FPM ==="
sudo mkdir -p /run/php

sudo rm -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

cat <<EOF | sudo tee "/etc/php/${PHP_VERSION}/fpm/pool.d/e2e.conf" >/dev/null
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

; Keep open_basedir in its own worker pool. A request-level value can remain
; active when the same worker handles a request for another test site.
[e2e-open-basedir]
user = nginx
group = nginx
listen = ${OPEN_BASEDIR_FPM_SOCKET}
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = ondemand
pm.max_children = 4

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
php_admin_value[open_basedir] = ${SITE_ROOT}/open-basedir:/tmp

env[SITE_EXPORT_TEST_MODE] = 1
EOF

# ---------- PHP-FPM master without pdo_mysql ----------
# Sites flagged noPdoMysql are served from here so they take
# create_db_connection()'s PDO-less route and export through $wpdb. PHP loads
# extensions per master rather than per pool, so this cannot be another pool.
echo "=== Configuring PHP-FPM master without pdo_mysql ==="
NO_PDO_MYSQL_CONF_D="/etc/php/${PHP_VERSION}/fpm-no-pdo-mysql-conf.d"
sudo rm -rf "$NO_PDO_MYSQL_CONF_D"
sudo cp -rL "/etc/php/${PHP_VERSION}/fpm/conf.d" "$NO_PDO_MYSQL_CONF_D"
sudo rm -f "$NO_PDO_MYSQL_CONF_D"/*pdo_mysql.ini

# The whole point of this master is that pdo_mysql is gone. If the ini were
# named differently, or the extension were built in, every test on these sites
# would quietly run on the ordinary PDO path and still pass.
if ! sudo env PHP_INI_SCAN_DIR="$NO_PDO_MYSQL_CONF_D" "php${PHP_VERSION}" \
    -r 'exit(extension_loaded("pdo_mysql") ? 1 : 0);'; then
    echo "pdo_mysql is still loaded in ${NO_PDO_MYSQL_CONF_D}; the no-pdo-mysql sites would not test the wpdb path." >&2
    exit 1
fi

cat <<EOF | sudo tee "/etc/php/${PHP_VERSION}/fpm/php-fpm-no-pdo-mysql.conf" >/dev/null
[global]
pid = /run/php/e2e-no-pdo-mysql.pid
error_log = /tmp/php-e2e-no-pdo-mysql.log
daemonize = no

[e2e-no-pdo-mysql]
user = nginx
group = nginx
listen = ${NO_PDO_MYSQL_FPM_SOCKET}
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = ondemand
pm.max_children = 8

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

cat <<EOF | sudo tee /etc/systemd/system/php-e2e-no-pdo-mysql.service >/dev/null
[Unit]
Description=PHP-FPM master for E2E sites without pdo_mysql
After=network.target

[Service]
Type=simple
Environment=PHP_INI_SCAN_DIR=${NO_PDO_MYSQL_CONF_D}
ExecStart=/usr/sbin/php-fpm${PHP_VERSION} --nodaemonize --fpm-config /etc/php/${PHP_VERSION}/fpm/php-fpm-no-pdo-mysql.conf
Restart=no

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload

# ---------- Nginx config ----------
echo "=== Configuring Nginx ==="
sudo mkdir -p "${SITE_ROOT}"
sudo chown nginx:nginx "${SITE_ROOT}"

cat <<'EOF' | sudo tee /etc/nginx/nginx.conf >/dev/null
user nginx;
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log;

events {
    worker_connections 768;
}

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
EOF

sudo rm -f /etc/nginx/sites-enabled/default
sudo rm -f /etc/nginx/conf.d/default.conf

# Read site definitions from registry (single source of truth)
# Standard sites — each gets the same fastcgi template on its own port.
jq -r '.sites | to_entries[] | select((.value.nginx // "standard") == "standard") | [.key, .value.port, (.value.openBasedir // false), (.value.noPdoMysql // false)] | @tsv' "$REGISTRY" | while IFS=$'\t' read -r site port open_basedir no_pdo_mysql; do
    site_fpm_socket="$FPM_SOCKET"
    if [ "$open_basedir" = "true" ]; then
        site_fpm_socket="$OPEN_BASEDIR_FPM_SOCKET"
    fi
    if [ "$no_pdo_mysql" = "true" ]; then
        site_fpm_socket="$NO_PDO_MYSQL_FPM_SOCKET"
    fi
    cat <<VHOST | sudo tee "/etc/nginx/conf.d/e2e-${site}.conf" >/dev/null
server {
    listen 127.0.0.1:${port};
    root ${SITE_ROOT}/${site};
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        fastcgi_pass unix:${site_fpm_socket};
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
    cat <<VHOST | sudo tee "/etc/nginx/conf.d/e2e-${site}.conf" >/dev/null
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

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

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
    cat <<VHOST | sudo tee "/etc/nginx/conf.d/e2e-${site}.conf" >/dev/null
server {
    listen 127.0.0.1:${port};
    location / {
        return 301 http://127.0.0.1:${target}\$request_uri;
    }
}
VHOST
done

# Buffered sites
jq -r '.sites | to_entries[] | select(.value.nginx == "buffered") | "\(.key) \(.value.port)"' "$REGISTRY" | while read site port target; do
    cat <<VHOST | sudo tee "/etc/nginx/conf.d/e2e-${site}.conf" >/dev/null
server {
    listen 127.0.0.1:${port};
    root ${SITE_ROOT}/${site};
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

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

# ---------- Start services ----------
echo "=== Starting services ==="
sudo systemctl restart "php${PHP_VERSION}-fpm"
sudo systemctl restart php-e2e-no-pdo-mysql

# Kill anything lingering on our ports before starting Nginx
for port in $(jq -r '.sites[].port' "$REGISTRY"); do
    sudo fuser -k "${port}/tcp" 2>/dev/null || true
done
sleep 1

# Validate config before starting
sudo nginx -t
sudo systemctl start nginx

# ---------- Verify ----------
echo "=== Verifying services ==="
sudo systemctl is-active --quiet "php${PHP_VERSION}-fpm" && echo "php${PHP_VERSION}-fpm: active" || { echo "php${PHP_VERSION}-fpm: FAILED"; exit 1; }
sudo systemctl is-active --quiet php-e2e-no-pdo-mysql && echo "php-e2e-no-pdo-mysql: active" || { echo "php-e2e-no-pdo-mysql: FAILED"; exit 1; }
sudo systemctl is-active --quiet nginx        && echo "nginx: active"      || { echo "nginx: FAILED"; exit 1; }
sudo systemctl is-active --quiet mariadb      && echo "mariadb: active"    || { echo "mariadb: FAILED"; exit 1; }

echo "=== Infrastructure setup complete (PHP ${PHP_VERSION}) ==="
