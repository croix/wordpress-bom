<?php
/**
 * Shared "latest completed build's snapshot cost" calculation for a
 * MANUFACTURED product.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WCBOM\Manufacture\ManufactureRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Extracted 2026-08-16 (Phase 11) from Integrations\CogsProvider — by then
 * duplicated a second time in Admin\ProductBomMetabox's cost hint, and
 * about to be needed a third time by Orders\OrderCostSnapshot. Same
 * reasoning as Reports\BomCost's own extraction: this number must never be
 * computed two different ways, or the plugin's own screens/reports and
 * whichever record actually gets frozen at sale time could silently
 * disagree about the same product's cost.
 *
 * Σ(qty_per_unit × unit_cost) over the latest completed-or-partially-
 * reversed manufacture order's consumption snapshot — what a unit
 * currently on the shelf actually cost to build, not what it would cost
 * today. Null when the product has never been built (callers fall back to
 * live BOM cost in that case).
 */
final class ManufacturedCost {

	/**
	 * Constructs the calculation.
	 *
	 * @param ManufactureRepository $mo_orders Latest completed MO lookup.
	 */
	public function __construct( private readonly ManufactureRepository $mo_orders ) {}

	/**
	 * The manufactured product's build-snapshot cost, or null if never built.
	 *
	 * @param int $product_id The finished good.
	 */
	public function for_product( int $product_id ): ?float {
		$mo = $this->mo_orders->latest_completed_for_product( $product_id );
		if ( null === $mo ) {
			return null;
		}

		$cost = 0.0;
		foreach ( $mo->items as $item ) {
			if ( null !== $item->unit_cost ) {
				$cost += $item->qty_per_unit * $item->unit_cost;
			}
		}

		return $cost;
	}
}
