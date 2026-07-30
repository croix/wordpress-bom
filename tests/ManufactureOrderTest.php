<?php
/**
 * §9 test scenarios 7, 8, 9, 10: manufacture order complete/reverse
 * lifecycle, crash-safety snapshot behavior.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;

final class ManufactureOrderTest extends WCBOM_UnitTestCase {

	/**
	 * §9.7: completing a draft MO consumes components, creates/increments
	 * the finished good's stock, and snapshots the recipe + unit cost used.
	 */
	public function test_complete_consumes_components_and_stocks_finished_good(): void {
		$blank  = $this->create_component( 'Blank', 100, 'ea', '3.50' );
		$glitter = $this->create_component( 'Glitter', 500, 'g', '0.02' );

		$made = $this->create_manufactured_product(
			'Pink Tumbler',
			array(
				array( 'component_id' => $blank, 'qty' => 1 ),
				array( 'component_id' => $glitter, 'qty' => 15 ),
			)
		);

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 12, 'Test build' );

		// Draft moves nothing.
		$this->assertSame( 100.0, $this->stock_of( $blank ) );
		$this->assertSame( 500.0, $this->stock_of( $glitter ) );
		$this->assertSame( 0.0, $this->stock_of( $made['product_id'] ) );

		$mo = $service->complete( $mo->mo_id, wp_generate_uuid4() );

		$this->assertSame( 'completed', $mo->status );
		$this->assertSame( 88.0, $this->stock_of( $blank ) );
		$this->assertSame( 320.0, $this->stock_of( $glitter ) );
		$this->assertSame( 12.0, $this->stock_of( $made['product_id'] ) );

		$this->assertCount( 2, $mo->items );
		$blank_line = current(
			array_filter( $mo->items, static fn( $item ): bool => $item->component_id === $blank )
		);
		$this->assertSame( 3.5, $blank_line->unit_cost );
		$this->assertSame( 12.0, $blank_line->qty_total );
	}

	/**
	 * §9.8: reversing part of a completed MO restores components
	 * proportionally; reversing more than what remains is blocked.
	 */
	public function test_partial_reverse_restores_proportionally_and_blocks_overreverse(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$made  = $this->create_manufactured_product( 'Pink Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );
		$mo      = $service->complete( $mo->mo_id, wp_generate_uuid4() );

		$this->assertSame( 90.0, $this->stock_of( $blank ) );
		$this->assertSame( 10.0, $this->stock_of( $made['product_id'] ) );

		$mo = $service->reverse( $mo->mo_id, 4, wp_generate_uuid4() );

		$this->assertSame( 'partially_reversed', $mo->status );
		$this->assertSame( 94.0, $this->stock_of( $blank ) );
		$this->assertSame( 6.0, $this->stock_of( $made['product_id'] ) );
		$this->assertSame( 6, $mo->remaining_units() );

		// Only 6 units remain — reversing 7 must be blocked, and must not
		// move any stock.
		$this->expectException( \RuntimeException::class );
		try {
			$service->reverse( $mo->mo_id, 7, wp_generate_uuid4() );
		} finally {
			$this->assertSame( 94.0, $this->stock_of( $blank ), 'Blocked over-reverse must not move stock.' );
			$this->assertSame( 6.0, $this->stock_of( $made['product_id'] ) );
		}
	}

	/**
	 * §9.9: reversal is blocked when the finished good's own stock can't
	 * cover the requested reversal — i.e. some of it has already been sold.
	 */
	public function test_reverse_blocked_when_finished_stock_sold_below_requested_qty(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$made  = $this->create_manufactured_product( 'Pink Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );
		$mo      = $service->complete( $mo->mo_id, wp_generate_uuid4() );

		// Simulate 8 of the 10 finished units having been sold — only 2
		// remain in physical/WC stock, even though the MO thinks 10 are
		// still outstanding.
		$this->place_order( $made['product_id'], 8 );
		$this->assertSame( 2.0, $this->stock_of( $made['product_id'] ) );

		$this->expectException( \RuntimeException::class );
		try {
			$service->reverse( $mo->mo_id, 5, wp_generate_uuid4() );
		} finally {
			$this->assertSame( 2.0, $this->stock_of( $made['product_id'] ), 'Blocked reversal must not move finished-good stock.' );
		}
	}

	/**
	 * §9.10: editing the BOM between MO completion and reversal must not
	 * affect the reversal — it reads the MOI snapshot taken at completion,
	 * never the current (edited) BOM.
	 */
	public function test_reversal_uses_moi_snapshot_not_current_bom(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$made  = $this->create_manufactured_product( 'Pink Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );
		$mo      = $service->complete( $mo->mo_id, wp_generate_uuid4() );

		$this->assertSame( 90.0, $this->stock_of( $blank ) );

		// Edit the BOM to consume 100x as much — a new version.
		( new BomRepository() )->save(
			$made['product_id'],
			array(
				array(
					'component_id'    => $blank,
					'qty'             => 100,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
			),
			1
		);

		$mo = $service->reverse( $mo->mo_id, 10, wp_generate_uuid4() );

		// Must restore exactly 10 (the original snapshot's qty_per_unit=1),
		// not 1000 (the edited BOM's qty_per_unit=100).
		$this->assertSame( 100.0, $this->stock_of( $blank ) );
		$this->assertSame( 'reversed', $mo->status );
	}
}
