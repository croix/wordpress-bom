<?php
/**
 * Persistence for purchase orders and their line items.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wcbom_purchase_orders/wcbom_po_items. Status
 * transitions are applied here as simple column updates; the state-machine
 * rules (what transitions are legal, when) live in PurchaseOrderService,
 * the only caller that should mutate status — the same split
 * ManufactureRepository/ManufactureService already use.
 */
final class PurchaseOrderRepository {

	/**
	 * Creates a draft purchase order with its line items. Nothing else
	 * happens at this point — no stock moves, nothing counts toward
	 * on-order, until it's placed.
	 *
	 * @param int                                                                       $vendor_id     The vendor to order from.
	 * @param array<int,array{component_id:int,qty_ordered:float,unit_cost:float|null}> $items Line items.
	 * @param string|null                                                               $reference     The vendor's own order/invoice number.
	 * @param string|null                                                               $expected_date Expected delivery date (Y-m-d).
	 * @param string|null                                                               $notes         Free-text notes.
	 * @param int                                                                       $user_id       Creating user.
	 */
	public function create_draft( int $vendor_id, array $items, ?string $reference, ?string $expected_date, ?string $notes, int $user_id ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcbom_purchase_orders',
			array(
				'vendor_id'     => $vendor_id,
				'status'        => PurchaseOrder::STATUS_DRAFT,
				'reference'     => $reference,
				'expected_date' => $expected_date,
				'notes'         => $notes,
				'created_by'    => $user_id,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$po_id = (int) $wpdb->insert_id;
		$this->save_items( $po_id, $items );

		return $po_id;
	}

	/**
	 * Replaces a draft PO's line items wholesale — the draft-editing path;
	 * only ever called while the PO is still a draft (PurchaseOrderService
	 * enforces this), since once ordered, lines lock per §5.13.
	 *
	 * @param int                                                                       $po_id The purchase order's ID.
	 * @param array<int,array{component_id:int,qty_ordered:float,unit_cost:float|null}> $items Line items.
	 */
	public function save_items( int $po_id, array $items ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'wcbom_po_items', array( 'po_id' => $po_id ), array( '%d' ) );

		foreach ( $items as $item ) {
			$wpdb->insert(
				$wpdb->prefix . 'wcbom_po_items',
				array(
					'po_id'        => $po_id,
					'component_id' => $item['component_id'],
					'qty_ordered'  => $item['qty_ordered'],
					'qty_received' => 0,
					'unit_cost'    => $item['unit_cost'],
				),
				array( '%d', '%d', '%f', '%f', '%f' )
			);
		}
	}

	/**
	 * Updates the header fields of a still-draft PO (vendor, reference,
	 * expected date, notes) — called alongside save_items() when editing.
	 *
	 * @param int         $po_id         The purchase order's ID.
	 * @param int         $vendor_id     The vendor to order from.
	 * @param string|null $reference     The vendor's own order/invoice number.
	 * @param string|null $expected_date Expected delivery date (Y-m-d).
	 * @param string|null $notes         Free-text notes.
	 */
	public function update_draft( int $po_id, int $vendor_id, ?string $reference, ?string $expected_date, ?string $notes ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wcbom_purchase_orders',
			array(
				'vendor_id'     => $vendor_id,
				'reference'     => $reference,
				'expected_date' => $expected_date,
				'notes'         => $notes,
			),
			array( 'po_id' => $po_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * A purchase order by ID, with its line items.
	 *
	 * @param int $po_id The purchase order's ID.
	 */
	public function get( int $po_id ): ?PurchaseOrder {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_purchase_orders WHERE po_id = %d", $po_id ),
			ARRAY_A
		);

		return null !== $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lists purchase orders, newest first.
	 *
	 * @param string|null $status    Filter to one status, or null for all.
	 * @param int|null    $vendor_id Filter to one vendor, or null for all.
	 * @return array<int,PurchaseOrder>
	 */
	public function list( ?string $status = null, ?int $vendor_id = null ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( null !== $vendor_id ) {
			$where[]  = 'vendor_id = %d';
			$params[] = $vendor_id;
		}

		$sql = "SELECT * FROM {$wpdb->prefix}wcbom_purchase_orders WHERE " . implode( ' AND ', $where ) . ' ORDER BY po_id DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare() when $params is non-empty.
		$rows = array() !== $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Marks a draft PO as placed with the vendor.
	 *
	 * @param int $po_id The purchase order's ID.
	 */
	public function mark_ordered( int $po_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wcbom_purchase_orders',
			array(
				'status'     => PurchaseOrder::STATUS_ORDERED,
				'ordered_at' => current_time( 'mysql', true ),
			),
			array( 'po_id' => $po_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Records a receipt against one line: adds to its cumulative
	 * qty_received. Over-receipt is allowed by design — see PurchaseOrderItem.
	 *
	 * @param int   $poi_id      The line item's ID.
	 * @param float $qty_this_receipt Quantity received in this receipt (added to the running total).
	 */
	public function add_received( int $poi_id, float $qty_this_receipt ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wcbom_po_items SET qty_received = qty_received + %f WHERE poi_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$qty_this_receipt,
				$poi_id
			)
		);
	}

	/**
	 * Updates the PO's own status after a receipt or cancellation.
	 *
	 * @param int         $po_id     The purchase order's ID.
	 * @param string      $status    One of PurchaseOrder::STATUS_*.
	 * @param string|null $closed_at MySQL datetime, UTC, when transitioning to a closed status; null to leave unset/unchanged.
	 */
	public function update_status( int $po_id, string $status, ?string $closed_at = null ): void {
		global $wpdb;

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( null !== $closed_at ) {
			$data['closed_at'] = $closed_at;
			$format[]          = '%s';
		}

		$wpdb->update( $wpdb->prefix . 'wcbom_purchase_orders', $data, array( 'po_id' => $po_id ), $format, array( '%d' ) );
	}

	/**
	 * Deletes a draft PO outright (its items cascade via a direct delete
	 * first) — safe because a draft never moved any stock or counted
	 * toward on-order. Refuses (returns false) for any other status.
	 *
	 * @param int $po_id The purchase order's ID.
	 */
	public function delete_draft( int $po_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'wcbom_purchase_orders',
			array(
				'po_id'  => $po_id,
				'status' => PurchaseOrder::STATUS_DRAFT,
			),
			array( '%d', '%s' )
		);

		if ( $deleted > 0 ) {
			$wpdb->delete( $wpdb->prefix . 'wcbom_po_items', array( 'po_id' => $po_id ), array( '%d' ) );
		}

		return $deleted > 0;
	}

	/**
	 * On-order quantity + nearest expected date per component, across every
	 * PO currently in 'ordered' or 'partially_received' status — the input
	 * Reports\LowStockReport/LowStockDigest and the Inventory screen surface
	 * when VendorsFeature is enabled. Bulk-computed in one query rather
	 * than per-component, so 200 components on the Inventory screen costs
	 * one query, not 200 (BUILD_PLAN.md §7.5).
	 *
	 * @return array<int,array{qty:float,expected_date:string|null}> component_id => on-order info.
	 */
	public function on_order_by_component(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input.
		$rows = $wpdb->get_results(
			"SELECT poi.component_id,
			        SUM(poi.qty_ordered - poi.qty_received) AS qty_outstanding,
			        MIN(po.expected_date) AS nearest_expected
			 FROM {$wpdb->prefix}wcbom_po_items poi
			 INNER JOIN {$wpdb->prefix}wcbom_purchase_orders po ON po.po_id = poi.po_id
			 WHERE po.status IN ('ordered','partially_received')
			 GROUP BY poi.component_id",
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$outstanding = (float) $row['qty_outstanding'];
			if ( $outstanding <= 0 ) {
				continue; // Every line on outstanding POs for this component is already fully received.
			}

			$result[ (int) $row['component_id'] ] = array(
				'qty'           => $outstanding,
				'expected_date' => $row['nearest_expected'],
			);
		}

		return $result;
	}

	/**
	 * PO line items referencing a component product/variation that no
	 * longer exists — the `wp wcbom audit` drift check (BUILD_PLAN.md
	 * §5.13), the same class of finding as the orphaned-BOM check queued
	 * in the Phase 6 Progress Log.
	 *
	 * @return array<int,array{poi_id:int,po_id:int,component_id:int}>
	 */
	public function orphaned_items(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input.
		$rows = $wpdb->get_results(
			"SELECT poi.poi_id, poi.po_id, poi.component_id
			 FROM {$wpdb->prefix}wcbom_po_items poi
			 LEFT JOIN {$wpdb->posts} p ON p.ID = poi.component_id
			 WHERE p.ID IS NULL",
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'poi_id'       => (int) $row['poi_id'],
				'po_id'        => (int) $row['po_id'],
				'component_id' => (int) $row['component_id'],
			),
			$rows
		);
	}

	/**
	 * Builds a PurchaseOrder (header + items) from a raw row.
	 *
	 * @param array<string,mixed> $row A wcbom_purchase_orders row (ARRAY_A).
	 */
	private function hydrate( array $row ): PurchaseOrder {
		return new PurchaseOrder(
			(int) $row['po_id'],
			(int) $row['vendor_id'],
			(string) $row['status'],
			$row['reference'],
			$row['expected_date'],
			$row['notes'],
			(int) $row['created_by'],
			(string) $row['created_at'],
			$row['ordered_at'],
			$row['closed_at'],
			$this->load_items( (int) $row['po_id'] )
		);
	}

	/**
	 * Loads a purchase order's line items.
	 *
	 * @param int $po_id The purchase order's ID.
	 * @return array<int,PurchaseOrderItem>
	 */
	private function load_items( int $po_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_po_items WHERE po_id = %d ORDER BY poi_id ASC", $po_id ),
			ARRAY_A
		);

		return array_map( array( PurchaseOrderItem::class, 'from_row' ), $rows );
	}
}
