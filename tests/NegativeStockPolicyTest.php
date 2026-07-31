<?php
/**
 * §9 test scenario 28 / BUILD_PLAN §11.2: the "Allow negative component
 * stock" setting governs manual operations (Inventory adjustments,
 * manufacture-order completion) only — order consumption's shortage
 * behavior (§13.3) is an invariant, not something this setting touches.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomRepository;
use WCBOM\Rest\InventoryApi;
use WCBOM\Stock\InsufficientStockException;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\NegativeStockPolicy;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

final class NegativeStockPolicyTest extends WCBOM_UnitTestCase {

	public function tear_down(): void {
		delete_option( NegativeStockPolicy::OPTION );
		parent::tear_down();
	}

	public function test_option_defaults_off(): void {
		delete_option( NegativeStockPolicy::OPTION );
		$this->assertFalse( NegativeStockPolicy::allowed() );
	}

	public function test_option_reflects_stored_value(): void {
		update_option( NegativeStockPolicy::OPTION, 'yes' );
		$this->assertTrue( NegativeStockPolicy::allowed() );

		update_option( NegativeStockPolicy::OPTION, 'no' );
		$this->assertFalse( NegativeStockPolicy::allowed() );
	}

	/**
	 * With the setting off (default), completing an under-stocked MO
	 * without the per-operation override still throws — the pre-existing
	 * behavior, unaffected by this setting when left off.
	 */
	public function test_manufacture_complete_blocked_by_default(): void {
		delete_option( NegativeStockPolicy::OPTION );

		$blank = $this->create_component( 'Blank', 5 );
		$made  = $this->create_manufactured_product( 'Batch Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );

		$this->expectException( InsufficientStockException::class );
		$service->complete( $mo->mo_id, wp_generate_uuid4() );
	}

	/**
	 * With the setting on, the same under-stocked completion proceeds
	 * without needing the per-call $allow_negative override — the setting
	 * is a sitewide default, not just a per-request opt-in.
	 */
	public function test_manufacture_complete_proceeds_when_setting_enabled(): void {
		update_option( NegativeStockPolicy::OPTION, 'yes' );

		$blank = $this->create_component( 'Blank', 5 );
		$made  = $this->create_manufactured_product( 'Batch Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );
		$mo      = $service->complete( $mo->mo_id, wp_generate_uuid4() );

		$this->assertSame( 'completed', $mo->status );
		$this->assertSame( -5.0, $this->stock_of( $blank ), 'Blank must go negative: 5 on hand - 10 consumed.' );
		$this->assertSame( 10.0, $this->stock_of( $made['product_id'] ) );
	}

	/**
	 * The Inventory screen's manual Adjust endpoint honors the same
	 * setting — this is the REST-layer half of the same acceptance
	 * scenario, since InventoryApi::apply_adjust() is where the option is
	 * actually read for that workflow (ManufactureService reads it
	 * directly; the REST controller has no service layer of its own to
	 * put it in).
	 */
	public function test_manual_adjust_endpoint_honors_setting(): void {
		delete_option( NegativeStockPolicy::OPTION );

		$blank = $this->create_component( 'Blank', 5 );
		$api   = new InventoryApi( new StockService( new Ledger() ), new BomRepository(), new OperationGuard() );

		$blocked = $api->apply_adjust( $this->adjust_request( $blank, -10.0, 'off by default' ) );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 5.0, $this->stock_of( $blank ), 'A blocked adjustment must leave stock untouched.' );

		update_option( NegativeStockPolicy::OPTION, 'yes' );

		$allowed = $api->apply_adjust( $this->adjust_request( $blank, -10.0, 'allowed once enabled' ) );
		$this->assertNotInstanceOf( WP_Error::class, $allowed );
		$this->assertSame( -5.0, $this->stock_of( $blank ) );
	}

	/**
	 * A manually built WP_REST_Request matching what InventoryApi's
	 * /inventory/adjust route expects — no full REST dispatch needed to
	 * exercise the controller method directly.
	 */
	private function adjust_request( int $product_id, float $qty, string $note ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wcbom/v1/inventory/adjust' );
		$request->set_body_params(
			array(
				'op_key' => wp_generate_uuid4(),
				'note'   => $note,
				'items'  => array(
					array( 'product_id' => $product_id, 'qty' => $qty ),
				),
			)
		);

		return $request;
	}
}
