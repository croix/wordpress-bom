<?php
/**
 * Plugin settings (WooCommerce → Settings → Advanced → BOM & Stock).
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Reports\LowStockDigest;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "BOM & Stock" section to WooCommerce's Advanced settings tab.
 * Currently one setting: the uninstall data policy — uninstall.php only
 * drops the plugin's tables when this is explicitly enabled, so deleting
 * the plugin can never silently destroy the ledger/BOM/manufacture
 * history (BUILD_PLAN.md §14.6).
 */
final class Settings {

	private const SECTION_ID = 'wcbom';

	/**
	 * Hooks the WooCommerce settings filters.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_sections_advanced', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( $this, 'add_settings' ), 10, 2 );
	}

	/**
	 * Adds the section to the Advanced tab's section list.
	 *
	 * @param array<string,string> $sections Existing sections.
	 * @return array<string,string>
	 */
	public function add_section( array $sections ): array {
		$sections[ self::SECTION_ID ] = __( 'BOM & Stock', 'wcbom' );

		return $sections;
	}

	/**
	 * Supplies the section's settings fields.
	 *
	 * @param array<int,array<string,mixed>> $settings        Existing settings for the current section.
	 * @param string                         $current_section The section being rendered.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_settings( array $settings, string $current_section ): array {
		if ( self::SECTION_ID !== $current_section ) {
			return $settings;
		}

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
