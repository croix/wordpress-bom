<?php
/**
 * Shared Σ(component price × qty) calculation over resolved BOM lines.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WCBOM\Bom\BomItem;

defined( 'ABSPATH' ) || exit;

/**
 * Extracted from MarginReport (BUILD_PLAN §5.11) so it can also back
 * Integrations\CogsProvider — the two must never compute cost differently,
 * or the plugin's own margin report and WooCommerce's Analytics would
 * silently disagree about the same product's cost.
 */
final class BomCost {

	/**
	 * Σ(component regular price × qty) over already-resolved BOM lines.
	 * Callers resolve which lines apply (ConditionMatcher::resolve() or
	 * resolve_for_selection()) before calling this — this class only sums.
	 *
	 * @param array<int,BomItem> $lines Resolved BOM lines (the subset that actually applies).
	 */
	public function for_lines( array $lines ): float {
		$cost = 0.0;

		foreach ( $lines as $line ) {
			$component = wc_get_product( $line->component_id );
			if ( $component ) {
				$cost += $line->qty * (float) $component->get_regular_price();
			}
		}

		return $cost;
	}
}
