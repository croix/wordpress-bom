<?php
/**
 * Enforces BUILD_PLAN.md §5.12's coverage requirement: every registered
 * admin page, wcbom/v1 REST route, and `wp wcbom` CLI subcommand must map
 * to a documented Guide section. A future feature shipped without
 * documentation fails this suite — the same instinct as Admin\EndpointsPage
 * reading routes live instead of maintaining a hand-written list.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Admin\EndpointsPage;
use WCBOM\Admin\Guide\GuideContent;
use WCBOM\Admin\GuidePage;
use WCBOM\Admin\InventoryPage;
use WCBOM\Admin\ManufacturePage;
use WCBOM\Admin\PurchasingPage;
use WCBOM\Admin\ReportsPage;
use WCBOM\Admin\SettingsPage;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Cli\Commands;
use WCBOM\Install\SampleData;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Manufacture\ManufactureService;
use WCBOM\Manufacture\ProductFactory;
use WCBOM\Orders\OrderItemCostRepository;
use WCBOM\Purchasing\LandedCost;
use WCBOM\Purchasing\PurchaseOrderMailer;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\PurchaseOrderService;
use WCBOM\Purchasing\VendorRepository;
use WCBOM\Purchasing\VendorsFeature;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\BuildableReport;
use WCBOM\Reports\ComponentUsageReport;
use WCBOM\Reports\LowStockReport;
use WCBOM\Reports\MarginReport;
use WCBOM\Reports\ProfitabilityAggregator;
use WCBOM\Reports\ProfitabilityReport;
use WCBOM\Rest\Api;
use WCBOM\Rest\InventoryApi;
use WCBOM\Rest\LedgerApi;
use WCBOM\Rest\ManufactureApi;
use WCBOM\Rest\PurchasingApi;
use WCBOM\Rest\ReportsApi;
use WCBOM\Rest\SampleDataApi;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

final class GuideCoverageTest extends WCBOM_UnitTestCase {

	/**
	 * The Guide's own page never needs to document itself.
	 */
	private const EXCLUDED_PAGES = array( 'wcbom-guide' );

	/**
	 * The namespace index route is not a feature to document.
	 */
	private const EXCLUDED_ROUTES = array( '/wcbom/v1' );

	public function tear_down(): void {
		delete_option( VendorsFeature::OPTION );
		// rest_get_server() is a process-wide singleton that PHPUnit does
		// not reset between tests; registered_routes() above adds real
		// routes (including Vendors & Purchase Orders ones) to it, which
		// would otherwise leak into later tests (e.g. PurchasingTest's
		// "feature off" assertion that no vendor route exists). Unsetting
		// it forces rest_get_server() to build a fresh instance next call.
		unset( $GLOBALS['wp_rest_server'] );
		parent::tear_down();
	}

	public function test_every_admin_page_is_documented(): void {
		$covered = $this->covered( 'covers_pages' );

		foreach ( $this->registered_page_slugs() as $slug ) {
			if ( in_array( $slug, self::EXCLUDED_PAGES, true ) ) {
				continue;
			}

			$this->assertArrayHasKey( $slug, $covered, "Admin page '{$slug}' has no Guide section documenting it." );
		}
	}

	public function test_every_rest_route_is_documented(): void {
		$covered = $this->covered( 'covers_routes' );

		foreach ( $this->registered_routes() as $route ) {
			if ( in_array( $route, self::EXCLUDED_ROUTES, true ) ) {
				continue;
			}

			$this->assertArrayHasKey( $route, $covered, "REST route '{$route}' has no Guide section documenting it." );
		}
	}

	public function test_every_cli_command_is_documented(): void {
		$covered = $this->covered( 'covers_commands' );

		foreach ( $this->registered_commands() as $command ) {
			$this->assertArrayHasKey( $command, $covered, "wp wcbom {$command} has no Guide section documenting it." );
		}
	}

	/**
	 * Flattens one covers_* property across every Guide section into a
	 * lookup set.
	 *
	 * @param string $property Which Section property to flatten.
	 * @return array<string,true>
	 */
	private function covered( string $property ): array {
		$covered = array();
		foreach ( GuideContent::sections() as $section ) {
			foreach ( $section->$property as $value ) {
				$covered[ $value ] = true;
			}
		}

		return $covered;
	}

	/**
	 * Every admin page slug this plugin actually registers, discovered by
	 * calling each page class's own real add_menu_page() method directly
	 * (not by firing the global 'admin_menu' action, which also invokes
	 * WooCommerce's own admin_menu callbacks and is not safe to fire from
	 * this stripped-down test bootstrap). Requires a capable current user:
	 * WordPress's own add_submenu_page() silently no-ops for a user who
	 * lacks the page's capability.
	 *
	 * @return array<int,string>
	 */
	private function registered_page_slugs(): array {
		update_option( VendorsFeature::OPTION, 'yes' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		( new InventoryPage() )->add_menu_page();
		( new ManufacturePage() )->add_menu_page();
		( new PurchasingPage() )->add_menu_page();
		( new ReportsPage() )->add_menu_page();
		( new EndpointsPage() )->add_menu_page();
		( new GuidePage() )->add_menu_page();
		( new SettingsPage() )->add_menu_page();

		return array_values( array_unique( array_column( $GLOBALS['submenu']['wcbom'] ?? array(), 2 ) ) );
	}

	/**
	 * Every wcbom/v1 REST route this plugin actually registers — the same
	 * wiring Plugin::init()'s rest_api_init closure does, with the
	 * Vendors & Purchase Orders feature turned on so its routes are
	 * included too (Rest\PurchasingApi::register_routes() no-ops
	 * otherwise).
	 *
	 * @return array<int,string>
	 */
	private function registered_routes(): array {
		update_option( VendorsFeature::OPTION, 'yes' );
		// register_rest_route() outside the real rest_api_init action
		// (unavoidable in a CLI test) triggers WP's own doing-it-wrong
		// notice; the routes still register correctly regardless.
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		$ledger    = new Ledger();
		$boms      = new BomRepository();
		$matcher   = new ConditionMatcher();
		$stock     = new StockService( $ledger );
		$guard     = new OperationGuard();
		$mo_orders = new ManufactureRepository();
		$factory   = new ProductFactory( $boms, $matcher );
		$vendors   = new VendorRepository();
		$po_orders = new PurchaseOrderRepository();
		$bom_cost  = new BomCost();

		( new Api( $boms ) )->register_routes();
		( new InventoryApi( $stock, $boms, $guard, $po_orders ) )->register_routes();
		( new SampleDataApi( new SampleData() ) )->register_routes();
		( new ManufactureApi( new ManufactureService( $mo_orders, $boms, $stock, $guard, $factory ), $mo_orders, $boms ) )->register_routes();
		( new PurchasingApi( new PurchaseOrderService( $po_orders, $stock, $guard ), $po_orders, $vendors, new LandedCost(), new PurchaseOrderMailer( $vendors ) ) )->register_routes();
		$profitability_report = new ProfitabilityReport( new OrderItemCostRepository(), new ProfitabilityAggregator() );
		( new ReportsApi( new BuildableReport( $boms ), new ComponentUsageReport( $boms, $ledger ), new LowStockReport( $boms, $po_orders ), new MarginReport( $boms, $matcher, $bom_cost ), $profitability_report ) )->register_routes();
		( new LedgerApi( $ledger ) )->register_routes();

		return array_keys( rest_get_server()->get_routes( 'wcbom/v1' ) );
	}

	/**
	 * Every `wp wcbom` subcommand — Cli\Commands' public methods, exactly
	 * as WP_CLI::add_command() would discover them via reflection.
	 *
	 * @return array<int,string>
	 */
	private function registered_commands(): array {
		$commands = array();
		foreach ( ( new ReflectionClass( Commands::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->isStatic() || str_starts_with( $method->getName(), '__' ) ) {
				continue;
			}

			$commands[] = $method->getName();
		}

		return $commands;
	}
}
