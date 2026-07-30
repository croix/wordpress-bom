<?php
/**
 * "WooCommerce → Inventory" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Inventory submenu page: receive/count/adjust stock for
 * every component from one screen, per BUILD_PLAN.md §5.7.
 */
final class InventoryPage {

	private const HOOK_SUFFIX = 'woocommerce_page_wcbom-inventory';

	/**
	 * Hooks the admin menu and enqueue callbacks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under the WooCommerce admin menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Inventory', 'wcbom' ),
			__( 'Inventory', 'wcbom' ),
			'manage_woocommerce',
			'wcbom-inventory',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the mount point for the React app.
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="wcbom-inventory-root"><p>' . esc_html__( 'Loading Inventory…', 'wcbom' ) . '</p></div></div>';
	}

	/**
	 * Enqueues the React Inventory app on its own admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}

		$asset_file = WCBOM_PLUGIN_DIR . '/assets/build/inventory/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'wcbom-inventory',
			plugins_url( 'assets/build/inventory/index.js', WCBOM_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( in_array( 'wp-components', $asset['dependencies'], true ) ) {
			wp_enqueue_style( 'wp-components' );
		}

		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
				wp_json_encode( wp_create_nonce( 'wp_rest' ) )
			),
			'after'
		);

		wp_localize_script(
			'wcbom-inventory',
			'wcbomInventory',
			array(
				'restNamespace' => 'wcbom/v1',
			)
		);
	}
}
