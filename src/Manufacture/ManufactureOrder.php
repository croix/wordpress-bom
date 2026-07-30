<?php
/**
 * A manufacture order: batch-converts components into a finished good.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Manufacture;

defined( 'ABSPATH' ) || exit;

/**
 * States: draft (planned, nothing moved) → completed (components
 * consumed, finished stock incremented, snapshot written) →
 * partially_reversed/reversed. See BUILD_PLAN.md §5.4/§13.6.
 */
final class ManufactureOrder {

	public const STATUS_DRAFT              = 'draft';
	public const STATUS_COMPLETED          = 'completed';
	public const STATUS_PARTIALLY_REVERSED = 'partially_reversed';
	public const STATUS_REVERSED           = 'reversed';

	/**
	 * Constructs an immutable manufacture order snapshot.
	 *
	 * @param int                             $mo_id        0 for a not-yet-persisted MO.
	 * @param int                             $product_id   The finished good being built.
	 * @param int                             $bom_id       The recipe used (a specific BOM version).
	 * @param int                             $qty_built    Units planned/built.
	 * @param int                             $qty_reversed Units disassembled back so far.
	 * @param string                          $status       One of the STATUS_* constants.
	 * @param string|null                     $notes        Free-text notes.
	 * @param int                             $created_by   User who created the MO.
	 * @param string                          $created_at   MySQL datetime, UTC.
	 * @param string|null                     $completed_at MySQL datetime, UTC, once completed.
	 * @param array<int,ManufactureOrderItem> $items    Consumption snapshot (empty until completed).
	 */
	public function __construct(
		public readonly int $mo_id,
		public readonly int $product_id,
		public readonly int $bom_id,
		public readonly int $qty_built,
		public readonly int $qty_reversed,
		public readonly string $status,
		public readonly ?string $notes,
		public readonly int $created_by,
		public readonly string $created_at,
		public readonly ?string $completed_at,
		public readonly array $items
	) {}

	/**
	 * Units still available to reverse.
	 */
	public function remaining_units(): int {
		return $this->qty_built - $this->qty_reversed;
	}
}
