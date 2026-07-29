<?php
/**
 * Thrown when a stock adjustment would take a product below zero.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by StockService when an adjustment would take a product below
 * zero and negative stock isn't explicitly allowed for that call.
 */
final class InsufficientStockException extends \RuntimeException {

	/**
	 * Builds the exception message from the details of the failed adjustment.
	 *
	 * @param int   $product_id      The product that would go negative.
	 * @param float $available       Stock on hand before the adjustment.
	 * @param float $requested_delta The (negative) delta that was requested.
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly float $available,
		public readonly float $requested_delta
	) {
		parent::__construct(
			sprintf(
				'Product #%d has %s in stock, not enough for a change of %s.',
				$product_id,
				(string) $available,
				(string) $requested_delta
			)
		);
	}
}
