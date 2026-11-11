#!/usr/bin/env bash
# Provisions a Linux WordPress source with WP Cloud's linked layout:
#
#   /srv/htdocs/wp-config.php                 one directory above ABSPATH
#   /srv/htdocs/__wp__ -> /wordpress/core/6.7 shared core, ABSPATH
#   /srv/htdocs/wp-admin -> __wp__/wp-admin
#   /srv/htdocs/wp-content/plugins/managed-plugin
#       -> ../../../../wordpress/plugins/managed-plugin/latest
#
# A native Windows client pulls this source; every link above must still
# resolve after the pull, merge-wp-content, and flat-docroot.
set -euo pipefail
repository=$1
private_key_path=$2
[[ $(uname -s) == Linux ]]

service mariadb start
mariadb -e "CREATE DATABASE wpcloud_source; CREATE USER 'source'@'127.0.0.1' IDENTIFIED BY 'source'; GRANT ALL ON wpcloud_source.* TO 'source'@'127.0.0.1';"

core=/wordpress/core/6.7
managed_plugin=/wordpress/plugins/managed-plugin/latest
mkdir -p "$core" "$managed_plugin" /srv/htdocs/wp-content/plugins /srv/htdocs/wp-content/uploads/2026/01
curl -fsSL https://wordpress.org/wordpress-6.7.tar.gz | tar -xz -C /tmp
cp -a /tmp/wordpress/. "$core/"
cp -a "$core/wp-content/themes" /srv/htdocs/wp-content/themes
rm -rf "$core/wp-content" "$core/wp-config-sample.php"
ln -s "$core" /srv/htdocs/__wp__
ln -s __wp__/wp-admin /srv/htdocs/wp-admin
ln -s __wp__/wp-includes /srv/htdocs/wp-includes
cat > /srv/htdocs/index.php <<'PHP'
<?php
define('WP_USE_THEMES', true);
require __DIR__ . '/__wp__/wp-blog-header.php';
PHP
cat > /srv/htdocs/wp-config.php <<'PHP'
<?php
define('DB_NAME', 'wpcloud_source');
define('DB_USER', 'source');
define('DB_PASSWORD', 'source');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('DISABLE_WP_CRON', true);
define('WP_CONTENT_DIR', '/srv/htdocs/wp-content');
$table_prefix = 'wp_';
if (!defined('ABSPATH')) {
    define('ABSPATH', '/srv/htdocs/__wp__/');
}
require_once ABSPATH . 'wp-settings.php';
PHP

cat > "$managed_plugin/managed-plugin.php" <<'PHP'
<?php
/**
 * Plugin Name: Managed plugin
 */
add_action('wp_footer', function () {
    echo '<p>Managed plugin output</p>';
});
PHP
ln -s ../../../../wordpress/plugins/managed-plugin/latest /srv/htdocs/wp-content/plugins/managed-plugin
printf 'Uploaded on Linux\n' > /srv/htdocs/wp-content/uploads/2026/01/hello.txt

(cd "$repository" && COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir=reprint-server-wp --no-dev --no-interaction --prefer-dist)
cp -a "$repository/reprint-server-wp" /srv/htdocs/wp-content/plugins/reprint-server
# The Windows client reaches WSL through this address; WordPress redirects other hosts.
source_url="http://$(hostname -I | cut -d ' ' -f 1):8082"
php "$repository/tests/e2e/windows/prepare-linux-source.php" /srv/htdocs "$private_key_path" "$source_url"
