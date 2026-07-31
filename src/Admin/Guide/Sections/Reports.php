<?php
/**
 * Guide section: Reports.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The five report tabs.
 */
final class Reports {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'reports',
			title: __( 'Reports', 'wcbom' ),
			body: '<p>' . __( 'Five tabs, all read-only and computed live:', 'wcbom' ) . '</p>'
				. '<ul>'
				. '<li>' . __( '<strong>Buildable</strong> — every made-to-order product\'s current buildable quantity and which component is the bottleneck.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Low Stock</strong> — components at or below WooCommerce\'s own low-stock threshold, and how many products each one blocks. When Vendors & Purchase Orders is on, this also shows what is already on order, so a low number does not necessarily mean it is time to reorder.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Margin</strong> — BOM-derived cost versus price, per variation for made-to-order products, including any surcharges.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Component Usage</strong> — every component with its on-hand stock, unit of measure, and 30/90-day consumption with a simple days-of-stock estimate; searchable, and paginated once the list runs long.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Ledger</strong> — every stock movement this plugin has ever recorded, filterable by product, reason, and date.', 'wcbom' ) . '</li>'
				. '</ul>'
				. '<p>' . __( 'Turning on the "Cost of Goods Sold from BOM" toggle (see "Building a BOM") does not just affect the Margin tab here — it is also what feeds WooCommerce\'s <em>own</em> native Analytics with a real, BOM-derived cost instead of a manually typed one, so this plugin\'s cost math and WooCommerce\'s profit reporting stay in agreement.', 'wcbom' ) . '</p>',
			screenshots: array(
				array(
					'file' => 'reports-buildable.png',
					'alt'  => __( 'The Reports screen\'s Buildable tab.', 'wcbom' ),
				),
				array(
					'file' => 'reports-low-stock.png',
					'alt'  => __( 'The Reports screen\'s Low Stock tab, including an on-order figure.', 'wcbom' ),
				),
				array(
					'file' => 'reports-margin.png',
					'alt'  => __( 'The Reports screen\'s Margin tab.', 'wcbom' ),
				),
				array(
					'file' => 'reports-usage.png',
					'alt'  => __( 'The Reports screen\'s Component Usage tab with unit of measure and run-rate columns.', 'wcbom' ),
				),
				array(
					'file' => 'reports-ledger.png',
					'alt'  => __( 'The Reports screen\'s Ledger tab, filtered by reason.', 'wcbom' ),
				),
			),
			links: array(
				array(
					'label' => __( 'Open Reports', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom-reports' ),
				),
			),
			covers_pages: array( 'wcbom-reports' ),
			covers_routes: array(
				'/wcbom/v1/reports/buildable',
				'/wcbom/v1/reports/low-stock',
				'/wcbom/v1/reports/margin',
				'/wcbom/v1/reports/usage',
				'/wcbom/v1/reports/usage/(?P<component_id>\d+)',
				'/wcbom/v1/ledger',
			)
		);
	}
}
