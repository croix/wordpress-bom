<?php
/**
 * Resolves which BOM lines apply to a specific order item.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Filters a BOM's lines down to the ones a given order item actually
 * consumes: "always" lines pass unconditionally; "attribute" lines match
 * the item's variation attributes; "addon" lines match values supplied by
 * integrations via the wcbom_order_item_addon_values filter.
 */
final class ConditionMatcher {

	/**
	 * The BOM lines applicable to this order item.
	 *
	 * @param Bom                   $bom  The (already resolved) BOM to filter.
	 * @param WC_Order_Item_Product $item The order line item being consumed.
	 * @return array<int,BomItem>
	 */
	public function resolve( Bom $bom, WC_Order_Item_Product $item ): array {
		$attributes = $this->item_attributes( $item );

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
