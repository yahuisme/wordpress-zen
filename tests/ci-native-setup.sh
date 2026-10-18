#!/usr/bin/env bash
set -Eeuo pipefail

# Reproducible disposable WordPress + SQLite runtime for native theme gates.
# Usage: tests/ci-native-setup.sh [--prepare-only]

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
RUNTIME=${ZEN_CI_RUNTIME:-${RUNNER_TEMP:-/tmp}/zen-native}
WP_VERSION=${ZEN_WP_VERSION:-6.5.5}
WPCLI=${WPCLI_BIN:-$RUNTIME/wp-cli.phar}
SQLITE_URL=${ZEN_SQLITE_URL:-https://github.com/WordPress/sqlite-database-integration/archive/refs/tags/v2.2.8.zip}

rm -rf "$RUNTIME"
mkdir -p "$RUNTIME/db" "$RUNTIME/wp-content/themes" "$RUNTIME/wp-content/plugins"

curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WPCLI"
chmod 0755 "$WPCLI"
php "$WPCLI" core download --allow-root --path="$RUNTIME" --version="$WP_VERSION" --skip-content
curl -fsSL "$SQLITE_URL" -o "$RUNTIME/sqlite.zip"
unzip -q "$RUNTIME/sqlite.zip" -d "$RUNTIME"
SQLITE_DIR=$(find "$RUNTIME" -mindepth 1 -maxdepth 1 -type d -name 'sqlite-database-integration-*' -print -quit)
mkdir -p "$RUNTIME/wp-content/plugins/sqlite-database-integration"
cp -a "$SQLITE_DIR"/. "$RUNTIME/wp-content/plugins/sqlite-database-integration/"
cp "$RUNTIME/wp-content/plugins/sqlite-database-integration/db.copy" "$RUNTIME/wp-content/db.php"
sed -i "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$RUNTIME/wp-content/plugins/sqlite-database-integration|g; s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|g" "$RUNTIME/wp-content/db.php"
cp -a "$ROOT" "$RUNTIME/wp-content/themes/zen"

cat > "$RUNTIME/private.php" <<PHP
<?php
define('DB_ENGINE', 'sqlite');
define('DB_NAME', 'zen_ci');
define('DB_USER', 'isolated');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
define('DB_DIR', '$RUNTIME/db/');
define('DB_FILE', 'zen.sqlite');
define('WP_HOME', 'http://127.0.0.1:18765');
define('WP_SITEURL', 'http://127.0.0.1:18765');
define('WP_ENVIRONMENT_TYPE', 'local');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_DEBUG', false);
\$table_prefix = 'wp_';
PHP
cat > "$RUNTIME/wp-config.php" <<PHP
<?php
require '$RUNTIME/private.php';
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
PHP

php "$WPCLI" core install --allow-root --path="$RUNTIME" --url=http://127.0.0.1:18765 --title='Zen CI' --admin_user=admin --admin_password='zen-ci-password' --admin_email=admin@example.test --skip-email
php "$WPCLI" theme activate zen --allow-root --path="$RUNTIME"
php "$WPCLI" option update permalink_structure '/%postname%/' --allow-root --path="$RUNTIME"
base64 -d > "$RUNTIME/fixture.png" <<'B64'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=
B64
IMAGE=$(php "$WPCLI" media import "$RUNTIME/fixture.png" --title='Zen CI fixture' --allow-root --path="$RUNTIME" --porcelain)
IMAGE_URL=$(php "$WPCLI" post get "$IMAGE" --field=guid --allow-root --path="$RUNTIME")
POST=$(php "$WPCLI" post create --allow-root --path="$RUNTIME" --post_type=post --post_status=publish --post_title='Zen CI fixture' --post_content="<h2>Fixture section</h2><h3>Fixture detail</h3><p>Isolated native fixture.</p><p><img src='$IMAGE_URL' alt='Zen CI fixture'></p><pre><code>const fixture = 42;</code></pre>" --porcelain)
SPLIT=$(php "$WPCLI" post create --allow-root --path="$RUNTIME" --post_type=post --post_status=publish --post_title='Zen CI split fixture' --post_content='<p>Short fixture.</p>' --porcelain)
LONG=$(php "$WPCLI" post create --allow-root --path="$RUNTIME" --post_type=post --post_status=publish --post_title='Zen CI long title 1234567890' --post_content='<p>Long title fixture.</p>' --porcelain)
ARCHIVES=$(php "$WPCLI" post create --allow-root --path="$RUNTIME" --post_type=page --post_status=publish --post_title=Archives --post_content='' --page_template=page-archives.php --porcelain)
LINKS=$(php "$WPCLI" post create --allow-root --path="$RUNTIME" --post_type=page --post_status=publish --post_title=Links --post_content='<p>Links fixture.</p>' --page_template=page-links.php --porcelain)
PAGE=$(php "$WPCLI" post create --allow-root --path="$RUNTIME" --post_type=page --post_status=publish --post_title=Page --post_content='<p>Page fixture.</p>' --porcelain)
php "$WPCLI" post meta update "$POST" _thumbnail_id "$IMAGE" --allow-root --path="$RUNTIME"
printf '{"normal":%s,"split":%s,"longtitle":%s,"archives":%s,"links":%s,"page":%s,"direct":%s,"synced":%s,"media":[%s]}\n' "$POST" "$SPLIT" "$LONG" "$ARCHIVES" "$LINKS" "$PAGE" "$POST" "$POST" "$IMAGE" > "$RUNTIME/fixture.json"
cat > "$RUNTIME/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ('/' !== $path && is_file($file)) return false;
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', true);
require __DIR__ . '/index.php';
PHP

if [[ "${1:-}" != '--prepare-only' ]]; then
    ZEN_TEST_ISOLATED=1 php "$ROOT/tests/native-regression.php" "$RUNTIME/wp-load.php"
    ZEN_TEST_ISOLATED=1 php "$ROOT/tests/php-native-regression.php" "$RUNTIME/wp-load.php"
fi
printf 'ZEN_CI_RUNTIME=%s\n' "$RUNTIME"
