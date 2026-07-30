<?php
/**
 * Low-stock report: components under threshold, and what they block.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WCBOM\Bom\BomRepository;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.5: WC's native low-stock threshold works per
 * product already (`wc_get_low_stock_amount()`, which falls back to the
 * site-wide "Low stock threshold" setting); this report is the piece WC
 * doesn't have — understanding that a short *component* blocks whichever
 * made-to-order products consume it via an always-line.
 */
final class LowStockReport {

	/**
	 * Constructs the report.
	 *
	 * @param BomRepository $boms Reverse "always-line" lookup for the blocks-N-products count.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * Every component at or below its low-stock threshold.
	 *
	 * @return array<int,array{component_id:int,name:string,stock:float,threshold:float,blocks_products:int}>
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

		$rows = array();

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

			$rows[] = array(
				'component_id'    => $component->get_id(),
				'name'            => $component->get_name(),
				'stock'           => $stock,
				'threshold'       => (float) $threshold,
				'blocks_products' => count( $this->boms->products_with_always_line( $component->get_id() ) ),
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['blocks_products'] <=> $a['blocks_products'] );

		return $rows;
	}
}
