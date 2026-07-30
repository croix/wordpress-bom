<?php
/**
 * BOM-derived shipping weight and add-on surcharges at cart time.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Cart;

use WC_Product;
use WCBOM\Bom\Bom;
use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.10: for a made-to-order product whose BOM lines
 * carry real component weights, cart-item weight = Σ(component weight ×
 * qty) over the lines the current selection resolves — opt-in per product
 * via `_wcbom_weight_from_bom`, since premade/manufactured products should
 * keep their normal Shipping-tab weight field. Independently, any matched
 * line with a `surcharge` adds to the cart-item's price — always active,
 * no toggle, since a surcharge is meaningless unless a line has one set.
 *
 * Both apply on `woocommerce_add_cart_item` (item just added) and
 * `woocommerce_get_cart_item_from_session` (cart rebuilt on each page
 * load). WooCommerce reconstructs `$cart_item['data']` fresh from
 * wc_get_product() every time rather than persisting it in the session,
 * so recomputing from the product's real stored weight/price here is
 * always idempotent — never cumulative across requests.
 */
final class CartPricing {

	/**
	 * Constructs the cart-time pricing/weight computation.
	 *
	 * @param BomRepository    $boms    BOM lookup for the cart item's product.
	 * @param ConditionMatcher $matcher Resolves which lines the current selection matches.
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher
	) {}

	/**
	 * Hooks the cart-item filters.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_cart_item', array( $this, 'apply' ) );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'apply' ) );
	}

	/**
	 * Applies BOM-derived weight/surcharge to one cart item's product object.
	 *
	 * @param array<string,mixed> $cart_item A WC_Cart cart item entry.
	 * @return array<string,mixed>
	 */
	public function apply( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return $cart_item;
		}

		$bom = $this->resolve_bom( $product->get_id() );
		if ( null === $bom ) {
			return $cart_item;
		}

		/**
		 * Add-on/option values chosen for this cart item, as a sanitized
		 * key => value map — the cart-time counterpart of
		 * wcbom_order_item_addon_values. Integrations (e.g. ThemeHigh EPO)
		 * hook this to expose their field data to addon-conditional BOM
		 * lines before an order exists.
		 *
		 * @param array<string,string> $values    Sanitized key => raw value map.
		 * @param array<string,mixed>  $cart_item The cart item array.
		 */
		$addons = apply_filters( 'wcbom_cart_item_addon_values', array(), $cart_item );

		$lines = $this->matcher->resolve_for_selection( $bom, $this->cart_attributes( $cart_item ), $addons );

		$this->apply_weight( $product, $lines );
		$this->apply_surcharges( $product, $lines );

		return $cart_item;
	}

	/**
	 * The active BOM for a cart item's product, falling back to the
	 * parent's BOM for a variation without its own — same rule used
	 * throughout (PhantomStock, StorefrontStock).
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
	 * Overrides the cart item's weight with Σ(component weight × qty) over
	 * every matched line, when the product has opted in.
	 *
	 * @param WC_Product         $product The cart item's product object.
	 * @param array<int,BomItem> $lines   The lines the current selection resolves to.
	 */
	private function apply_weight( WC_Product $product, array $lines ): void {
		if ( 'yes' !== $this->weight_from_bom_enabled( $product->get_id() ) ) {
			return;
		}

		$weight = 0.0;
		foreach ( $lines as $line ) {
			if ( $line->qty <= 0 ) {
				continue;
			}

			$component = wc_get_product( $line->component_id );
			if ( ! $component ) {
				continue;
			}

			$weight += $line->qty * (float) $component->get_weight();
		}

		$product->set_weight( (string) $weight );
	}

	/**
	 * Adds every matched line's surcharge to the cart item's price.
	 *
	 * @param WC_Product         $product The cart item's product object.
	 * @param array<int,BomItem> $lines   The lines the current selection resolves to.
	 */
	private function apply_surcharges( WC_Product $product, array $lines ): void {
		$surcharge = 0.0;
		foreach ( $lines as $line ) {
			if ( null !== $line->surcharge && $line->surcharge > 0 ) {
				$surcharge += $line->surcharge;
			}
		}

		if ( $surcharge > 0 ) {
			$product->set_price( (string) ( (float) $product->get_price() + $surcharge ) );
		}
	}

	/**
	 * Whether weight-from-BOM is enabled for a product, falling back to
	 * the parent's setting for a variation without its own meta.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function weight_from_bom_enabled( int $product_id ): string {
		$value = (string) get_post_meta( $product_id, '_wcbom_weight_from_bom', true );
		if ( '' !== $value ) {
			return $value;
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );

		return $parent_id > 0 ? (string) get_post_meta( $parent_id, '_wcbom_weight_from_bom', true ) : '';
	}

	/**
	 * The cart item's chosen variation attributes as taxonomy => value,
	 * mirroring Stock\StorefrontStock::validate_add_to_cart()'s parsing of
	 * the same `attribute_*` submitted-data shape.
	 *
	 * @param array<string,mixed> $cart_item A WC_Cart cart item entry.
	 * @return array<string,string>
	 */
	private function cart_attributes( array $cart_item ): array {
		$attributes = array();

		$variation = $cart_item['variation'] ?? array();
		if ( ! is_array( $variation ) ) {
			return $attributes;
		}

		foreach ( $variation as $key => $value ) {
			if ( str_starts_with( (string) $key, 'attribute_' ) && is_string( $value ) && '' !== $value ) {
				$attributes[ substr( (string) $key, strlen( 'attribute_' ) ) ] = $value;
			}
		}

		return $attributes;
	}
}
