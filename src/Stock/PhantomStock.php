<?php
/**
 * Buildable-quantity computation and caching for made-to-order products.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

use WC_Product;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ProductMode;

defined( 'ABSPATH' ) || exit;

/**
 * Computes "how many can I sell right now" for a made-to-order product:
 * min(floor(component_stock ÷ qty)) across its BOM's unconditional
 * ("always") lines only — attribute/addon-conditional components gate a
 * specific variation instead (Stock\StorefrontStock::validate_add_to_cart()),
 * per BUILD_PLAN.md §5.3. Cached indefinitely per product (no TTL) and
 * invalidated via two triggers: our own wcbom_stock_adjusted/wcbom_bom_saved
 * hooks (StockService/BomRepository), and WooCommerce's own
 * woocommerce_product_object_updated_props — the latter catches stock
 * edits that don't go through StockService at all, like a merchant
 * manually editing a component's stock on its own Inventory tab. Without
 * it the cache would only ever reflect ledgered changes, silently going
 * stale on the single most common real-world stock edit.
 *
 * Invalidate() also mirrors the freshly computed number into the
 * product's REAL `_stock`/`_manage_stock`/`_stock_status` postmeta, not
 * just the transient Stock\StorefrontStock's PHP-object filters expose.
 * Found via a real Blocks-checkout test (BUILD_PLAN.md §8 Phase 6 HPOS/
 * checkout matrix): WooCommerce's modern stock-reservation system
 * (`Checkout\Helpers\ReserveStock`, used by the Store API/Blocks
 * checkout to prevent overselling under concurrent checkouts) queries
 * `_stock` directly via raw SQL — entirely bypassing the PHP object/
 * filter layer — so a made-to-order product whose real postmeta was
 * never updated (only ever faked at the filter level) was rejected by
 * every Blocks checkout with "not enough stock", even with plenty of
 * buildable components. The classic shortcode checkout doesn't hit this
 * (no raw-SQL reservation step), which is why it went undetected until
 * a real browser checkout was tried. Mirroring is gated to
 * ProductMode::MADE_TO_ORDER only — products_with_always_line() also
 * matches MANUFACTURED products (their recipe is an active BOM too), and
 * those carry a real, ManufactureService-controlled stock count that
 * must never be overwritten by a phantom number.
 */
final class PhantomStock {

	private const CACHE_KEY_PREFIX = 'wcbom_buildable_';

	/**
	 * Constructs the computation.
	 *
	 * @param BomRepository $boms BOM lookup used to compute buildable qty.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * Hooks the invalidation triggers.
	 */
	public function register(): void {
		add_action( 'wcbom_stock_adjusted', array( $this, 'handle_stock_adjusted' ) );
		add_action( 'wcbom_bom_saved', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'handle_product_updated' ), 10, 2 );
	}

	/**
	 * A component's stock just changed — invalidate every made-to-order
	 * product whose active BOM depends on it via an always-line.
	 *
	 * @param int $product_id The product/variation that was adjusted.
	 */
	public function handle_stock_adjusted( int $product_id ): void {
		foreach ( $this->boms->products_with_always_line( $product_id ) as $affected_id ) {
			$this->invalidate( $affected_id );
		}
	}

	/**
	 * Catches any stock edit that goes through the standard WC_Product API
	 * without passing through StockService — chiefly a merchant manually
	 * editing a component's stock on its own product edit screen.
	 *
	 * @param WC_Product        $product       The product WooCommerce just saved.
	 * @param array<int,string> $updated_props Names of the props that changed.
	 */
	public function handle_product_updated( WC_Product $product, array $updated_props ): void {
		if ( in_array( 'stock_quantity', $updated_props, true ) ) {
			$this->handle_stock_adjusted( $product->get_id() );
		}
	}

	/**
	 * Clears one product's cached buildable quantity, and cascades to its
	 * variation children if it's a variable product. Variations fall back
	 * to the parent's BOM (compute()) but are cached under their own
	 * product ID, so a variation's cache entry — once computed — would
	 * otherwise never be refreshed by a parent-level BOM or component
	 * change; only its own direct invalidation call would catch it, and
	 * nothing makes that call today.
	 *
	 * @param int $product_id Product/variation ID.
	 */
	public function invalidate( int $product_id ): void {
		delete_transient( self::CACHE_KEY_PREFIX . $product_id );
		$this->sync_real_stock( $product_id );

		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( $product->get_children() as $variation_id ) {
				delete_transient( self::CACHE_KEY_PREFIX . $variation_id );
				$this->sync_real_stock( $variation_id );
			}
		}
	}

	/**
	 * Mirrors the computed buildable qty into real postmeta for
	 * made-to-order products/variations only — see class docblock.
	 * Writes postmeta directly (not via wc_update_product_stock()/
	 * WC_Product::save()) so this cache-mirror write doesn't fire
	 * WooCommerce's own product-updated hooks/webhooks, which are meant
	 * for real inventory changes.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function sync_real_stock( int $product_id ): void {
		if ( ProductMode::MADE_TO_ORDER !== ProductMode::resolve( $product_id ) ) {
			return;
		}

		$qty = $this->compute( $product_id );

		update_post_meta( $product_id, '_stock', $qty );
		update_post_meta( $product_id, '_manage_stock', 'yes' );
		update_post_meta( $product_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock' );
		wc_delete_product_transients( $product_id );

		// Repopulate the buildable-qty cache with the value just computed,
		// so the next get_buildable_qty() call doesn't immediately recompute
		// the same thing invalidate() just cleared.
		set_transient( self::CACHE_KEY_PREFIX . $product_id, $qty, 0 );
	}

	/**
	 * The cached (or freshly computed) buildable quantity for a product.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	public function get_buildable_qty( int $product_id ): int {
		$cached = get_transient( self::CACHE_KEY_PREFIX . $product_id );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$qty = $this->compute( $product_id );
		set_transient( self::CACHE_KEY_PREFIX . $product_id, $qty, 0 );

		return $qty;
	}

	/**
	 * The actual computation: min(floor(stock ÷ qty)) over always-lines.
	 * A variation without its own BOM falls back to its parent's.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function compute( int $product_id ): int {
		$bom = $this->boms->get_active_for_product( $product_id );

		if ( null === $bom ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 ) {
				$bom = $this->boms->get_active_for_product( $parent_id );
			}
		}

		if ( null === $bom ) {
			return 0;
		}

		$buildable = null;

		foreach ( $bom->always_items() as $line ) {
			if ( $line->qty <= 0 ) {
				continue;
			}

			$component = wc_get_product( $line->component_id );
			if ( ! $component ) {
				continue;
			}

			$possible = (int) floor( (float) $component->get_stock_quantity() / $line->qty );
			if ( null === $buildable || $possible < $buildable ) {
				$buildable = $possible;
			}
		}

		return max( 0, $buildable ?? 0 );
	}
}
