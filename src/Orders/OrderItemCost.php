<?php
/**
 * A frozen per-order-item cost snapshot, captured at time of sale.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Written once by Orders\OrderCostSnapshot and never updated afterward —
 * the drift rule (BUILD_PLAN.md §5.15): a later component-price or
 * manufacture-order change updates the cost of *future* sales, never
 * restates a sale that already happened.
 */
final class OrderItemCost {

	public const SOURCE_BOM_LIVE    = 'bom_live';
	public const SOURCE_MO_SNAPSHOT = 'mo_snapshot';
	public const SOURCE_UNCOSTED    = 'uncosted';

	/**
	 * Constructs an immutable cost snapshot.
	 *
	 * @param int        $id            0 for a not-yet-persisted row.
	 * @param int        $order_id      The order this line belongs to.
	 * @param int        $order_item_id The specific order line item.
	 * @param int        $product_id    The sold item's own ID — the variation ID for a variation sale, never the parent.
	 * @param float      $quantity      Units sold on this line at capture time.
	 * @param float|null $unit_cost     Cost basis at capture time, or null when uncosted — never 0.
	 * @param string     $cost_source   One of the SOURCE_* constants.
	 * @param string     $captured_at   MySQL datetime, UTC.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly int $order_item_id,
		public readonly int $product_id,
		public readonly float $quantity,
		public readonly ?float $unit_cost,
		public readonly string $cost_source,
		public readonly string $captured_at
	) {}

	/**
	 * Builds an OrderItemCost from a raw database row.
	 *
	 * @param array<string,mixed> $row A wcbom_order_item_costs row (ARRAY_A).
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['order_id'],
			(int) $row['order_item_id'],
			(int) $row['product_id'],
			(float) $row['quantity'],
			null !== $row['unit_cost'] ? (float) $row['unit_cost'] : null,
			(string) $row['cost_source'],
			(string) $row['captured_at']
		);
	}
}
