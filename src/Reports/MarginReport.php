<?php
/**
 * Margin report: BOM-derived cost vs. price, per finished good.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WC_Product;
use WCBOM\Bom\Bom;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Bom\ProductMode;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.5: finished-good cost = Σ(component cost × qty),
 * surfaced against price for margin visibility. Covers both made-to-order
 * (per variation, since price and the resolved BOM lines both vary by
 * attribute selection — reuses the same ConditionMatcher::resolve_for_selection()
 * the storefront/cart use, so cost reflects exactly what that combination
 * actually consumes) and manufactured products (one row, their BOM is
 * already a fixed, unconditional resolution — no variations to iterate).
 * Price includes any matched-line surcharge (Cart\CartPricing charges
 * this at cart time), not just the variation's own stored price — a
 * surcharge-priced option would otherwise show an understated margin.
 */
final class MarginReport {

	/**
	 * Constructs the report.
	 *
	 * @param BomRepository    $boms    BOM lookup.
	 * @param ConditionMatcher $matcher Resolves per-variation BOM lines.
	 * @param BomCost          $cost    Shared cost calculation (also used by Integrations\CogsProvider).
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher,
		private readonly BomCost $cost
	) {}

	/**
	 * One row per made-to-order variation, and one row per manufactured product.
	 *
	 * @return array<int,array{product_id:int,name:string,price:float,cost:float,margin:float,margin_pct:float|null}>
	 */
	public function generate(): array {
		$rows = array();

		foreach ( ProductMode::products_with_mode( array( ProductMode::MADE_TO_ORDER, ProductMode::MANUFACTURED ) ) as $product_id ) {
			$product = wc_get_product( $product_id );
			$bom     = $this->boms->get_active_for_product( $product_id );
			if ( ! $product || null === $bom ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						$rows[] = $this->row( $variation, $bom, $this->variation_attributes( $variation ) );
					}
				}
			} else {
				$rows[] = $this->row( $product, $bom, array() );
			}
		}

		return $rows;
	}

	/**
	 * Builds one report row for a product/variation.
	 *
	 * @param WC_Product           $product    The product or variation to price out.
	 * @param Bom                  $bom        Its (or its parent's) active BOM.
	 * @param array<string,string> $attributes Taxonomy => term slug, empty for non-variations.
	 * @return array{product_id:int,name:string,price:float,cost:float,margin:float,margin_pct:float|null}
	 */
	private function row( WC_Product $product, Bom $bom, array $attributes ): array {
		// With $attributes empty (simple/manufactured products), this
		// correctly degrades to always-items only — attribute-conditional
		// lines can never match an empty attribute map.
		$lines = $this->matcher->resolve_for_selection( $bom, $attributes );

		$cost      = $this->cost->for_lines( $lines );
		$surcharge = 0.0;
		foreach ( $lines as $line ) {
			if ( null !== $line->surcharge && $line->surcharge > 0 ) {
				$surcharge += $line->surcharge;
			}
		}

		// Price includes any matched-line surcharge — Cart\CartPricing
		// charges this at cart time, so leaving it out here would
		// understate real revenue for options priced that way instead of
		// through their own variation price.
		$price  = (float) $product->get_price() + $surcharge;
		$margin = $price - $cost;

		return array(
			'product_id' => $product->get_id(),
			'name'       => $product->get_name(),
			'price'      => $price,
			'cost'       => round( $cost, 4 ),
			'margin'     => round( $margin, 4 ),
			'margin_pct' => $price > 0 ? round( ( $margin / $price ) * 100, 1 ) : null,
		);
	}

	/**
	 * A variation's attributes as taxonomy => term-slug, matching
	 * ConditionMatcher's private item_attributes() parsing for order items.
	 *
	 * @param WC_Product $variation The variation product.
	 * @return array<string,string>
	 */
	private function variation_attributes( WC_Product $variation ): array {
		$attributes = array();
		foreach ( $variation->get_attributes() as $taxonomy => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$attributes[ (string) $taxonomy ] = $value;
			}
		}

		return $attributes;
	}
}
