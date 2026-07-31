<?php
/**
 * §9 test scenarios 26–27: nested BOMs / sub-assemblies (BUILD_PLAN.md
 * §5.14, Phase 10) — consumption/invalidation for a manufactured
 * sub-assembly used as another product's component, and the two new
 * save-time guards (cycle detection, made-to-order rejection).
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Stock\PhantomStock;

final class NestedBomTest extends WCBOM_UnitTestCase {

	/**
	 * §9.26: a manufactured sub-assembly's real stock flows through the
	 * ledgered consumption path exactly like any other component; a
	 * made-to-order parent's buildable is floor(sub-assembly on-hand ÷
	 * qty), refreshing when the sub-assembly is built/reversed; and a raw
	 * material used only inside the sub-assembly's own recipe does NOT
	 * move the parent's buildable.
	 */
	public function test_manufactured_subassembly_consumption_and_buildable(): void {
		$blank   = $this->create_component( 'Blank', 1000 );
		$glitter = $this->create_component( 'Glitter', 1000, 'g' );

		// The sub-assembly: a manufactured product built from raw materials.
		$sub_assembly = $this->create_manufactured_product(
			'Glittered Blank',
			array(
				array( 'component_id' => $blank, 'qty' => 1 ),
				array( 'component_id' => $glitter, 'qty' => 5 ),
			)
		);

		$service = $this->manufacture_service();
		$mo      = $service->create_draft_for_existing( $sub_assembly['product_id'], 20, null );
		$service->complete( $mo->mo_id, wp_generate_uuid4() );

		$this->assertSame( 20.0, $this->stock_of( $sub_assembly['product_id'] ), 'Sub-assembly should have real, physical stock after its own MO builds it.' );

		// The parent: a made-to-order product whose only always-line is the sub-assembly.
		$parent = $this->create_made_to_order(
			'Pink Tumbler',
			array( array( 'component_id' => $sub_assembly['product_id'], 'qty' => 1 ) )
		);

		$phantom = new PhantomStock( new BomRepository() );
		$this->assertSame( 20, $phantom->get_buildable_qty( $parent['product_id'] ), 'Buildable = floor(sub-assembly on-hand ÷ qty).' );

		// Consuming the parent (a real order) must decrement the
		// sub-assembly's real stock through the ledgered order-consumption
		// path — a component is just a product ID; nothing about
		// consumption cares what mode it is.
		$this->place_order( $parent['product_id'], 3 );
		$this->assertSame( 17.0, $this->stock_of( $sub_assembly['product_id'] ) );
		$this->assertSame( 17, $phantom->get_buildable_qty( $parent['product_id'] ), 'Buildable refreshes when the sub-assembly is consumed.' );

		// Building more of the sub-assembly must refresh the parent's
		// buildable too (the invalidation chain fires on MO complete).
		$mo2 = $service->create_draft_for_existing( $sub_assembly['product_id'], 5, null );
		$service->complete( $mo2->mo_id, wp_generate_uuid4() );
		$this->assertSame( 22.0, $this->stock_of( $sub_assembly['product_id'] ) );
		$this->assertSame( 22, $phantom->get_buildable_qty( $parent['product_id'] ) );

		// Reversing the sub-assembly's build must refresh it back down too.
		$service->reverse( $mo2->mo_id, 5, wp_generate_uuid4() );
		$this->assertSame( 17.0, $this->stock_of( $sub_assembly['product_id'] ) );
		$this->assertSame( 17, $phantom->get_buildable_qty( $parent['product_id'] ) );

		// The subtle correct case: Glitter is used only inside the
		// sub-assembly's OWN recipe, never directly in the parent's BOM.
		// Changing its stock must NOT move the parent's buildable, since
		// the parent's buildable depends only on the sub-assembly's real
		// on-hand stock, which didn't move.
		$stock_service = new \WCBOM\Stock\StockService( new \WCBOM\Stock\Ledger() );
		$stock_service->adjust( $glitter, -500, \WCBOM\Stock\Ledger::REASON_MANUAL_ADJUST, null, null, 'test: deplete glitter directly' );
		$this->assertSame( 17, $phantom->get_buildable_qty( $parent['product_id'] ), 'A raw material used only inside the sub-assembly\'s recipe must not move the parent\'s buildable.' );
	}

	/**
	 * §9.27a: a direct self-reference (a product naming itself as its own
	 * component) is rejected with a clear error.
	 */
	public function test_save_rejects_direct_self_reference(): void {
		$product = $this->create_manufactured_product( 'Self Referencing', array( array( 'component_id' => $this->create_component( 'Filler', 100 ), 'qty' => 1 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/circular/i' );

		( new BomRepository() )->save(
			$product['product_id'],
			array(
				array(
					'component_id'    => $product['product_id'],
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
			),
			1
		);
	}

	/**
	 * §9.27b: a longer A→B→A cycle through active BOMs (not just direct
	 * self-reference) is rejected too.
	 */
	public function test_save_rejects_indirect_cycle(): void {
		$filler = $this->create_component( 'Filler', 100 );

		$product_a = $this->create_manufactured_product( 'Product A', array( array( 'component_id' => $filler, 'qty' => 1 ) ) );

		// Product B's own active BOM already includes A as a component —
		// legitimate on its own (A is not made-to-order).
		$product_b = $this->create_manufactured_product( 'Product B', array( array( 'component_id' => $product_a['product_id'], 'qty' => 1 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/circular/i' );

		// Now try to make A depend on B — a cycle, since B already depends on A.
		( new BomRepository() )->save(
			$product_a['product_id'],
			array(
				array(
					'component_id'    => $product_b['product_id'],
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
			),
			1
		);
	}

	/**
	 * §9.27c: any made-to-order product is rejected as a BOM component,
	 * regardless of cycles — its stock is a computed buildable number,
	 * not a real on-hand count.
	 */
	public function test_save_rejects_made_to_order_component(): void {
		$filler = $this->create_component( 'Filler', 100 );
		$mto    = $this->create_made_to_order( 'Custom Product', array( array( 'component_id' => $filler, 'qty' => 1 ) ) );

		$target = $this->create_manufactured_product( 'Target Product', array( array( 'component_id' => $filler, 'qty' => 1 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/made-to-order/i' );

		( new BomRepository() )->save(
			$target['product_id'],
			array(
				array(
					'component_id'    => $mto['product_id'],
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
			),
			1
		);
	}

	/**
	 * A save that neither cycles nor references a made-to-order product
	 * must still succeed — the guards shouldn't false-positive on a
	 * legitimate, non-overlapping sub-assembly chain (A uses B, B uses a
	 * plain raw material — no cycle).
	 */
	public function test_save_allows_legitimate_subassembly_chain(): void {
		$filler = $this->create_component( 'Filler', 100 );
		$sub    = $this->create_manufactured_product( 'Sub-assembly', array( array( 'component_id' => $filler, 'qty' => 1 ) ) );

		$target      = new WC_Product_Simple();
		$target->set_name( 'Final Product' );
		$target_id   = $target->save();

		$saved = ( new BomRepository() )->save(
			$target_id,
			array(
				array(
					'component_id'    => $sub['product_id'],
					'qty'             => 1,
					'condition_type'  => BomItem::CONDITION_ALWAYS,
					'condition_key'   => null,
					'condition_value' => null,
					'surcharge'       => null,
				),
			),
			1
		);

		$this->assertCount( 1, $saved->items );
		$this->assertSame( $sub['product_id'], $saved->items[0]->component_id );
	}
}
