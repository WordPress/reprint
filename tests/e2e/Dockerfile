# E2E test environment — replicates the CI infrastructure locally.
# Build from the project root: docker build -f tests/e2e/Dockerfile .
FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_VERSION=8.2

# Base packages
RUN apt-get update -qq && apt-get install -y \
    software-properties-common curl jq sudo gnupg2 xxd \
    && rm -rf /var/lib/apt/lists/*

# PHP
RUN add-apt-repository -y ppa:ondrej/php && apt-get update -qq && apt-get install -y \
    php${PHP_VERSION}-cli php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-curl \
    php${PHP_VERSION}-xml php${PHP_VERSION}-zip php${PHP_VERSION}-sqlite3 \
    && rm -rf /var/lib/apt/lists/*

# MariaDB
RUN apt-get update -qq && apt-get install -y mariadb-server && rm -rf /var/lib/apt/lists/*

# Nginx
RUN apt-get update -qq && apt-get install -y nginx && rm -rf /var/lib/apt/lists/*

# Node 20
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

# Create nginx user
RUN groupadd -f nginx && useradd -r -g nginx -s /usr/sbin/nologin nginx 2>/dev/null || true

# WP-CLI
RUN curl -sfL "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -o /tmp/wp-cli.phar \
    && chmod +x /tmp/wp-cli.phar

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy project
COPY . /app
WORKDIR /app

# Install PHP dependencies (vendor/ is gitignored)
RUN composer install --no-dev --no-interaction --prefer-dist
RUN composer install --no-dev --no-interaction --prefer-dist --working-dir=reprint-exporter-wp

# Install npm deps for E2E
RUN cd tests/e2e && npm install --silent

# Keep live and staged state persistent. Docker Desktop may report local-driver
# volumes on one device; the cross-device test invocation mounts the latter two
# paths as tmpfs so their st_dev values are observably distinct.
VOLUME ["/srv/e2e-sites", "/srv/e2e-sites/push-mounted-parent/mounted-parent", "/srv/reprint-e2e-staging"]

ENTRYPOINT ["/app/tests/e2e/docker-entrypoint.sh"]
