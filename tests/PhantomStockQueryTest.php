<?php
/**
 * §9 test scenario 12: phantom stock on a shop-grid-sized catalog does
 * not re-query the database per product once the buildable-qty cache is
 * warm — the whole point of PhantomStock's no-TTL transient cache.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomRepository;
use WCBOM\Stock\PhantomStock;

final class PhantomStockQueryTest extends WCBOM_UnitTestCase {

	public function test_warm_cache_adds_no_queries_across_many_products(): void {
		global $wpdb;

		$phantom = new PhantomStock( new BomRepository() );

		$blank = $this->create_component( 'Blank', 1000 );
		$product_ids = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$made          = $this->create_made_to_order( "Tumbler {$i}", array( array( 'component_id' => $blank, 'qty' => 1 ) ) );
			$product_ids[] = $made['product_id'];
		}

		// Cold pass: computes and caches every product's buildable qty.
		foreach ( $product_ids as $product_id ) {
			$phantom->get_buildable_qty( $product_id );
		}

		// Warm pass: every product's qty should now come straight from the
		// transient cache — no BOM lookups, no product queries.
		$queries_before = $wpdb->num_queries;
		foreach ( $product_ids as $product_id ) {
			$phantom->get_buildable_qty( $product_id );
		}
		$queries_after = $wpdb->num_queries;

		$this->assertSame(
			0,
			$queries_after - $queries_before,
			'A warm phantom-stock cache must not issue any DB queries — the shop grid would N+1 otherwise.'
		);
	}
}
