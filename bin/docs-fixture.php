<?php
/**
 * Dev-only WP-CLI eval-file script: creates deterministic demo data
 * (a manufacture order lifecycle, a vendor, and purchase orders in a
 * couple of statuses) purely so bin/capture-docs-screenshots.mjs has
 * real, interesting states to screenshot. Never shipped, never run
 * against anything but a disposable `wp wcbom seed --reset` fixture.
 *
 * Usage: wp eval-file bin/docs-fixture.php
 *
 * @package WCBOM
 */

use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Manufacture\ManufactureService;
use WCBOM\Manufacture\ProductFactory;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\PurchaseOrderService;
use WCBOM\Purchasing\VendorRepository;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

$template_id = (int) ( wc_get_product_id_by_sku( 'WCBOM-CUSTOM-TUMBLER' ) ?: 0 );
if ( ! $template_id ) {
	WP_CLI::error( 'Seeded made-to-order product not found — run "wp wcbom seed --reset" first.' );
}

$ledger    = new Ledger();
$boms      = new BomRepository();
$matcher   = new ConditionMatcher();
$stock     = new StockService( $ledger );
$guard     = new OperationGuard();
$mo_orders = new ManufactureRepository();
$factory   = new ProductFactory( $boms, $matcher );
$mo        = new ManufactureService( $mo_orders, $boms, $stock, $guard, $factory );

// A completed build: gives Manufacturing's list a real "Completed" row and
// a real finished product to open the Reverse (with scrap) modal against.
$built = $mo->create_draft_from_template(
	$template_id,
	'Pink Glitter Tumbler (docs demo)',
	'24.99',
	array(
		'pa_wcbom-glitter-color' => 'pink',
		'pa_wcbom-straw'         => 'standard',
	),
	12,
	'Docs screenshot fixture'
);
$mo->complete( $built->mo_id, wp_generate_uuid4() );
WP_CLI::log( "Completed MO #{$built->mo_id} (Pink Glitter Tumbler, qty 12)." );

// Flag the freshly-built batch as a usable sub-assembly, then use it as a
// component in the premade product's BOM — the "Building a BOM" section's
// sub-assembly screenshot needs a real line like this to show.
update_post_meta( $built->product_id, '_wcbom_is_component', 'yes' );
update_post_meta( $built->product_id, '_wcbom_unit', 'ea' );

$premade_id = (int) wc_get_product_id_by_sku( 'WCBOM-PREMADE-OCEAN' );
$boms->save(
	$premade_id,
	array(
		array(
			'component_id'    => $built->product_id,
			'qty'             => 1.0,
			'condition_type'  => 'always',
			'condition_key'   => null,
			'condition_value' => null,
			'surcharge'       => null,
		),
	),
	get_current_user_id()
);
WP_CLI::log( 'Added a sub-assembly BOM line to the premade product.' );

// A deliberately oversized draft: on the same template, a different
// combination, at a quantity guaranteed to exceed every component's
// stock — gives the Complete modal a real shortage table to screenshot.
$shortage = $mo->create_draft_from_template(
	$template_id,
	'Blue Upgraded Tumbler (docs demo)',
	'29.99',
	array(
		'pa_wcbom-glitter-color' => 'blue',
		'pa_wcbom-straw'         => 'upgraded',
	),
	5000,
	'Docs screenshot fixture — intentionally oversized to show a shortage'
);
WP_CLI::log( "Created draft MO #{$shortage->mo_id} (Blue Upgraded Tumbler, qty 5000, shortage expected)." );

// Vendors & Purchase Orders demo data.
$vendors   = new VendorRepository();
$po_orders = new PurchaseOrderRepository();
$po        = new PurchaseOrderService( $po_orders, $stock, $guard );

$vendor_id = $vendors->create( 'Acme Tumbler Supply', 'orders@acmetumblersupply.example', '555-0107', 'https://acmetumblersupply.example', 'Primary blank/epoxy vendor.' );
WP_CLI::log( "Created vendor #{$vendor_id} (Acme Tumbler Supply)." );

$blank_id = (int) wc_get_product_id_by_sku( 'WCBOM-BLANK' );
$epoxy_id = (int) wc_get_product_id_by_sku( 'WCBOM-EPOXY' );

$ordered_po = $po->create_draft(
	$vendor_id,
	array(
		array(
			'component_id' => $blank_id,
			'qty_ordered'  => 500,
			'unit_cost'    => 1.10,
		),
		array(
			'component_id' => $epoxy_id,
			'qty_ordered'  => 2000,
			'unit_cost'    => 0.04,
		),
	),
	'PO-DOCS-1001',
	gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
	'Docs screenshot fixture'
);
$po->update_costs( $ordered_po->po_id, 45.00, 12.50, 5.00 );
$po->place( $ordered_po->po_id );
$ordered_po = $po->receive( $ordered_po->po_id, array( $ordered_po->items[0]->poi_id => 480 ), wp_generate_uuid4() );
WP_CLI::log( "Created + placed + partially received PO #{$ordered_po->po_id} (Acme Tumbler Supply)." );

$draft_po = $po->create_draft(
	$vendor_id,
	array(
		array(
			'component_id' => (int) wc_get_product_id_by_sku( 'WCBOM-CAP-STD' ),
			'qty_ordered'  => 300,
			'unit_cost'    => 0.22,
		),
	),
	null,
	null,
	'Docs screenshot fixture — draft, not yet placed'
);
WP_CLI::log( "Created draft PO #{$draft_po->po_id} (Acme Tumbler Supply)." );

WP_CLI::success( 'Docs fixture ready.' );
