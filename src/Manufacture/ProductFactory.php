<?php
/**
 * Creates a manufactured-mode product listing from a made-to-order template.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Manufacture;

use WC_Product_Simple;
use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * "New product from template" (BUILD_PLAN.md §5.4): picks a made-to-order
 * template, resolves its BOM against a chosen attribute combination (reusing
 * the same ConditionMatcher::resolve_for_selection() the storefront uses at
 * add-to-cart time), and creates a new simple, manufactured-mode product
 * whose own BOM is that resolved combination — fixed and unconditional,
 * since the finished good no longer offers a choice.
 *
 * Deliberately created outside any atomic transaction (BUILD_PLAN.md
 * §13.6 rule 1): if this crashes partway, the result is at worst a
 * harmless 0-stock orphan product the merchant can see and delete —
 * unlike a stock movement, this failure mode is never silent or
 * financially wrong, so it doesn't need an idempotency key.
 */
final class ProductFactory {

	/**
	 * Constructs the factory.
	 *
	 * @param BomRepository    $boms    BOM lookup/save.
	 * @param ConditionMatcher $matcher Resolves the template's BOM against the chosen attributes.
	 */
	public function __construct(
		private readonly BomRepository $boms,
		private readonly ConditionMatcher $matcher
	) {}

	/**
	 * Creates the new product and its concrete BOM.
	 *
	 * @param int                  $template_product_id The made-to-order product to base this on.
	 * @param string               $title               New product title.
	 * @param string               $price                New product's regular price.
	 * @param array<string,string> $attributes           Taxonomy => term slug/name, the chosen combination.
	 * @return array{product_id:int,bom_id:int}
	 *
	 * @throws \RuntimeException If the template is invalid, has no active
	 *                           BOM, or the chosen attributes match no lines.
	 */
	public function create_from_template( int $template_product_id, string $title, string $price, array $attributes ): array {
		if ( ! wc_get_product( $template_product_id ) ) {
			throw new \RuntimeException( esc_html__( 'Unknown template product.', 'wcbom' ) );
		}

		$bom = $this->boms->get_active_for_product( $template_product_id );
		if ( null === $bom ) {
			throw new \RuntimeException( esc_html__( 'The template product has no active BOM.', 'wcbom' ) );
		}

		$lines = $this->matcher->resolve_for_selection( $bom, $attributes );
		if ( array() === $lines ) {
			throw new \RuntimeException( esc_html__( 'No BOM lines match the chosen options.', 'wcbom' ) );
		}

		$product_id = $this->create_product( $title, $price );
		$bom_id     = $this->save_resolved_bom( $product_id, $lines );

		return array(
			'product_id' => $product_id,
			'bom_id'     => $bom_id,
		);
	}

	/**
	 * Creates the new manufactured-mode product listing.
	 *
	 * @param string $title New product title.
	 * @param string $price New product's regular price.
	 */
	private function create_product( string $title, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_regular_price( $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		update_post_meta( $product_id, '_wcbom_mode', 'manufactured' );

		return $product_id;
	}

	/**
	 * Saves the resolved lines as the new product's own (unconditional) BOM.
	 *
	 * @param int                $product_id The new product's ID.
	 * @param array<int,BomItem> $lines      The resolved template lines.
	 */
	private function save_resolved_bom( int $product_id, array $lines ): int {
		$items = array_map(
			static fn( BomItem $line ): array => array(
				'component_id'    => $line->component_id,
				'qty'             => $line->qty,
				'condition_type'  => BomItem::CONDITION_ALWAYS,
				'condition_key'   => null,
				'condition_value' => null,
			),
			$lines
		);

		$bom = $this->boms->save( $product_id, $items, get_current_user_id() );

		return $bom->bom_id;
	}
}
