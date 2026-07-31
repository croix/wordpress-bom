<?php
/**
 * Guide section: Companion plugins.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * ThemeHigh Extra Product Options and Variation Swatches setup, and the
 * only place a third-party video link belongs (see class docblock notes
 * in BUILD_PLAN.md §5.12 for why it is linked, never embedded).
 */
final class CompanionPlugins {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'companion-plugins',
			title: __( 'Companion plugins', 'wcbom' ),
			body: '<p>' . __( 'This plugin works fully on its own — these are recommended, not required, and a dismissible notice on the plugins screen offers one-click install links for either.', 'wcbom' ) . '</p>'
				. '<ul>'
				. '<li>' . __( '<strong>Variation Swatches for WooCommerce</strong> — turns plain variation dropdowns (color, size) into swatch buttons on the storefront. Purely visual; this plugin\'s conditional BOM lines work identically with or without it.', 'wcbom' ) . '</li>'
				. '<li>' . __( '<strong>Extra Product Options (ThemeHigh)</strong> — adds text personalization, uploads, and add-on checkboxes to a product page. Its fields are what "add-on" conditioned BOM lines match against (see "Building a BOM").', 'wcbom' ) . '</li>'
				. '</ul>'
				. '<p>' . __( 'ThemeHigh publishes a short video tutorial for their plugin on YouTube, linked below (only shown here when that plugin is active) — it teaches their plugin, not this one, so it is a pointer rather than part of this guide\'s own material. Variation Swatches has no equivalent video inside its own admin screens to point to.', 'wcbom' ) . '</p>',
			links: array(
				array(
					'label'           => __( 'ThemeHigh: Video Tutorial (YouTube, opens in a new tab)', 'wcbom' ),
					'url'             => 'https://www.youtube.com/watch?v=YoVPQhdwuis',
					'requires_plugin' => 'woo-extra-product-options/woo-extra-product-options.php',
					'external'        => true,
				),
			)
		);
	}
}
