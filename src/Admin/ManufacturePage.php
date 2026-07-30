<?php
/**
 * "BOM & Stock → Manufacturing" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Manufacturing submenu page: create/complete/reverse
 * manufacture orders, per BUILD_PLAN.md §5.4.
 */
final class ManufacturePage {

	/**
	 * The hook suffix WordPress assigns this submenu, captured from
	 * add_submenu_page()'s return value rather than hand-computed: its
	 * prefix is derived from sanitize_title() of the *parent's menu
	 * title* (via a WP core global keyed by parent slug), not the
	 * parent's slug itself — WooCommerce's own menu title happens to
	 * sanitize to match its slug ("WooCommerce" → "woocommerce"), masking
	 * this until a custom-titled parent menu (ours) exposes it.
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
	 * Adds the page under the plugin's own top-level menu.
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_submenu_page(
			PluginMenu::SLUG,
			__( 'Manufacturing', 'wcbom' ),
			__( 'Manufacturing', 'wcbom' ),
			'manage_woocommerce',
			'wcbom-manufacturing',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the mount point for the React app.
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="wcbom-manufacturing-root"><p>' . esc_html__( 'Loading Manufacturing…', 'wcbom' ) . '</p></div></div>';
	}

	/**
	 * Enqueues the React Manufacturing app on its own admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}

		$asset_file = WCBOM_PLUGIN_DIR . '/assets/build/manufacture/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'wcbom-manufacture',
			plugins_url( 'assets/build/manufacture/index.js', WCBOM_PLUGIN_FILE ),
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
			'wcbom-manufacture',
			'wcbomManufacture',
			array(
				'restNamespace' => 'wcbom/v1',
			)
		);
	}
}
