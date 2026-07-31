<?php
/**
 * Component usage report: which BOMs use it, and its run-rate.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WCBOM\Bom\BomRepository;
use WCBOM\Stock\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.5: for one component, which active BOMs consume it,
 * how much has been consumed over the last 30/90 days, and a simple
 * run-rate days-of-stock estimate. "Consumption" means order sales and
 * manufacture-order builds — the two reasons that represent real demand,
 * as opposed to receiving/counting/manual adjustments.
 */
final class ComponentUsageReport {

	private const CONSUMPTION_REASONS = array( Ledger::REASON_ORDER, Ledger::REASON_MANUFACTURE );

	/**
	 * Constructs the report.
	 *
	 * @param BomRepository $boms   Reverse "used in" lookup.
	 * @param Ledger        $ledger Consumption sums.
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly Ledger $ledger
	) {}

	/**
	 * Usage data for every product flagged as a component — the Reports
	 * screen's "Component Usage" table. Same 200-post ceiling as
	 * Rest\InventoryApi::list_components() (an admin management screen,
	 * not a paginated frontend list); the React table paginates client-side
	 * on top of that.
	 *
	 * @return array<int,array{component_id:int,name:string,stock:float,used_in:array<int,array{product_id:int,name:string}>,consumed_30d:float,consumed_90d:float,days_of_stock:float|null}>
	 */
	public function generate(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin management screen, matching Rest\InventoryApi::list_components().
				'orderby'        => 'title',
				'order'          => 'ASC',
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
			$row = $this->for_component( (int) $post->ID );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Usage data for one component.
	 *
	 * @param int $component_id Component product/variation ID.
	 * @return array{component_id:int,name:string,stock:float,used_in:array<int,array{product_id:int,name:string}>,consumed_30d:float,consumed_90d:float,days_of_stock:float|null}|null
	 */
	public function for_component( int $component_id ): ?array {
		$component = wc_get_product( $component_id );
		if ( ! $component ) {
			return null;
		}

		$consumed_30d = $this->ledger->consumed_since( $component_id, self::CONSUMPTION_REASONS, 30 );
		$stock        = (float) $component->get_stock_quantity();

		return array(
			'component_id'  => $component_id,
			'name'          => $component->get_name(),
			'stock'         => $stock,
			'used_in'       => $this->boms->used_in( $component_id ),
			'consumed_30d'  => $consumed_30d,
			'consumed_90d'  => $this->ledger->consumed_since( $component_id, self::CONSUMPTION_REASONS, 90 ),
			'days_of_stock' => $consumed_30d > 0 ? round( $stock / ( $consumed_30d / 30 ), 1 ) : null,
		);
	}
}
