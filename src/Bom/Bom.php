<?php
/**
 * A versioned bill of materials for one product.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

defined( 'ABSPATH' ) || exit;

/**
 * A BOM header plus its ordered lines. Immutable — saving changes always
 * produces a new version via BomRepository, never an in-place edit.
 */
final class Bom {

	/**
	 * Constructs an immutable BOM snapshot.
	 *
	 * @param int                $bom_id     0 for a not-yet-persisted BOM.
	 * @param int                $product_id Product/variation this BOM applies to.
	 * @param int                $version    1-based version number.
	 * @param bool               $is_active Whether this is the currently-active version.
	 * @param string             $created_at MySQL datetime, UTC.
	 * @param int                $created_by User ID who saved this version.
	 * @param array<int,BomItem> $items    Ordered lines.
	 */
	public function __construct(
		public readonly int $bom_id,
		public readonly int $product_id,
		public readonly int $version,
		public readonly bool $is_active,
		public readonly string $created_at,
		public readonly int $created_by,
		public readonly array $items
	) {}

	/**
	 * Lines that always consume, regardless of chosen options.
	 *
	 * @return array<int,BomItem>
	 */
	public function always_items(): array {
		return array_values(
			array_filter(
				$this->items,
				static fn( BomItem $item ): bool => BomItem::CONDITION_ALWAYS === $item->condition_type
			)
		);
	}
}
