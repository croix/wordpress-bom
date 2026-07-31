<?php
/**
 * PHPUnit bootstrap: loads the WP core test suite (WP_TESTS_DIR), then
 * manually requires WooCommerce and this plugin at muplugins_loaded —
 * the standard pattern for plugin integration tests, since there's no
 * "real" plugin activation happening (no register_activation_hook
 * firing), so Schema::install() is called explicitly afterward to create
 * this plugin's own tables.
 *
 * Only ever run inside the tests-cli container — see BUILD_PLAN.md §8
 * Phase 6 / CLAUDE.md for the docker compose commands.
 *
 * @package WCBOM
 */

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

$plugin_root = dirname( __DIR__ );

require_once $plugin_root . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_root . '/vendor/yoast/phpunit-polyfills' );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

// Manually loads WooCommerce and this plugin before WP's own
// `plugins_loaded` fires, so both are fully initialized by the time
// tests run — equivalent to both being "active" for hook-firing purposes.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ) {
		require '/var/www/html/wp-content/plugins/woocommerce/woocommerce.php';
		require $plugin_root . '/pv-bom-stock.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// register_activation_hook() never fires for a manually-required plugin
// (no real WP activation ceremony happened above), so both WooCommerce's
// own install routine (webhooks/attribute-taxonomies tables etc. — our
// BOM system's variation attributes depend on the latter) and this
// plugin's tables are created explicitly. Both are idempotent, safe to
// call once per test run.
\WC_Install::install();
\WCBOM\Install\Schema::install();

// Not itself a *Test.php file (PHPUnit's directory scan wouldn't pick it
// up), and not PSR-4 autoloadable (no WCBOM\ namespace — matches
// WP_UnitTestCase's own global-namespace convention).
require_once __DIR__ . '/WCBOM_UnitTestCase.php';
