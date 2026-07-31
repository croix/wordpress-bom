<?php
/**
 * Storefront stock display and add-to-cart enforcement for made-to-order products.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

use WC_Product;
use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Bom\ProductMode;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a made-to-order product's storefront stock display equal its
 * cached buildable quantity (BUILD_PLAN.md §5.3), and blocks add-to-cart
 * for a specific variation whose attribute-conditional components are
 * short — the part of the BOM the headline number doesn't cover.
 */
final class StorefrontStock {

	/**
	 * Constructs the storefront enforcement.
	 *
	 * @param PhantomStock     $phantom Buildable-qty computation/cache.
	 * @param BomRepository    $boms    BOM lookup for add-to-cart validation.
	 * @param ConditionMatcher $matcher Resolves conditional lines for a selection.
	 */
	public function __construct(
		private readonly PhantomStock $phantom,
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher
	) {}

	/**
	 * Hooks the storefront display filters and add-to-cart validation.
	 */
	public function register(): void {
		foreach ( array( '', '_variation' ) as $suffix ) {
			add_filter( "woocommerce_product{$suffix}_get_manage_stock", array( $this, 'filter_manage_stock' ), 10, 2 );
			add_filter( "woocommerce_product{$suffix}_get_stock_quantity", array( $this, 'filter_stock_quantity' ), 10, 2 );
			add_filter( "woocommerce_product{$suffix}_get_stock_status", array( $this, 'filter_stock_status' ), 10, 2 );
		}

		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'filter_is_in_stock' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
	}

	/**
	 * Forces manage_stock on for made-to-order products so WooCommerce's
	 * templates show a number instead of treating stock as unmanaged.
	 *
	 * @param mixed      $value   The stored prop value.
	 * @param WC_Product $product The product being read.
	 * @return mixed
	 */
	public function filter_manage_stock( $value, WC_Product $product ) {
		return $this->is_made_to_order( $product ) ? true : $value;
	}

	/**
	 * Replaces the stored stock quantity with the cached buildable qty.
	 *
	 * @param mixed      $value   The stored prop value.
	 * @param WC_Product $product The product being read.
	 * @return mixed
	 */
	public function filter_stock_quantity( $value, WC_Product $product ) {
		return $this->is_made_to_order( $product ) ? $this->phantom->get_buildable_qty( $product->get_id() ) : $value;
	}

	/**
	 * Replaces the stored stock status with one derived from buildable qty.
	 *
	 * @param mixed      $value   The stored prop value.
	 * @param WC_Product $product The product being read.
	 * @return mixed
	 */
	public function filter_stock_status( $value, WC_Product $product ) {
		if ( ! $this->is_made_to_order( $product ) ) {
			return $value;
		}

		return $this->phantom->get_buildable_qty( $product->get_id() ) > 0 ? 'instock' : 'outofstock';
	}

	/**
	 * Mirrors filter_stock_status() for the is_in_stock() boolean method,
	 * which some templates call directly instead of reading stock_status.
	 *
	 * @param bool       $value   Whether WC considers the product in stock.
	 * @param WC_Product $product The product being read.
	 */
	public function filter_is_in_stock( bool $value, WC_Product $product ): bool {
		return $this->is_made_to_order( $product ) ? $this->phantom->get_buildable_qty( $product->get_id() ) > 0 : $value;
	}

	/**
	 * Blocks adding to cart when an attribute-conditional component for the
	 * chosen variation is short — the headline buildable number only
	 * covers "always" lines, so this is the check that catches e.g. an
	 * upgraded straw being out of stock while blanks are plentiful.
	 *
	 * @param bool                 $passed       Whether validation has passed so far.
	 * @param int                  $product_id   The product being added.
	 * @param int                  $quantity     Requested quantity.
	 * @param int|string           $variation_id Variation ID, or '' for a simple product.
	 * @param array<string,string> $variation Submitted attribute_* => value pairs.
	 */
	public function validate_add_to_cart( bool $passed, int $product_id, int $quantity, $variation_id = 0, array $variation = array() ): bool {
		if ( ! $passed ) {
			return $passed;
		}

		$product = wc_get_product( (int) $variation_id > 0 ? (int) $variation_id : $product_id );
		if ( ! $product || ! $this->is_made_to_order( $product ) ) {
			return $passed;
		}

		$bom = $this->boms->get_active_for_product( $product->get_id() );
		if ( null === $bom ) {
			$parent_id = (int) wp_get_post_parent_id( $product->get_id() );
			if ( $parent_id > 0 ) {
				$bom = $this->boms->get_active_for_product( $parent_id );
			}
		}
		if ( null === $bom ) {
			return $passed;
		}

		$attributes = array();
		foreach ( $variation as $key => $value ) {
			if ( str_starts_with( $key, 'attribute_' ) && '' !== $value ) {
				$attributes[ substr( $key, strlen( 'attribute_' ) ) ] = $value;
			}
		}

		foreach ( $this->matcher->resolve_for_selection( $bom, $attributes ) as $line ) {
			// "Always" lines are already covered by the headline buildable
			// number (filter_stock_quantity()/is_in_stock()) — only the
			// conditional lines need an extra check here.
			if ( BomItem::CONDITION_ALWAYS === $line->condition_type ) {
				continue;
			}

			$component = wc_get_product( $line->component_id );
			if ( ! $component || $line->qty <= 0 ) {
				continue;
			}

			$required  = $line->qty * $quantity;
			$available = (float) $component->get_stock_quantity();

			if ( $available < $required ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: component name, 2: quantity available */
						__( 'Sorry, "%1$s" doesn\'t have enough stock for this option — only %2$s available.', 'pv-bom-stock' ),
						$component->get_name(),
						rtrim( rtrim( number_format( $available, 4 ), '0' ), '.' )
					),
					'error'
				);

				return false;
			}
		}

		return $passed;
	}

	/**
	 * Whether this product's effective BOM stock mode is made-to-order.
	 *
	 * @param WC_Product $product The product to check.
	 */
	private function is_made_to_order( WC_Product $product ): bool {
		return ProductMode::MADE_TO_ORDER === ProductMode::resolve( $product->get_id() );
	}
}
