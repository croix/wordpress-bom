<?php
/**
 * Single, row-locked path for every plugin-driven stock mutation.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * The only place plugin code should change WooCommerce stock. Locks the
 * affected product rows, mutates via wc_update_product_stock(), and writes
 * one Ledger row per product — always, no exceptions.
 */
final class StockService {

	/**
	 * Constructs the service.
	 *
	 * @param Ledger $ledger Ledger writer used to record every adjustment.
	 */
	public function __construct( private readonly Ledger $ledger ) {}

	/**
	 * Adjusts one product's stock by $delta.
	 *
	 * @param int         $product_id     Product/variation ID to adjust.
	 * @param float       $delta          Signed stock change.
	 * @param string      $reason         One of Ledger::REASON_*.
	 * @param string|null $ref_type       e.g. 'wc_order', 'manufacture_order'.
	 * @param int|null    $ref_id         ID within $ref_type's domain.
	 * @param string|null $note           Free-text context for the ledger row.
	 * @param bool        $allow_negative Permit the result to go below zero.
	 * @return int The resulting (whole-number) stock quantity.
	 *
	 * @throws \RuntimeException If $product_id doesn't resolve to a product.
	 * @throws InsufficientStockException If the product would go negative
	 *                                    and $allow_negative is false.
	 */
	public function adjust(
		int $product_id,
		float $delta,
		string $reason,
		?string $ref_type = null,
		?int $ref_id = null,
		?string $note = null,
		bool $allow_negative = false
	): int {
		$results = $this->adjust_many( array( $product_id => $delta ), $reason, $ref_type, $ref_id, $note, $allow_negative );

		return $results[ $product_id ];
	}

	/**
	 * Adjusts multiple products' stock atomically — all succeed or none do.
	 * This is the primitive order consumption/restoration and manufacture
	 * orders both build on, so a partially-consumed BOM can never happen.
	 *
	 * @param array<int,float> $deltas         Product ID => signed stock delta.
	 * @param string           $reason         One of Ledger::REASON_*.
	 * @param string|null      $ref_type       e.g. 'wc_order', 'manufacture_order'.
	 * @param int|null         $ref_id         ID within $ref_type's domain.
	 * @param string|null      $note           Free-text context for the ledger rows.
	 * @param bool             $allow_negative Permit results to go below zero.
	 * @return array<int,int> Product ID => resulting (whole-number) stock quantity.
	 *
	 * @throws \RuntimeException If a product ID doesn't resolve to a product.
	 * @throws InsufficientStockException If a product would go negative and
	 *                                    $allow_negative is false.
	 * @throws \Throwable Re-thrown after rolling back the transaction.
	 */
	public function adjust_many(
		array $deltas,
		string $reason,
		?string $ref_type = null,
		?int $ref_id = null,
		?string $note = null,
		bool $allow_negative = false
	): array {
		global $wpdb;

		if ( array() === $deltas ) {
			return array();
		}

		// Lock rows in a stable order (ascending product ID) across every
		// caller so concurrent multi-product transactions can't deadlock.
		ksort( $deltas );

		// If we're already inside a transaction (e.g. WP_UnitTestCase's own
		// test-isolation transaction, or some other plugin's hook), issuing
		// a bare START TRANSACTION would implicitly COMMIT it — MySQL has no
		// true nested transactions. Use a SAVEPOINT instead whenever one is
		// already open, so this never disturbs a caller's own transaction.
		$nested = (bool) $wpdb->get_var( 'SELECT @@in_transaction' );

		if ( $nested ) {
			$wpdb->query( 'SAVEPOINT wcbom_stock' );
		} else {
			$wpdb->query( 'START TRANSACTION' );
		}

		try {
			$results = array();

			foreach ( $deltas as $product_id => $delta ) {
				$product_id = (int) $product_id;

				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					throw new \RuntimeException( "Unknown product #{$product_id}." );
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
				$locked = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE",
						$product_id
					)
				);

				$current_stock = null !== $locked ? (float) $locked : (float) $product->get_stock_quantity();
				// WooCommerce stock is always a whole number; BOM quantities
				// can be fractional (e.g. grams of glitter), so round the
				// running total once here and record that same rounded
				// value everywhere below — the ledger must never disagree
				// with what WooCommerce actually stored.
				$new_stock = (int) round( $current_stock + $delta );

				if ( ! $allow_negative && $new_stock < 0 ) {
					throw new InsufficientStockException( $product_id, $current_stock, $delta );
				}

				wc_update_product_stock( $product, $new_stock, 'set' );

				$this->ledger->record( $product_id, $delta, $new_stock, $reason, $ref_type, $ref_id, $note );

				$results[ $product_id ] = $new_stock;
			}

			if ( $nested ) {
				$wpdb->query( 'RELEASE SAVEPOINT wcbom_stock' );
			} else {
				$wpdb->query( 'COMMIT' );
			}
		} catch ( \Throwable $e ) {
			if ( $nested ) {
				$wpdb->query( 'ROLLBACK TO SAVEPOINT wcbom_stock' );
			} else {
				$wpdb->query( 'ROLLBACK' );
			}

			// wc_update_product_stock() updated object caches *before* our
			// COMMIT — on rollback a persistent object cache (Redis etc.)
			// would keep serving the rolled-back value while the DB has the
			// real one. Flush every touched product so the next read comes
			// from the database. See BUILD_PLAN.md §13.2.
			foreach ( array_keys( $deltas ) as $touched_id ) {
				wp_cache_delete( (int) $touched_id, 'post_meta' );
				wc_delete_product_transients( (int) $touched_id );
			}

			throw $e;
		}

		foreach ( $results as $product_id => $new_stock ) {
			/**
			 * Fires once per product after its stock is committed.
			 * Stock\PhantomStock hooks this to invalidate the buildable-qty
			 * cache of any made-to-order product whose BOM uses $product_id
			 * as an always-consumed component.
			 *
			 * @param int    $product_id The product/variation just adjusted.
			 * @param int    $new_stock  Its resulting stock quantity.
			 * @param string $reason     One of Ledger::REASON_*.
			 */
			do_action( 'wcbom_stock_adjusted', $product_id, $new_stock, $reason );
		}

		return $results;
	}
}
