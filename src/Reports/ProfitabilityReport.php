<?php
/**
 * WP-integration glue for the profitability reports.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WC_Order;
use WC_Order_Item_Product;
use WCBOM\Orders\OrderItemCostRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.15: builds realized (refund-netted) line arrays from
 * real orders via `wc_get_orders()` — never raw `posts`/`wc_orders` queries,
 * since HPOS may store orders in either place — joins in the frozen cost
 * from Orders\OrderItemCostRepository, and hands the result to
 * Reports\ProfitabilityAggregator. Not unit-tested directly (needs a live
 * WooCommerce), matching every other report in this plugin.
 *
 * A line only exists here at all if a cost-snapshot row was captured for
 * it — a STANDARD-mode sale never gets one (Orders\OrderCostSnapshot), so
 * it's correctly excluded from these reports without any extra mode-check
 * in this class.
 */
final class ProfitabilityReport {

	private const REALIZED_STATUSES = array( 'processing', 'completed', 'refunded' );

	/**
	 * Constructs the report.
	 *
	 * @param OrderItemCostRepository $costs      Frozen cost-snapshot lookup.
	 * @param ProfitabilityAggregator $aggregator Pure grouping/arithmetic.
	 */
	public function __construct(
		private readonly OrderItemCostRepository $costs,
		private readonly ProfitabilityAggregator $aggregator
	) {}

	/**
	 * Product profitability over a date range.
	 *
	 * @param string $date_from Y-m-d, inclusive.
	 * @param string $date_to   Y-m-d, inclusive.
	 * @return array<int,array{product_id:int,product_name:string,quantity:float,revenue:float,cost:float,uncosted_quantity:float,profit:float,margin:float|null}>
	 */
	public function product_rows( string $date_from, string $date_to ): array {
		$rows = $this->aggregator->by_product( $this->build_lines( $date_from, $date_to ) );

		return array_map(
			static function ( array $row ): array {
				$product             = wc_get_product( $row['product_id'] );
				$row['product_name'] = $product ? $product->get_name() : ( '#' . $row['product_id'] );

				return $row;
			},
			$rows
		);
	}

	/**
	 * Order profitability over a date range.
	 *
	 * @param string $date_from Y-m-d, inclusive.
	 * @param string $date_to   Y-m-d, inclusive.
	 * @return array<int,array{order_id:int,quantity:float,revenue:float,cost:float,uncosted_quantity:float,profit:float,margin:float|null}>
	 */
	public function order_rows( string $date_from, string $date_to ): array {
		return $this->aggregator->by_order( $this->build_lines( $date_from, $date_to ) );
	}

	/**
	 * Trailing-N-calendar-month trend, independent of whatever date range
	 * product_rows()/order_rows() are currently showing.
	 *
	 * @param int $months How many trailing calendar months to include, current month counted as one.
	 * @return array<int,array{month:string,quantity:float,revenue:float,cost:float,uncosted_quantity:float,profit:float,margin:float|null}>
	 */
	public function trend_rows( int $months = 12 ): array {
		$date_to   = current_time( 'Y-m-d' );
		$date_from = gmdate( 'Y-m-01', strtotime( current_time( 'mysql' ) . ' -' . ( max( 1, $months ) - 1 ) . ' months' ) );

		return $this->aggregator->by_month( $this->build_lines( $date_from, $date_to ) );
	}

	/**
	 * Builds realized, refund-netted line arrays for every costed order
	 * item on realized orders within the date range.
	 *
	 * @param string $date_from Y-m-d, inclusive.
	 * @param string $date_to   Y-m-d, inclusive.
	 * @return array<int,array{product_id:int,order_id:int,month:string,quantity:float,revenue:float,cost:float|null}>
	 */
	private function build_lines( string $date_from, string $date_to ): array {
		$orders = wc_get_orders(
			array(
				// 'shop_order_refund' is registered with
				// exclude_from_order_views => false, so it's included in
				// wc_get_order_types('view-orders') — wc_get_orders()'s own
				// default — and a refund's own status is always
				// 'wc-completed', which our REALIZED_STATUSES filter
				// happily matches. Without this explicit 'type', a WC_Order_
				// Refund object (not a WC_Order) leaks into $orders and
				// fatals the moment it's used as one.
				'type'         => 'shop_order',
				'status'       => self::REALIZED_STATUSES,
				'date_created' => $date_from . '...' . $date_to,
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$order_ids = array_map( static fn( WC_Order $order ): int => $order->get_id(), $orders );
		$costs     = $this->costs->for_order_ids( $order_ids );

		$lines = array();
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$month   = $created ? $created->date( 'Y-m' ) : '';

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$cost_row = $costs[ $item_id ] ?? null;
				if ( null === $cost_row ) {
					continue; // No frozen cost row — a standard-mode sale, not this plugin's data.
				}

				// Net per line using WooCommerce's own aggregate refund
				// accessors, both wrapped in abs() before arithmetic:
				// WooCommerce's own documentation disagrees with itself on
				// whether these return positive or negative numbers, so
				// abs()-then-subtract is correct regardless of which
				// convention the installed version actually uses.
				$qty_sold     = (float) $item->get_quantity();
				$qty_refunded = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
				$qty_net      = $qty_sold - $qty_refunded;

				if ( $qty_net <= 0.0 ) {
					continue; // Fully refunded — nothing realized, leave it out entirely.
				}

				$revenue_gross    = (float) $item->get_total();
				$revenue_refunded = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
				$revenue_net      = $revenue_gross - $revenue_refunded;

				// Cost reduces proportionally to the refunded quantity —
				// assumes the refunded unit was restocked (the common case
				// here, per Orders\RefundHandler's own existing assumption).
				$unit_cost = $cost_row->unit_cost;
				$cost_net  = null !== $unit_cost ? $unit_cost * $qty_net : null;

				$lines[] = array(
					'product_id' => $cost_row->product_id,
					'order_id'   => $order->get_id(),
					'month'      => $month,
					'quantity'   => $qty_net,
					'revenue'    => $revenue_net,
					'cost'       => $cost_net,
				);
			}
		}

		return $lines;
	}
}
