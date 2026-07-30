<?php
/**
 * Order-driven component consumption and restoration.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Orders;

use WC_Order;
use WC_Order_Item_Product;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Bom\ProductMode;
use WCBOM\Stock\InsufficientStockException;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\StockService;

defined( 'ABSPATH' ) || exit;

/**
 * Consumes BOM components when WooCommerce reduces order stock, and
 * restores them when WooCommerce restores it (cancellation/failed).
 * Consumption is snapshotted to _wcbom_consumed order-item meta;
 * restoration reads only that snapshot — never the current BOM — so a
 * BOM edited after the sale can't corrupt the restore (hard rule).
 */
final class OrderSync {

	public const META_CONSUMED       = '_wcbom_consumed';
	public const META_RESTORED       = '_wcbom_restored';
	public const META_REFUNDED_UNITS = '_wcbom_refund_restored_units';

	/**
	 * Constructs the sync handler.
	 *
	 * @param StockService     $stock   The single stock-mutation path.
	 * @param BomRepository    $boms    BOM lookup.
	 * @param ConditionMatcher $matcher Resolves conditional lines per item.
	 */
	public function __construct(
		private readonly StockService $stock,
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher
	) {}

	/**
	 * Hooks WooCommerce's own stock reduce/restore events — consumption
	 * timing therefore always matches WC's (hold-stock, gateway, and
	 * checkout-type nuances included).
	 */
	public function register(): void {
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'consume_for_order' ) );
		add_action( 'woocommerce_restore_order_stock', array( $this, 'restore_for_order' ) );
	}

	/**
	 * Consumes components for every made-to-order item on the order.
	 *
	 * This hook runs inside checkout/payment flows, after the customer has
	 * paid — an unexpected failure here (e.g. a lock-wait timeout under
	 * load) must never fatal their order-received page. StockService has
	 * already rolled back atomically by the time anything is thrown, so on
	 * an unexpected error we log it, flag the order loudly, and let
	 * checkout complete; `wp wcbom audit` can reconcile later.
	 * (BUILD_PLAN.md §13.3)
	 *
	 * @param WC_Order $order The order whose stock WC just reduced.
	 */
	public function consume_for_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			try {
				$this->consume_item( $order, $item );
			} catch ( \Throwable $e ) {
				wc_get_logger()->error(
					sprintf( 'BOM consumption failed for order #%d item "%s": %s', $order->get_id(), $item->get_name(), $e->getMessage() ),
					array( 'source' => 'wcbom' )
				);
				$order->add_order_note(
					sprintf(
						/* translators: 1: order item name, 2: error message */
						__( '⚠ BOM: component consumption FAILED for "%1$s" (%2$s). No component stock was changed for this item — adjust inventory manually or run the audit.', 'wcbom' ),
						$item->get_name(),
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Consumes one order item's components (the per-item body of
	 * consume_for_order(), separated so its failures can be isolated).
	 *
	 * @param WC_Order              $order The order being consumed for.
	 * @param WC_Order_Item_Product $item  The line item to consume.
	 */
	private function consume_item( WC_Order $order, WC_Order_Item_Product $item ): void {
		if ( '' !== (string) $item->get_meta( self::META_CONSUMED ) ) {
			return; // Already consumed (idempotency belt-and-braces on top of WC's own reduced flag).
		}

		$product = $item->get_product();
		if ( ! $product || ProductMode::MADE_TO_ORDER !== ProductMode::resolve( $product->get_id() ) ) {
			return;
		}

		$bom = $this->boms->get_active_for_product( $product->get_id() );
		if ( null === $bom && $product->is_type( 'variation' ) ) {
			$bom = $this->boms->get_active_for_product( $product->get_parent_id() );
		}
		if ( null === $bom ) {
			return;
		}

		$lines = $this->matcher->resolve( $bom, $item );
		if ( array() === $lines ) {
			return;
		}

		$item_qty = (int) $item->get_quantity();
		$deltas   = array();
		$snapshot = array();

		foreach ( $lines as $line ) {
			if ( ! wc_get_product( $line->component_id ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: component product ID, 2: order item name */
						__( 'BOM warning: component #%1$d referenced by "%2$s" no longer exists — skipped.', 'wcbom' ),
						$line->component_id,
						$item->get_name()
					)
				);
				continue;
			}

			$deltas[ $line->component_id ] = ( $deltas[ $line->component_id ] ?? 0.0 ) - ( $line->qty * $item_qty );
			$snapshot[]                    = array(
				'component_id' => $line->component_id,
				'qty_per_unit' => $line->qty,
				'qty_total'    => $line->qty * $item_qty,
			);
		}

		if ( array() === $deltas ) {
			return;
		}

		$note = sprintf( 'Order #%d: %s ×%d', $order->get_id(), $item->get_name(), $item_qty );

		try {
			$this->stock->adjust_many( $deltas, Ledger::REASON_ORDER, 'wc_order', $order->get_id(), $note );
		} catch ( InsufficientStockException $e ) {
			// The order is already placed/paid — we can't refuse it here.
			// Consume anyway (going negative), and flag it loudly so the
			// merchant knows real-world stock didn't cover this sale.
			$this->stock->adjust_many( $deltas, Ledger::REASON_ORDER, 'wc_order', $order->get_id(), $note . ' [SHORTAGE]', true );
			$order->add_order_note(
				sprintf(
					/* translators: 1: order item name, 2: shortage details */
					__( '⚠ BOM component shortage while consuming for "%1$s": %2$s. Component stock has gone negative — check physical inventory.', 'wcbom' ),
					$item->get_name(),
					$e->getMessage()
				)
			);
		}

		$item->update_meta_data(
			self::META_CONSUMED,
			(string) wp_json_encode(
				array(
					'bom_id'     => $bom->bom_id,
					'item_qty'   => $item_qty,
					'components' => $snapshot,
				)
			)
		);
		$item->save();

		$order->add_order_note( $this->consumption_note( $item->get_name(), $snapshot ) );
	}

	/**
	 * Restores components for every previously-consumed item, from the
	 * consumption snapshot, net of anything a partial refund already
	 * restored. Idempotent via the _wcbom_restored flag.
	 *
	 * @param WC_Order $order The order whose stock WC just restored.
	 */
	public function restore_for_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$snapshot = self::read_snapshot( $item );
			if ( null === $snapshot || 'yes' === (string) $item->get_meta( self::META_RESTORED ) ) {
				continue;
			}

			$refunded_units = (float) $item->get_meta( self::META_REFUNDED_UNITS );
			$deltas         = array();

			foreach ( $snapshot['components'] as $component ) {
				$remaining = $component['qty_total'] - ( $component['qty_per_unit'] * $refunded_units );
				if ( $remaining <= 0 ) {
					continue;
				}
				$deltas[ $component['component_id'] ] = ( $deltas[ $component['component_id'] ] ?? 0.0 ) + $remaining;
			}

			if ( array() !== $deltas ) {
				$this->stock->adjust_many(
					$deltas,
					Ledger::REASON_ORDER_RESTORE,
					'wc_order',
					$order->get_id(),
					sprintf( 'Order #%d cancelled/restored: %s', $order->get_id(), $item->get_name() ),
					true
				);
			}

			$item->update_meta_data( self::META_RESTORED, 'yes' );
			$item->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: order item name */
					__( 'BOM: restored components for "%s" from consumption snapshot.', 'wcbom' ),
					$item->get_name()
				)
			);
		}
	}

	/**
	 * Decodes an item's consumption snapshot.
	 *
	 * @param WC_Order_Item_Product $item The order line item.
	 * @return array{bom_id:int,item_qty:int,components:array<int,array{component_id:int,qty_per_unit:float,qty_total:float}>}|null
	 */
	public static function read_snapshot( WC_Order_Item_Product $item ): ?array {
		$raw = (string) $item->get_meta( self::META_CONSUMED );
		if ( '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['components'] ) || ! is_array( $decoded['components'] ) ) {
			return null;
		}

		$components = array();
		foreach ( $decoded['components'] as $component ) {
			if ( ! isset( $component['component_id'], $component['qty_per_unit'], $component['qty_total'] ) ) {
				continue;
			}
			$components[] = array(
				'component_id' => (int) $component['component_id'],
				'qty_per_unit' => (float) $component['qty_per_unit'],
				'qty_total'    => (float) $component['qty_total'],
			);
		}

		return array(
			'bom_id'     => (int) ( $decoded['bom_id'] ?? 0 ),
			'item_qty'   => (int) ( $decoded['item_qty'] ?? 0 ),
			'components' => $components,
		);
	}

	/**
	 * A human-readable order note listing what was consumed.
	 *
	 * @param string                                                                $item_name The order item's display name.
	 * @param array<int,array{component_id:int,qty_per_unit:float,qty_total:float}> $snapshot Consumed components.
	 */
	private function consumption_note( string $item_name, array $snapshot ): string {
		$parts = array();
		foreach ( $snapshot as $component ) {
			$product = wc_get_product( $component['component_id'] );
			$unit    = (string) get_post_meta( $component['component_id'], '_wcbom_unit', true );
			$parts[] = sprintf(
				'%s× %s%s',
				rtrim( rtrim( number_format( $component['qty_total'], 4 ), '0' ), '.' ),
				$product ? $product->get_name() : "#{$component['component_id']}",
				'' !== $unit && 'ea' !== $unit ? " ({$unit})" : ''
			);
		}

		return sprintf(
			/* translators: 1: order item name, 2: comma-separated component list */
			__( 'BOM: consumed for "%1$s": %2$s.', 'wcbom' ),
			$item_name,
			implode( ', ', $parts )
		);
	}
}
