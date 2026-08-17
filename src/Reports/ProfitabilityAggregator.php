<?php
/**
 * Pure grouping/arithmetic for the profitability reports.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.15: takes plain realized-line arrays (already
 * refund-netted, already looked up against the frozen cost snapshot) and
 * groups them — no WordPress or database calls, so this is the part that's
 * cheap and worthwhile to test exhaustively (Reports\ProfitabilityReport
 * does the WP-integration glue that builds these lines in the first place).
 *
 * "Uncosted is never zero" throughout: a line with cost === null contributes
 * to quantity/revenue but is left out of the cost sum entirely, and its
 * quantity is tracked separately as uncosted_quantity — never treated as a
 * $0 cost, which would silently inflate profit and margin.
 */
final class ProfitabilityAggregator {

	/**
	 * Groups realized lines by product.
	 *
	 * @param array<int,array{product_id:int,order_id:int,month:string,quantity:float,revenue:float,cost:float|null}> $lines Realized (refund-netted) lines.
	 * @return array<int,array{product_id:int,quantity:float,revenue:float,cost:float,uncosted_quantity:float,profit:float,margin:float|null}>
	 */
	public function by_product( array $lines ): array {
		return $this->group( $lines, 'product_id' );
	}

	/**
	 * Groups realized lines by order.
	 *
	 * @param array<int,array{product_id:int,order_id:int,month:string,quantity:float,revenue:float,cost:float|null}> $lines Realized (refund-netted) lines.
	 * @return array<int,array{order_id:int,quantity:float,revenue:float,cost:float,uncosted_quantity:float,profit:float,margin:float|null}>
	 */
	public function by_order( array $lines ): array {
		return $this->group( $lines, 'order_id' );
	}

	/**
	 * Groups realized lines by calendar month ('Y-m'), independent of
	 * whatever date range the product/order views are showing.
	 *
	 * @param array<int,array{product_id:int,order_id:int,month:string,quantity:float,revenue:float,cost:float|null}> $lines Realized (refund-netted) lines.
	 * @return array<int,array{month:string,quantity:float,revenue:float,cost:float,uncosted_quantity:float,profit:float,margin:float|null}>
	 */
	public function by_month( array $lines ): array {
		return $this->group( $lines, 'month' );
	}

	/**
	 * The shared grouping logic behind all three views.
	 *
	 * @param array<int,array{product_id:int,order_id:int,month:string,quantity:float,revenue:float,cost:float|null}> $lines Realized (refund-netted) lines.
	 * @param string                                                                                                  $key_field Which line field to group by ('product_id', 'order_id', or 'month').
	 * @return array<int,array<string,mixed>>
	 */
	private function group( array $lines, string $key_field ): array {
		$groups = array();

		foreach ( $lines as $line ) {
			$key = $line[ $key_field ];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					$key_field          => $key,
					'quantity'          => 0.0,
					'revenue'           => 0.0,
					'cost'              => 0.0,
					'uncosted_quantity' => 0.0,
				);
			}

			$groups[ $key ]['quantity'] += $line['quantity'];
			$groups[ $key ]['revenue']  += $line['revenue'];

			if ( null !== $line['cost'] ) {
				$groups[ $key ]['cost'] += $line['cost'];
			} else {
				$groups[ $key ]['uncosted_quantity'] += $line['quantity'];
			}
		}

		return array_values( array_map( array( $this, 'finalize_row' ), $groups ) );
	}

	/**
	 * Rounds the accumulated sums and computes profit/margin for one grouped row.
	 *
	 * @param array<string,mixed> $row A grouped row before profit/margin are added.
	 * @return array<string,mixed>
	 */
	private function finalize_row( array $row ): array {
		$profit = $row['revenue'] - $row['cost'];

		$row['quantity']          = round( $row['quantity'], 4 );
		$row['revenue']           = round( $row['revenue'], 4 );
		$row['cost']              = round( $row['cost'], 4 );
		$row['uncosted_quantity'] = round( $row['uncosted_quantity'], 4 );
		$row['profit']            = round( $profit, 4 );
		$row['margin']            = $row['revenue'] > 0 ? round( ( $profit / $row['revenue'] ) * 100, 1 ) : null;

		return $row;
	}
}
