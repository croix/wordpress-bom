<?php
/**
 * Guide section: Component Inventory.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Receive vs. count vs. adjust, and the on-order column.
 */
final class ComponentInventory {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'component-inventory',
			title: __( 'Component Inventory', 'pv-bom-stock' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'One screen lists every component with its current stock, which products it is used in, and when it last moved.', 'pv-bom-stock' ) . '</p>'
				),
				Section::screenshot(
					'inventory-table.png',
					__( 'The Component Inventory table listing components with stock, usage, and last movement.', 'pv-bom-stock' )
				),
				Section::text(
					'<p>' . __( 'Three actions cover every reason stock changes outside of a sale, each recorded on the ledger under its own reason so a history is always distinguishable:', 'pv-bom-stock' ) . '</p>'
						. '<ul>'
						. '<li>' . __( '<strong>Receive</strong> — stock arrived. Enter how much came in; it is added to what is already on hand. Select several components at once to receive a whole delivery in one pass.', 'pv-bom-stock' ) . '</li>'
						. '<li>' . __( '<strong>Count</strong> — a physical cycle count. Enter the number actually counted; the system computes and shows the drift from what it expected, and records the counted number as the new stock.', 'pv-bom-stock' ) . '</li>'
						. '<li>' . __( '<strong>Adjust</strong> — a manual correction (breakage, a miscount found later, anything else). Enter a signed change and a required note explaining why — this is the one action that can take stock negative, and the only one where a note is mandatory.', 'pv-bom-stock' ) . '</li>'
						. '</ul>'
				),
				Section::screenshot(
					'inventory-receive-modal.png',
					__( 'The Receive stock modal with a quantity and note field.', 'pv-bom-stock' )
				),
				Section::text(
					'<p>' . __( 'When Vendors & Purchase Orders is turned on (see that section), an additional "On order" figure appears here per component — how much is on an open purchase order and when it is expected — so a low number does not automatically mean it is time to reorder again.', 'pv-bom-stock' ) . '</p>'
				),
			),
			links: array(
				array(
					'label' => __( 'Open Component Inventory', 'pv-bom-stock' ),
					'url'   => admin_url( 'admin.php?page=wcbom' ),
				),
			),
			covers_pages: array( 'wcbom' ),
			covers_routes: array(
				'/wcbom/v1/inventory',
				'/wcbom/v1/inventory/receive',
				'/wcbom/v1/inventory/count',
				'/wcbom/v1/inventory/adjust',
			)
		);
	}
}
