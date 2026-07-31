<?php
/**
 * Guide section: Selling made-to-order.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Buildable stock, add-to-cart blocking, consumption, restoration.
 */
final class SellingMadeToOrder {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'selling-made-to-order',
			title: __( 'Selling made-to-order products', 'wcbom' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'A made-to-order product\'s displayed stock is not a number you enter — it is computed from its recipe, as the largest quantity buildable from the components with the least stock relative to how much each one is needed ("always" lines only). This is what the storefront and shop grid show as its stock, kept up to date automatically whenever a component\'s stock changes, whether that change came from this plugin or a plain product edit.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'A shortage in an <em>attribute-conditional</em> component blocks only the specific variation that needs it — an out-of-stock upgraded strap does not take down every other option, only the one that requires it.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'When a customer completes an order, the matched BOM lines are resolved against exactly what they chose and each component\'s stock is reduced accordingly, in one transaction. What was consumed is recorded on the order itself, so cancelling or refunding with restock restores <em>exactly</em> that — even if the recipe has since been edited.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'If components run short after an order is already placed and paid, the sale is never blocked at that point — refusing a paid order would be worse than a stock discrepancy. Instead the components are consumed anyway (going negative if necessary), a clear warning is added to the order, and the shortage shows up in "Troubleshooting & recovery".', 'wcbom' ) . '</p>'
				),
			),
			links: array(),
			covers_pages: array()
		);
	}
}
