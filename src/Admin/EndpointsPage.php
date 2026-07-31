<?php
/**
 * "BOM & Stock → Endpoints" admin page.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Admin\Guide\ContextualHelp;

defined( 'ABSPATH' ) || exit;

/**
 * A one-page directory of every wcbom/v1 REST route, for the developer/developers
 * integrating against this plugin or debugging what's registered. Routes
 * are read live from `rest_get_server()->get_routes()` rather than
 * maintained as a hand-written list, so this can never drift out of sync
 * with what's actually registered — new endpoints just show up. The
 * description column is a small best-effort lookup, not a source of
 * truth; an undescribed route still appears, just without a blurb.
 */
final class EndpointsPage {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * The hook suffix WordPress assigns this submenu, captured from
	 * add_submenu_page()'s return value — see Admin\ManufacturePage's
	 * docblock on the same property for why.
	 *
	 * @var string|false|null
	 */
	private $hook_suffix;

	/**
	 * Hand-written descriptions for the routes worth explaining. Keyed by
	 * the exact route pattern WP_REST_Server reports. Deliberately not
	 * exhaustive — see the class docblock.
	 *
	 * @return array<string,string>
	 */
	private function descriptions(): array {
		return array(
			'/wcbom/v1/boms/(?P<product_id>\d+)'           => __( 'Read or save a product\'s BOM lines.', 'wcbom' ),
			'/wcbom/v1/buildable/(?P<product_id>\d+)'      => __( 'Buildable quantity + cost preview for a product (always-lines only).', 'wcbom' ),
			'/wcbom/v1/components/search'                  => __( 'Search products flagged as components, by title.', 'wcbom' ),
			'/wcbom/v1/components'                         => __( 'Quick-create a hidden component product.', 'wcbom' ),
			'/wcbom/v1/inventory'                          => __( 'List components with on-hand stock, BOM usage, last movement.', 'wcbom' ),
			'/wcbom/v1/inventory/receive'                  => __( 'Additive stock receipt.', 'wcbom' ),
			'/wcbom/v1/inventory/count'                    => __( 'Cycle count — enter the absolute counted number.', 'wcbom' ),
			'/wcbom/v1/inventory/adjust'                   => __( 'Signed manual adjustment with a required note.', 'wcbom' ),
			'/wcbom/v1/sample-data/install'                => __( 'Install the sample tumbler catalog.', 'wcbom' ),
			'/wcbom/v1/sample-data/remove'                 => __( 'Remove the sample tumbler catalog.', 'wcbom' ),
			'/wcbom/v1/manufacture-orders'                 => __( 'List or create manufacture orders.', 'wcbom' ),
			'/wcbom/v1/manufacture-orders/(?P<id>\d+)'     => __( 'Get or delete one manufacture order.', 'wcbom' ),
			'/wcbom/v1/manufacture-orders/(?P<id>\d+)/complete' => __( 'Complete a draft manufacture order.', 'wcbom' ),
			'/wcbom/v1/manufacture-orders/(?P<id>\d+)/reverse' => __( 'Reverse (disassemble) a completed manufacture order.', 'wcbom' ),
			'/wcbom/v1/manufacture/templates'              => __( 'Made-to-order products usable as a "new from template" MO source.', 'wcbom' ),
			'/wcbom/v1/manufacture/existing'               => __( 'Products with a BOM, usable as a "restock existing" MO target.', 'wcbom' ),
			'/wcbom/v1/reports/buildable'                  => __( 'Buildable-stock report: every made-to-order product\'s bottleneck.', 'wcbom' ),
			'/wcbom/v1/reports/low-stock'                  => __( 'Components at/below their low-stock threshold, and what they block.', 'wcbom' ),
			'/wcbom/v1/reports/margin'                     => __( 'BOM-derived cost vs. price per finished good/variation.', 'wcbom' ),
			'/wcbom/v1/reports/usage'                      => __( 'Usage/run-rate report for every component.', 'wcbom' ),
			'/wcbom/v1/reports/usage/(?P<component_id>\d+)' => __( 'Usage/run-rate report for one component.', 'wcbom' ),
			'/wcbom/v1/ledger'                             => __( 'Filtered, paginated stock-ledger rows.', 'wcbom' ),
			'/wcbom/v1/vendors'                            => __( 'List or create vendors (only registered when Vendors & Purchase Orders is enabled).', 'wcbom' ),
			'/wcbom/v1/vendors/(?P<id>\d+)'                => __( 'Update or archive one vendor.', 'wcbom' ),
			'/wcbom/v1/purchase-orders'                    => __( 'List or create purchase orders (draft).', 'wcbom' ),
			'/wcbom/v1/purchase-orders/(?P<id>\d+)'        => __( 'Get, update (draft only), or delete (draft only) one purchase order.', 'wcbom' ),
			'/wcbom/v1/purchase-orders/(?P<id>\d+)/costs'  => __( 'Set freight/tax/fees for landed-cost allocation.', 'wcbom' ),
			'/wcbom/v1/purchase-orders/(?P<id>\d+)/place'  => __( 'Place a draft order with the vendor (locks lines).', 'wcbom' ),
			'/wcbom/v1/purchase-orders/(?P<id>\d+)/receive' => __( 'Record a receipt against one or more lines.', 'wcbom' ),
			'/wcbom/v1/purchase-orders/(?P<id>\d+)/cancel' => __( 'Cancel (or close, once partially received) a purchase order.', 'wcbom' ),
			'/wcbom/v1/purchase-orders/(?P<id>\d+)/send'   => __( 'Email the purchase order to the vendor and/or the current user.', 'wcbom' ),
		);
	}

	/**
	 * Hooks the admin menu callback.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Adds the page under the plugin's own top-level menu.
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_submenu_page(
			PluginMenu::SLUG,
			__( 'Endpoints', 'wcbom' ),
			__( 'Endpoints', 'wcbom' ),
			'manage_woocommerce',
			'wcbom-endpoints',
			array( $this, 'render_page' )
		);

		ContextualHelp::attach( $this->hook_suffix, 'for-developers' );
	}

	/**
	 * Renders the route table.
	 */
	public function render_page(): void {
		$descriptions = $this->descriptions();
		$rest_url     = trailingslashit( get_rest_url() ) . self::NAMESPACE;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'wc-bom-stock Endpoints', 'wcbom' ) . '</h1>';
		echo '<p>' . sprintf(
			/* translators: %s: REST API base URL */
			esc_html__( 'Every REST route this plugin registers, read live from the REST server — nothing here is hand-maintained, so it can\'t drift out of date. Base URL: %s', 'wcbom' ),
			'<code>' . esc_html( $rest_url ) . '</code>'
		) . '</p>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th style="width:110px">' . esc_html__( 'Methods', 'wcbom' ) . '</th>';
		echo '<th>' . esc_html__( 'Route', 'wcbom' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'wcbom' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->routes() as $route => $methods ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( implode( ', ', $methods ) ) . '</code></td>';
			echo '<td><code>' . esc_html( $route ) . '</code></td>';
			echo '<td>' . esc_html( $descriptions[ $route ] ?? '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Every registered wcbom/v1 route with its HTTP methods, sorted for
	 * readable grouping.
	 *
	 * @return array<string,array<int,string>> Route => list of HTTP methods.
	 */
	private function routes(): array {
		$server = rest_get_server();
		$routes = array();

		foreach ( $server->get_routes( self::NAMESPACE ) as $route => $handlers ) {
			$methods = array();
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $method ) {
					$methods[ $method ] = true;
				}
			}

			$routes[ $route ] = array_keys( $methods );
		}

		ksort( $routes );

		return $routes;
	}
}
