<?php
/**
 * §9 test scenarios 34, 36-37: Reports\ProfitabilityReport's WP-integration
 * glue — refund netting, variation grouping, and the trend view's
 * independence from the product/order date range (BUILD_PLAN.md §5.15,
 * Phase 11).
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Orders\OrderCostSnapshot;
use WCBOM\Orders\OrderItemCostRepository;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\ManufacturedCost;
use WCBOM\Reports\ProfitabilityAggregator;
use WCBOM\Reports\ProfitabilityReport;

final class ProfitabilityReportTest extends WCBOM_UnitTestCase {

	/**
	 * §9.34 (partial): refunding half the units nets both quantity and
	 * revenue proportionally, with cost reduced by the same proportion.
	 */
	public function test_partial_refund_nets_quantity_revenue_and_cost_proportionally(): void {
		$blank = $this->create_component( 'Report Blank', 100, 'ea', '3.00' );
		$made  = $this->create_made_to_order( 'Report Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ), '20.00' );

		$this->snapshot()->register();
		$order   = $this->place_order( $made['product_id'], 2 );
		$item_id = array_key_first( $order->get_items() );

		wc_create_refund(
			array(
				'order_id'      => $order->get_id(),
				'amount'        => 20.00,
				'restock_items' => true,
				'line_items'    => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 20.00,
					),
				),
			)
		);

		$rows = $this->report()->product_rows( gmdate( 'Y-m-d', strtotime( '-1 day' ) ), gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
		$row  = current( array_filter( $rows, static fn( array $r ): bool => $r['product_id'] === $made['product_id'] ) );

		$this->assertNotFalse( $row );
		$this->assertSame( 1.0, $row['quantity'], 'One of two units remains after the refund.' );
		$this->assertSame( 20.0, $row['revenue'], '$40 gross minus $20 refunded = $20 net.' );
		$this->assertSame( 3.0, $row['cost'], '$6 gross cost minus half = $3 net.' );
	}

	/**
	 * §9.34 (full): a fully refunded line is excluded from the report
	 * entirely — never shown as a $0 row.
	 */
	public function test_fully_refunded_line_excluded_entirely(): void {
		$blank = $this->create_component( 'Report Full Refund Blank', 100, 'ea', '3.00' );
		$made  = $this->create_made_to_order( 'Report Full Refund Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ), '20.00' );

		$this->snapshot()->register();
		$order   = $this->place_order( $made['product_id'], 1 );
		$item_id = array_key_first( $order->get_items() );

		wc_create_refund(
			array(
				'order_id'      => $order->get_id(),
				'amount'        => 20.00,
				'restock_items' => true,
				'line_items'    => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 20.00,
					),
				),
			)
		);

		$rows = $this->report()->product_rows( gmdate( 'Y-m-d', strtotime( '-1 day' ) ), gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
		$row  = current( array_filter( $rows, static fn( array $r ): bool => $r['product_id'] === $made['product_id'] ) );

		$this->assertFalse( $row, 'A fully refunded line must not appear at all, not even as a $0 row.' );
	}

	/**
	 * §9.36 (report side): grouping is by the sold item's own ID (variation
	 * ID for a variation sale), not the parent product ID.
	 */
	public function test_product_rows_group_by_variation_id_not_parent(): void {
		$blank        = $this->create_component( 'Report Variant Blank', 100, 'ea', '3.00' );
		$standard_cap = $this->create_component( 'Report Variant Cap', 300, 'ea', '1.00' );

		$slug     = 'wcbom-report-test-cap';
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

		$product = new WC_Product_Variable();
		$product->set_name( 'Report Variable Tumbler' );
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
		$this->place_order( $variation_id, 1 );

		$rows = $this->report()->product_rows( gmdate( 'Y-m-d', strtotime( '-1 day' ) ), gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
		$ids  = array_column( $rows, 'product_id' );

		$this->assertContains( $variation_id, $ids );
		$this->assertNotContains( $product_id, $ids, 'The parent product ID must never appear as its own row.' );
	}

	/**
	 * §9.37: the trend view always covers the trailing 12 calendar months
	 * including the current one, regardless of what date range is passed
	 * to product_rows()/order_rows().
	 */
	public function test_trend_rows_independent_of_product_view_date_range(): void {
		$blank = $this->create_component( 'Report Trend Blank', 100, 'ea', '3.00' );
		$made  = $this->create_made_to_order( 'Report Trend Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ), '20.00' );

		$this->snapshot()->register();
		$this->place_order( $made['product_id'], 1 );

		// A product-view date range that would exclude today entirely.
		$this->report()->product_rows( '2000-01-01', '2000-01-31' );

		$trend         = $this->report()->trend_rows();
		$current_month = current_time( 'Y-m' );
		$months        = array_column( $trend, 'month' );

		$this->assertContains( $current_month, $months, 'The trend view must include the current month regardless of the other views\' date range.' );
	}

	/**
	 * A standard-mode sale never gets a cost row captured, so it's
	 * correctly excluded from every profitability view with no extra
	 * mode-check needed in this class.
	 */
	public function test_standard_product_sale_excluded_from_report(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Report Plain Mug' );
		$product->set_regular_price( '9.99' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 50 );
		$product_id = $product->save();

		$this->snapshot()->register();
		$this->place_order( $product_id, 1 );

		$rows = $this->report()->product_rows( gmdate( 'Y-m-d', strtotime( '-1 day' ) ), gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
		$ids  = array_column( $rows, 'product_id' );

		$this->assertNotContains( $product_id, $ids );
	}

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

	private function report(): ProfitabilityReport {
		return new ProfitabilityReport( new OrderItemCostRepository(), new ProfitabilityAggregator() );
	}
}
