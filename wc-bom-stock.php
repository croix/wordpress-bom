<?php
/**
 * Plugin Name:       WooCommerce BOM & Stock Manager
 * Plugin URI:        https://github.com/croix/wordpress-bom
 * Description:       Bill-of-materials and component-level stock management for WooCommerce — made-to-order component consumption, manufacture orders, and a full stock ledger.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.5
 * Author:            Poor Vida
 * Text Domain:       wcbom
 * Domain Path:       /languages
 * Update URI:        https://github.com/croix/wordpress-bom
 *
 * @package WCBOM
 */

defined( 'ABSPATH' ) || exit;

define( 'WCBOM_VERSION', '0.1.0' );
define( 'WCBOM_PLUGIN_FILE', __FILE__ );
define( 'WCBOM_PLUGIN_DIR', __DIR__ );

$wcbom_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $wcbom_autoload ) ) {
	require_once $wcbom_autoload;
}
unset( $wcbom_autoload );

register_activation_hook( __FILE__, array( \WCBOM\Install\Schema::class, 'install' ) );

register_deactivation_hook(
	__FILE__,
	static function () {
		$timestamp = wp_next_scheduled( \WCBOM\Reports\LowStockDigest::HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, \WCBOM\Reports\LowStockDigest::HOOK );
		}
	}
);

add_action( 'before_woocommerce_init', array( \WCBOM\Integrations\Hpos::class, 'declare_compatibility' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce BOM & Stock Manager requires WooCommerce to be installed and active.', 'wcbom' ) . '</p></div>';
				}
			);
			return;
		}

		\WCBOM\Install\Schema::maybe_upgrade();
		\WCBOM\Plugin::instance()->init();
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'wcbom', \WCBOM\Cli\Commands::class );
}
