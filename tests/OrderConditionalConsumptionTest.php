<?php
/**
 * §9 test scenario 2: variation-attribute-conditional and
 * addon-conditional BOM lines are consumed only when they actually
 * match the order item's selection — the extensibility mechanism this
 * whole plugin is built around (BUILD_PLAN.md §2.4).
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;

final class OrderConditionalConsumptionTest extends WCBOM_UnitTestCase {

	public function test_attribute_and_addon_conditional_lines_match_selectively(): void {
		$blank        = $this->create_component( 'Blank', 100 );
		$standard_cap = $this->create_component( 'Standard Cap', 300 );
		$upgraded_cap = $this->create_component( 'Upgraded Cap', 50 );
		$stickers     = $this->create_component( 'Sticker Pack', 100 );

		$taxonomy = $this->register_attribute( 'wcbom-test-cap', 'Cap', array( 'Standard', 'Upgraded' ) );

		$product = new WC_Product_Variable();
		$product->set_name( 'Variable Tumbler' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( wp_list_pluck( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ), 'term_id' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product_id = $product->save();
		update_post_meta( $product_id, '_wcbom_mode', 'made_to_order' );

		$standard_variation = new WC_Product_Variation();
		$standard_variation->set_parent_id( $product_id );
		$standard_variation->set_attributes( array( $taxonomy => 'standard' ) );
		$standard_variation->set_regular_price( '19.99' );
		$standard_variation->set_status( 'publish' );
		$standard_id = $standard_variation->save();

		$upgraded_variation = new WC_Product_Variation();
		$upgraded_variation->set_parent_id( $product_id );
		$upgraded_variation->set_attributes( array( $taxonomy => 'upgraded' ) );
		$upgraded_variation->set_regular_price( '24.99' );
		$upgraded_variation->set_status( 'publish' );
		$upgraded_id = $upgraded_variation->save();

		( new BomRepository() )->save(
			$product_id,
			array(
				array(
					'component_id'    => $blank,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
				array(
					'component_id'    => $standard_cap,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ATTRIBUTE,
					'condition_key'   => $taxonomy,
					'condition_value' => 'standard',
					'surcharge'       => null,
				),
				array(
					'component_id'    => $upgraded_cap,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ATTRIBUTE,
					'condition_key'   => $taxonomy,
					'condition_value' => 'upgraded',
					'surcharge'       => null,
				),
				array(
					'component_id'    => $stickers,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ADDON,
					'condition_key'   => 'add_stickers',
					'condition_value' => 'yes',
					'surcharge'       => null,
				),
			),
			1
		);

		// Order the Upgraded variation, with a visible order-item meta
		// value simulating what an add-ons plugin (e.g. ThemeHigh EPO)
		// would record for a "Yes" sticker add-on selection.
		$order = $this->place_order( $upgraded_id, 1, array( 'add_stickers' => 'Yes' ) );

		$this->assertSame( 99.0, $this->stock_of( $blank ), 'Always-line must consume.' );
		$this->assertSame( 300.0, $this->stock_of( $standard_cap ), 'Non-matching attribute line must NOT consume.' );
		$this->assertSame( 49.0, $this->stock_of( $upgraded_cap ), 'Matching attribute line must consume.' );
		$this->assertSame( 99.0, $this->stock_of( $stickers ), 'Matching addon line (via visible item meta) must consume.' );

		// Cancelling must restore exactly what was consumed.
		$order->update_status( 'cancelled' );

		$this->assertSame( 100.0, $this->stock_of( $blank ) );
		$this->assertSame( 300.0, $this->stock_of( $standard_cap ) );
		$this->assertSame( 50.0, $this->stock_of( $upgraded_cap ) );
		$this->assertSame( 100.0, $this->stock_of( $stickers ) );
	}

	/**
	 * Registers a global product attribute + terms, mirroring
	 * Install\SampleData's own approach. Returns the full taxonomy name
	 * (e.g. "pa_wcbom-test-cap") — what `WC_Product_Variation::set_attributes()`
	 * and BOM `condition_key`s both actually key on, not the bare slug.
	 *
	 * @param array<int,string> $terms Term names.
	 */
	private function register_attribute( string $slug, string $label, array $terms ): string {
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		if ( ! taxonomy_exists( $taxonomy ) && 0 === wc_attribute_taxonomy_id_by_name( $slug ) ) {
			wc_create_attribute(
				array(
					'name'     => $label,
					'slug'     => $slug,
					'type'     => 'select',
					'order_by' => 'menu_order',
				)
			);
			register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false ) );
		}

		foreach ( $terms as $term ) {
			if ( ! term_exists( $term, $taxonomy ) ) {
				wp_insert_term( $term, $taxonomy );
			}
		}

		return $taxonomy;
	}
}
