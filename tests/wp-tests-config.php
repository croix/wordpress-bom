<?php
/**
 * WP core test-suite config. Only ever loaded inside the tests-cli
 * container (see tests/bootstrap.php), which is the sole place this
 * suite runs — so container-specific values (DB host, ABSPATH) are safe
 * to hardcode/read from the env vars docker-compose.yml already sets on
 * that container, matching wp-env's own WORDPRESS_DB_* convention.
 *
 * @package WCBOM
 */

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'tests-mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'pv-bom-stock test suite' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Matches the tests-cli/tests-wordpress containers' shared bind mount
// (docker-compose.yml: .../tests-WordPress:/var/www/html) — real
// WordPress core files, with WooCommerce and this plugin already present
// in wp-content/plugins/.
define( 'ABSPATH', '/var/www/html/' );
