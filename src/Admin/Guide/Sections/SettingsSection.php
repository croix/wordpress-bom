<?php
/**
 * Guide section: Settings.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own Settings page. Named SettingsSection (not Settings) to
 * avoid colliding with Admin\SettingsPage in file listings/imports.
 */
final class SettingsSection {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'settings',
			title: __( 'Settings', 'wcbom' ),
			body: '<ul>'
				. '<li>' . __( '<strong>Remove all data on uninstall</strong> — off by default. Deleting the plugin normally keeps every BOM, the full stock ledger, and all manufacture/purchase-order history, so reinstalling it picks up exactly where you left off. Only turn this on if you actually want deletion to erase all of it permanently.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Allow negative component stock</strong> — governs manual Adjust entries and completing a manufacture order that is short a component, letting either proceed without asking each time. It has no effect on customer orders either way: a paid order always consumes its components, even into negative stock if it must, because refusing a paid order is worse than a stock discrepancy — see "Selling made-to-order".', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Low stock digest email</strong> — an optional daily email listing components at or below their low-stock threshold, and how many made-to-order products each one blocks. Nothing is sent on a day nothing is low.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Enable vendors & purchase orders</strong> — off by default; see "Vendors & Purchase Orders" for what turning it on adds.', 'wcbom' ) . '</li>'
				. '</ul>',
			screenshots: array(
				array(
					'file' => 'settings-page.png',
					'alt'  => __( 'The Settings page with the uninstall, negative-stock, digest, and vendors checkboxes.', 'wcbom' ),
				),
			),
			links: array(
				array(
					'label' => __( 'Open Settings', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom-settings' ),
				),
			),
			covers_pages: array( 'wcbom-settings' )
		);
	}
}
