<?php
/**
 * Persistence for BOMs and their lines.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wcbom_boms/wcbom_bom_items. Saving always creates a new
 * version and deactivates the previous one — BOMs are never edited in
 * place, so manufacture-order/order-item snapshots taken against an older
 * version stay meaningful forever.
 */
final class BomRepository {

	/**
	 * The currently-active BOM for a product, or null if it has none.
	 *
	 * @param int $product_id Product/variation ID.
	 */
	public function get_active_for_product( int $product_id ): ?Bom {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcbom_boms WHERE product_id = %d AND is_active = 1 ORDER BY version DESC LIMIT 1",
				$product_id
			),
			ARRAY_A
		);

		return null !== $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * A specific BOM version by ID, regardless of active status — used when
	 * resolving a snapshot reference (order-item/manufacture-order) rather
	 * than "what applies right now".
	 *
	 * @param int $bom_id The BOM version's ID.
	 */
	public function get( int $bom_id ): ?Bom {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_boms WHERE bom_id = %d", $bom_id ),
			ARRAY_A
		);

		return null !== $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Saves a new BOM version for a product: deactivates any existing
	 * active version, inserts the new header + lines in one transaction.
	 *
	 * @param int                                                                                                              $product_id Product/variation this BOM applies to.
	 * @param array<int,array{component_id:int,qty:float,condition_type:string,condition_key:?string,condition_value:?string}> $items Ordered lines to save.
	 * @param int                                                                                                              $user_id User performing the save.
	 *
	 * @throws \Throwable Re-thrown after rolling back the transaction.
	 */
	public function save( int $product_id, array $items, int $user_id ): Bom {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}wcbom_boms SET is_active = 0 WHERE product_id = %d AND is_active = 1",
					$product_id
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
			$max_version = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(version) FROM {$wpdb->prefix}wcbom_boms WHERE product_id = %d",
					$product_id
				)
			);
			$version     = null !== $max_version ? ( (int) $max_version + 1 ) : 1;
			$created_at  = current_time( 'mysql', true );

			$wpdb->insert(
				$wpdb->prefix . 'wcbom_boms',
				array(
					'product_id' => $product_id,
					'version'    => $version,
					'is_active'  => 1,
					'created_at' => $created_at,
					'created_by' => $user_id,
				),
				array( '%d', '%d', '%d', '%s', '%d' )
			);
			$bom_id = (int) $wpdb->insert_id;

			$bom_items = array();
			foreach ( array_values( $items ) as $sort_order => $item ) {
				$wpdb->insert(
					$wpdb->prefix . 'wcbom_bom_items',
					array(
						'bom_id'          => $bom_id,
						'component_id'    => $item['component_id'],
						'qty'             => $item['qty'],
						'condition_type'  => $item['condition_type'],
						'condition_key'   => $item['condition_key'],
						'condition_value' => $item['condition_value'],
						'sort_order'      => $sort_order,
					),
					array( '%d', '%d', '%f', '%s', '%s', '%s', '%d' )
				);

				$bom_items[] = new BomItem(
					(int) $wpdb->insert_id,
					(int) $item['component_id'],
					(float) $item['qty'],
					(string) $item['condition_type'],
					$item['condition_key'],
					$item['condition_value'],
					$sort_order
				);
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		/**
		 * Fires after a new BOM version is committed. Stock\PhantomStock
		 * hooks this to invalidate the product's buildable-qty cache — the
		 * recipe itself just changed, not just component stock.
		 *
		 * @param int $product_id The product/variation whose BOM was saved.
		 */
		do_action( 'wcbom_bom_saved', $product_id );

		return new Bom( $bom_id, $product_id, $version, true, $created_at, $user_id, $bom_items );
	}

	/**
	 * Whether a product is used as a component anywhere in any currently
	 * active BOM — used to block deleting/trashing it out from under a
	 * recipe. Ignores inactive (superseded) BOM versions on purpose.
	 *
	 * @param int $component_id Product/variation ID to check.
	 */
	public function is_component_in_use( int $component_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wcbom_bom_items bi
				 INNER JOIN {$wpdb->prefix}wcbom_boms b ON b.bom_id = bi.bom_id
				 WHERE bi.component_id = %d AND b.is_active = 1",
				$component_id
			)
		);

		return ( (int) $count ) > 0;
	}

	/**
	 * Products whose currently-active BOM references this component —
	 * the "used in N products" reverse view on a component's edit screen.
	 *
	 * @param int $component_id Product/variation ID to look up.
	 * @return array<int,array{product_id:int,name:string}>
	 */
	public function used_in( int $component_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT b.product_id, p.post_title
				 FROM {$wpdb->prefix}wcbom_bom_items bi
				 INNER JOIN {$wpdb->prefix}wcbom_boms b ON b.bom_id = bi.bom_id
				 INNER JOIN {$wpdb->posts} p ON p.ID = b.product_id
				 WHERE bi.component_id = %d AND b.is_active = 1
				 ORDER BY p.post_title ASC",
				$component_id
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'product_id' => (int) $row['product_id'],
				'name'       => (string) $row['post_title'],
			),
			$rows
		);
	}

	/**
	 * Products whose currently-active BOM consumes this component via an
	 * unconditional ("always") line — the set whose cached buildable
	 * quantity depends on this component's stock. Deliberately excludes
	 * attribute/addon-conditional lines: those only gate a specific
	 * variation at add-to-cart time (Stock\StorefrontStock) and never
	 * factor into the cached headline number (BUILD_PLAN.md §5.3).
	 *
	 * @param int $component_id Product/variation ID to look up.
	 * @return array<int,int> Product IDs.
	 */
	public function products_with_always_line( int $component_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT b.product_id
				 FROM {$wpdb->prefix}wcbom_bom_items bi
				 INNER JOIN {$wpdb->prefix}wcbom_boms b ON b.bom_id = bi.bom_id
				 WHERE bi.component_id = %d AND bi.condition_type = %s AND b.is_active = 1",
				$component_id,
				BomItem::CONDITION_ALWAYS
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Builds a Bom (header + items) from a raw database row.
	 *
	 * @param array<string,mixed> $row A wcbom_boms row (as from $wpdb, ARRAY_A).
	 */
	private function hydrate( array $row ): Bom {
		return new Bom(
			(int) $row['bom_id'],
			(int) $row['product_id'],
			(int) $row['version'],
			'1' === (string) $row['is_active'],
			(string) $row['created_at'],
			(int) $row['created_by'],
			$this->load_items( (int) $row['bom_id'] )
		);
	}

	/**
	 * Loads a BOM's lines in save/display order.
	 *
	 * @param int $bom_id The BOM version's ID.
	 * @return array<int,BomItem>
	 */
	private function load_items( int $bom_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wcbom_bom_items WHERE bom_id = %d ORDER BY sort_order ASC, item_id ASC",
				$bom_id
			),
			ARRAY_A
		);

		return array_map( array( BomItem::class, 'from_row' ), $rows );
	}
}
