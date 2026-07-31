<?php
/**
 * Amortizes a purchase order's freight/tax/fees across its line items.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * Display-only (decided 2026-07-30): computed live from the PO's current
 * freight_cost/tax_cost/fees_cost fields, never written to a product field
 * or fed into Reports\BomCost/Integrations\CogsProvider — same reasoning
 * as PurchaseOrderItem::$unit_cost being a historical record only. Kept as
 * its own class (rather than inline in Rest\PurchasingApi) so the
 * allocation math is unit-testable without a REST request.
 *
 * Allocation is proportional to each line's ordered value (qty_ordered ×
 * unit_cost) — the standard landed-cost basis. A line with no unit_cost
 * entered has no value to allocate by, so it gets none of the fee total
 * and a null landed_unit_cost; the total_fees the caller displays is the
 * true total regardless, so a merchant sees at a glance when a line
 * couldn't participate (its landed cost column reads "—") and knows to
 * enter a unit cost on it for a complete breakdown.
 */
final class LandedCost {

	/**
	 * Computes the amortized fee and landed unit cost for every line of a PO.
	 *
	 * @param PurchaseOrder $po The purchase order.
	 * @return array{total_fees:float,items:array<int,array{amortized_fee:float,landed_unit_cost:float|null}>} Keyed by poi_id.
	 */
	public function for_order( PurchaseOrder $po ): array {
		$total_fees = ( $po->freight_cost ?? 0.0 ) + ( $po->tax_cost ?? 0.0 ) + ( $po->fees_cost ?? 0.0 );

		$total_value = 0.0;
		foreach ( $po->items as $item ) {
			if ( null !== $item->unit_cost && $item->qty_ordered > 0 ) {
				$total_value += $item->qty_ordered * $item->unit_cost;
			}
		}

		$items = array();
		foreach ( $po->items as $item ) {
			$can_allocate = $total_fees > 0 && $total_value > 0 && null !== $item->unit_cost && $item->qty_ordered > 0;

			if ( ! $can_allocate ) {
				$items[ $item->poi_id ] = array(
					'amortized_fee'    => 0.0,
					'landed_unit_cost' => null !== $item->unit_cost ? $item->unit_cost : null,
				);
				continue;
			}

			$line_value    = $item->qty_ordered * $item->unit_cost;
			$amortized_fee = $total_fees * ( $line_value / $total_value );
			$landed_unit   = $item->unit_cost + ( $amortized_fee / $item->qty_ordered );

			$items[ $item->poi_id ] = array(
				'amortized_fee'    => round( $amortized_fee, 4 ),
				'landed_unit_cost' => round( $landed_unit, 4 ),
			);
		}

		return array(
			'total_fees' => $total_fees,
			'items'      => $items,
		);
	}
}
