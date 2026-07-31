<?php
/**
 * "BOM & Stock → Purchasing" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Purchasing\VendorsFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Purchasing submenu page: vendors + purchase orders, per
 * BUILD_PLAN.md §5.13. Entirely gated on VendorsFeature — when the setting
 * is off, register() no-ops before ever calling add_action(), so the menu
 * item genuinely does not exist rather than existing-but-empty. This is
 * the load-bearing property of the whole feature (§5.13's "invisible until
 * deliberately turned on"), so it's enforced here rather than trusted to
 * every caller remembering to check first.
 */
final class PurchasingPage {

	/**
	 * The hook suffix WordPress assigns this submenu, captured from
	 * add_submenu_page()'s return value — see Admin\ManufacturePage's
	 * docblock on the same property for why.
	 *
	 * @var string|false|null
	 */
	private $hook_suffix;

	/**
	 * Hooks the admin menu and enqueue callbacks — only if the feature is
	 * enabled.
	 */
	public function register(): void {
		if ( ! VendorsFeature::enabled() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under the plugin's own top-level menu.
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_submenu_page(
			PluginMenu::SLUG,
			__( 'Purchasing', 'wcbom' ),
			__( 'Purchasing', 'wcbom' ),
			'manage_woocommerce',
			'wcbom-purchasing',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the mount point for the React app.
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="wcbom-purchasing-root"><p>' . esc_html__( 'Loading Purchasing…', 'wcbom' ) . '</p></div></div>';
	}

	/**
	 * Enqueues the React Purchasing app on its own admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}

		$asset_file = WCBOM_PLUGIN_DIR . '/assets/build/purchasing/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'wcbom-purchasing',
			plugins_url( 'assets/build/purchasing/index.js', WCBOM_PLUGIN_FILE ),
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
			'wcbom-purchasing',
			'wcbomPurchasing',
			array(
				'restNamespace' => 'wcbom/v1',
			)
		);
	}
}
