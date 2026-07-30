<?php
/**
 * A manufacture order's consumption snapshot line.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Manufacture;

defined( 'ABSPATH' ) || exit;

/**
 * One component's consumption record for a completed manufacture order —
 * written once, at completion, from the BOM that was actually used.
 * Reversal reads only this snapshot, never the current BOM (identical
 * reasoning to Orders\OrderSync's _wcbom_consumed: a BOM edited after the
 * build must never corrupt the reversal).
 */
final class ManufactureOrderItem {

	/**
	 * Constructs an immutable snapshot line.
	 *
	 * @param int        $moi_id       0 for a not-yet-persisted line.
	 * @param int        $mo_id        The owning manufacture order's ID.
	 * @param int        $component_id Component product/variation ID.
	 * @param float      $qty_per_unit Quantity consumed per finished unit.
	 * @param float      $qty_total    Total quantity consumed (qty_per_unit × qty_built).
	 * @param float|null $unit_cost    Component cost at build time, for COGS.
	 */
	public function __construct(
		public readonly int $moi_id,
		public readonly int $mo_id,
		public readonly int $component_id,
		public readonly float $qty_per_unit,
		public readonly float $qty_total,
		public readonly ?float $unit_cost
	) {}

	/**
	 * Builds a ManufactureOrderItem from a raw database row.
	 *
	 * @param array<string,mixed> $row A wcbom_manufacture_order_items row (ARRAY_A).
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['moi_id'],
			(int) $row['mo_id'],
			(int) $row['component_id'],
			(float) $row['qty_per_unit'],
			(float) $row['qty_total'],
			null !== $row['unit_cost'] ? (float) $row['unit_cost'] : null
		);
	}
}
