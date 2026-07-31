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
	public const REASON_RECEIVED            = 'received';
	public const REASON_CYCLE_COUNT         = 'cycle_count';
	public const REASON_PO_RECEIVE          = 'po_receive';

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

	/**
	 * Filtered, paginated ledger rows for the Reports screen's ledger
	 * browser and CSV export — both need the identical filter set, so
	 * they share this one query.
	 *
	 * @param array{product_id?:int,reason?:string,ref_type?:string,date_from?:string,date_to?:string} $filters Optional filters; omit a key to not filter on it.
	 * @param int                                                                                      $page     1-based page number.
	 * @param int                                                                                      $per_page Rows per page (ignored, i.e. unlimited, when $page is 0).
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $filters = array(), int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		[$where, $params] = $this->build_where( $filters );

		$sql = "SELECT * FROM {$wpdb->prefix}wcbom_stock_ledger WHERE {$where} ORDER BY created_at DESC, ledger_id DESC";

		if ( $page > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare() when $params is non-empty, matching ManufactureRepository::list()'s identical pattern.
		return array() !== $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Total row count for a filter set, for the ledger browser's pagination.
	 *
	 * @param array{product_id?:int,reason?:string,ref_type?:string,date_from?:string,date_to?:string} $filters Same shape as query()'s.
	 */
	public function count( array $filters = array() ): int {
		global $wpdb;

		[$where, $params] = $this->build_where( $filters );

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wcbom_stock_ledger WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a placeholder string built by build_where(), not raw input.

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare() when $params is non-empty, matching ManufactureRepository::list()'s identical pattern.
		return (int) ( array() !== $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * Builds a parameterized WHERE clause shared by query() and count().
	 *
	 * @param array{product_id?:int,reason?:string,ref_type?:string,date_from?:string,date_to?:string} $filters Optional filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $filters ): array {
		$conditions = array( '1=1' );
		$params     = array();

		if ( isset( $filters['product_id'] ) ) {
			$conditions[] = 'product_id = %d';
			$params[]     = (int) $filters['product_id'];
		}
		if ( isset( $filters['reason'] ) && '' !== $filters['reason'] ) {
			$conditions[] = 'reason = %s';
			$params[]     = (string) $filters['reason'];
		}
		if ( isset( $filters['ref_type'] ) && '' !== $filters['ref_type'] ) {
			$conditions[] = 'ref_type = %s';
			$params[]     = (string) $filters['ref_type'];
		}
		if ( isset( $filters['date_from'] ) && '' !== $filters['date_from'] ) {
			$conditions[] = 'created_at >= %s';
			$params[]     = (string) $filters['date_from'];
		}
		if ( isset( $filters['date_to'] ) && '' !== $filters['date_to'] ) {
			$conditions[] = 'created_at <= %s';
			$params[]     = (string) $filters['date_to'];
		}

		return array( implode( ' AND ', $conditions ), $params );
	}

	/**
	 * Sum of negative (consumption) deltas for a component within a
	 * lookback window, across the given reasons — the run-rate input for
	 * Reports\ComponentUsageReport.
	 *
	 * @param int               $component_id Component product/variation ID.
	 * @param array<int,string> $reasons      REASON_* values to include (e.g. order + manufacture).
	 * @param int               $days         Lookback window in days.
	 */
	public function consumed_since( int $component_id, array $reasons, int $days ): float {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $reasons ), '%s' ) );
		$since        = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// $placeholders is a runtime-built list of %s markers matching count($reasons); phpcs can't count a
		// dynamically-interpolated IN() clause statically, so both sniffs below are expected false positives.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(delta) FROM {$wpdb->prefix}wcbom_stock_ledger
				 WHERE product_id = %d AND created_at >= %s AND delta < 0 AND reason IN ({$placeholders})",
				array_merge( array( $component_id, $since ), $reasons )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return null !== $total ? abs( (float) $total ) : 0.0;
	}
}
