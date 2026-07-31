<?php
/**
 * Guide section: Orientation.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The core mental model, read first.
 */
final class Orientation {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'orientation',
			title: __( 'Orientation', 'wcbom' ),
			body: '<p>' . __( 'This plugin adds Bill-of-Materials (recipe) and component-level stock tracking to WooCommerce. Three ideas explain almost everything else in this guide:', 'wcbom' ) . '</p>'
				. '<ul>'
				. '<li>' . __( '<strong>Components are ordinary WooCommerce products.</strong> A blank tumbler, a jar of glitter, a box of caps — each is a product, flagged as a component so it can be picked when building a recipe. There is no separate parts database to keep in sync.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>A BOM is a recipe.</strong> It lists which components (and how many of each) a finished product consumes. Some lines always apply; others only apply for a specific variation or add-on choice — see "Building a BOM".', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Every product has one of three modes.</strong> <em>Standard</em> products are unaffected by this plugin. <em>Made-to-order</em> products have no stock of their own — their "buildable" quantity is computed live from their recipe\'s components, and building one at sale time consumes those components. <em>Manufactured</em> products are batch-built ahead of time via a Manufacture Order into real, sellable stock (like a normal product), and that batch build is what consumes components.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Every stock change is recorded.</strong> A sale, a refund, a manufacture order, a manual count — all of it writes a row to one ledger, so "why is this number what it is" always has an answer.', 'wcbom' ) . '</li>'
				. '</ul>'
				. '<p>' . __( 'Everything this plugin adds lives under one "BOM & Stock" menu in the WordPress admin sidebar.', 'wcbom' ) . '</p>',
			screenshots: array(
				array(
					'file' => 'orientation-menu.png',
					'alt'  => __( 'The BOM & Stock admin menu, showing Inventory, Manufacturing, Reports, Endpoints, and Settings.', 'wcbom' ),
				),
			),
			links: array(
				array(
					'label' => __( 'Open BOM & Stock', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom' ),
				),
			)
		);
	}
}
