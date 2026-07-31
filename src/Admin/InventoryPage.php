<?php
/**
 * "BOM & Stock → Inventory" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Admin\Guide\ContextualHelp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Inventory submenu page: receive/count/adjust stock for
 * every component from one screen, per BUILD_PLAN.md §5.7. Uses
 * PluginMenu::SLUG as its own menu slug too (not just its parent) —
 * WordPress's standard trick for making the top-level menu link itself
 * open this page directly, instead of a generic duplicate first entry.
 */
final class InventoryPage {

	/**
	 * The hook suffix WordPress assigns this submenu, captured from
	 * add_submenu_page()'s return value — see Admin\ManufacturePage's
	 * docblock on the same property for why this can't be hand-computed
	 * from the parent slug in general (this particular page's slug ===
	 * its parent's, which happens to always resolve to a fixed
	 * "toplevel_page_{slug}" pattern regardless — but capturing the
	 * return value keeps every page class in this plugin consistent).
	 *
	 * @var string|false|null
	 */
	private $hook_suffix;

	/**
	 * Hooks the admin menu and enqueue callbacks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under the plugin's own top-level menu, as the entry
	 * the top-level link itself opens (see class docblock).
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_submenu_page(
			PluginMenu::SLUG,
			__( 'Component Inventory', 'wcbom' ),
			__( 'Inventory', 'wcbom' ),
			'manage_woocommerce',
			PluginMenu::SLUG,
			array( $this, 'render_page' )
		);

		ContextualHelp::attach( $this->hook_suffix, 'component-inventory' );
	}

	/**
	 * Renders the mount point for the React app.
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="wcbom-inventory-root"><p>' . esc_html__( 'Loading Component Inventory…', 'wcbom' ) . '</p></div></div>';
	}

	/**
	 * Enqueues the React Inventory app on its own admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
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
