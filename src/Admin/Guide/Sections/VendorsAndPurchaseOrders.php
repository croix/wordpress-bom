<?php
/**
 * Guide section: Vendors & Purchase Orders.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in vendor/PO tracking. Stated up front: skippable entirely.
 */
final class VendorsAndPurchaseOrders {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'vendors-and-purchase-orders',
			title: __( 'Vendors & Purchase Orders', 'wcbom' ),
			blocks: array(
				Section::text(
					'<p>' . __( '<strong>This entire feature is optional and off by default.</strong> If you order supplies informally and just receive them on the Component Inventory screen, there is nothing here you need — skip this section.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'Turn it on with "Enable vendors & purchase orders" on the Settings page. A new "Purchasing" menu item appears (nothing else changes for anyone not using it), starting empty — turning it on never creates sample vendors or orders. It has a Vendors tab (name, contact, notes)…', 'wcbom' ) . '</p>'
				),
				Section::screenshot(
					'purchasing-vendors.png',
					__( 'The Purchasing screen\'s Vendors tab.', 'wcbom' )
				),
				Section::text(
					'<p>' . __( '…and a Purchase Orders tab. A purchase order moves through a small lifecycle: <strong>draft</strong> (freely editable, deletable), <strong>ordered</strong> (placed with the vendor — lines lock; to correct a mistake, cancel and start a new draft), then <strong>partially received</strong> or <strong>received</strong> as deliveries arrive. Receiving more than was ordered is allowed and simply flagged, never blocked — vendors do ship extra sometimes. "Cancel" is offered at any point before fully received, and relabels itself to "Close" once something has already arrived — both end an order that will not be completed further; whatever already arrived stays received either way.', 'wcbom' ) . '</p>'
				),
				Section::screenshot(
					'purchasing-po-list.png',
					__( 'The Purchasing screen\'s Purchase Orders tab, filterable by status.', 'wcbom' )
				),
				Section::screenshot(
					'purchasing-new-po.png',
					__( 'The new Purchase Order modal with a vendor picker and component lines.', 'wcbom' )
				),
				Section::text(
					'<p>' . __( 'Freight, tax, and other fees can be entered on a purchase order at any time (even after it is fully received, since the real bill often arrives later) and are spread across the order\'s lines in proportion to what each line cost, giving a landed cost per component. This is for your own visibility only — it is never written back into a product\'s price or fed into the margin/COGS reports.', 'wcbom' ) . '</p>'
				),
				Section::screenshot(
					'purchasing-receive-costs.png',
					__( 'The Receive modal and the freight/tax/fees cost fields on a purchase order.', 'wcbom' )
				),
				Section::text(
					'<p>' . __( 'A placed order can be emailed with "Send PO" — to the vendor, to yourself, or both. Recipients always come from the vendor\'s own record and your own account, never a typed-in address.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'Once enabled, an "On order" figure appears on the Component Inventory screen and in the Low Stock report — a low component with a shipment already on the way is still shown as low (hiding it would be misleading), just with a note of what is expected and when.', 'wcbom' ) . '</p>'
				),
			),
			links: array(
				array(
					'label' => __( 'Open Purchasing', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom-purchasing' ),
				),
				array(
					'label' => __( 'Enable it on Settings', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom-settings' ),
				),
			),
			covers_pages: array( 'wcbom-purchasing' ),
			covers_routes: array(
				'/wcbom/v1/vendors',
				'/wcbom/v1/vendors/(?P<id>\d+)',
				'/wcbom/v1/purchase-orders',
				'/wcbom/v1/purchase-orders/(?P<id>\d+)',
				'/wcbom/v1/purchase-orders/(?P<id>\d+)/costs',
				'/wcbom/v1/purchase-orders/(?P<id>\d+)/place',
				'/wcbom/v1/purchase-orders/(?P<id>\d+)/receive',
				'/wcbom/v1/purchase-orders/(?P<id>\d+)/cancel',
				'/wcbom/v1/purchase-orders/(?P<id>\d+)/send',
			)
		);
	}
}
