<?php
/**
 * §9 test scenarios 32-33, 36: order-time cost snapshot capture
 * (BUILD_PLAN.md §5.15, Phase 11).
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Orders\OrderCostSnapshot;
use WCBOM\Orders\OrderItemCost;
use WCBOM\Orders\OrderItemCostRepository;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\ManufacturedCost;

final class OrderCostSnapshotTest extends WCBOM_UnitTestCase {

	/**
	 * §9.32 (part 1): a made-to-order sale writes exactly one cost row per
	 * order item, sourced from live BOM cost.
	 */
	public function test_made_to_order_sale_captures_bom_live_cost(): void {
		$blank = $this->create_component( 'Snapshot Blank', 100, 'ea', '3.00' );
		$made  = $this->create_made_to_order( 'Snapshot Tumbler', array( array( 'component_id' => $blank, 'qty' => 2 ) ) );

		$this->snapshot()->register();

		$order   = $this->place_order( $made['product_id'], 3 );
		$item_id = array_key_first( $order->get_items() );

		$costs = new OrderItemCostRepository();
		$row   = $costs->get_for_item( $item_id );

		$this->assertNotNull( $row );
		$this->assertSame( $order->get_id(), $row->order_id );
		$this->assertSame( $made['product_id'], $row->product_id );
		$this->assertSame( 3.0, $row->quantity );
		$this->assertSame( 6.0, $row->unit_cost, 'Blank(3.00×2) = 6.00 per unit.' );
		$this->assertSame( OrderItemCost::SOURCE_BOM_LIVE, $row->cost_source );
	}

	/**
	 * §9.32 (part 2): a manufactured-product sale writes mo_snapshot cost
	 * once built, and falls back to live BOM cost when never built.
	 */
	public function test_manufactured_sale_captures_mo_snapshot_and_falls_back_when_unbuilt(): void {
		$blank = $this->create_component( 'Snapshot MO Blank', 100, 'ea', '3.00' );
		$made  = $this->create_manufactured_product( 'Snapshot Batch Tumbler', array( array( 'component_id' => $blank, 'qty' => 2 ) ) );

		// Never built: falls back to live BOM cost.
		$this->snapshot()->register();
		$order   = $this->place_order( $made['product_id'], 1 );
		$item_id = array_key_first( $order->get_items() );

		$costs = new OrderItemCostRepository();
		$row   = $costs->get_for_item( $item_id );
		$this->assertSame( 6.0, $row->unit_cost );
		$this->assertSame( OrderItemCost::SOURCE_BOM_LIVE, $row->cost_source, 'Never built — falls back to live BOM cost.' );

		// Now actually build it, at the same $3.00/unit component price.
		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $made['product_id'], 10, null );
		$service->complete( $mo->mo_id, wp_generate_uuid4() );

		$order2   = $this->place_order( $made['product_id'], 1 );
		$item2_id = array_key_first( $order2->get_items() );
		$row2     = $costs->get_for_item( $item2_id );

		$this->assertSame( 6.0, $row2->unit_cost );
		$this->assertSame( OrderItemCost::SOURCE_MO_SNAPSHOT, $row2->cost_source );
	}

	/**
	 * §9.32 (part 3): a standard (non-BOM) product's sale writes no cost row at all.
	 */
	public function test_standard_product_sale_writes_no_row(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Snapshot Plain Mug' );
		$product->set_regular_price( '9.99' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 50 );
		$product_id = $product->save();

		$this->snapshot()->register();
		$order   = $this->place_order( $product_id, 1 );
		$item_id = array_key_first( $order->get_items() );

		$this->assertNull( ( new OrderItemCostRepository() )->get_for_item( $item_id ) );
	}

	/**
	 * §9.33: the captured row never changes after a later component-price
	 * change — enforced at the DB level (UNIQUE KEY on order_item_id), not
	 * just by the "already captured" check being run once.
	 */
	public function test_drift_rule_captured_cost_survives_price_change_and_retry(): void {
		$blank = $this->create_component( 'Drift Blank', 100, 'ea', '3.00' );
		$made  = $this->create_made_to_order( 'Drift Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$snapshot = $this->snapshot();
		$snapshot->register();

		$order   = $this->place_order( $made['product_id'], 1 );
		$item_id = array_key_first( $order->get_items() );

		$blank_product = wc_get_product( $blank );
		$blank_product->set_regular_price( '99.00' );
		$blank_product->save();

		// A retried capture attempt (e.g. a duplicate hook fire) must not
		// overwrite the frozen row — re-run the capture directly.
		$snapshot->capture_for_order( wc_get_order( $order->get_id() ) );

		$row = ( new OrderItemCostRepository() )->get_for_item( $item_id );
		$this->assertSame( 3.0, $row->unit_cost, 'Must still read the original $3.00 cost, not the new $99.00 price.' );
	}

	/**
	 * §9.36 (capture side): a variation sale's cost row is keyed to the
	 * variation's own product ID, not the parent product ID.
	 */
	public function test_variation_sale_captures_variation_id_not_parent(): void {
		$blank        = $this->create_component( 'Variant Blank', 100, 'ea', '3.00' );
		$standard_cap = $this->create_component( 'Variant Standard Cap', 300, 'ea', '1.00' );

		$taxonomy = $this->register_variant_attribute();

		$product = new WC_Product_Variable();
		$product->set_name( 'Snapshot Variable Tumbler' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( wp_list_pluck( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ), 'term_id' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product_id = $product->save();
		update_post_meta( $product_id, '_wcbom_mode', 'made_to_order' );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( array( $taxonomy => 'standard' ) );
		$variation->set_regular_price( '19.99' );
		$variation->set_status( 'publish' );
		$variation_id = $variation->save();

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
			),
			1
		);

		$this->snapshot()->register();
		$order   = $this->place_order( $variation_id, 1 );
		$item_id = array_key_first( $order->get_items() );
		$item    = $order->get_item( $item_id );

		// Sanity: WC_Order_Item_Product::get_product_id() would return the
		// PARENT id — this assertion documents that our captured product_id
		// deliberately differs from it.
		$this->assertNotSame( $item->get_product_id(), $variation_id );

		$row = ( new OrderItemCostRepository() )->get_for_item( $item_id );
		$this->assertSame( $variation_id, $row->product_id );
	}

	/**
	 * A made-to-order product with no resolvable BOM captures as uncosted
	 * (null cost), never zero.
	 */
	public function test_uncosted_when_no_bom_resolves(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Snapshot No-BOM Product' );
		$product->set_regular_price( '19.99' );
		$product_id = $product->save();
		update_post_meta( $product_id, '_wcbom_mode', 'made_to_order' );

		$this->snapshot()->register();
		$order   = $this->place_order( $product_id, 1 );
		$item_id = array_key_first( $order->get_items() );

		$row = ( new OrderItemCostRepository() )->get_for_item( $item_id );
		$this->assertNotNull( $row );
		$this->assertNull( $row->unit_cost, 'Uncosted must be null, never 0.' );
		$this->assertSame( OrderItemCost::SOURCE_UNCOSTED, $row->cost_source );
	}

	/**
	 * A freshly wired OrderCostSnapshot, built the same way Plugin::init() wires it.
	 */
	private function snapshot(): OrderCostSnapshot {
		$boms = new BomRepository();

		return new OrderCostSnapshot(
			$boms,
			new ConditionMatcher(),
			new BomCost(),
			new ManufacturedCost( new ManufactureRepository() ),
			new OrderItemCostRepository()
		);
	}

	/**
	 * Registers a global product attribute + terms for the variation test.
	 */
	private function register_variant_attribute(): string {
		$slug     = 'wcbom-snapshot-test-cap';
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		if ( ! taxonomy_exists( $taxonomy ) && 0 === wc_attribute_taxonomy_id_by_name( $slug ) ) {
			wc_create_attribute(
				array(
					'name'     => 'Cap',
					'slug'     => $slug,
					'type'     => 'select',
					'order_by' => 'menu_order',
				)
			);
			register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false ) );
		}

		if ( ! term_exists( 'Standard', $taxonomy ) ) {
			wp_insert_term( 'Standard', $taxonomy );
		}

		return $taxonomy;
	}
}
