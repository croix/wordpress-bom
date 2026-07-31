<?php
/**
 * §9 test scenarios 14-19: WooCommerce native Cost of Goods Sold
 * integration (BUILD_PLAN.md §5.11, Phase 7).
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Integrations\CogsProvider;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\MarginReport;

final class CogsIntegrationTest extends WCBOM_UnitTestCase {

	private const COGS_OPTION = 'woocommerce_feature_cost_of_goods_sold_enabled';

	public function tear_down(): void {
		delete_option( self::COGS_OPTION );
		parent::tear_down();
	}

	/**
	 * §9.14: with the feature off, the filter never runs (WooCommerce's own
	 * get_cogs_total_value() short-circuits to 0 before reaching it) — even
	 * for a product that's opted in with a real BOM.
	 */
	public function test_inert_when_cogs_feature_disabled(): void {
		delete_option( self::COGS_OPTION );

		// WooCommerce itself calls wc_doing_it_wrong() from get_cogs_total_value()
		// whenever the feature is off — expected and by design (CogsAwareTrait),
		// not a symptom of anything this plugin does.
		$this->setExpectedIncorrectUsage( 'WC_Product::get_cogs_total_value' );

		$blank = $this->create_component( 'Blank', 100, 'ea', '3.00' );
		$made  = $this->create_made_to_order( 'Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );
		update_post_meta( $made['product_id'], '_wcbom_cogs_from_bom', 'yes' );

		$this->provider()->register();

		$this->assertSame( 0.0, wc_get_product( $made['product_id'] )->get_cogs_total_value() );
	}

	/**
	 * §9.15: with the feature and the toggle both on, a made-to-order
	 * variation's COGS equals its resolved BOM cost — and two variations
	 * whose attribute-conditional lines differ report different costs.
	 */
	public function test_made_to_order_variation_cost_matches_resolved_bom_and_varies_by_option(): void {
		update_option( self::COGS_OPTION, 'yes' );

		$blank        = $this->create_component( 'Blank', 100, 'ea', '3.00' );
		$standard_cap = $this->create_component( 'Standard Cap', 300, 'ea', '1.00' );
		$upgraded_cap = $this->create_component( 'Upgraded Cap', 50, 'ea', '2.50' );

		[ $product_id, $standard_id, $upgraded_id, $taxonomy ] = $this->create_variable_made_to_order(
			$blank,
			$standard_cap,
			$upgraded_cap
		);
		update_post_meta( $product_id, '_wcbom_cogs_from_bom', 'yes' );

		$this->provider()->register();

		// Blank(3.00×1) + Standard Cap(1.00×1) = 4.00.
		$this->assertSame( 4.0, wc_get_product( $standard_id )->get_cogs_total_value() );
		// Blank(3.00×1) + Upgraded Cap(2.50×1) = 5.50.
		$this->assertSame( 5.5, wc_get_product( $upgraded_id )->get_cogs_total_value() );
	}

	/**
	 * §9.16: a real order for such a variation snapshots per-item and
	 * order-total COGS as BOM cost × quantity.
	 */
	public function test_real_order_snapshots_cogs_as_bom_cost_times_quantity(): void {
		update_option( self::COGS_OPTION, 'yes' );

		$blank        = $this->create_component( 'Blank', 100, 'ea', '3.00' );
		$standard_cap = $this->create_component( 'Standard Cap', 300, 'ea', '1.00' );
		$upgraded_cap = $this->create_component( 'Upgraded Cap', 50, 'ea', '2.50' );

		[ $product_id, $standard_id, $upgraded_id ] = $this->create_variable_made_to_order(
			$blank,
			$standard_cap,
			$upgraded_cap
		);
		update_post_meta( $product_id, '_wcbom_cogs_from_bom', 'yes' );

		$this->provider()->register();

		$order = $this->place_order( $upgraded_id, 3 );

		$item = current( $order->get_items() );
		$this->assertSame( 16.5, $item->get_cogs_value(), 'Per-item COGS must be 5.50 × 3.' );
		$this->assertSame( 16.5, $order->get_cogs_total_value(), 'Order total must match the single item.' );
	}

	/**
	 * §9.17: a MANUFACTURED product reports its latest completed MO's
	 * snapshot unit cost (not live component prices) once built, and
	 * correctly falls back to live BOM cost when it's never been built.
	 */
	public function test_manufactured_product_uses_snapshot_cost_and_falls_back_when_unbuilt(): void {
		update_option( self::COGS_OPTION, 'yes' );

		$blank = $this->create_component( 'Blank', 100, 'ea', '3.00' );
		$made  = $this->create_manufactured_product( 'Batch Tumbler', array( array( 'component_id' => $blank, 'qty' => 2 ) ) );
		update_post_meta( $made['product_id'], '_wcbom_cogs_from_bom', 'yes' );

		$this->provider()->register();

		// Never built: falls back to live cost — Blank(3.00×2) = 6.00.
		$this->assertSame( 6.0, wc_get_product( $made['product_id'] )->get_cogs_total_value() );

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );
		$service->complete( $mo->mo_id, wp_generate_uuid4() );

		// Built at $3.00/unit: snapshot cost = 6.00, pinned regardless of a later price change.
		$this->assertSame( 6.0, wc_get_product( $made['product_id'] )->get_cogs_total_value() );

		$blank_product = wc_get_product( $blank );
		$blank_product->set_regular_price( '9.00' );
		$blank_product->save();
		$this->assertSame(
			6.0,
			wc_get_product( $made['product_id'] )->get_cogs_total_value(),
			'A live component price change after the build must not move the snapshot-based COGS value.'
		);
	}

	/**
	 * §9.18: MarginReport's cost and CogsProvider's cost must agree for
	 * the same product/variation — the shared-calculation guarantee
	 * BUILD_PLAN.md §5.11 calls the most important part of this task.
	 */
	public function test_margin_report_and_cogs_provider_agree(): void {
		update_option( self::COGS_OPTION, 'yes' );

		$blank        = $this->create_component( 'Blank', 100, 'ea', '3.00' );
		$standard_cap = $this->create_component( 'Standard Cap', 300, 'ea', '1.00' );
		$upgraded_cap = $this->create_component( 'Upgraded Cap', 50, 'ea', '2.50' );

		[ $product_id, , $upgraded_id ] = $this->create_variable_made_to_order( $blank, $standard_cap, $upgraded_cap );
		update_post_meta( $product_id, '_wcbom_cogs_from_bom', 'yes' );

		$this->provider()->register();

		$boms    = new BomRepository();
		$matcher = new ConditionMatcher();
		$report  = new MarginReport( $boms, $matcher, new BomCost() );

		$rows = array_values( array_filter( $report->generate(), static fn( array $row ): bool => $row['product_id'] === $upgraded_id ) );
		$this->assertCount( 1, $rows );

		$this->assertSame( $rows[0]['cost'], wc_get_product( $upgraded_id )->get_cogs_total_value() );
	}

	/**
	 * §9.19: a standard (non-BOM) product's COGS is completely untouched
	 * by this plugin, even with the feature enabled.
	 */
	public function test_standard_product_cogs_untouched(): void {
		update_option( self::COGS_OPTION, 'yes' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Plain Mug' );
		$product->set_regular_price( '9.99' );
		$product_id = $product->save();

		$native_value = wc_get_product( $product_id )->get_cogs_total_value();

		$this->provider()->register();

		$this->assertSame( $native_value, wc_get_product( $product_id )->get_cogs_total_value() );
	}

	/**
	 * A freshly wired CogsProvider, built the same way Plugin::init() wires it.
	 */
	private function provider(): CogsProvider {
		return new CogsProvider(
			new BomRepository(),
			new ConditionMatcher(),
			new BomCost(),
			new ManufactureRepository()
		);
	}

	/**
	 * A variable made-to-order product with two variations (Standard/
	 * Upgraded) and an always-line plus two attribute-conditional lines,
	 * mirroring OrderConditionalConsumptionTest's fixture shape.
	 *
	 * @return array{0:int,1:int,2:int,3:string} product_id, standard variation_id, upgraded variation_id, taxonomy.
	 */
	private function create_variable_made_to_order( int $always_component, int $standard_component, int $upgraded_component ): array {
		$taxonomy = $this->register_attribute( 'wcbom-cogs-test-cap', 'Cap', array( 'Standard', 'Upgraded' ) );

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
					'component_id'    => $always_component,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
				array(
					'component_id'    => $standard_component,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ATTRIBUTE,
					'condition_key'   => $taxonomy,
					'condition_value' => 'standard',
					'surcharge'       => null,
				),
				array(
					'component_id'    => $upgraded_component,
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ATTRIBUTE,
					'condition_key'   => $taxonomy,
					'condition_value' => 'upgraded',
					'surcharge'       => null,
				),
			),
			1
		);

		return array( $product_id, $standard_id, $upgraded_id, $taxonomy );
	}

	/**
	 * Registers a global product attribute + terms, mirroring
	 * OrderConditionalConsumptionTest's own approach.
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
