<?php
/**
 * A purchase order line: one component, ordered and (cumulatively) received.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * Qty_received accumulates across partial receipts (BUILD_PLAN.md §5.13) —
 * over-receipt (qty_received > qty_ordered) is allowed, never blocked, since
 * refusing to record what a vendor actually shipped would be the exact
 * anti-pattern §13 exists to avoid. unit_cost is a historical record of
 * what was paid — never written to any WC product field (a dual-role
 * component's regular_price is also its live retail price; auto-pushing a
 * wholesale cost into it would corrupt storefront pricing).
 */
final class PurchaseOrderItem {

	/**
	 * Constructs an immutable PO line.
	 *
	 * @param int        $poi_id       0 for a not-yet-persisted line.
	 * @param int        $po_id        The owning purchase order's ID.
	 * @param int        $component_id Component product/variation ID.
	 * @param float      $qty_ordered  Quantity ordered from the vendor.
	 * @param float      $qty_received Cumulative quantity received so far.
	 * @param float|null $unit_cost    Per-unit price paid, if known.
	 */
	public function __construct(
		public readonly int $poi_id,
		public readonly int $po_id,
		public readonly int $component_id,
		public readonly float $qty_ordered,
		public readonly float $qty_received,
		public readonly ?float $unit_cost
	) {}

	/**
	 * Quantity still outstanding (never negative — over-receipt clamps to 0,
	 * not a negative "owed" figure).
	 */
	public function qty_outstanding(): float {
		return max( 0.0, $this->qty_ordered - $this->qty_received );
	}

	/**
	 * Builds a PurchaseOrderItem from a raw database row.
	 *
	 * @param array<string,mixed> $row A wcbom_po_items row (ARRAY_A).
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['poi_id'],
			(int) $row['po_id'],
			(int) $row['component_id'],
			(float) $row['qty_ordered'],
			(float) $row['qty_received'],
			null !== $row['unit_cost'] ? (float) $row['unit_cost'] : null
		);
	}
}
