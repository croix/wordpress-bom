<?php
/**
 * CSV import/export for BOMs.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Bom;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.8: CSV round-trip of BOMs, keyed by SKU rather than
 * ID so a file exported from one environment (e.g. staging) can be
 * imported into another where product IDs differ. Columns: parent_sku,
 * component_sku, qty, condition_type, condition_key, condition_value,
 * surcharge. Shared by Rest\BomCsvApi (admin-post download/upload) and
 * `wp wcbom import` (CLI) so both go through identical parsing rules.
 */
final class BomCsv {

	private const HEADER = array( 'parent_sku', 'component_sku', 'qty', 'condition_type', 'condition_key', 'condition_value', 'surcharge' );

	/**
	 * Constructs the importer/exporter.
	 *
	 * @param BomRepository $boms BOM read/write.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * Every active BOM as CSV text. Products/components without a SKU are
	 * skipped (their row can never round-trip reliably across
	 * environments) with a note in the returned warnings list.
	 *
	 * @return array{csv:string,warnings:array<int,string>}
	 */
	public function export(): array {
		$warnings = array();
		$fh       = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream, not real filesystem access; WP_Filesystem doesn't do in-memory streams.
		if ( false === $fh ) {
			return array(
				'csv'      => '',
				'warnings' => array( __( 'Could not open a temporary stream for export.', 'wcbom' ) ),
			);
		}

		fputcsv( $fh, self::HEADER );

		foreach ( $this->boms->all_active() as $bom ) {
			$parent = wc_get_product( $bom->product_id );
			if ( ! $parent ) {
				continue;
			}

			$parent_sku = $parent->get_sku();
			if ( '' === $parent_sku ) {
				$warnings[] = sprintf(
					/* translators: %s: product name */
					__( 'Skipped "%s" — it has no SKU, so its BOM can\'t round-trip by SKU.', 'wcbom' ),
					$parent->get_name()
				);
				continue;
			}

			foreach ( $bom->items as $item ) {
				$component     = wc_get_product( $item->component_id );
				$component_sku = $component ? $component->get_sku() : '';
				if ( ! $component || '' === $component_sku ) {
					$warnings[] = sprintf(
						/* translators: 1: product name, 2: component name or ID */
						__( 'Skipped a line on "%1$s" — its component (%2$s) has no SKU.', 'wcbom' ),
						$parent->get_name(),
						$component ? $component->get_name() : '#' . $item->component_id
					);
					continue;
				}

				fputcsv(
					$fh,
					array(
						$parent_sku,
						$component_sku,
						(string) $item->qty,
						$item->condition_type,
						$item->condition_key ?? '',
						$item->condition_value ?? '',
						null !== $item->surcharge ? (string) $item->surcharge : '',
					)
				);
			}
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the in-memory stream opened above.

		return array(
			'csv'      => false !== $csv ? $csv : '',
			'warnings' => $warnings,
		);
	}

	/**
	 * Imports a CSV file: parses, groups by parent SKU, and saves one new
	 * BOM version per parent (a full replace, matching the BOM editor's
	 * own Save behavior — never a merge with the existing lines). If any
	 * row for a given parent fails to resolve, that parent's whole group
	 * is skipped rather than saved incomplete — a partially-wrong recipe
	 * is worse than an unchanged one.
	 *
	 * @param string $csv_content Raw CSV file content.
	 * @param int    $user_id     User performing the import.
	 * @return array{updated:array<int,string>,errors:array<int,string>}
	 */
	public function import( string $csv_content, int $user_id ): array {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream, not real filesystem access.
		if ( false === $fh ) {
			return array(
				'updated' => array(),
				'errors'  => array( __( 'Could not open a temporary stream for import.', 'wcbom' ) ),
			);
		}
		fwrite( $fh, $csv_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes to the in-memory stream above.
		rewind( $fh );

		fgetcsv( $fh ); // Header row, discarded — columns are positional, not name-matched.
		$groups      = array();
		$line_number = 1;

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the standard fgetcsv() read-until-false idiom.
		while ( false !== ( $row = fgetcsv( $fh ) ) ) {
			++$line_number;
			if ( count( $row ) < 3 || '' === trim( (string) $row[0] ) ) {
				continue; // Blank/short line — tolerate trailing blank lines.
			}

			$groups[ trim( (string) $row[0] ) ][] = array( 'line' => $line_number ) + array_combine(
				array_slice( self::HEADER, 1 ),
				array_pad( array_slice( $row, 1 ), count( self::HEADER ) - 1, '' )
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the in-memory stream opened above.

		$updated = array();
		$errors  = array();

		foreach ( $groups as $parent_sku => $rows ) {
			$result = $this->import_one( (string) $parent_sku, $rows, $user_id );
			if ( null !== $result['error'] ) {
				$errors[] = $result['error'];
			} else {
				$updated[] = $result['name'];
			}
		}

		return array(
			'updated' => $updated,
			'errors'  => $errors,
		);
	}

	/**
	 * Resolves and saves one parent's BOM from its group of CSV rows.
	 *
	 * @param string                              $parent_sku The parent product's SKU.
	 * @param array<int,array<string,string|int>> $rows       This parent's CSV rows.
	 * @param int                                 $user_id    User performing the import.
	 * @return array{name:?string,error:?string}
	 */
	private function import_one( string $parent_sku, array $rows, int $user_id ): array {
		$product_id = wc_get_product_id_by_sku( $parent_sku );
		if ( 0 === $product_id ) {
			return array(
				'name'  => null,
				'error' => sprintf(
					/* translators: %s: SKU */
					__( 'Row referencing parent SKU "%s": no product with that SKU exists — skipped.', 'wcbom' ),
					$parent_sku
				),
			);
		}

		$items = array();
		foreach ( $rows as $row ) {
			$component_id = wc_get_product_id_by_sku( (string) $row['component_sku'] );
			if ( 0 === $component_id ) {
				return array(
					'name'  => null,
					'error' => sprintf(
						/* translators: 1: line number, 2: component SKU, 3: parent SKU */
						__( 'Line %1$d: component SKU "%2$s" not found — "%3$s"\'s whole BOM was skipped (a partial import would be an incomplete recipe).', 'wcbom' ),
						$row['line'],
						$row['component_sku'],
						$parent_sku
					),
				);
			}

			$condition_type = (string) $row['condition_type'];
			if ( ! in_array( $condition_type, array( BomItem::CONDITION_ALWAYS, BomItem::CONDITION_ATTRIBUTE, BomItem::CONDITION_ADDON ), true ) ) {
				return array(
					'name'  => null,
					'error' => sprintf(
						/* translators: 1: line number, 2: condition_type value, 3: parent SKU */
						__( 'Line %1$d: invalid condition_type "%2$s" — "%3$s"\'s whole BOM was skipped.', 'wcbom' ),
						$row['line'],
						$condition_type,
						$parent_sku
					),
				);
			}

			$surcharge_raw = trim( (string) $row['surcharge'] );

			$items[] = array(
				'component_id'    => $component_id,
				'qty'             => (float) $row['qty'],
				'condition_type'  => $condition_type,
				'condition_key'   => BomItem::CONDITION_ALWAYS === $condition_type ? null : sanitize_key( (string) $row['condition_key'] ),
				'condition_value' => BomItem::CONDITION_ALWAYS === $condition_type ? null : sanitize_title( (string) $row['condition_value'] ),
				'surcharge'       => '' !== $surcharge_raw ? (float) $surcharge_raw : null,
			);
		}

		if ( array() === $items ) {
			return array(
				'name'  => null,
				'error' => sprintf(
					/* translators: %s: parent SKU */
					__( 'Parent SKU "%s" had no valid lines — skipped.', 'wcbom' ),
					$parent_sku
				),
			);
		}

		try {
			$this->boms->save( $product_id, $items, $user_id );
		} catch ( \RuntimeException $e ) {
			return array(
				'name'  => null,
				'error' => sprintf(
					/* translators: 1: parent SKU, 2: rejection reason (already a full sentence) */
					__( '"%1$s"\'s whole BOM was skipped: %2$s', 'wcbom' ),
					$parent_sku,
					$e->getMessage()
				),
			);
		}

		$product = wc_get_product( $product_id );

		return array(
			'name'  => $product ? $product->get_name() : $parent_sku,
			'error' => null,
		);
	}
}
