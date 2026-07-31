<?php
/**
 * Shared factory helpers for pv-bom-stock's integration tests.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Manufacture\ManufactureService;
use WCBOM\Manufacture\ProductFactory;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

/**
 * Base test case: small, focused factories for the fixtures every §9
 * scenario needs (components, made-to-order products, orders), so each
 * test file reads as "given this recipe, when this happens, then..."
 * rather than repeating WC/BOM setup boilerplate.
 */
abstract class WCBOM_UnitTestCase extends WP_UnitTestCase {

	/**
	 * Creates a component product with real stock.
	 *
	 * @param string $name  Product name.
	 * @param int    $stock Starting stock quantity.
	 * @param string $unit  _wcbom_unit value.
	 * @param string $price Regular price (per-unit cost).
	 */
	protected function create_component( string $name, int $stock, string $unit = 'ea', string $price = '1.00' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_stock_status( 'instock' );
		$product->set_status( 'publish' );
		$id = $product->save();

		update_post_meta( $id, '_wcbom_is_component', 'yes' );
		update_post_meta( $id, '_wcbom_unit', $unit );

		return $id;
	}

	/**
	 * Creates a simple (non-variable) made-to-order product with an
	 * always-consume-only BOM — the common case most §9 scenarios need;
	 * variation-specific scenarios build their own attributes.
	 *
	 * @param string                                   $name  Product name.
	 * @param array<int,array{component_id:int,qty:float}> $lines Always-lines: component_id => qty.
	 * @param string                                   $price Regular price.
	 * @return array{product_id:int,bom_id:int}
	 */
	protected function create_made_to_order( string $name, array $lines, string $price = '19.99' ): array {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product_id = $product->save();

		update_post_meta( $product_id, '_wcbom_mode', 'made_to_order' );

		$items = array_map(
			static fn( array $line ): array => array(
				'component_id'    => $line['component_id'],
				'qty'             => $line['qty'],
				'condition_type'  => BomItem::CONDITION_ALWAYS,
				'condition_key'   => null,
				'condition_value' => null,
				'surcharge'       => null,
			),
			$lines
		);

		$bom = ( new BomRepository() )->save( $product_id, $items, 1 );

		return array(
			'product_id' => $product_id,
			'bom_id'     => $bom->bom_id,
		);
	}

	/**
	 * Creates a "manufactured" finished-good product with real managed
	 * stock (starting at 0) and an always-line BOM — the recipe a
	 * manufacture order consumes. Unlike create_made_to_order(), this
	 * product's own stock is a plain physical count, never the
	 * phantom/buildable number (that filter only applies to
	 * ProductMode::MADE_TO_ORDER products).
	 *
	 * @param string                                        $name  Product name.
	 * @param array<int,array{component_id:int,qty:float}>  $lines Always-lines: component_id => qty.
	 * @param string                                        $price Regular price.
	 * @return array{product_id:int,bom_id:int}
	 */
	protected function create_manufactured_product( string $name, array $lines, string $price = '19.99' ): array {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );
		$product_id = $product->save();

		update_post_meta( $product_id, '_wcbom_mode', 'manufactured' );

		$items = array_map(
			static fn( array $line ): array => array(
				'component_id'    => $line['component_id'],
				'qty'             => $line['qty'],
				'condition_type'  => BomItem::CONDITION_ALWAYS,
				'condition_key'   => null,
				'condition_value' => null,
				'surcharge'       => null,
			),
			$lines
		);

		$bom = ( new BomRepository() )->save( $product_id, $items, 1 );

		return array(
			'product_id' => $product_id,
			'bom_id'     => $bom->bom_id,
		);
	}

	/**
	 * Creates and places an order for a product, reducing stock via WC's
	 * own pending→processing transition — the same real hook path
	 * (`woocommerce_reduce_order_stock`) production checkout uses.
	 *
	 * @param int $product_id Product or variation ID.
	 * @param int $qty        Quantity ordered.
	 * @param array<string,string> $item_meta Optional visible order-item meta (e.g. EPO-style addon values).
	 */
	protected function place_order( int $product_id, int $qty, array $item_meta = array() ): WC_Order {
		$order = wc_create_order();
		$item_id = $order->add_product( wc_get_product( $product_id ), $qty );

		if ( array() !== $item_meta ) {
			$item = $order->get_item( $item_id );
			foreach ( $item_meta as $key => $value ) {
				$item->add_meta_data( $key, $value );
			}
			$item->save();
		}

		$order->calculate_totals();
		$order->update_status( 'processing' );

		// The in-memory $order's items are stale after update_status()'s
		// hooks ran against their own freshly-loaded instance (a gotcha
		// this project hit repeatedly in manual eval-script testing) —
		// always re-fetch before returning.
		return wc_get_order( $order->get_id() );
	}

	/**
	 * The current stock quantity for a product/variation.
	 */
	protected function stock_of( int $product_id ): float {
		return (float) wc_get_product( $product_id )->get_stock_quantity();
	}

	/**
	 * A freshly wired ManufactureService, built the same way Plugin::init()
	 * wires it — so tests exercise the real object graph, not a stand-in.
	 */
	protected function manufacture_service(): ManufactureService {
		$boms = new BomRepository();

		return new ManufactureService(
			new ManufactureRepository(),
			$boms,
			new StockService( new Ledger() ),
			new OperationGuard(),
			new ProductFactory( $boms, new ConditionMatcher() )
		);
	}
}
