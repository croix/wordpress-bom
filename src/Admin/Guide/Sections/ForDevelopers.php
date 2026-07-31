<?php
/**
 * Guide section: For developers.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * REST API shape, WP-CLI commands, and the live Endpoints page.
 */
final class ForDevelopers {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'for-developers',
			title: __( 'For developers', 'wcbom' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'Every feature in this plugin is also a REST route under the <code>wcbom/v1</code> namespace, gated on the <code>manage_woocommerce</code> capability plus a standard WordPress REST nonce — the same routes the admin screens themselves call. Rather than maintain a separate list here (which would drift the moment a route changes), the Endpoints page reads the real, currently-registered route list live from the REST server, so it can never fall out of date.', 'wcbom' ) . '</p>'
				),
				Section::screenshot(
					'endpoints-page.png',
					__( 'The Endpoints page listing every registered wcbom/v1 REST route live.', 'wcbom' )
				),
				Section::text(
					'<p>' . __( 'A handful of <code>wp wcbom</code> WP-CLI subcommands are also available for scripting and recovery: <code>seed</code> (install/reset the sample catalog), <code>audit</code> (detect stock/BOM/order drift), <code>recompute</code> (rebuild the buildable-stock cache), and <code>import</code> (CSV BOM import). See "Troubleshooting & recovery" for <code>audit</code> in particular.', 'wcbom' ) . '</p>'
				),
			),
			links: array(
				array(
					'label' => __( 'Open Endpoints', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom-endpoints' ),
				),
			),
			covers_pages: array( 'wcbom-endpoints' ),
			covers_commands: array( 'recompute' )
		);
	}
}
