<?php
/**
 * Freight/tax/fees amortization across PO line items (BUILD_PLAN.md
 * §5.13 addendum, added 2026-07-31) — proportional to each line's
 * ordered value (qty_ordered × unit_cost), display-only.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Purchasing\LandedCost;
use WCBOM\Purchasing\PurchaseOrder;
use WCBOM\Purchasing\PurchaseOrderItem;

final class LandedCostTest extends WCBOM_UnitTestCase {

	public function test_amortizes_proportional_to_line_value(): void {
		// Line A: 100 × $2.00 = $200 (2/3 of $300 total value).
		// Line B: 50 × $2.00  = $100 (1/3 of $300 total value).
		$po = $this->po_with_items(
			array(
				$this->item( 1, 100.0, 2.00 ),
				$this->item( 2, 50.0, 2.00 ),
			),
			30.0,
			0.0,
			0.0
		);

		$result = ( new LandedCost() )->for_order( $po );

		$this->assertSame( 30.0, $result['total_fees'] );
		$this->assertSame( 20.0, $result['items'][1]['amortized_fee'], 'Line A gets 2/3 of the $30 fee.' );
		$this->assertSame( 10.0, $result['items'][2]['amortized_fee'], 'Line B gets 1/3 of the $30 fee.' );
		$this->assertSame( 2.2, $result['items'][1]['landed_unit_cost'], '$2.00 + ($20/100 units).' );
		$this->assertSame( 2.2, $result['items'][2]['landed_unit_cost'], '$2.00 + ($10/50 units) — same landed cost despite different quantities, since both lines have equal per-unit cost.' );
	}

	public function test_freight_tax_fees_all_sum_into_total(): void {
		$po = $this->po_with_items( array( $this->item( 1, 10.0, 5.00 ) ), 10.0, 5.0, 2.5 );

		$result = ( new LandedCost() )->for_order( $po );

		$this->assertSame( 17.5, $result['total_fees'] );
		$this->assertSame( 17.5, $result['items'][1]['amortized_fee'], 'Sole line absorbs the entire fee total.' );
	}

	public function test_line_with_no_unit_cost_gets_zero_allocation(): void {
		$po = $this->po_with_items(
			array(
				$this->item( 1, 100.0, 2.00 ),
				$this->item( 2, 50.0, null ),
			),
			30.0,
			0.0,
			0.0
		);

		$result = ( new LandedCost() )->for_order( $po );

		// The costed line absorbs the entire fee — its value is the only basis available.
		$this->assertSame( 30.0, $result['items'][1]['amortized_fee'] );
		$this->assertSame( 0.0, $result['items'][2]['amortized_fee'] );
		$this->assertNull( $result['items'][2]['landed_unit_cost'], 'No unit cost means no landed cost can be computed.' );
	}

	public function test_zero_fees_means_zero_allocation_everywhere(): void {
		$po = $this->po_with_items( array( $this->item( 1, 10.0, 5.00 ) ), null, null, null );

		$result = ( new LandedCost() )->for_order( $po );

		$this->assertSame( 0.0, $result['total_fees'] );
		$this->assertSame( 0.0, $result['items'][1]['amortized_fee'] );
		$this->assertSame( 5.0, $result['items'][1]['landed_unit_cost'], 'With no fees, landed cost equals the plain unit cost.' );
	}

	/**
	 * @param array<int,PurchaseOrderItem> $items
	 */
	private function po_with_items( array $items, ?float $freight, ?float $tax, ?float $fees ): PurchaseOrder {
		return new PurchaseOrder(
			1,
			1,
			PurchaseOrder::STATUS_ORDERED,
			null,
			null,
			null,
			$freight,
			$tax,
			$fees,
			1,
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			null,
			$items
		);
	}

	private function item( int $poi_id, float $qty_ordered, ?float $unit_cost ): PurchaseOrderItem {
		return new PurchaseOrderItem( $poi_id, 1, 99, $qty_ordered, 0.0, $unit_cost );
	}
}
