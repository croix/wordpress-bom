<?php
/**
 * Orchestrates purchase order placement, receiving, and cancellation.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

defined( 'ABSPATH' ) || exit;

/**
 * Draft creation/editing moves nothing (BUILD_PLAN.md §5.13, mirroring
 * Manufacture Orders' draft semantics). Placing and cancelling are pure
 * status transitions with no stock movement, so they're naturally
 * idempotent by state rather than needing an OperationGuard key.
 * Receiving *is* a stock write, so it goes through the same
 * StockService::adjust_many() + OperationGuard idempotency-key pattern as
 * ManufactureService::complete() — a double-submitted receive can never
 * double-stock.
 */
final class PurchaseOrderService {

	/**
	 * Constructs the service.
	 *
	 * @param PurchaseOrderRepository $orders PO persistence.
	 * @param StockService            $stock  The single stock-mutation path.
	 * @param OperationGuard          $guard  Idempotency-key claim/release.
	 */
	public function __construct(
		private readonly PurchaseOrderRepository $orders,
		private readonly StockService $stock,
		private readonly OperationGuard $guard
	) {}

	/**
	 * Creates a draft PO.
	 *
	 * @param int                                                                       $vendor_id     The vendor to order from.
	 * @param array<int,array{component_id:int,qty_ordered:float,unit_cost:float|null}> $items       Line items.
	 * @param string|null                                                               $reference     The vendor's own order/invoice number.
	 * @param string|null                                                               $expected_date Expected delivery date (Y-m-d).
	 * @param string|null                                                               $notes         Free-text notes.
	 *
	 * @throws \RuntimeException If no line items are given.
	 */
	public function create_draft( int $vendor_id, array $items, ?string $reference, ?string $expected_date, ?string $notes ): PurchaseOrder {
		if ( array() === $items ) {
			throw new \RuntimeException( esc_html__( 'A purchase order needs at least one line item.', 'pv-bom-stock' ) );
		}

		$po_id = $this->orders->create_draft( $vendor_id, $items, $reference, $expected_date, $notes, get_current_user_id() );

		return $this->must_get( $po_id );
	}

	/**
	 * Replaces a draft PO's header + line items. Refuses once the PO has
	 * been placed — lines lock at that point (§5.13); cancel-and-redraft
	 * is the correction path for an already-ordered PO.
	 *
	 * @param int                                                                       $po_id         The purchase order to update.
	 * @param int                                                                       $vendor_id     The vendor to order from.
	 * @param array<int,array{component_id:int,qty_ordered:float,unit_cost:float|null}> $items       Line items.
	 * @param string|null                                                               $reference     The vendor's own order/invoice number.
	 * @param string|null                                                               $expected_date Expected delivery date (Y-m-d).
	 * @param string|null                                                               $notes         Free-text notes.
	 *
	 * @throws \RuntimeException If the PO is unknown, not a draft, or no items are given.
	 */
	public function update_draft( int $po_id, int $vendor_id, array $items, ?string $reference, ?string $expected_date, ?string $notes ): PurchaseOrder {
		$po = $this->must_get( $po_id );

		if ( PurchaseOrder::STATUS_DRAFT !== $po->status ) {
			throw new \RuntimeException( esc_html__( 'Only a draft purchase order can be edited.', 'pv-bom-stock' ) );
		}
		if ( array() === $items ) {
			throw new \RuntimeException( esc_html__( 'A purchase order needs at least one line item.', 'pv-bom-stock' ) );
		}

		$this->orders->update_draft( $po_id, $vendor_id, $reference, $expected_date, $notes );
		$this->orders->save_items( $po_id, $items );

		return $this->must_get( $po_id );
	}

	/**
	 * Updates the landed-cost fields (freight/tax/fees). Deliberately has
	 * no status restriction — see PurchaseOrderRepository::update_costs().
	 *
	 * @param int        $po_id        The purchase order to update.
	 * @param float|null $freight_cost Freight/shipping paid, or null to clear.
	 * @param float|null $tax_cost     Tax paid, or null to clear.
	 * @param float|null $fees_cost    Other fees paid, or null to clear.
	 *
	 * @throws \RuntimeException If the PO is unknown.
	 */
	public function update_costs( int $po_id, ?float $freight_cost, ?float $tax_cost, ?float $fees_cost ): PurchaseOrder {
		$this->must_get( $po_id );

		$this->orders->update_costs( $po_id, $freight_cost, $tax_cost, $fees_cost );

		return $this->must_get( $po_id );
	}

	/**
	 * Places a draft PO with its vendor. Idempotent by state — a PO that's
	 * already past draft is returned as-is rather than re-placed.
	 *
	 * @param int $po_id The purchase order to place.
	 *
	 * @throws \RuntimeException If the PO is unknown or has no line items.
	 */
	public function place( int $po_id ): PurchaseOrder {
		$po = $this->must_get( $po_id );

		if ( PurchaseOrder::STATUS_DRAFT !== $po->status ) {
			return $po;
		}
		if ( array() === $po->items ) {
			throw new \RuntimeException( esc_html__( 'Cannot place a purchase order with no line items.', 'pv-bom-stock' ) );
		}

		$this->orders->mark_ordered( $po_id );

		return $this->must_get( $po_id );
	}

	/**
	 * Records a receipt against one or more lines of an ordered PO,
	 * incrementing their cumulative qty_received and the matching
	 * components' real stock in one atomic StockService call. Over-receipt
	 * is allowed (§5.13) — never blocked, never clamped, which is why
	 * STATUS_RECEIVED is itself a receivable status: a vendor can still
	 * ship (and this can still record) more after a PO nominally closed.
	 * Only STATUS_DRAFT (nothing ordered yet) and STATUS_CANCELLED (the
	 * order was called off) refuse a receipt. This also makes a
	 * retried-after-timeout duplicate request (identical op_key) land here
	 * rather than failing status validation, so the OperationGuard check
	 * below is what correctly no-ops it — not a lucky side effect.
	 *
	 * @param int              $po_id  The purchase order being received against.
	 * @param array<int,float> $receipts poi_id => quantity received in this receipt (only positive entries applied).
	 * @param string           $op_key Client-generated idempotency key.
	 *
	 * @throws \RuntimeException If the PO is unknown, not receivable from
	 *                           its current status, or a poi_id doesn't
	 *                           belong to it.
	 * @throws \Throwable Re-thrown (after releasing the op_key) from the
	 *                    underlying stock adjustment.
	 */
	public function receive( int $po_id, array $receipts, string $op_key ): PurchaseOrder {
		$po = $this->must_get( $po_id );

		if ( in_array( $po->status, array( PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED ), true ) ) {
			throw new \RuntimeException( esc_html__( 'This purchase order cannot be received against from its current status.', 'pv-bom-stock' ) );
		}

		$items_by_id = array();
		foreach ( $po->items as $item ) {
			$items_by_id[ $item->poi_id ] = $item;
		}

		$applied = array();
		foreach ( $receipts as $poi_id => $qty ) {
			$poi_id = (int) $poi_id;
			$qty    = (float) $qty;

			if ( $qty <= 0 ) {
				continue;
			}
			if ( ! isset( $items_by_id[ $poi_id ] ) ) {
				throw new \RuntimeException( esc_html__( 'One of the submitted line items does not belong to this purchase order.', 'pv-bom-stock' ) );
			}

			$applied[ $poi_id ] = $qty;
		}

		if ( array() === $applied ) {
			throw new \RuntimeException( esc_html__( 'Enter a received quantity for at least one line.', 'pv-bom-stock' ) );
		}

		if ( ! $this->guard->claim( $op_key, "Receive PO #{$po_id}" ) ) {
			return $po; // A prior identical request already applied this receipt.
		}

		try {
			$deltas = array();
			foreach ( $applied as $poi_id => $qty ) {
				$component_id            = $items_by_id[ $poi_id ]->component_id;
				$deltas[ $component_id ] = ( $deltas[ $component_id ] ?? 0.0 ) + $qty;
			}

			$this->stock->adjust_many(
				$deltas,
				Ledger::REASON_PO_RECEIVE,
				'purchase_order',
				$po_id,
				sprintf( 'Purchase Order #%d: received', $po_id ),
				true // Receiving only ever adds stock — never a shortage to guard against.
			);

			foreach ( $applied as $poi_id => $qty ) {
				$this->orders->add_received( $poi_id, $qty );
			}
		} catch ( \Throwable $e ) {
			$this->guard->release( $op_key );
			throw $e;
		}

		$updated    = $this->must_get( $po_id );
		$new_status = $updated->fully_received() ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIALLY_RECEIVED;
		$this->orders->update_status(
			$po_id,
			$new_status,
			PurchaseOrder::STATUS_RECEIVED === $new_status ? current_time( 'mysql', true ) : null
		);

		return $this->must_get( $po_id );
	}

	/**
	 * Cancels a PO — allowed from draft, ordered, or partially_received
	 * (§5.13): already-received stock is untouched either way, the
	 * remaining outstanding quantity simply stops counting toward
	 * on-order. Idempotent for an already-cancelled PO; refuses a fully
	 * received one (nothing left to cancel).
	 *
	 * Deliberately the same action for two different real-world reasons —
	 * "the order was called off before anything shipped" and "we're
	 * accepting a short delivery and closing this out" — rather than a
	 * separate status for the latter (decided 2026-07-30): both end in the
	 * same state (stops counting as on-order, received stock stands), so a
	 * second status would only duplicate this method for no behavioral
	 * difference. `Rest\PurchasingApi`/the React UI adjust the button
	 * label and confirmation copy contextually instead.
	 *
	 * @param int $po_id The purchase order to cancel.
	 *
	 * @throws \RuntimeException If the PO is unknown or already fully received.
	 */
	public function cancel( int $po_id ): PurchaseOrder {
		$po = $this->must_get( $po_id );

		if ( PurchaseOrder::STATUS_CANCELLED === $po->status ) {
			return $po;
		}
		if ( PurchaseOrder::STATUS_RECEIVED === $po->status ) {
			throw new \RuntimeException( esc_html__( 'A fully received purchase order cannot be cancelled.', 'pv-bom-stock' ) );
		}

		$this->orders->update_status( $po_id, PurchaseOrder::STATUS_CANCELLED, current_time( 'mysql', true ) );

		return $this->must_get( $po_id );
	}

	/**
	 * Deletes a draft PO outright — safe because a draft never moved any
	 * stock or counted toward on-order. Refuses for any other status.
	 *
	 * @param int $po_id The purchase order to delete.
	 */
	public function delete_draft( int $po_id ): bool {
		return $this->orders->delete_draft( $po_id );
	}

	/**
	 * Fetches a purchase order or throws.
	 *
	 * @param int $po_id The purchase order's ID.
	 *
	 * @throws \RuntimeException If no such purchase order exists.
	 */
	private function must_get( int $po_id ): PurchaseOrder {
		$po = $this->orders->get( $po_id );
		if ( null === $po ) {
			throw new \RuntimeException( esc_html__( 'Unknown purchase order.', 'pv-bom-stock' ) );
		}

		return $po;
	}
}
