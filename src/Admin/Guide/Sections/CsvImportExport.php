<?php
/**
 * Guide section: CSV import/export.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin\Guide\Sections;

use WCBOM\Admin\Guide\Section;

defined( 'ABSPATH' ) || exit;

/**
 * SKU-keyed BOM CSV round-trip.
 */
final class CsvImportExport {

	/**
	 * Builds the section.
	 */
	public static function get(): Section {
		return new Section(
			id: 'csv-import-export',
			title: __( 'CSV import/export', 'wcbom' ),
			blocks: array(
				Section::text(
					'<p>' . __( 'The Reports screen can export every BOM to a CSV file, keyed by SKU rather than internal ID — so a file exported from one site (or environment) can be imported into another where the IDs differ, as long as the SKUs match.', 'wcbom' ) . '</p>'
						. '<p>' . __( 'Importing replaces the whole recipe for every parent SKU found in the file — the same full-replace behavior as saving in the BOM editor, and for the same reason: a partially-merged recipe is harder to reason about than a clean new version. If any row for a parent cannot be resolved (an unknown SKU, a row naming a made-to-order product as a component, or one that would create a recipe loop), that parent\'s entire group is skipped with a clear reason rather than importing an incomplete recipe — the rest of the file still imports normally.', 'wcbom' ) . '</p>'
				),
			),
			links: array(
				array(
					'label' => __( 'Open Reports (Import / Export)', 'wcbom' ),
					'url'   => admin_url( 'admin.php?page=wcbom-reports' ),
				),
			),
			covers_commands: array( 'import' )
		);
	}
}
