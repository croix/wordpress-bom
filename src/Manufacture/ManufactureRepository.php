<?php
/**
 * Persistence for manufacture orders and their consumption snapshots.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Manufacture;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wcbom_manufacture_orders/wcbom_manufacture_order_items.
 * Status transitions are applied here as simple column updates; the state
 * machine rules (what transitions are legal, when) live in
 * ManufactureService, which is the only caller that should mutate status.
 */
final class ManufactureRepository {

	/**
	 * Creates a draft manufacture order. Nothing else happens at this
	 * point — no stock moves until it's completed.
	 *
	 * @param int         $product_id The finished good to build.
	 * @param int         $bom_id     The BOM version driving this build.
	 * @param int         $qty_built  Units to build.
	 * @param string|null $notes      Free-text notes.
	 * @param int         $user_id    Creating user.
	 */
	public function create_draft( int $product_id, int $bom_id, int $qty_built, ?string $notes, int $user_id ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcbom_manufacture_orders',
			array(
				'product_id' => $product_id,
				'bom_id'     => $bom_id,
				'qty_built'  => $qty_built,
				'status'     => ManufactureOrder::STATUS_DRAFT,
				'notes'      => $notes,
				'created_by' => $user_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * A manufacture order by ID, with its consumption snapshot (empty
	 * until completed).
	 *
	 * @param int $mo_id The manufacture order's ID.
	 */
	public function get( int $mo_id ): ?ManufactureOrder {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_manufacture_orders WHERE mo_id = %d", $mo_id ),
			ARRAY_A
		);

		return null !== $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lists manufacture orders, newest first.
	 *
	 * @param string|null $status     Filter to one status, or null for all.
	 * @param int|null    $product_id Filter to one finished good, or null for all.
	 * @return array<int,ManufactureOrder>
	 */
	public function list( ?string $status = null, ?int $product_id = null ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( null !== $product_id ) {
			$where[]  = 'product_id = %d';
			$params[] = $product_id;
		}

		$sql = "SELECT * FROM {$wpdb->prefix}wcbom_manufacture_orders WHERE " . implode( ' AND ', $where ) . ' ORDER BY mo_id DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare() when $params is non-empty.
		$rows = array() !== $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Writes the consumption snapshot for a completed manufacture order.
	 *
	 * @param int                                                                                        $mo_id The manufacture order's ID.
	 * @param array<int,array{component_id:int,qty_per_unit:float,qty_total:float,unit_cost:float|null}> $items Consumption lines to record.
	 */
	public function save_items( int $mo_id, array $items ): void {
		global $wpdb;

		foreach ( $items as $item ) {
			$wpdb->insert(
				$wpdb->prefix . 'wcbom_manufacture_order_items',
				array(
					'mo_id'        => $mo_id,
					'component_id' => $item['component_id'],
					'qty_per_unit' => $item['qty_per_unit'],
					'qty_total'    => $item['qty_total'],
					'unit_cost'    => $item['unit_cost'],
				),
				array( '%d', '%d', '%f', '%f', '%f' )
			);
		}
	}

	/**
	 * Marks a manufacture order completed.
	 *
	 * @param int $mo_id The manufacture order's ID.
	 */
	public function mark_completed( int $mo_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wcbom_manufacture_orders',
			array(
				'status'       => ManufactureOrder::STATUS_COMPLETED,
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'mo_id' => $mo_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Updates the reversed quantity/status after a (partial) reversal.
	 *
	 * @param int    $mo_id            The manufacture order's ID.
	 * @param int    $new_qty_reversed The new total units reversed.
	 * @param string $new_status       One of ManufactureOrder::STATUS_*.
	 */
	public function update_reversal( int $mo_id, int $new_qty_reversed, string $new_status ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wcbom_manufacture_orders',
			array(
				'qty_reversed' => $new_qty_reversed,
				'status'       => $new_status,
			),
			array( 'mo_id' => $mo_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Deletes a draft manufacture order outright — safe because a draft
	 * never moved any stock. Refuses (returns false) for any other status.
	 *
	 * @param int $mo_id The manufacture order's ID.
	 */
	public function delete_draft( int $mo_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'wcbom_manufacture_orders',
			array(
				'mo_id'  => $mo_id,
				'status' => ManufactureOrder::STATUS_DRAFT,
			),
			array( '%d', '%s' )
		);

		return $deleted > 0;
	}

	/**
	 * Builds a ManufactureOrder (header + snapshot items) from a raw row.
	 *
	 * @param array<string,mixed> $row A wcbom_manufacture_orders row (ARRAY_A).
	 */
	private function hydrate( array $row ): ManufactureOrder {
		return new ManufactureOrder(
			(int) $row['mo_id'],
			(int) $row['product_id'],
			(int) $row['bom_id'],
			(int) $row['qty_built'],
			(int) $row['qty_reversed'],
			(string) $row['status'],
			$row['notes'],
			(int) $row['created_by'],
			(string) $row['created_at'],
			$row['completed_at'],
			$this->load_items( (int) $row['mo_id'] )
		);
	}

	/**
	 * Loads a manufacture order's consumption snapshot lines.
	 *
	 * @param int $mo_id The manufacture order's ID.
	 * @return array<int,ManufactureOrderItem>
	 */
	private function load_items( int $mo_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_manufacture_order_items WHERE mo_id = %d ORDER BY moi_id ASC", $mo_id ),
			ARRAY_A
		);

		return array_map( array( ManufactureOrderItem::class, 'from_row' ), $rows );
	}
}
