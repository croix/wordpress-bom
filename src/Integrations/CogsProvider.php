<?php
/**
 * WooCommerce native Cost of Goods Sold integration.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Integrations;

use WC_Product;
use WCBOM\Bom\Bom;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Bom\ProductMode;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\ManufacturedCost;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.11: feeds this plugin's already-correct BOM cost
 * into WooCommerce's own (opt-in, off-by-default) COGS feature by filtering
 * the *effective* value WC_Product::get_cogs_total_value() returns —
 * never writing `_cogs_value` meta. That keeps the merchant-typed "defined
 * value" field untouched and means the filtered number can never go stale,
 * unlike Stock\PhantomStock's forced postmeta mirror (that one exists only
 * because WC's Store API stock reservation reads `_stock` via raw SQL;
 * COGS has no such raw-SQL reader, so the clean filter approach works here).
 *
 * Inert unless: WC's `cost_of_goods_sold` feature is on (WooCommerce's own
 * get_cogs_total_value() already returns 0 before this filter ever runs),
 * AND the product is MADE_TO_ORDER/MANUFACTURED with a resolvable BOM, AND
 * the merchant has opted in via `_wcbom_cogs_from_bom` on that product.
 */
final class CogsProvider {

	/**
	 * Constructs the integration.
	 *
	 * @param BomRepository    $boms             BOM lookup.
	 * @param ConditionMatcher $matcher          Resolves which lines a variation's selection matches.
	 * @param BomCost          $cost             Shared Σ(component price × qty) calculation.
	 * @param ManufacturedCost $manufactured_cost Shared MANUFACTURED build-snapshot cost calculation.
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher,
		private readonly BomCost $cost,
		private readonly ManufacturedCost $manufactured_cost
	) {}

	/**
	 * Hooks the COGS effective-value filter.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_product_cogs_total_value', array( $this, 'filter_cost' ), 10, 2 );
	}

	/**
	 * Replaces WooCommerce's COGS value with this product's BOM cost, when
	 * the product is BOM-managed and has opted in. Returns $value unchanged
	 * for every other product — including a standard (non-BOM) product,
	 * per acceptance test §9.19.
	 *
	 * @param float      $value   The value WooCommerce would otherwise return (its own defined-value logic, already feature-gated by WC itself).
	 * @param WC_Product $product The product (or variation) being priced.
	 */
	public function filter_cost( float $value, WC_Product $product ): float {
		$product_id = $product->get_id();
		$mode       = ProductMode::resolve( $product_id );

		if ( ! in_array( $mode, array( ProductMode::MADE_TO_ORDER, ProductMode::MANUFACTURED ), true ) ) {
			return $value;
		}

		if ( ! $this->opted_in( $product_id ) ) {
			return $value;
		}

		$bom = $this->resolve_bom( $product_id );
		if ( null === $bom ) {
			return $value;
		}

		if ( ProductMode::MANUFACTURED === $mode ) {
			$snapshot_cost = $this->manufactured_cost->for_product( $product_id );
			if ( null !== $snapshot_cost ) {
				return $snapshot_cost;
			}
		}

		// MADE_TO_ORDER, or a MANUFACTURED product never yet built — both
		// price out from live component prices against the current BOM.
		return $this->live_bom_cost( $product, $bom );
	}

	/**
	 * Live BOM cost for a product/variation against its currently resolved
	 * lines — identical calculation to Reports\MarginReport::row(), via the
	 * shared BomCost so the two can never disagree.
	 *
	 * @param WC_Product $product The product or variation to price out.
	 * @param Bom        $bom     Its (or its parent's) active BOM.
	 */
	private function live_bom_cost( WC_Product $product, Bom $bom ): float {
		$attributes = $product->is_type( 'variation' ) ? $this->variation_attributes( $product ) : array();
		$lines      = $this->matcher->resolve_for_selection( $bom, $attributes );

		return $this->cost->for_lines( $lines );
	}

	/**
	 * The active BOM for a product/variation, falling back to the parent's
	 * BOM for a variation without its own — same rule used throughout
	 * (PhantomStock, StorefrontStock, CartPricing).
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function resolve_bom( int $product_id ): ?Bom {
		$bom = $this->boms->get_active_for_product( $product_id );
		if ( null !== $bom ) {
			return $bom;
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );

		return $parent_id > 0 ? $this->boms->get_active_for_product( $parent_id ) : null;
	}

	/**
	 * Whether this product has opted into BOM-derived COGS, falling back to
	 * the parent's setting for a variation without its own meta — same
	 * pattern as Cart\CartPricing's `_wcbom_weight_from_bom` toggle.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function opted_in( int $product_id ): bool {
		$value = get_post_meta( $product_id, '_wcbom_cogs_from_bom', true );
		if ( '' !== $value ) {
			return 'yes' === $value;
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );

		return $parent_id > 0 && 'yes' === get_post_meta( $parent_id, '_wcbom_cogs_from_bom', true );
	}

	/**
	 * A variation's attributes as taxonomy => term-slug, matching
	 * ConditionMatcher's own parsing for order items/cart selections.
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
