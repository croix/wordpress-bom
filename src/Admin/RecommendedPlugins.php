<?php
/**
 * Recommends (but never requires) the companion customizer plugins.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a dismissible admin notice when the two companion plugins from the
 * customizer decision (BUILD_PLAN.md §10) aren't active, with one-click
 * install/activate links. Deliberately a recommendation, not a dependency:
 * the plugin is fully functional without them — swatches is purely
 * presentational, and EPO only matters for addon-conditional BOM lines —
 * so blocking activation on them (Requires Plugins style) would be wrong.
 * WooCommerce itself IS a hard dependency and is declared via the
 * `Requires Plugins: woocommerce` header instead.
 */
final class RecommendedPlugins {

	private const DISMISS_OPTION = 'wcbom_recommended_plugins_dismissed';
	private const DISMISS_ACTION = 'wcbom_dismiss_recommended';

	/**
	 * The recommended plugins: basename => display name.
	 * Slugs/paths match their wordpress.org distributions.
	 */
	private const RECOMMENDED = array(
		'woo-extra-product-options/woo-extra-product-options.php' => 'Extra Product Options for WooCommerce (ThemeHigh)',
		'variation-swatches-woo/variation-swatches-woo.php'       => 'Variation Swatches for WooCommerce',
	);

	/**
	 * Hooks the notice and its dismiss handler.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Renders the recommendation notice on relevant screens when at least
	 * one companion plugin is missing/inactive and it hasn't been dismissed.
	 */
	public function maybe_show_notice(): void {
		if ( ! current_user_can( 'install_plugins' ) || 'yes' === get_option( self::DISMISS_OPTION ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'woocommerce_page_wcbom-inventory', 'product' ), true ) ) {
			return;
		}

		$missing = $this->missing_plugins();
		if ( array() === $missing ) {
			return;
		}

		$items = array();
		foreach ( $missing as $basename => $name ) {
			$items[] = sprintf(
				'<strong>%s</strong> — <a href="%s">%s</a>',
				esc_html( $name ),
				esc_url( $this->action_url( $basename ) ),
				file_exists( WP_PLUGIN_DIR . '/' . $basename )
					? esc_html__( 'Activate', 'wcbom' )
					: esc_html__( 'Install', 'wcbom' )
			);
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ACTION, '1' ),
			self::DISMISS_ACTION
		);

		printf(
			'<div class="notice notice-info"><p>%s</p><p>%s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'BOM & Stock Manager works best with these free companion plugins: variation swatches give customers a visual option picker, and Extra Product Options powers add-on-conditional BOM lines (text personalization, upgrades). Both are optional — everything else works without them.', 'wcbom' ),
			wp_kses_post( implode( '<br>', $items ) ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice permanently', 'wcbom' )
		);
	}

	/**
	 * Persists the dismissal.
	 */
	public function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) {
			return;
		}

		check_admin_referer( self::DISMISS_ACTION );

		if ( current_user_can( 'install_plugins' ) ) {
			update_option( self::DISMISS_OPTION, 'yes' );
		}

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ACTION, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * The recommended plugins that aren't currently active.
	 *
	 * @return array<string,string> Basename => display name.
	 */
	private function missing_plugins(): array {
		$missing = array();
		foreach ( self::RECOMMENDED as $basename => $name ) {
			if ( ! is_plugin_active( $basename ) ) {
				$missing[ $basename ] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Core's one-click install URL for a missing plugin, or the activate
	 * URL when it's installed but inactive.
	 *
	 * @param string $basename Plugin basename (slug/file.php).
	 */
	private function action_url( string $basename ): string {
		if ( file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
			return wp_nonce_url(
				self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $basename ) ),
				'activate-plugin_' . $basename
			);
		}

		$slug = dirname( $basename );

		return wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ),
			'install-plugin_' . $slug
		);
	}
}
