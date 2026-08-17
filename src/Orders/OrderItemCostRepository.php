<?php
/**
 * Persistence for frozen order-item cost snapshots.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wcbom_order_item_costs. record() is INSERT-first
 * against the table's own UNIQUE KEY on order_item_id — the same pattern
 * Stock\OperationGuard::claim() uses — so a race between two triggers of
 * the same capture event can never double-insert or silently overwrite an
 * already-frozen row; the drift rule is enforced by the database, not just
 * by application-level care.
 */
final class OrderItemCostRepository {

	/**
	 * Attempts to write a cost snapshot for one order item. Returns false
	 * (without throwing) if a row for this order_item_id already exists —
	 * the caller should treat that as "already captured," never as an error.
	 *
	 * @param int        $order_id      The order this line belongs to.
	 * @param int        $order_item_id The specific order line item.
	 * @param int        $product_id    The sold item's own ID (variation ID for a variation).
	 * @param float      $quantity      Units sold on this line.
	 * @param float|null $unit_cost     Cost basis, or null when uncosted.
	 * @param string     $cost_source   One of OrderItemCost::SOURCE_*.
	 */
	public function record( int $order_id, int $order_item_id, int $product_id, float $quantity, ?float $unit_cost, string $cost_source ): bool {
		global $wpdb;

		// INSERT-first: the UNIQUE KEY on order_item_id decides the race,
		// same reasoning as OperationGuard::claim(). Suppress errors so the
		// expected duplicate-key failure doesn't hit the error log.
		$suppressing = $wpdb->suppress_errors( true );
		$inserted    = $wpdb->insert(
			$wpdb->prefix . 'wcbom_order_item_costs',
			array(
				'order_id'      => $order_id,
				'order_item_id' => $order_item_id,
				'product_id'    => $product_id,
				'quantity'      => $quantity,
				'unit_cost'     => $unit_cost,
				'cost_source'   => $cost_source,
				'captured_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%f', '%f', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppressing );

		return false !== $inserted && $inserted > 0;
	}

	/**
	 * The cost snapshot for one order item, if captured.
	 *
	 * @param int $order_item_id The order line item's ID.
	 */
	public function get_for_item( int $order_item_id ): ?OrderItemCost {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_order_item_costs WHERE order_item_id = %d", $order_item_id ),
			ARRAY_A
		);

		return null !== $row ? OrderItemCost::from_row( $row ) : null;
	}

	/**
	 * Every captured cost row across a set of orders, keyed by
	 * order_item_id — bulk-fetched in one query so a report iterating N
	 * orders' line items costs one query, not N (BUILD_PLAN.md §7.5).
	 *
	 * @param array<int,int> $order_ids Order IDs to fetch cost rows for.
	 * @return array<int,OrderItemCost> Keyed by order_item_id.
	 */
	public function for_order_ids( array $order_ids ): array {
		if ( array() === $order_ids ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		// $placeholders is a runtime-built list of %d markers matching count($order_ids);
		// phpcs can't count a dynamically-interpolated IN() clause statically.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcbom_order_item_costs WHERE order_id IN ({$placeholders})",
				$order_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$result = array();
		foreach ( $rows as $row ) {
			$cost                           = OrderItemCost::from_row( $row );
			$result[ $cost->order_item_id ] = $cost;
		}

		return $result;
	}
}
