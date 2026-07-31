<?php
/**
 * Guide section: What this plugin deliberately doesn't do.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Expectation-setting, to head off support questions about absent
 * features rather than let a new user assume something is broken.
 */
final class WhatThisPluginDoesnt {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'what-this-plugin-doesnt-do',
			title: __( 'What this plugin deliberately doesn\'t do', 'wcbom' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'Stated up front so these read as deliberate choices, not missing features:', 'wcbom' ) . '</p>'
						. '<ul>'
						. '<li>' . __( '<strong>No dimension stacking.</strong> Shipping weight can be built up from a recipe\'s components (see "Building a BOM"), but length/width/height come only from the product or variation\'s own stored dimensions — summing physical dimensions across components is not physically meaningful the way weight is.', 'wcbom' ) . '</li>'
						. '<li>' . __( '<strong>No live price preview on the product page.</strong> A surcharge shows as a static "(+$5)" label next to the option, not a running total that updates as choices are made.', 'wcbom' ) . '</li>'
						. '<li>' . __( '<strong>No "buildable-through" for nested sub-assemblies.</strong> A made-to-order product\'s buildable count reflects a sub-assembly\'s real on-hand stock, not what could theoretically be built from the raw materials behind it.', 'wcbom' ) . '</li>'
						. '<li><strong>' . __( 'No multi-warehouse or location stock, barcode scanning, or serial/lot tracking.', 'wcbom' ) . '</strong></li>'
						. '</ul>'
				),
			),
		);
	}
}
