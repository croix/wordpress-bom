<?php
/**
 * "BOM & Stock → Reports" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Admin\Guide\ContextualHelp;
use WCBOM\Admin\ImportExportHandlers as IE;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Reports submenu page: buildable/usage/low-stock/margin
 * reports plus the ledger browser (React, tabbed), and — above the React
 * mount, plain PHP forms since these are simple file download/upload, not
 * interactive data — the BOM CSV import/export actions (BUILD_PLAN.md
 * §5.5/§5.8).
 */
final class ReportsPage {

	/**
	 * The hook suffix WordPress assigns this submenu, captured from
	 * add_submenu_page()'s return value — see Admin\ManufacturePage's
	 * docblock on the same property for why this can't be hand-computed
	 * from the parent slug.
	 *
	 * @var string|false|null
	 */
	private $hook_suffix;

	/**
	 * Hooks the admin menu and enqueue callbacks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under the plugin's own top-level menu.
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_submenu_page(
			PluginMenu::SLUG,
			__( 'Reports', 'pv-bom-stock' ),
			__( 'Reports', 'pv-bom-stock' ),
			'manage_woocommerce',
			'wcbom-reports',
			array( $this, 'render_page' )
		);

		ContextualHelp::attach( $this->hook_suffix, 'reports' );
	}

	/**
	 * Renders the import/export forms and the React mount point.
	 */
	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Reports', 'pv-bom-stock' ) . '</h1>';

		$this->render_bom_csv_forms();

		echo '<div id="wcbom-reports-root"><p>' . esc_html__( 'Loading Reports…', 'pv-bom-stock' ) . '</p></div>';
		echo '</div>';
	}

	/**
	 * Renders the "download/upload BOMs CSV" section — plain HTML forms
	 * against admin-post.php, since a file download/upload doesn't need
	 * (or fit) the React data-display app below it.
	 */
	private function render_bom_csv_forms(): void {
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=wcbom_export_boms' ), IE::NONCE_ACTION );

		echo '<div class="card" style="max-width:none;padding:1em 2em;margin-bottom:1em;">';
		echo '<h2>' . esc_html__( 'Import / Export BOMs', 'pv-bom-stock' ) . '</h2>';
		echo '<p>' . esc_html__( 'CSV, keyed by SKU so a file exported from one site can be imported into another. Importing replaces the whole BOM for every parent SKU in the file — the same full-replace behavior as saving in the BOM editor.', 'pv-bom-stock' ) . '</p>';

		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download BOMs CSV', 'pv-bom-stock' ) . '</a></p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( IE::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="wcbom_import_boms" />';
		echo '<input type="file" name="file" accept=".csv" required="required" /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Upload BOMs CSV', 'pv-bom-stock' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Enqueues the React Reports app on its own admin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}

		$asset_file = WCBOM_PLUGIN_DIR . '/assets/build/reports/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'wcbom-reports',
			plugins_url( 'assets/build/reports/index.js', WCBOM_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( in_array( 'wp-components', $asset['dependencies'], true ) ) {
			wp_enqueue_style( 'wp-components' );
		}

		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
				wp_json_encode( wp_create_nonce( 'wp_rest' ) )
			),
			'after'
		);

		wp_localize_script(
			'wcbom-reports',
			'wcbomReports',
			array(
				'restNamespace'          => 'wcbom/v1',
				'ledgerExportUrl'        => wp_nonce_url( admin_url( 'admin-post.php?action=wcbom_export_ledger' ), IE::NONCE_ACTION ),
				'profitabilityExportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=wcbom_export_profitability' ), IE::NONCE_ACTION ),
			)
		);
	}
}
