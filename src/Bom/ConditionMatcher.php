<?php
/**
 * Resolves which BOM lines apply to a specific order item or cart selection.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Filters a BOM's lines down to the ones that actually apply: "always"
 * lines pass unconditionally; "attribute" lines match a taxonomy => term
 * map; "addon" lines match a sanitized field-key => value map. Two entry
 * points share the same matching logic: resolve() for an already-placed
 * order item (Orders\OrderSync), and resolve_for_selection() for a
 * variation the customer is about to add to cart (Stock\StorefrontStock) —
 * the latter has no order item yet, so it takes attributes directly.
 */
final class ConditionMatcher {

	/**
	 * The BOM lines an order item actually consumes.
	 *
	 * @param Bom                   $bom  The (already resolved) BOM to filter.
	 * @param WC_Order_Item_Product $item The order line item being consumed.
	 * @return array<int,BomItem>
	 */
	public function resolve( Bom $bom, WC_Order_Item_Product $item ): array {
		/**
		 * Add-on/option values chosen for this order item, as a
		 * sanitized key => value map. Integrations (e.g. ThemeHigh EPO)
		 * hook this to expose their field data to addon-conditional
		 * BOM lines.
		 *
		 * @param array<string,string>  $values Sanitized key => raw value map.
		 * @param WC_Order_Item_Product $item   The order line item.
		 */
		$addons = apply_filters( 'wcbom_order_item_addon_values', array(), $item );

		return $this->resolve_lines( $bom, $this->item_attributes( $item ), $addons );
	}

	/**
	 * The BOM lines that would apply to a given variation attribute
	 * selection — used at add-to-cart time, before an order item exists,
	 * and at cart-display time (Cart\CartPricing) for BOM-derived weight
	 * and surcharge pricing. Addon-conditional lines only match here if
	 * the caller can supply live addon values (Cart\CartPricing does, via
	 * wcbom_cart_item_addon_values; Stock\StorefrontStock's add-to-cart
	 * check does not — see BUILD_PLAN.md §12 risk 6 — so it omits $addons
	 * and those lines simply don't match there).
	 *
	 * @param Bom                  $bom        The (already resolved) BOM to filter.
	 * @param array<string,string> $attributes Taxonomy => term slug/name.
	 * @param array<string,string> $addons     Sanitized field key => value, if available.
	 * @return array<int,BomItem>
	 */
	public function resolve_for_selection( Bom $bom, array $attributes, array $addons = array() ): array {
		return $this->resolve_lines( $bom, $attributes, $addons );
	}

	/**
	 * The shared condition-matching switch both entry points use.
	 *
	 * @param Bom                  $bom        The BOM to filter.
	 * @param array<string,string> $attributes Taxonomy => term slug/name.
	 * @param array<string,string> $addons     Sanitized field key => value.
	 * @return array<int,BomItem>
	 */
	private function resolve_lines( Bom $bom, array $attributes, array $addons ): array {
		return array_values(
			array_filter(
				$bom->items,
				function ( BomItem $line ) use ( $attributes, $addons ): bool {
					switch ( $line->condition_type ) {
						case BomItem::CONDITION_ALWAYS:
							return true;

						case BomItem::CONDITION_ATTRIBUTE:
							return null !== $line->condition_key
								&& isset( $attributes[ $line->condition_key ] )
								&& sanitize_title( $attributes[ $line->condition_key ] ) === $line->condition_value;

						case BomItem::CONDITION_ADDON:
							return null !== $line->condition_key
								&& isset( $addons[ $line->condition_key ] )
								&& sanitize_title( (string) $addons[ $line->condition_key ] ) === $line->condition_value;

						default:
							return false;
					}
				}
			)
		);
	}

	/**
	 * The item's variation attributes as taxonomy => term-slug.
	 *
	 * @param WC_Order_Item_Product $item The order line item.
	 * @return array<string,string>
	 */
	private function item_attributes( WC_Order_Item_Product $item ): array {
		$product = $item->get_product();

		if ( $product && $product->is_type( 'variation' ) ) {
			$attributes = array();
			foreach ( $product->get_attributes() as $taxonomy => $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$attributes[ (string) $taxonomy ] = $value;
				}
			}

			return $attributes;
		}

		// Non-variation fallback: variation attributes chosen at add-to-cart
		// are stored as item meta keyed by taxonomy (pa_*).
		$attributes = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) $data['key'];
			if ( str_starts_with( $key, 'pa_' ) && is_string( $data['value'] ) ) {
				$attributes[ $key ] = $data['value'];
			}
		}

		return $attributes;
	}
}
