<?php
/**
 * Append-only stock movement ledger.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wcbom_stock_ledger. Every plugin-driven stock change
 * gets exactly one row here — see StockService, the only writer.
 */
final class Ledger {

	public const REASON_ORDER               = 'order';
	public const REASON_ORDER_RESTORE       = 'order_restore';
	public const REASON_REFUND              = 'refund';
	public const REASON_MANUFACTURE         = 'manufacture';
	public const REASON_MANUFACTURE_REVERSE = 'manufacture_reverse';
	public const REASON_MANUAL_ADJUST       = 'manual_adjust';
	public const REASON_IMPORT              = 'import';

	/**
	 * Writes one ledger row.
	 *
	 * @param int         $product_id  Product/variation ID the delta applies to.
	 * @param float       $delta       Signed stock change.
	 * @param float|null  $stock_after Resulting stock quantity, if known.
	 * @param string      $reason      One of the REASON_* constants.
	 * @param string|null $ref_type    e.g. 'wc_order', 'manufacture_order'.
	 * @param int|null    $ref_id      ID within $ref_type's domain.
	 * @param string|null $note        Free-text context.
	 */
	public function record(
		int $product_id,
		float $delta,
		?float $stock_after,
		string $reason,
		?string $ref_type = null,
		?int $ref_id = null,
		?string $note = null
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcbom_stock_ledger',
			array(
				'product_id'  => $product_id,
				'delta'       => $delta,
				'stock_after' => $stock_after,
				'reason'      => $reason,
				'ref_type'    => $ref_type,
				'ref_id'      => $ref_id,
				'user_id'     => get_current_user_id(),
				'note'        => $note,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%f', '%f', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Recent ledger rows for one product, newest first.
	 *
	 * @param int $product_id Product/variation ID.
	 * @param int $limit      Maximum rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_product( int $product_id, int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcbom_stock_ledger WHERE product_id = %d ORDER BY created_at DESC, ledger_id DESC LIMIT %d",
				$product_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Recent ledger rows for a given reference (e.g. all rows for one order
	 * or one manufacture order), oldest first so they read like a sequence.
	 *
	 * @param string $ref_type e.g. 'wc_order', 'manufacture_order'.
	 * @param int    $ref_id   ID within $ref_type's domain.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_ref( string $ref_type, int $ref_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcbom_stock_ledger WHERE ref_type = %s AND ref_id = %d ORDER BY created_at ASC, ledger_id ASC",
				$ref_type,
				$ref_id
			),
			ARRAY_A
		);
	}
}
