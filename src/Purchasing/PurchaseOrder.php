<?php
/**
 * A purchase order placed with a vendor for one or more components.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * States (BUILD_PLAN.md §5.13): draft (editable, counts toward nothing) →
 * ordered (lines lock, counts toward on-order) → partially_received /
 * received (per-line receiving, cumulative) — or cancelled from draft or
 * ordered (including a partially-received PO: already-received stock
 * stays received, the remainder simply stops being expected).
 */
final class PurchaseOrder {

	public const STATUS_DRAFT              = 'draft';
	public const STATUS_ORDERED            = 'ordered';
	public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
	public const STATUS_RECEIVED           = 'received';
	public const STATUS_CANCELLED          = 'cancelled';

	/**
	 * Constructs an immutable purchase order snapshot.
	 *
	 * @param int                          $po_id         0 for a not-yet-persisted PO.
	 * @param int                          $vendor_id     The vendor this was ordered from.
	 * @param string                       $status        One of the STATUS_* constants.
	 * @param string|null                  $reference     The vendor's own order/invoice number.
	 * @param string|null                  $expected_date Expected delivery date (Y-m-d), if known.
	 * @param string|null                  $notes         Free-text notes.
	 * @param int                          $created_by    User who created the PO.
	 * @param string                       $created_at    MySQL datetime, UTC.
	 * @param string|null                  $ordered_at    MySQL datetime, UTC, once placed.
	 * @param string|null                  $closed_at     MySQL datetime, UTC, once received-in-full or cancelled.
	 * @param array<int,PurchaseOrderItem> $items         Line items.
	 */
	public function __construct(
		public readonly int $po_id,
		public readonly int $vendor_id,
		public readonly string $status,
		public readonly ?string $reference,
		public readonly ?string $expected_date,
		public readonly ?string $notes,
		public readonly int $created_by,
		public readonly string $created_at,
		public readonly ?string $ordered_at,
		public readonly ?string $closed_at,
		public readonly array $items
	) {}

	/**
	 * Whether every line is fully received (qty_received >= qty_ordered).
	 */
	public function fully_received(): bool {
		foreach ( $this->items as $item ) {
			if ( $item->qty_received < $item->qty_ordered ) {
				return false;
			}
		}

		return array() !== $this->items;
	}
}
