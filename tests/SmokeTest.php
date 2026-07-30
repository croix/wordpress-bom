<?php
/**
 * Proves the PHPUnit + WP test-suite harness actually works: WordPress
 * core, WooCommerce, and this plugin all load; our custom tables exist.
 *
 * @package WCBOM
 */

declare(strict_types=1);

/**
 * @group smoke
 */
final class SmokeTest extends WP_UnitTestCase {

	public function test_woocommerce_is_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
	}

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( class_exists( \WCBOM\Plugin::class ) );
	}

	public function test_custom_tables_exist(): void {
		global $wpdb;

		foreach ( array( 'wcbom_boms', 'wcbom_bom_items', 'wcbom_stock_ledger', 'wcbom_manufacture_orders', 'wcbom_manufacture_order_items', 'wcbom_ops' ) as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) );
			$this->assertSame( $wpdb->prefix . $table, $exists, "Table {$table} should exist." );
		}
	}

	public function test_can_create_a_product(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Smoke Test Widget' );
		$product->set_regular_price( '9.99' );
		$id = $product->save();

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'Smoke Test Widget', wc_get_product( $id )->get_name() );
	}
}
