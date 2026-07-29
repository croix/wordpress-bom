<?php
/**
 * WP-CLI commands for wc-bom-stock.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Cli;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp wcbom ...` commands. `seed` builds the demo tumbler catalog every
 * phase of this plugin is demoed against (see BUILD_PLAN.md conventions).
 */
final class Commands {

	private const FIXTURE_META = '_wcbom_fixture';

	/**
	 * Seeds a demo tumbler catalog: components, a premade (manufactured)
	 * product, and a made-to-order customizable product with a starter BOM.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Delete any previously seeded fixture products/BOMs first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcbom seed
	 *     wp wcbom seed --reset
	 *
	 * @param array<int,string>    $args Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @when after_wp_load
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce must be active to seed fixtures.' );
		}

		if ( isset( $assoc_args['reset'] ) ) {
			$this->reset();
		}

		$components = $this->create_components();
		$premade_id = $this->create_premade( 'Ocean Wave 24oz Tumbler', 12 );
		$result     = $this->create_made_to_order( $components );

		WP_CLI::success(
			sprintf(
				'Seeded %d components, 1 premade product (#%d), 1 made-to-order product (#%d) with BOM #%d.',
				count( $components ),
				$premade_id,
				$result['product_id'],
				$result['bom_id']
			)
		);
	}

	/**
	 * Deletes previously seeded fixture products and their BOM rows.
	 */
	private function reset(): void {
		global $wpdb;

		$product_ids = get_posts(
			array(
				'post_type'   => 'product',
				'meta_key'    => self::FIXTURE_META,
				'meta_value'  => '1',
				'numberposts' => -1,
				'fields'      => 'ids',
				'post_status' => 'any',
			)
		);

		foreach ( $product_ids as $product_id ) {
			wp_delete_post( $product_id, true );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE bi FROM {$wpdb->prefix}wcbom_bom_items bi LEFT JOIN {$wpdb->posts} p ON p.ID = (SELECT product_id FROM {$wpdb->prefix}wcbom_boms b WHERE b.bom_id = bi.bom_id) WHERE p.ID IS NULL" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE b FROM {$wpdb->prefix}wcbom_boms b LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id WHERE p.ID IS NULL" );

		WP_CLI::log( sprintf( 'Removed %d previously seeded fixture product(s).', count( $product_ids ) ) );
	}

	/**
	 * Creates the component products (blank tumbler + raw materials).
	 *
	 * @return array<string,int> Component name => product ID.
	 */
	private function create_components(): array {
		$specs = array(
			array( '24oz Blank Tumbler', 100, 'ea', true ),
			array( 'Glitter - Pink', 500, 'g', false ),
			array( 'Glitter - Blue', 500, 'g', false ),
			array( 'Vinyl Sheet', 40, 'sheet', false ),
			array( 'Epoxy', 200, 'ml', false ),
			array( 'Standard Straw', 300, 'ea', false ),
			array( 'Upgraded Metal Straw', 50, 'ea', false ),
			array( 'Standard Cap', 300, 'ea', false ),
			array( 'Upgraded Cap', 50, 'ea', false ),
			array( 'Sticker Pack', 100, 'ea', false ),
		);

		$ids = array();

		foreach ( $specs as list( $name, $stock, $unit, $sellable ) ) {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_regular_price( '0' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( 'instock' );
			$product->set_catalog_visibility( $sellable ? 'visible' : 'hidden' );
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
	 * Seeds a starter BOM directly into the plugin tables: always-consume
	 * the blank tumbler, plus glitter/straw lines conditional on the
	 * variation attributes seeded above. (Phase 1's BomRepository will
	 * read/write these same tables — this is just enough to demo Phase 0.)
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
			array( $components['24oz Blank Tumbler'], 1, 'always', null, null ),
			array( $components['Glitter - Pink'], 15, 'attribute', $glitter_taxonomy, 'pink' ),
			array( $components['Glitter - Blue'], 15, 'attribute', $glitter_taxonomy, 'blue' ),
			array( $components['Epoxy'], 5, 'always', null, null ),
			array( $components['Standard Cap'], 1, 'always', null, null ),
			array( $components['Standard Straw'], 1, 'attribute', $straw_taxonomy, 'standard' ),
			array( $components['Upgraded Metal Straw'], 1, 'attribute', $straw_taxonomy, 'upgraded' ),
		);

		foreach ( $lines as $i => list( $component_id, $qty, $condition_type, $condition_key, $condition_value ) ) {
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
				),
				array( '%d', '%d', '%f', '%s', '%s', '%s', '%d' )
			);
		}

		return $bom_id;
	}
}
