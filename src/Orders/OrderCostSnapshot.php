<?php
/**
 * Freezes each order line item's cost at time of sale.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Orders;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WCBOM\Bom\Bom;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Bom\ProductMode;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\ManufacturedCost;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.15: the prerequisite Reports\ProfitabilityReport
 * depends on. Hooks the same `woocommerce_reduce_order_stock` event
 * Orders\OrderSync already uses (so capture timing exactly matches
 * "pending → processing/completed, whichever comes first"), but is
 * deliberately a separate class rather than folded into OrderSync — the
 * two have genuinely different scope: OrderSync only ever touches
 * MADE_TO_ORDER products, this needs to run for MADE_TO_ORDER *and*
 * MANUFACTURED, and this plugin's convention is one class per concern.
 *
 * Writes each order item's cost row exactly once, ever — the drift rule.
 * A later component-price or manufacture-order change must never restate
 * a sale that already happened; OrderItemCostRepository::record()'s
 * UNIQUE KEY enforces this at the database level, not just here.
 */
final class OrderCostSnapshot {

	/**
	 * Constructs the capture handler.
	 *
	 * @param BomRepository           $boms              BOM lookup.
	 * @param ConditionMatcher        $matcher            Resolves which BOM lines this order item actually matched.
	 * @param BomCost                 $cost              Shared Σ(component price × qty) calculation.
	 * @param ManufacturedCost        $manufactured_cost Shared MANUFACTURED build-snapshot cost calculation.
	 * @param OrderItemCostRepository $costs             Cost-snapshot persistence.
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher,
		private readonly BomCost $cost,
		private readonly ManufacturedCost $manufactured_cost,
		private readonly OrderItemCostRepository $costs
	) {}

	/**
	 * Hooks WooCommerce's stock-reduce event — the same timing OrderSync
	 * already relies on.
	 */
	public function register(): void {
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'capture_for_order' ) );
	}

	/**
	 * Captures a cost snapshot for every eligible item on the order. Never
	 * lets a capture failure propagate — this is internal reporting
	 * infrastructure, not a customer-facing consequence, so a bug here must
	 * never risk a paid checkout (BUILD_PLAN.md §13.3's same reasoning).
	 *
	 * @param WC_Order $order The order whose stock WC just reduced.
	 */
	public function capture_for_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			try {
				$this->capture_item( $order, $item );
			} catch ( \Throwable $e ) {
				wc_get_logger()->error(
					sprintf( 'Cost snapshot failed for order #%d item "%s": %s', $order->get_id(), $item->get_name(), $e->getMessage() ),
					array( 'source' => 'wcbom' )
				);
			}
		}
	}

	/**
	 * Captures one order item's cost snapshot, if eligible and not already captured.
	 *
	 * @param WC_Order              $order The order being captured for.
	 * @param WC_Order_Item_Product $item  The line item to capture.
	 */
	private function capture_item( WC_Order $order, WC_Order_Item_Product $item ): void {
		if ( null !== $this->costs->get_for_item( $item->get_id() ) ) {
			return; // Already captured — the drift rule: never recompute.
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return;
		}

		$mode = ProductMode::resolve( $product->get_id() );
		if ( ProductMode::STANDARD === $mode ) {
			// No BOM cost basis for a plain product, and this plugin was
			// never asked to invent one — not this plugin's data to
			// report on (WooCommerce's own Analytics already covers it).
			return;
		}

		[ $unit_cost, $cost_source ] = $this->resolve_cost( $product, $item, $mode );

		$this->costs->record(
			$order->get_id(),
			$item->get_id(),
			$product->get_id(),
			(float) $item->get_quantity(),
			$unit_cost,
			$cost_source
		);
	}

	/**
	 * The cost basis and its source for this specific sale, matching
	 * Integrations\CogsProvider's resolution exactly so the two can never
	 * disagree about the same product's cost.
	 *
	 * @param WC_Product            $product The sold product/variation.
	 * @param WC_Order_Item_Product $item    The order line item (gives access to the exact attributes/add-ons actually selected).
	 * @param string                $mode    The product's resolved ProductMode.
	 * @return array{0:float|null,1:string} Unit cost (or null when uncosted) and its OrderItemCost::SOURCE_* label.
	 */
	private function resolve_cost( WC_Product $product, WC_Order_Item_Product $item, string $mode ): array {
		if ( ProductMode::MANUFACTURED === $mode ) {
			$snapshot_cost = $this->manufactured_cost->for_product( $product->get_id() );
			if ( null !== $snapshot_cost ) {
				return array( $snapshot_cost, OrderItemCost::SOURCE_MO_SNAPSHOT );
			}
			// Never built yet — fall through to live BOM cost below, same
			// fallback Integrations\CogsProvider already uses.
		}

		$bom = $this->resolve_bom( $product->get_id() );
		if ( null === $bom ) {
			return array( null, OrderItemCost::SOURCE_UNCOSTED );
		}

		// Resolved against the real order item (not just variation
		// attributes, unlike CogsProvider's live_bom_cost() — that class
		// only ever has a bare product object to work from; here we
		// genuinely have the placed order item, so addon-conditional
		// lines resolve correctly too via the real
		// wcbom_order_item_addon_values providers).
		$lines = $this->matcher->resolve( $bom, $item );
		if ( array() === $lines ) {
			return array( null, OrderItemCost::SOURCE_UNCOSTED );
		}

		return array( $this->cost->for_lines( $lines ), OrderItemCost::SOURCE_BOM_LIVE );
	}

	/**
	 * The active BOM for a product/variation, falling back to the parent's
	 * BOM for a variation without its own — same rule used throughout
	 * (Orders\OrderSync, Integrations\CogsProvider, PhantomStock, etc.).
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
}
