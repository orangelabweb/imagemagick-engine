#!/bin/sh
# Prepares a wordpress:cli container to run the test suite. Used by CI.
set -eu

PLUGIN_DIR="${1:?Usage: install.sh <plugin directory>}"
WEBP_UPLOADS_VERSION="2.7.1"
WP_ROOT="/var/www/html"

apk add --no-cache -q imagemagick imagemagick-jpeg imagemagick-webp graphicsmagick >/dev/null

# Extracting the core zip needs more than the default 128M.
php -d memory_limit=512M "$(command -v wp)" core download --path="$WP_ROOT" --version="${WP_VERSION:-latest}" --allow-root --quiet
WP_RESOLVED="$(php -r "include '$WP_ROOT/wp-includes/version.php'; echo \$wp_version;")"

mkdir -p /wordpress-phpunit
curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_RESOLVED.tar.gz" \
	| tar -xz -C /wordpress-phpunit --strip-components=3 "wordpress-develop-$WP_RESOLVED/tests/phpunit"

curl -fsSL "https://downloads.wordpress.org/plugin/webp-uploads.$WEBP_UPLOADS_VERSION.zip" -o /tmp/webp-uploads.zip
unzip -q /tmp/webp-uploads.zip -d "$WP_ROOT/wp-content/plugins"

ln -s "$PLUGIN_DIR" "$WP_ROOT/wp-content/plugins/imagemagick-engine"

curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer

cd "$PLUGIN_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --quiet

echo "Ready: WordPress $WP_RESOLVED"
