<?php
/**
 * §9 test scenarios 20-25: vendors & purchase orders (BUILD_PLAN.md §5.13,
 * Phase 9) — strictly opt-in, so scenario 20 (the gating itself) is the
 * load-bearing one; 21-25 cover the PO lifecycle once enabled.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Admin\PurchasingPage;
use WCBOM\Bom\BomRepository;
use WCBOM\Purchasing\PurchaseOrder;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\PurchaseOrderService;
use WCBOM\Purchasing\VendorRepository;
use WCBOM\Purchasing\VendorsFeature;
use WCBOM\Reports\LowStockReport;
use WCBOM\Rest\InventoryApi;
use WCBOM\Rest\PurchasingApi;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

final class PurchasingTest extends WCBOM_UnitTestCase {

	public function tear_down(): void {
		delete_option( VendorsFeature::OPTION );
		parent::tear_down();
	}

	/**
	 * §9.20: with the feature off (the default), no Purchasing menu page
	 * and no vendor/PO REST routes get registered, and the Inventory/
	 * low-stock surfaces omit on-order data entirely rather than showing
	 * it as null/zero — this plugin's every other manual flow is
	 * unaffected either way.
	 */
	public function test_feature_off_registers_nothing_and_omits_on_order_everywhere(): void {
		delete_option( VendorsFeature::OPTION );

		$page = new PurchasingPage();
		$page->register();
		$this->assertFalse( has_action( 'admin_menu', array( $page, 'add_menu_page' ) ), 'The Purchasing menu page must not be registered when the feature is off.' );

		$po_orders = new PurchaseOrderRepository();
		$api       = new PurchasingApi( new PurchaseOrderService( $po_orders, new StockService( new Ledger() ), new OperationGuard() ), $po_orders, new VendorRepository() );
		$api->register_routes();
		$this->assertArrayNotHasKey( '/wcbom/v1/vendors', rest_get_server()->get_routes(), 'No vendor REST route may exist when the feature is off.' );
		$this->assertArrayNotHasKey( '/wcbom/v1/purchase-orders', rest_get_server()->get_routes(), 'No purchase-order REST route may exist when the feature is off.' );

		$blank = $this->create_component( 'Blank', 5 );
		// Force the component below its (default) low-stock threshold so it's a candidate row.
		wc_update_product_stock( wc_get_product( $blank ), 1, 'set' );

		$low_stock_rows = ( new LowStockReport( new BomRepository(), $po_orders ) )->generate();
		$row            = current( array_filter( $low_stock_rows, static fn( array $r ): bool => $r['component_id'] === $blank ) );
		$this->assertArrayNotHasKey( 'on_order', $row, 'LowStockReport must omit on_order entirely when the feature is off.' );

		$inventory_api = new InventoryApi( new StockService( new Ledger() ), new BomRepository(), new OperationGuard(), $po_orders );
		$response      = $inventory_api->list_components( new WP_REST_Request( 'GET', '/wcbom/v1/inventory' ) );
		$component_row = current( array_filter( $response->get_data()['components'], static fn( array $r ) => $r['id'] === $blank ) );
		$this->assertArrayNotHasKey( 'on_order', $component_row, 'The Inventory screen response must omit on_order entirely when the feature is off.' );
	}

	/**
	 * §9.21: draft -> placed -> partial receive -> receive remainder ->
	 * received, with exact stock/ledger movement and cumulative
	 * qty_received at each step.
	 */
	public function test_full_lifecycle_draft_to_received(): void {
		update_option( VendorsFeature::OPTION, 'yes' );

		$blank    = $this->create_component( 'Blank', 100, 'ea', '3.00' );
		$vendor   = new VendorRepository();
		$orders   = new PurchaseOrderRepository();
		$service  = new PurchaseOrderService( $orders, new StockService( new Ledger() ), new OperationGuard() );

		$vendor_id = $vendor->create( 'Acme Blanks Co', 'sales@acme.test', null, null, null );

		$po = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 500, 'unit_cost' => 2.50 ) ), 'INV-001', '2026-08-12', null );
		$this->assertSame( PurchaseOrder::STATUS_DRAFT, $po->status );
		$this->assertSame( 100.0, $this->stock_of( $blank ), 'A draft must move no stock.' );

		$po = $service->place( $po->po_id );
		$this->assertSame( PurchaseOrder::STATUS_ORDERED, $po->status );
		$this->assertNotNull( $po->ordered_at );

		$poi_id = $po->items[0]->poi_id;

		$po = $service->receive( $po->po_id, array( $poi_id => 480.0 ), wp_generate_uuid4() );
		$this->assertSame( PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $po->status );
		$this->assertSame( 480.0, $po->items[0]->qty_received );
		$this->assertSame( 580.0, $this->stock_of( $blank ), '100 on hand + 480 received.' );

		$po = $service->receive( $po->po_id, array( $poi_id => 20.0 ), wp_generate_uuid4() );
		$this->assertSame( PurchaseOrder::STATUS_RECEIVED, $po->status );
		$this->assertSame( 500.0, $po->items[0]->qty_received );
		$this->assertNotNull( $po->closed_at );
		$this->assertSame( 600.0, $this->stock_of( $blank ), '100 on hand + 500 total received.' );

		$ledger_rows = ( new Ledger() )->for_ref( 'purchase_order', $po->po_id );
		$this->assertCount( 2, $ledger_rows, 'One ledger row per receipt.' );
		$this->assertSame( 'po_receive', $ledger_rows[0]['reason'] );
	}

	/**
	 * §9.22: replaying the same receive op_key must not double-stock —
	 * the same OperationGuard idempotency pattern as MO completion.
	 */
	public function test_receive_is_idempotent_by_op_key(): void {
		update_option( VendorsFeature::OPTION, 'yes' );

		$blank   = $this->create_component( 'Blank', 0 );
		$vendor  = new VendorRepository();
		$orders  = new PurchaseOrderRepository();
		$service = new PurchaseOrderService( $orders, new StockService( new Ledger() ), new OperationGuard() );

		$vendor_id = $vendor->create( 'Acme Blanks Co', null, null, null, null );
		$po        = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 50, 'unit_cost' => null ) ), null, null, null );
		$po        = $service->place( $po->po_id );
		$poi_id    = $po->items[0]->poi_id;
		$op_key    = wp_generate_uuid4();

		$service->receive( $po->po_id, array( $poi_id => 50.0 ), $op_key );
		$this->assertSame( 50.0, $this->stock_of( $blank ) );

		// Replaying the identical request (e.g. a retried timeout) must not apply again.
		$po = $service->receive( $po->po_id, array( $poi_id => 50.0 ), $op_key );
		$this->assertSame( 50.0, $this->stock_of( $blank ), 'A replayed op_key must not double-stock.' );
		$this->assertSame( 50.0, $po->items[0]->qty_received );
	}

	/**
	 * §9.23: an ordered PO's outstanding quantity shows up as on_order on
	 * the matching component's low-stock row, with the nearest expected
	 * date — and the component is still listed (on-order never hides
	 * real lowness).
	 */
	public function test_on_order_surfaces_in_low_stock_report_without_hiding_the_row(): void {
		update_option( VendorsFeature::OPTION, 'yes' );

		$blank = $this->create_component( 'Blank', 1 ); // Below the default low-stock threshold.
		wc_update_product_stock( wc_get_product( $blank ), 1, 'set' ); // Ensure managed stock is recognized as low.

		$vendor  = new VendorRepository();
		$orders  = new PurchaseOrderRepository();
		$service = new PurchaseOrderService( $orders, new StockService( new Ledger() ), new OperationGuard() );

		$vendor_id = $vendor->create( 'Acme Blanks Co', null, null, null, null );
		$po        = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 200, 'unit_cost' => null ) ), null, '2026-09-01', null );
		$service->place( $po->po_id );

		$rows = ( new LowStockReport( new BomRepository(), $orders ) )->generate();
		$row  = current( array_filter( $rows, static fn( array $r ): bool => $r['component_id'] === $blank ) );

		$this->assertNotFalse( $row, 'The component must still be listed as low even with stock on order.' );
		$this->assertSame( 200.0, $row['on_order'] );
		$this->assertSame( '2026-09-01', $row['on_order_expected'] );
	}

	/**
	 * §9.24: cancelling a PO (including a partially-received one) stops
	 * its remaining quantity from counting toward on-order; already
	 * received stock is untouched.
	 */
	public function test_cancel_stops_on_order_without_touching_received_stock(): void {
		update_option( VendorsFeature::OPTION, 'yes' );

		$blank   = $this->create_component( 'Blank', 0 );
		$vendor  = new VendorRepository();
		$orders  = new PurchaseOrderRepository();
		$service = new PurchaseOrderService( $orders, new StockService( new Ledger() ), new OperationGuard() );

		$vendor_id = $vendor->create( 'Acme Blanks Co', null, null, null, null );
		$po        = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 100, 'unit_cost' => null ) ), null, null, null );
		$po        = $service->place( $po->po_id );

		$po = $service->receive( $po->po_id, array( $po->items[0]->poi_id => 40.0 ), wp_generate_uuid4() );
		$this->assertSame( PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $po->status );

		$this->assertArrayHasKey( $blank, $orders->on_order_by_component(), 'Precondition: the outstanding quantity must count toward on-order before cancelling.' );

		$po = $service->cancel( $po->po_id );
		$this->assertSame( PurchaseOrder::STATUS_CANCELLED, $po->status );
		$this->assertSame( 40.0, $this->stock_of( $blank ), 'Already-received stock must be untouched by cancellation.' );
		$this->assertArrayNotHasKey( $blank, $orders->on_order_by_component(), 'A cancelled PO must stop counting toward on-order.' );
	}

	/**
	 * §9.25: a draft PO deletes outright; once placed, only cancel — never
	 * delete — is available.
	 */
	public function test_draft_deletes_outright_placed_cannot_be_deleted(): void {
		update_option( VendorsFeature::OPTION, 'yes' );

		$blank   = $this->create_component( 'Blank', 0 );
		$vendor  = new VendorRepository();
		$orders  = new PurchaseOrderRepository();
		$service = new PurchaseOrderService( $orders, new StockService( new Ledger() ), new OperationGuard() );

		$vendor_id = $vendor->create( 'Acme Blanks Co', null, null, null, null );

		$draft = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 10, 'unit_cost' => null ) ), null, null, null );
		$this->assertTrue( $service->delete_draft( $draft->po_id ) );
		$this->assertNull( $orders->get( $draft->po_id ) );

		$placed = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 10, 'unit_cost' => null ) ), null, null, null );
		$placed = $service->place( $placed->po_id );
		$this->assertFalse( $service->delete_draft( $placed->po_id ), 'An ordered PO must not be deletable.' );
		$this->assertNotNull( $orders->get( $placed->po_id ), 'A refused delete must leave the PO intact.' );
	}
}
