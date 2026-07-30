<?php
/**
 * Proportional component restoration on restocking refunds.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Orders;

use WC_Order_Item_Product;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\StockService;

defined( 'ABSPATH' ) || exit;

/**
 * When a refund is created with "restock items" checked, restores each
 * refunded item's components proportionally (qty_per_unit × refunded
 * units), from the consumption snapshot. Cumulative refunded units are
 * tracked on the item so repeated partial refunds can never over-restore.
 */
final class RefundHandler {

	/**
	 * Constructs the handler.
	 *
	 * @param StockService $stock The single stock-mutation path.
	 */
	public function __construct( private readonly StockService $stock ) {}

	/**
	 * Hooks refund creation.
	 */
	public function register(): void {
		add_action( 'woocommerce_refund_created', array( $this, 'restore_for_refund' ), 10, 2 );
	}

	/**
	 * Restores components for a restocking refund's line items.
	 *
	 * @param int                 $refund_id The refund order's ID.
	 * @param array<string,mixed> $args      wc_create_refund() args (restock_items, line_items).
	 */
	public function restore_for_refund( int $refund_id, array $args ): void {
		if ( empty( $args['restock_items'] ) || empty( $args['line_items'] ) || ! is_array( $args['line_items'] ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		$order  = $refund ? wc_get_order( $refund->get_parent_id() ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $args['line_items'] as $item_id => $line ) {
			$refunded_qty = isset( $line['qty'] ) ? (float) $line['qty'] : 0.0;
			if ( $refunded_qty <= 0 ) {
				continue;
			}

			$item = $order->get_item( (int) $item_id );
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$snapshot = OrderSync::read_snapshot( $item );
			if ( null === $snapshot || 'yes' === (string) $item->get_meta( OrderSync::META_RESTORED ) ) {
				continue;
			}

			// Cap at the units not yet restored by earlier refunds.
			$already_refunded = (float) $item->get_meta( OrderSync::META_REFUNDED_UNITS );
			$restorable_units = min( $refunded_qty, max( 0.0, $snapshot['item_qty'] - $already_refunded ) );
			if ( $restorable_units <= 0 ) {
				continue;
			}

			$deltas = array();
			foreach ( $snapshot['components'] as $component ) {
				$deltas[ $component['component_id'] ] = ( $deltas[ $component['component_id'] ] ?? 0.0 )
					+ ( $component['qty_per_unit'] * $restorable_units );
			}

			if ( array() !== $deltas ) {
				$this->stock->adjust_many(
					$deltas,
					Ledger::REASON_REFUND,
					'wc_order',
					$order->get_id(),
					sprintf( 'Order #%d refund #%d: %s ×%s restocked', $order->get_id(), $refund_id, $item->get_name(), rtrim( rtrim( number_format( $restorable_units, 2 ), '0' ), '.' ) ),
					true
				);
			}

			$item->update_meta_data( OrderSync::META_REFUNDED_UNITS, (string) ( $already_refunded + $restorable_units ) );
			$item->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: refunded quantity, 2: order item name */
					__( 'BOM: restored components for %1$s refunded unit(s) of "%2$s".', 'wcbom' ),
					rtrim( rtrim( number_format( $restorable_units, 2 ), '0' ), '.' ),
					$item->get_name()
				)
			);
		}
	}
}
