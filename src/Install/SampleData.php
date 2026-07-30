<?php
/**
 * Sample tumbler catalog: install/remove.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Install;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Creates (and removes) the sample tumbler catalog: ten components, one
 * premade finished good, and one made-to-order variable product with a
 * starter BOM demonstrating always- and attribute-conditional lines. All
 * sample content is original to this plugin (no third-party data) and
 * every created product carries the _wcbom_fixture meta flag so removal
 * is exact. Shared by `wp wcbom seed` and the Inventory screen's
 * "Install sample products" button.
 */
final class SampleData {

	public const FIXTURE_META = '_wcbom_fixture';

	/**
	 * Whether sample products are currently present.
	 */
	public function is_installed(): bool {
		return array() !== $this->fixture_product_ids( 1 );
	}

	/**
	 * Installs the sample catalog.
	 *
	 * @return array{components:int,premade_id:int,product_id:int,bom_id:int}
	 *
	 * @throws \RuntimeException If sample products are already installed.
	 */
	public function install(): array {
		if ( $this->is_installed() ) {
			throw new \RuntimeException( esc_html__( 'Sample products are already installed.', 'wcbom' ) );
		}

		$components = $this->create_components();
		$premade_id = $this->create_premade( 'Ocean Wave 24oz Tumbler', 12 );
		$result     = $this->create_made_to_order( $components );

		return array(
			'components' => count( $components ),
			'premade_id' => $premade_id,
			'product_id' => $result['product_id'],
			'bom_id'     => $result['bom_id'],
		);
	}

	/**
	 * Permanently deletes all sample products and their BOMs.
	 *
	 * The sample BOMs are deleted BEFORE the products: Admin\DeletionGuard
	 * (rightly) blocks deleting any product referenced by an active BOM,
	 * and the sample blank tumbler is exactly that — with the sample BOMs
	 * gone first, the guard passes. If the merchant has used sample
	 * components in BOMs of their own, we refuse instead of breaking
	 * their recipes.
	 *
	 * @return int Number of products removed.
	 *
	 * @throws \RuntimeException If a sample component is used in a
	 *                           non-sample product's active BOM.
	 */
	public function remove(): int {
		global $wpdb;

		$product_ids = $this->fixture_product_ids();
		if ( array() === $product_ids ) {
			return 0;
		}

		$id_list = implode( ',', $product_ids );

		// $id_list is a comma-joined list of integers from our own query, not user input.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$foreign_users = $wpdb->get_col(
			"SELECT DISTINCT p.post_title
			 FROM {$wpdb->prefix}wcbom_bom_items bi
			 INNER JOIN {$wpdb->prefix}wcbom_boms b ON b.bom_id = bi.bom_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = b.product_id
			 WHERE b.is_active = 1
			   AND bi.component_id IN ({$id_list})
			   AND b.product_id NOT IN ({$id_list})"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( array() !== $foreign_users ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: comma-separated product names */
					esc_html__( 'Sample components are used in your own BOMs (%s). Remove them from those BOMs first.', 'wcbom' ),
					esc_html( implode( ', ', array_map( 'strval', $foreign_users ) ) )
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_list is integers from our own query.
		$wpdb->query( "DELETE bi FROM {$wpdb->prefix}wcbom_bom_items bi INNER JOIN {$wpdb->prefix}wcbom_boms b ON b.bom_id = bi.bom_id WHERE b.product_id IN ({$id_list})" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $id_list is integers from our own query.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wcbom_boms WHERE product_id IN ({$id_list})" );

		foreach ( $product_ids as $product_id ) {
			wp_delete_post( $product_id, true );
		}

		return count( $product_ids );
	}

	/**
	 * IDs of all products flagged as sample fixtures.
	 *
	 * @param int $limit Maximum IDs to fetch (-1 for all).
	 * @return array<int,int>
	 */
	private function fixture_product_ids( int $limit = -1 ): array {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'meta_key'    => self::FIXTURE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'numberposts' => $limit,
				'fields'      => 'ids',
				'post_status' => 'any',
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Creates the component products (blank tumbler + raw materials).
	 * Weights are in the store's configured weight unit (lbs in this
	 * dev env) per unit of the component's own stock unit — e.g. Epoxy's
	 * 0.0022 is "per ml" so a 5ml BOM line contributes 0.011 — so §5.10's
	 * BOM-derived shipping weight demos correctly out of the box.
	 *
	 * @return array<string,int> Component name => product ID.
	 */
	private function create_components(): array {
		$specs = array(
			array( '24oz Blank Tumbler', 100, 'ea', true, 0.5 ),
			array( 'Glitter - Pink', 500, 'g', false, 0.0022 ),
			array( 'Glitter - Blue', 500, 'g', false, 0.0022 ),
			array( 'Vinyl Sheet', 40, 'sheet', false, 0.02 ),
			array( 'Epoxy', 200, 'ml', false, 0.0022 ),
			array( 'Standard Straw', 300, 'ea', false, 0.02 ),
			array( 'Upgraded Metal Straw', 50, 'ea', false, 0.05 ),
			array( 'Standard Cap', 300, 'ea', false, 0.04 ),
			array( 'Upgraded Cap', 50, 'ea', false, 0.06 ),
			array( 'Sticker Pack', 100, 'ea', false, 0.01 ),
		);

		$ids = array();

		foreach ( $specs as list( $name, $stock, $unit, $sellable, $weight ) ) {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_regular_price( '0' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( 'instock' );
			$product->set_catalog_visibility( $sellable ? 'visible' : 'hidden' );
			$product->set_weight( (string) $weight );
			$product->set_status( 'publish' );
			$product_id = $product->save();

			update_post_meta( $product_id, '_wcbom_is_component', 'yes' );
			update_post_meta( $product_id, '_wcbom_unit', $unit );
			update_post_meta( $product_id, self::FIXTURE_META, '1' );

			$ids[ $name ] = $product_id;
		}

		return $ids;
	}

	/**
	 * Creates a premade (manufactured-mode) finished good with its own stock.
	 *
	 * @param string $name  Product title.
	 * @param int    $stock Starting stock quantity.
	 */
	private function create_premade( string $name, int $stock ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '34.99' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_stock_status( 'instock' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_weight( '0.55' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		update_post_meta( $product_id, '_wcbom_mode', 'manufactured' );
		update_post_meta( $product_id, self::FIXTURE_META, '1' );

		return $product_id;
	}

	/**
	 * Creates the made-to-order variable product (glitter color × straw
	 * upgrade) and its starter BOM.
	 *
	 * @param array<string,int> $components Component name => product ID.
	 * @return array{product_id:int,bom_id:int}
	 */
	private function create_made_to_order( array $components ): array {
		$glitter_taxonomy = $this->register_attribute( 'wcbom-glitter-color', 'Glitter Color', array( 'Pink', 'Blue' ) );
		$straw_taxonomy   = $this->register_attribute( 'wcbom-straw', 'Straw', array( 'Standard', 'Upgraded' ) );

		$product = new WC_Product_Variable();
		$product->set_name( 'Custom 24oz Tumbler' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_status( 'publish' );

		$attributes = array();
		foreach ( array( $glitter_taxonomy, $straw_taxonomy ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( wp_list_pluck( $terms, 'term_id' ) );
			$attribute->set_position( 0 );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );
		$product_id = $product->save();

		update_post_meta( $product_id, '_wcbom_mode', 'made_to_order' );
		update_post_meta( $product_id, '_wcbom_weight_from_bom', 'yes' );
		update_post_meta( $product_id, self::FIXTURE_META, '1' );

		foreach ( $this->variation_combinations() as list( $glitter, $straw ) ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_attributes(
				array(
					$glitter_taxonomy => sanitize_title( $glitter ),
					$straw_taxonomy   => sanitize_title( $straw ),
				)
			);
			$variation->set_regular_price( 'Upgraded' === $straw ? '29.99' : '24.99' );
			$variation->set_status( 'publish' );
			$variation->save();
		}

		wc_delete_product_transients( $product_id );

		$bom_id = $this->seed_bom( $product_id, $components, $glitter_taxonomy, $straw_taxonomy );

		return array(
			'product_id' => $product_id,
			'bom_id'     => $bom_id,
		);
	}

	/**
	 * The glitter-color × straw-upgrade variation combinations to create.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function variation_combinations(): array {
		return array(
			array( 'Pink', 'Standard' ),
			array( 'Pink', 'Upgraded' ),
			array( 'Blue', 'Standard' ),
			array( 'Blue', 'Upgraded' ),
		);
	}

	/**
	 * Creates a global product attribute (if missing) and its terms.
	 *
	 * @param string            $slug  Attribute slug (without the pa_ prefix).
	 * @param string            $label Attribute display label.
	 * @param array<int,string> $terms Term names to create under the attribute.
	 * @return string The attribute's taxonomy name, e.g. "pa_wcbom-straw".
	 */
	private function register_attribute( string $slug, string $label, array $terms ): string {
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		if ( ! taxonomy_exists( $taxonomy ) && 0 === wc_attribute_taxonomy_id_by_name( $slug ) ) {
			wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);

			// wc_create_attribute() registers the taxonomy on the next load;
			// register it now too so we can attach terms in this same request.
			register_taxonomy(
				$taxonomy,
				'product',
				array( 'hierarchical' => false )
			);
		}

		foreach ( $terms as $term ) {
			if ( ! term_exists( $term, $taxonomy ) ) {
				wp_insert_term( $term, $taxonomy );
			}
		}

		return $taxonomy;
	}

	/**
	 * Seeds the starter BOM directly into the plugin tables: always-consume
	 * the blank tumbler/epoxy/cap, plus glitter/straw lines conditional on
	 * the variation attributes registered above. The Blue Glitter line
	 * carries a $2 surcharge (§5.10) as a clean surcharge demo: unlike
	 * straw, glitter color has no variation-level price difference of its
	 * own, so this doesn't double-charge — blue costs $2 more purely via
	 * the BOM line, not by adding a second Blue variation price tier.
	 *
	 * @param int               $product_id       The made-to-order product's ID.
	 * @param array<string,int> $components       Component name => product ID.
	 * @param string            $glitter_taxonomy Glitter-color attribute taxonomy name.
	 * @param string            $straw_taxonomy   Straw attribute taxonomy name.
	 */
	private function seed_bom( int $product_id, array $components, string $glitter_taxonomy, string $straw_taxonomy ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcbom_boms',
			array(
				'product_id' => $product_id,
				'version'    => 1,
				'is_active'  => 1,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => get_current_user_id(),
			),
			array( '%d', '%d', '%d', '%s', '%d' )
		);
		$bom_id = (int) $wpdb->insert_id;

		$lines = array(
			array( $components['24oz Blank Tumbler'], 1, 'always', null, null, null ),
			array( $components['Glitter - Pink'], 15, 'attribute', $glitter_taxonomy, 'pink', null ),
			array( $components['Glitter - Blue'], 15, 'attribute', $glitter_taxonomy, 'blue', 2.00 ),
			array( $components['Epoxy'], 5, 'always', null, null, null ),
			array( $components['Standard Cap'], 1, 'always', null, null, null ),
			array( $components['Standard Straw'], 1, 'attribute', $straw_taxonomy, 'standard', null ),
			array( $components['Upgraded Metal Straw'], 1, 'attribute', $straw_taxonomy, 'upgraded', null ),
		);

		foreach ( $lines as $i => list( $component_id, $qty, $condition_type, $condition_key, $condition_value, $surcharge ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'wcbom_bom_items',
				array(
					'bom_id'          => $bom_id,
					'component_id'    => $component_id,
					'qty'             => $qty,
					'condition_type'  => $condition_type,
					'condition_key'   => $condition_key,
					'condition_value' => $condition_value,
					'sort_order'      => $i,
					'surcharge'       => $surcharge,
				),
				array( '%d', '%d', '%f', '%s', '%s', '%s', '%d', '%f' )
			);
		}

		/**
		 * This BOM is written directly via $wpdb, not BomRepository::save(),
		 * so it must fire the same action save() does. Without this,
		 * Stock\PhantomStock never invalidates — and it matters here more
		 * than it looks: _wcbom_mode is set on $product_id (made_to_order)
		 * before this method runs but after the variations above are
		 * created, so each variation->save() call above executes while
		 * ProductMode::resolve() already falls back to a "made_to_order"
		 * parent with no BOM yet — anything that reads a variation's stock
		 * status during that window caches a permanent buildable-qty of 0
		 * for it. Firing this now invalidates the product and (per
		 * PhantomStock::invalidate()'s cascade) every variation child too,
		 * clearing any such poisoning left over from that window.
		 */
		do_action( 'wcbom_bom_saved', $product_id );

		return $bom_id;
	}
}
