<?php
/**
 * Guide section: Troubleshooting & recovery.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI audit, oversell flags, stock drift.
 */
final class Troubleshooting {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'troubleshooting',
			title: __( 'Troubleshooting & recovery', 'wcbom' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'An order note reading <strong>"⚠ [SHORTAGE]"</strong> means that order\'s components were consumed even though one or more ran short — the sale itself was never blocked, since refusing a paid order would be worse than a stock discrepancy, but the physical inventory should be checked against what the order claims it needed.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'For anything else that looks off — a stock number that does not match the ledger, an order missing its consumption record, a manufacture order stuck in draft — run the audit command from the site\'s command line (via WP-CLI):', 'wcbom' ) . '</p>'
						. '<pre>wp wcbom audit</pre>'
						. '<p>' . __( 'It reports drift without changing anything by default. Add <code>--fix</code> to have it repair what it safely can (for example, rebuilding a missing order consumption snapshot from the ledger rows that prove what actually happened) — it only ever acts when it can do so without guessing.', 'wcbom' ) . '</p>'
				),
			),
			covers_commands: array( 'audit' )
		);
	}
}
