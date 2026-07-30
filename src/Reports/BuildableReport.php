<?php
/**
 * Buildable-stock report: every made-to-order product's bottleneck.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ProductMode;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.5: every made-to-order product, its bottleneck
 * component, and its buildable qty — the "what's actually limiting my
 * sellable stock" screen. Manufactured/premade products are deliberately
 * excluded: their displayed WC stock is already the real number (set by
 * completed manufacture orders or manual stock), so a "buildable" figure
 * would be redundant with what the Products list already shows.
 */
final class BuildableReport {

	/**
	 * Constructs the report.
	 *
	 * @param BomRepository $boms BOM lookup.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * One row per made-to-order product with an active BOM.
	 *
	 * @return array<int,array{product_id:int,name:string,buildable_qty:int,bottleneck:array{id:int,name:string}|null}>
	 */
	public function generate(): array {
		$rows = array();

		foreach ( ProductMode::products_with_mode( array( ProductMode::MADE_TO_ORDER ) ) as $product_id ) {
			$product = wc_get_product( $product_id );
			$bom     = $this->boms->get_active_for_product( $product_id );
			if ( ! $product || null === $bom ) {
				continue;
			}

			$buildable  = null;
			$bottleneck = null;

			foreach ( $bom->always_items() as $line ) {
				if ( $line->qty <= 0 ) {
					continue;
				}

				$component = wc_get_product( $line->component_id );
				if ( ! $component ) {
					continue;
				}

				$possible = (int) floor( (float) $component->get_stock_quantity() / $line->qty );
				if ( null === $buildable || $possible < $buildable ) {
					$buildable  = $possible;
					$bottleneck = array(
						'id'   => $component->get_id(),
						'name' => $component->get_name(),
					);
				}
			}

			$rows[] = array(
				'product_id'    => $product_id,
				'name'          => $product->get_name(),
				'buildable_qty' => $buildable ?? 0,
				'bottleneck'    => $bottleneck,
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['buildable_qty'] <=> $b['buildable_qty'] );

		return $rows;
	}
}
