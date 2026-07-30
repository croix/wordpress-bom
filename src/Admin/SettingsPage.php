<?php
/**
 * "BOM & Stock → Settings" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WC_Admin_Settings;
use WCBOM\Reports\LowStockDigest;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, on the plugin's own menu rather than buried in WooCommerce's
 * Settings → Advanced tab (moved here 2026-07-30 at the developer's request, so
 * every page this plugin adds lives in one place). Reuses WooCommerce's
 * own field renderer/saver (`woocommerce_admin_fields()` /
 * `WC_Admin_Settings::save_fields()`) directly — both are plain static
 * helpers with no dependency on the `WC_Settings_Page` tab system, so
 * they work standalone on a custom page exactly as well as inside a WC
 * settings tab. `save_fields()` does no nonce/capability check itself
 * (that's the tab system's job normally), so this class adds its own.
 *
 * Currently two settings: the uninstall data policy (BUILD_PLAN.md
 * §14.6) and the low-stock digest (§5.5, Phase 5).
 */
final class SettingsPage {

	private const NONCE_ACTION = 'wcbom-settings';

	/**
	 * Hooks the admin menu callback.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Adds the page under the plugin's own top-level menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			PluginMenu::SLUG,
			__( 'Settings', 'wcbom' ),
			__( 'Settings', 'wcbom' ),
			'manage_woocommerce',
			'wcbom-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handles a save (if submitted) and renders the form.
	 */
	public function render_page(): void {
		$fields = $this->fields();

		if ( isset( $_POST['wcbom_settings_submit'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			WC_Admin_Settings::save_fields( $fields );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wcbom' ) . '</p></div>';
		}

		echo '<div class="wrap">';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		woocommerce_admin_fields( $fields );
		echo '<input type="hidden" name="wcbom_settings_submit" value="1" />';
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The settings fields, in WooCommerce's own field-array shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fields(): array {
		return array(
			array(
				'title' => __( 'BOM & Stock Manager', 'wcbom' ),
				'type'  => 'title',
				'id'    => 'wcbom_settings_title',
			),
			array(
				'title'    => __( 'Remove all data on uninstall', 'wcbom' ),
				'desc'     => __( 'Permanently delete this plugin\'s data when the plugin is deleted from the Plugins screen.', 'wcbom' ),
				'desc_tip' => __( 'When unchecked (the default), deleting the plugin keeps every BOM, the full stock ledger, and all manufacture-order history in the database, so reinstalling picks up exactly where you left off. Only enable this if you want deletion to permanently erase all of that.', 'wcbom' ),
				'id'       => 'wcbom_purge_data_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Low stock digest email', 'wcbom' ),
				'desc'     => __( 'Send a daily email listing components at or below their low-stock threshold.', 'wcbom' ),
				'desc_tip' => __( 'Unlike WooCommerce\'s native per-product low-stock notice, this understands components: it also says how many made-to-order products a short component blocks. No email is sent on a day nothing is low.', 'wcbom' ),
				'id'       => LowStockDigest::OPTION_ENABLED,
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Digest recipient', 'wcbom' ),
				'desc_tip' => __( 'Defaults to the site admin email when left blank.', 'wcbom' ),
				'id'       => LowStockDigest::OPTION_EMAIL,
				'type'     => 'email',
				'default'  => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcbom_settings_end',
			),
		);
	}
}
