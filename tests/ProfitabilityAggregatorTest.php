<?php
/**
 * §9 test scenario 35: Reports\ProfitabilityAggregator's pure grouping and
 * "uncosted is never zero" arithmetic (BUILD_PLAN.md §5.15, Phase 11).
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Reports\ProfitabilityAggregator;

final class ProfitabilityAggregatorTest extends WCBOM_UnitTestCase {

	public function test_groups_and_sums_by_product(): void {
		$lines = array(
			$this->line( 1, 1, '2026-08', 2.0, 40.0, 10.0 ),
			$this->line( 1, 2, '2026-08', 1.0, 20.0, 5.0 ),
			$this->line( 2, 3, '2026-08', 3.0, 60.0, 15.0 ),
		);

		$rows = ( new ProfitabilityAggregator() )->by_product( $lines );
		$rows = array_column( $rows, null, 'product_id' );

		$this->assertSame( 3.0, $rows[1]['quantity'] );
		$this->assertSame( 60.0, $rows[1]['revenue'] );
		$this->assertSame( 15.0, $rows[1]['cost'] );
		$this->assertSame( 45.0, $rows[1]['profit'] );
		$this->assertSame( 75.0, $rows[1]['margin'], 'profit/revenue × 100 = 45/60 × 100.' );

		$this->assertSame( 3.0, $rows[2]['quantity'] );
		$this->assertSame( 60.0, $rows[2]['revenue'] );
	}

	public function test_groups_by_order(): void {
		$lines = array(
			$this->line( 1, 10, '2026-08', 1.0, 20.0, 5.0 ),
			$this->line( 2, 10, '2026-08', 1.0, 30.0, 8.0 ),
		);

		$rows = ( new ProfitabilityAggregator() )->by_order( $lines );
		$rows = array_column( $rows, null, 'order_id' );

		$this->assertSame( 2.0, $rows[10]['quantity'] );
		$this->assertSame( 50.0, $rows[10]['revenue'] );
		$this->assertSame( 13.0, $rows[10]['cost'] );
	}

	public function test_groups_by_month(): void {
		$lines = array(
			$this->line( 1, 1, '2026-07', 1.0, 20.0, 5.0 ),
			$this->line( 2, 2, '2026-08', 1.0, 20.0, 5.0 ),
		);

		$rows = ( new ProfitabilityAggregator() )->by_month( $lines );
		$rows = array_column( $rows, null, 'month' );

		$this->assertArrayHasKey( '2026-07', $rows );
		$this->assertArrayHasKey( '2026-08', $rows );
	}

	/**
	 * §9.35: an uncosted line contributes quantity/revenue but must never
	 * be treated as $0 cost — it's excluded from the cost sum and tracked
	 * separately, so profit/margin aren't silently inflated.
	 */
	public function test_uncosted_line_excluded_from_cost_never_treated_as_zero(): void {
		$lines = array(
			$this->line( 1, 1, '2026-08', 2.0, 40.0, 10.0 ),
			$this->line( 1, 2, '2026-08', 1.0, 15.0, null ),
		);

		$rows = ( new ProfitabilityAggregator() )->by_product( $lines );
		$row  = $rows[0];

		$this->assertSame( 3.0, $row['quantity'] );
		$this->assertSame( 55.0, $row['revenue'] );
		$this->assertSame( 10.0, $row['cost'], 'Only the costed line contributes — the uncosted line is never treated as $0.' );
		$this->assertSame( 1.0, $row['uncosted_quantity'] );
		$this->assertSame( 45.0, $row['profit'] );
	}

	public function test_margin_is_null_when_revenue_is_zero(): void {
		$lines = array( $this->line( 1, 1, '2026-08', 1.0, 0.0, 0.0 ) );

		$rows = ( new ProfitabilityAggregator() )->by_product( $lines );

		$this->assertNull( $rows[0]['margin'] );
	}

	public function test_finalize_rounds_money_and_quantity_fields(): void {
		$lines = array(
			$this->line( 1, 1, '2026-08', 1.0 / 3, 10.0 / 3, 1.0 / 3 ),
		);

		$rows = ( new ProfitabilityAggregator() )->by_product( $lines );
		$row  = $rows[0];

		$this->assertSame( round( 1.0 / 3, 4 ), $row['quantity'] );
		$this->assertSame( round( 10.0 / 3, 4 ), $row['revenue'] );
		$this->assertSame( round( 1.0 / 3, 4 ), $row['cost'] );
	}

	/**
	 * @return array{product_id:int,order_id:int,month:string,quantity:float,revenue:float,cost:float|null}
	 */
	private function line( int $product_id, int $order_id, string $month, float $quantity, float $revenue, ?float $cost ): array {
		return array(
			'product_id' => $product_id,
			'order_id'   => $order_id,
			'month'      => $month,
			'quantity'   => $quantity,
			'revenue'    => $revenue,
			'cost'       => $cost,
		);
	}
}
