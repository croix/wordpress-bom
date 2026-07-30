<?php
/**
 * §9 test scenarios 1, 3, 4, 5: order consumption/restoration for a
 * simple (non-variable) made-to-order product.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Orders\OrderSync;
use WCBOM\Stock\Ledger;

final class OrderConsumptionTest extends WCBOM_UnitTestCase {

	/**
	 * §9.1: ordering consumes the always-line components, writes a
	 * ledger row per component, and snapshots consumption on the item.
	 */
	public function test_order_consumes_bom_and_snapshots_it(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$epoxy = $this->create_component( 'Epoxy', 200, 'ml' );

		$made = $this->create_made_to_order(
			'Custom Tumbler',
			array(
				array( 'component_id' => $blank, 'qty' => 1 ),
				array( 'component_id' => $epoxy, 'qty' => 5 ),
			)
		);

		$order = $this->place_order( $made['product_id'], 1 );

		$this->assertSame( 99.0, $this->stock_of( $blank ) );
		$this->assertSame( 195.0, $this->stock_of( $epoxy ) );

		$item = current( $order->get_items() );
		$snapshot = OrderSync::read_snapshot( $item );
		$this->assertNotNull( $snapshot );
		$this->assertSame( $made['bom_id'], $snapshot['bom_id'] );
		$this->assertCount( 2, $snapshot['components'] );

		$ledger_rows = ( new Ledger() )->for_ref( 'wc_order', $order->get_id() );
		$this->assertCount( 2, $ledger_rows );
		foreach ( $ledger_rows as $row ) {
			$this->assertSame( 'order', $row['reason'] );
		}
	}

	/**
	 * §9.3: cancelling an order restores every consumed component exactly.
	 */
	public function test_cancelling_order_restores_components_exactly(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$made  = $this->create_made_to_order( 'Custom Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$order = $this->place_order( $made['product_id'], 3 );
		$this->assertSame( 97.0, $this->stock_of( $blank ) );

		$order->update_status( 'cancelled' );

		$this->assertSame( 100.0, $this->stock_of( $blank ) );
	}

	/**
	 * §9.4: refunding half the units with restock restores exactly half
	 * the components, and the remaining refunded-units bookkeeping caps
	 * a later full cancel from over-restoring.
	 */
	public function test_partial_refund_restores_proportionally(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$made  = $this->create_made_to_order( 'Custom Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$order = $this->place_order( $made['product_id'], 2 );
		$this->assertSame( 98.0, $this->stock_of( $blank ) );

		$item_id = array_key_first( $order->get_items() );

		wc_create_refund(
			array(
				'order_id'     => $order->get_id(),
				'amount'       => 0,
				'restock_items' => true,
				'line_items'   => array(
					// refund_total must be present (even if 0) — WC core's
					// own line-item processing reads it directly and the
					// real admin refund UI always sends both keys.
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 0,
					),
				),
			)
		);

		$this->assertSame( 99.0, $this->stock_of( $blank ), 'Refunding 1 of 2 units should restore exactly half.' );

		// Cancelling afterward must restore only the still-outstanding unit,
		// never double-restoring the already-refunded one.
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );

		$this->assertSame( 100.0, $this->stock_of( $blank ) );
	}

	/**
	 * §9.5: editing the BOM after the sale must not affect restoration of
	 * an already-placed order — restoration reads the snapshot taken at
	 * sale time, never the current (edited) BOM.
	 */
	public function test_restoration_uses_snapshot_not_current_bom(): void {
		$blank = $this->create_component( 'Blank', 100 );
		$made  = $this->create_made_to_order( 'Custom Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$order = $this->place_order( $made['product_id'], 1 );
		$this->assertSame( 99.0, $this->stock_of( $blank ) );

		// Edit the BOM to consume 100x as much — a new version, per the
		// "BOMs are never edited in place" rule.
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

		$order->update_status( 'cancelled' );

		// Must restore exactly 1 (the original snapshot), not 100.
		$this->assertSame( 100.0, $this->stock_of( $blank ) );
	}
}
