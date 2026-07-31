<?php
/**
 * Dev-only WP-CLI eval-file script: tears down whatever
 * bin/docs-fixture.php created on a previous run, so
 * `wp wcbom seed --reset` (which only knows about the original sample
 * catalog, not this demo data layered on top of it) can proceed cleanly.
 * Run before `wp wcbom seed --reset`, never after.
 *
 * Usage: wp eval-file bin/docs-fixture-cleanup.php
 *
 * @package WCBOM
 */

global $wpdb;

// The premade product may carry a sub-assembly BOM line pointing at a
// demo product — clear that reference *before* touching the demo
// products below, or DeletionGuard blocks deleting a product that's
// still in active use as a component.
$premade_id = (int) wc_get_product_id_by_sku( 'WCBOM-PREMADE-OCEAN' );
if ( $premade_id ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wcbom_bom_items WHERE bom_id IN (SELECT bom_id FROM {$wpdb->prefix}wcbom_boms WHERE product_id = %d)",
			$premade_id
		)
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wcbom_boms WHERE product_id = %d", $premade_id ) );
}

$demo_product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE '%(docs demo)%'" );

if ( array() !== $demo_product_ids ) {
	$placeholders = implode( ',', array_fill( 0, count( $demo_product_ids ), '%d' ) );

	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- built via $wpdb->prepare() with a dynamic IN() list, the documented pattern for this.
			"DELETE FROM {$wpdb->prefix}wcbom_bom_items WHERE bom_id IN (SELECT bom_id FROM {$wpdb->prefix}wcbom_boms WHERE product_id IN ({$placeholders}))",
			$demo_product_ids
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- built via $wpdb->prepare() with a dynamic IN() list, the documented pattern for this.
			"DELETE FROM {$wpdb->prefix}wcbom_boms WHERE product_id IN ({$placeholders})",
			$demo_product_ids
		)
	);

	foreach ( $demo_product_ids as $product_id ) {
		wp_delete_post( (int) $product_id, true );
	}

	WP_CLI::log( 'Removed ' . count( $demo_product_ids ) . ' product(s) from a previous docs fixture run.' );
}

// Truncate (not DELETE) so the auto-increment counters reset too — the
// MO/PO ids these produce (e.g. "Complete MO #3") are visible, screenshotted
// text, and would otherwise keep climbing forever across every future run
// in a long-lived dev database, which is exactly the kind of regeneration
// churn determinism is supposed to avoid.
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcbom_manufacture_order_items" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcbom_manufacture_orders" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcbom_po_items" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcbom_purchase_orders" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wcbom_vendors" );

WP_CLI::success( 'Docs fixture cleaned up.' );
