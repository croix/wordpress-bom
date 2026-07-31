<?php
/**
 * Guide section: Manufacturing.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Draft/complete/reverse manufacture orders, scrap, sub-assemblies.
 */
final class Manufacturing {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'manufacturing',
			title: __( 'Manufacturing', 'pv-bom-stock' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'A Manufacture Order batch-converts components into finished stock — for example, 12 blank tumblers plus glitter and epoxy become a "Pink Glitter Tumbler" listing with stock of 12. Create one either against an existing product you want to restock, or as a brand-new listing built from a made-to-order product\'s recipe for one specific attribute combination.', 'pv-bom-stock' ) . '</p>'
				),
				Section::screenshot(
					'manufacture-list.png',
					__( 'The Manufacturing screen listing draft and completed manufacture orders.', 'pv-bom-stock' )
				),
				Section::text(
					'<p>' . __( 'A manufacture order starts as a <strong>draft</strong> — nothing has moved yet, and it can be edited or deleted freely. If any component would run short, completing shows exactly which lines and by how much, and asks for confirmation before building anyway. <strong>Completing</strong> reduces every component and increases the finished product\'s stock in one step, and records a snapshot of exactly what was built.', 'pv-bom-stock' ) . '</p>'
				),
				Section::screenshot(
					'manufacture-complete-shortage.png',
					__( 'The Complete manufacture order modal showing a component shortage table with a build-anyway override.', 'pv-bom-stock' )
				),
				Section::text(
					'<p>' . __( 'A completed order can be <strong>reversed</strong>, in whole or in part — undoing a build restores the components from that snapshot (not whatever the recipe has since become) and reduces the finished stock back down. Any component that was ruined during the build can be marked <strong>scrapped</strong> on reversal, so it is recorded as used rather than incorrectly restored.', 'pv-bom-stock' ) . '</p>'
				),
				Section::screenshot(
					'manufacture-reverse-scrap.png',
					__( 'The Reverse manufacture order modal with per-component scrap checkboxes.', 'pv-bom-stock' )
				),
				Section::text(
					'<p>' . __( 'A batch built this way can itself be used as a component in another product\'s recipe (a "sub-assembly") — see "Building a BOM" for the one restriction on what can be used this way.', 'pv-bom-stock' ) . '</p>'
				),
			),
			links: array(
				array(
					'label' => __( 'Open Manufacturing', 'pv-bom-stock' ),
					'url'   => admin_url( 'admin.php?page=wcbom-manufacturing' ),
				),
			),
			covers_pages: array( 'wcbom-manufacturing' ),
			covers_routes: array(
				'/wcbom/v1/manufacture-orders',
				'/wcbom/v1/manufacture-orders/(?P<id>\d+)',
				'/wcbom/v1/manufacture-orders/(?P<id>\d+)/complete',
				'/wcbom/v1/manufacture-orders/(?P<id>\d+)/reverse',
				'/wcbom/v1/manufacture/templates',
				'/wcbom/v1/manufacture/existing',
			)
		);
	}
}
