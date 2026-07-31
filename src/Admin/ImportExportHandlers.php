<?php
/**
 * Admin-post.php handlers for CSV downloads/uploads.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Bom\BomCsv;
use WCBOM\Stock\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * A file download/upload doesn't fit the JSON-response REST shape, so
 * these go through WordPress's standard admin-post.php mechanism instead
 * (BUILD_PLAN.md §5.8): BOM export/import and the ledger's CSV export.
 * Every action is capability- and nonce-gated identically to the REST
 * routes, just via check_admin_referer() instead of a REST nonce header.
 */
final class ImportExportHandlers {

	/**
	 * The nonce action shared with Admin\ReportsPage, which builds the
	 * download link and upload form these handlers verify.
	 */
	public const NONCE_ACTION = 'wcbom_import_export';

	/**
	 * Constructs the handlers.
	 *
	 * @param BomCsv $bom_csv BOM CSV import/export.
	 * @param Ledger $ledger  Ledger query, for CSV export.
	 */
	public function __construct(
		private readonly BomCsv $bom_csv,
		private readonly Ledger $ledger
	) {}

	/**
	 * Hooks the admin-post actions and the deferred-warning admin notice.
	 */
	public function register(): void {
		add_action( 'admin_post_wcbom_export_boms', array( $this, 'export_boms' ) );
		add_action( 'admin_post_wcbom_import_boms', array( $this, 'import_boms' ) );
		add_action( 'admin_post_wcbom_export_ledger', array( $this, 'export_ledger' ) );
		add_action( 'admin_notices', array( $this, 'render_deferred_notice' ) );
	}

	/**
	 * Downloads every active BOM as CSV.
	 */
	public function export_boms(): void {
		$this->guard();

		$result = $this->bom_csv->export();

		if ( array() !== $result['warnings'] ) {
			$this->defer_notice( $result['warnings'], 'warning' );
		}

		$this->stream_csv( $result['csv'], 'wcbom-boms-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Imports an uploaded BOM CSV, then redirects back to the Reports
	 * screen with a summary shown via a deferred admin notice.
	 */
	public function import_boms(): void {
		$this->guard();

		$redirect_to = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wcbom-reports' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_admin_referer() in guard() above.
		$file  = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error || ! isset( $file['tmp_name'] ) ) {
			$this->defer_notice( array( __( 'No file was uploaded, or the upload failed.', 'pv-bom-stock' ) ), 'error' );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$tmp_name = sanitize_text_field( wp_unslash( (string) $file['tmp_name'] ) );

		// Defense in depth beyond the nonce check above: refuse to read
		// anything that isn't a file PHP itself just wrote as part of
		// handling *this* upload, in case $tmp_name were ever forged.
		if ( ! is_uploaded_file( $tmp_name ) ) {
			$this->defer_notice( array( __( 'Could not read the uploaded file.', 'pv-bom-stock' ) ), 'error' );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$content = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading PHP's own upload tmp file, verified via is_uploaded_file() above, not a remote/user-supplied path.
		if ( false === $content ) {
			$this->defer_notice( array( __( 'Could not read the uploaded file.', 'pv-bom-stock' ) ), 'error' );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$result = $this->bom_csv->import( $content, get_current_user_id() );

		$messages = array();
		if ( array() !== $result['updated'] ) {
			$messages[] = sprintf(
				/* translators: %s: comma-separated product names */
				__( 'Updated BOMs for: %s.', 'pv-bom-stock' ),
				implode( ', ', $result['updated'] )
			);
		}
		$messages = array_merge( $messages, $result['errors'] );

		if ( array() === $messages ) {
			$messages[] = __( 'The file had no rows to import.', 'pv-bom-stock' );
		}

		$this->defer_notice( $messages, array() === $result['errors'] ? 'success' : 'warning' );

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Downloads ledger rows as CSV, honoring the same filters the Reports
	 * screen's ledger browser is currently showing.
	 */
	public function export_ledger(): void {
		$this->guard();

		$filters = array();
		foreach ( array( 'reason', 'ref_type', 'date_from', 'date_to' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() in guard() above.
				$filters[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		if ( isset( $_GET['product_id'] ) && '' !== $_GET['product_id'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filters['product_id'] = (int) $_GET['product_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$rows = $this->ledger->query( $filters, 0 );

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream, not real filesystem access.
		fputcsv( $fh, array( 'ledger_id', 'product', 'delta', 'stock_after', 'reason', 'ref_type', 'ref_id', 'note', 'created_at' ) );

		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $row['product_id'] );
			fputcsv(
				$fh,
				array(
					$row['ledger_id'],
					$product ? $product->get_name() : ( '#' . $row['product_id'] ),
					$row['delta'],
					$row['stock_after'] ?? '',
					$row['reason'],
					$row['ref_type'] ?? '',
					$row['ref_id'] ?? '',
					$row['note'] ?? '',
					$row['created_at'],
				)
			);
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the in-memory stream opened above.

		$this->stream_csv( false !== $csv ? $csv : '', 'wcbom-ledger-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Capability + nonce check shared by every handler above.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pv-bom-stock' ) );
		}

		check_admin_referer( self::NONCE_ACTION );
	}

	/**
	 * Sends CSV content as a file download and ends the request.
	 *
	 * @param string $csv      The CSV content.
	 * @param string $filename The suggested download filename.
	 */
	private function stream_csv( string $csv, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV file content is the deliberate response body, not HTML.
		exit;
	}

	/**
	 * Stashes messages to show as an admin notice on the next page load —
	 * used because export_boms() ends the request as a file download, and
	 * import_boms() redirects, so neither can render a notice directly.
	 *
	 * @param array<int,string> $messages Notice lines.
	 * @param string            $type    'success'|'warning'|'error'.
	 */
	private function defer_notice( array $messages, string $type ): void {
		set_transient(
			'wcbom_notice_' . get_current_user_id(),
			array(
				'messages' => $messages,
				'type'     => $type,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Renders (and clears) a deferred notice left by defer_notice().
	 */
	public function render_deferred_notice(): void {
		$key    = 'wcbom_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['messages'], $notice['type'] ) ) {
			return;
		}
		delete_transient( $key );

		printf( '<div class="notice notice-%s is-dismissible"><p>', esc_attr( (string) $notice['type'] ) );
		echo esc_html( implode( ' ', (array) $notice['messages'] ) );
		echo '</p></div>';
	}
}
