<?php
/**
 * Low-stock report: components under threshold, and what they block.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WCBOM\Bom\BomRepository;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\VendorsFeature;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.5: WC's native low-stock threshold works per
 * product already (`wc_get_low_stock_amount()`, which falls back to the
 * site-wide "Low stock threshold" setting); this report is the piece WC
 * doesn't have — understanding that a short *component* blocks whichever
 * made-to-order products consume it via an always-line.
 *
 * When VendorsFeature (§5.13, Phase 9) is enabled, rows also gain an
 * `on_order`/`on_order_expected` pair — display-only, never hiding a
 * component from this list just because a PO might arrive (a low
 * component with stock on order is still genuinely low right now). Off by
 * default, and the keys are omitted entirely (not merely null) when the
 * feature is disabled, so this report's shape is identical to before
 * Phase 9 existed.
 */
final class LowStockReport {

	/**
	 * Constructs the report.
	 *
	 * @param BomRepository           $boms          Reverse "always-line" lookup for the blocks-N-products count.
	 * @param PurchaseOrderRepository $purchase_orders On-order aggregation, read only when VendorsFeature is enabled.
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly PurchaseOrderRepository $purchase_orders
	) {}

	/**
	 * Every component at or below its low-stock threshold.
	 *
	 * @return array<int,array{component_id:int,name:string,stock:float,threshold:float,blocks_products:int,on_order?:float,on_order_expected?:string|null}>
	 */
	public function generate(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wcbom_is_component',
						'value' => 'yes',
					),
				),
			)
		);

		$rows     = array();
		$on_order = VendorsFeature::enabled() ? $this->purchase_orders->on_order_by_component() : array();

		foreach ( $query->posts as $post ) {
			$component = wc_get_product( $post->ID );
			if ( ! $component || ! $component->managing_stock() ) {
				continue;
			}

			$threshold = wc_get_low_stock_amount( $component );
			$stock     = (float) $component->get_stock_quantity();

			if ( $stock > $threshold ) {
				continue;
			}

			$row = array(
				'component_id'    => $component->get_id(),
				'name'            => $component->get_name(),
				'stock'           => $stock,
				'threshold'       => (float) $threshold,
				'blocks_products' => count( $this->boms->products_with_always_line( $component->get_id() ) ),
			);

			if ( isset( $on_order[ $component->get_id() ] ) ) {
				$row['on_order']          = $on_order[ $component->get_id() ]['qty'];
				$row['on_order_expected'] = $on_order[ $component->get_id() ]['expected_date'];
			}

			$rows[] = $row;
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['blocks_products'] <=> $a['blocks_products'] );

		return $rows;
	}
}
