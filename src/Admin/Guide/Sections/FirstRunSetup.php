<?php
/**
 * Guide section: First-run setup.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Sample data, flagging components, choosing units.
 */
final class FirstRunSetup {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'first-run-setup',
			title: __( 'First-run setup', 'wcbom' ),
			body: '<p>' . __( 'The fastest way to learn this plugin is against real (if fictional) data. On a fresh install with no components yet, the Inventory screen offers an "Install sample products" button — a small catalog of tumbler components, a premade design, and a made-to-order product with a working recipe, all original content this plugin ships with. It is storefront-visible, so use it on a staging site, not a live store with real customers browsing. Remove it any time from the same screen; nothing else on the site is affected.', 'wcbom' ) . '</p>'
				. '<p>' . __( 'To turn any product into a component yourself: open it for editing and check "This product is a component" on its Bill of Materials tab. Components can (and often should) also be hidden from the storefront if they are not meant to be sold on their own — a jar of glitter is a component, not a listing.', 'wcbom' ) . '</p>'
				. '<p>' . __( '<strong>Pick a unit that lets stock stay a whole number.</strong> WooCommerce stock quantities are whole numbers, so a bulk material like glitter or epoxy should be stocked in grams (or another small unit) rather than kilograms — "500" is exact, "0.5" is not. The unit field on a component is just a label for the inventory screens; it does not affect any calculation.', 'wcbom' ) . '</p>',
			screenshots: array(
				array(
					'file' => 'first-run-empty-inventory.png',
					'alt'  => __( 'The Component Inventory screen with no components yet, offering to install the sample catalog.', 'wcbom' ),
				),
				array(
					'file' => 'first-run-component-flag.png',
					'alt'  => __( 'A product\'s Bill of Materials tab with "This product is a component" checked and a unit of measure chosen.', 'wcbom' ),
				),
			),
			links: array(
				array(
					'label' => __( 'Open Component Inventory', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom' ),
				),
			),
			covers_pages: array(),
			covers_routes: array(
				'/wcbom/v1/sample-data/install',
				'/wcbom/v1/sample-data/remove',
				'/wcbom/v1/components',
			),
			covers_commands: array( 'seed' )
		);
	}
}
