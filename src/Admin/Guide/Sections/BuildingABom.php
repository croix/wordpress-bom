<?php
/**
 * Guide section: Building a BOM.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The product BOM tab: lines, conditions, surcharge, weight, COGS,
 * sub-assemblies.
 */
final class BuildingABom {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'building-a-bom',
			title: __( 'Building a BOM', 'wcbom' ),
			body: '<p>' . __( 'Open any product for editing and use its "Bill of Materials" tab to add lines. Each line picks a component (searched by name, or created inline if it does not exist yet) and a quantity. A live "buildable" count and cost estimate update as you edit.', 'wcbom' ) . '</p>'
				. '<p>' . __( 'Each line has a condition:', 'wcbom' ) . '</p>'
				. '<ul>'
				. '<li>' . __( '<strong>Always</strong> — consumed on every sale, regardless of what the customer chose. The blank tumbler, the epoxy, the standard cap.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Attribute</strong> — only consumed when a specific variation attribute is selected (e.g. glitter color = Blue). Pick the attribute and value from dropdowns populated from the product\'s own variations.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Add-on</strong> — only consumed when a specific Extra Product Options field has a specific value (e.g. "Add Stickers" = Yes).', 'wcbom' ) . '</li>'
				. '</ul>'
				. '<p>' . __( 'An attribute-conditional line can also carry an optional <strong>surcharge</strong> — an extra amount added to the cart price whenever that line matches, for choices that cost more but do not already have their own variation price. A choice should price through <em>either</em> its variation <em>or</em> a surcharge, never both; the editor warns when a line looks like it is doing both.', 'wcbom' ) . '</p>'
				. '<p>' . __( 'The <strong>"Weight from BOM"</strong> checkbox (next to the unit field) makes the cart-item shipping weight the sum of every matched line\'s component weight, instead of the product\'s own stored weight — so a heavier upgrade (like a metal straw) is reflected automatically, with no per-variation weight to maintain by hand.', 'wcbom' ) . '</p>'
				. '<p>' . __( 'The <strong>"Cost of Goods Sold from BOM"</strong> checkbox feeds WooCommerce\'s own native Cost of Goods Sold feature (when that feature is turned on in WooCommerce itself) with this product\'s BOM-derived cost, instead of the number typed into WooCommerce\'s own Cost field. That field will look empty even with the toggle on — that is expected: it shows the value <em>you typed</em>, while the toggle supplies the value WooCommerce actually <em>uses</em>, computed fresh every time so it can never go stale. See "Reports" for where that number is used.', 'wcbom' ) . '</p>'
				. '<p>' . __( '<strong>Saving always creates a new version</strong> of the BOM rather than editing the old one in place. Past orders and manufacture orders keep a snapshot of the recipe as it existed when they happened, so editing a recipe today never rewrites history — a return processed next month still restores exactly what was actually consumed, not whatever the recipe has since become.', 'wcbom' ) . '</p>'
				. '<p>' . __( 'A BOM line can point at another product this store <em>manufactures</em> (a "sub-assembly") instead of a raw material — a batch of pre-glittered blanks, for example, built once via a Manufacture Order and then drawn on by several finished products\' recipes. A line cannot point at a <em>made-to-order</em> product: made-to-order stock is itself computed from a recipe, so using one as a component would mix a real count with a derived guess, and a recipe that looped back on itself would never resolve. Saving a BOM that would create such a loop, directly or through several products, is rejected with a clear message.', 'wcbom' ) . '</p>',
			screenshots: array(
				array(
					'file' => 'bom-editor-lines.png',
					'alt'  => __( 'The Bill of Materials tab: the Weight from BOM and Cost of Goods Sold from BOM checkboxes, and the BOM lines with a surcharge, always/attribute conditions, and a live buildable/cost estimate.', 'wcbom' ),
				),
				array(
					'file' => 'bom-editor-subassembly.png',
					'alt'  => __( 'A BOM line whose component is a manufactured sub-assembly product.', 'wcbom' ),
				),
			),
			covers_routes: array(
				'/wcbom/v1/boms/(?P<product_id>\d+)',
				'/wcbom/v1/buildable/(?P<product_id>\d+)',
				'/wcbom/v1/components/search',
			)
		);
	}
}
