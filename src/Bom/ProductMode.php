<?php
/**
 * Resolves a product's effective BOM stock mode.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

defined( 'ABSPATH' ) || exit;

/**
 * Reads _wcbom_mode, falling back to the parent's when a variation has
 * none of its own. Shared by anything that needs to know whether a
 * product/variation is standard/made_to_order/manufactured — order
 * consumption (Orders\OrderSync) and storefront stock display
 * (Stock\StorefrontStock) both use this so the fallback rule can't drift
 * between them.
 */
final class ProductMode {

	public const STANDARD      = 'standard';
	public const MADE_TO_ORDER = 'made_to_order';
	public const MANUFACTURED  = 'manufactured';

	/**
	 * The product's effective stock mode.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	public static function resolve( int $product_id ): string {
		$mode = (string) get_post_meta( $product_id, '_wcbom_mode', true );
		if ( '' !== $mode ) {
			return $mode;
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			$mode = (string) get_post_meta( $parent_id, '_wcbom_mode', true );
		}

		return '' !== $mode ? $mode : self::STANDARD;
	}

	/**
	 * Top-level (non-variation) product IDs whose own `_wcbom_mode` is one
	 * of the given modes — the reporting screens' "which finished goods
	 * do I have" query (Reports\BuildableReport/MarginReport). Deliberately
	 * only looks at the product's own meta, not the parent-fallback
	 * resolve() uses: a variation never carries `_wcbom_mode` itself in
	 * practice (mode is set once on the parent), so this is the query
	 * equivalent of "products a merchant configured this mode on".
	 *
	 * @param array<int,string> $modes One or more MADE_TO_ORDER/MANUFACTURED/STANDARD values.
	 * @return array<int,int> Product IDs.
	 */
	public static function products_with_mode( array $modes ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wcbom_mode',
						'value'   => $modes,
						'compare' => 'IN',
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}
}
