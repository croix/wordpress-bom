<?php
/**
 * Reports\ComponentUsageReport::generate() — the Reports screen's
 * "Component Usage" list-everything table.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomRepository;
use WCBOM\Reports\ComponentUsageReport;
use WCBOM\Stock\Ledger;

final class ComponentUsageReportTest extends WCBOM_UnitTestCase {

	public function test_generate_lists_every_flagged_component(): void {
		$blank   = $this->create_component( 'Usage Report Blank', 100 );
		$glitter = $this->create_component( 'Usage Report Glitter', 500, 'g' );

		// A non-component product must never appear in the report.
		$other = new WC_Product_Simple();
		$other->set_name( 'Usage Report Not A Component' );
		$other->set_manage_stock( true );
		$other->set_stock_quantity( 10 );
		$other_id = $other->save();

		$report = new ComponentUsageReport( new BomRepository(), new Ledger() );
		$rows   = $report->generate();

		$ids = array_column( $rows, 'component_id' );
		$this->assertContains( $blank, $ids );
		$this->assertContains( $glitter, $ids );
		$this->assertNotContains( $other_id, $ids );

		$blank_row = current( array_filter( $rows, static fn( $row ): bool => $row['component_id'] === $blank ) );
		$this->assertSame( 'Usage Report Blank', $blank_row['name'] );
		$this->assertSame( 100.0, $blank_row['stock'] );
		$this->assertSame( 0.0, $blank_row['consumed_30d'], 'No orders/MOs consumed it yet.' );
		$this->assertNull( $blank_row['days_of_stock'], 'No recent consumption means the run-rate estimate is undefined.' );
	}

	public function test_generate_reflects_real_consumption(): void {
		$blank = $this->create_component( 'Usage Report Consumed Blank', 100 );
		$made  = $this->create_made_to_order( 'Usage Report Product', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$this->place_order( $made['product_id'], 5 );

		$report = new ComponentUsageReport( new BomRepository(), new Ledger() );
		$row    = current( array_filter( $report->generate(), static fn( $r ): bool => $r['component_id'] === $blank ) );

		$this->assertSame( 95.0, $row['stock'] );
		$this->assertSame( 5.0, $row['consumed_30d'] );
		$this->assertNotEmpty( $row['used_in'] );
	}
}
