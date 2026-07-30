<?php
/**
 * A single BOM line.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

defined( 'ABSPATH' ) || exit;

/**
 * One component + quantity + optional consumption condition within a Bom.
 */
final class BomItem {

	public const CONDITION_ALWAYS    = 'always';
	public const CONDITION_ATTRIBUTE = 'attribute';
	public const CONDITION_ADDON     = 'addon';

	/**
	 * Constructs an immutable BOM line.
	 *
	 * @param int         $item_id         0 for a not-yet-persisted line.
	 * @param int         $component_id    Component product/variation ID.
	 * @param float       $qty             Quantity consumed per unit built/sold.
	 * @param string      $condition_type  One of the CONDITION_* constants.
	 * @param string|null $condition_key   e.g. an attribute taxonomy or add-on field name.
	 * @param string|null $condition_value e.g. a sanitized attribute term slug.
	 * @param int         $sort_order      Display/evaluation order within the BOM.
	 * @param float|null  $surcharge       Optional customer-facing price add-on when this line matches (§5.10).
	 */
	public function __construct(
		public readonly int $item_id,
		public readonly int $component_id,
		public readonly float $qty,
		public readonly string $condition_type,
		public readonly ?string $condition_key,
		public readonly ?string $condition_value,
		public readonly int $sort_order,
		public readonly ?float $surcharge = null
	) {}

	/**
	 * Builds a BomItem from a raw database row.
	 *
	 * @param array<string,mixed> $row A wcbom_bom_items row (as from $wpdb, ARRAY_A).
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['item_id'],
			(int) $row['component_id'],
			(float) $row['qty'],
			(string) $row['condition_type'],
			null !== $row['condition_key'] ? (string) $row['condition_key'] : null,
			null !== $row['condition_value'] ? (string) $row['condition_value'] : null,
			(int) $row['sort_order'],
			null !== $row['surcharge'] ? (float) $row['surcharge'] : null
		);
	}
}
