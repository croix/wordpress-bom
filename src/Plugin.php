<?php
/**
 * Plugin service wiring root.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM;

use WCBOM\Admin\DeletionGuard;
use WCBOM\Admin\EndpointsPage;
use WCBOM\Admin\ImportExportHandlers;
use WCBOM\Admin\InventoryPage;
use WCBOM\Admin\ManufacturePage;
use WCBOM\Admin\PluginMenu;
use WCBOM\Admin\ProductBomMetabox;
use WCBOM\Admin\PurchasingPage;
use WCBOM\Admin\RecommendedPlugins;
use WCBOM\Admin\ReportsPage;
use WCBOM\Admin\SettingsPage;
use WCBOM\Bom\BomCsv;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Cart\CartPricing;
use WCBOM\Install\SampleData;
use WCBOM\Integrations\CogsProvider;
use WCBOM\Integrations\ThemeHighEpo;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Manufacture\ManufactureService;
use WCBOM\Manufacture\ProductFactory;
use WCBOM\Orders\OrderSync;
use WCBOM\Orders\RefundHandler;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\PurchaseOrderService;
use WCBOM\Purchasing\VendorRepository;
use WCBOM\Reports\BomCost;
use WCBOM\Reports\BuildableReport;
use WCBOM\Reports\ComponentUsageReport;
use WCBOM\Reports\LowStockDigest;
use WCBOM\Reports\LowStockReport;
use WCBOM\Reports\MarginReport;
use WCBOM\Rest\Api;
use WCBOM\Rest\InventoryApi;
use WCBOM\Rest\LedgerApi;
use WCBOM\Rest\ManufactureApi;
use WCBOM\Rest\PurchasingApi;
use WCBOM\Rest\ReportsApi;
use WCBOM\Rest\SampleDataApi;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\PhantomStock;
use WCBOM\Stock\StockService;
use WCBOM\Stock\StorefrontStock;
use WCBOM\Updates\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Service wiring root. Feature modules (ledger, BOM editor, order sync,
 * manufacture orders, admin screens) register themselves from here as
 * each phase lands.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the singleton instance, creating it on first call.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Wires up feature modules. Called from plugins_loaded once WooCommerce
	 * is confirmed active.
	 */
	public function init(): void {
		$ledger     = new Ledger();
		$boms       = new BomRepository();
		$stock      = new StockService( $ledger );
		$matcher    = new ConditionMatcher();
		$phantom    = new PhantomStock( $boms );
		$guard      = new OperationGuard();
		$mo_orders  = new ManufactureRepository();
		$factory    = new ProductFactory( $boms, $matcher );
		$mo_service = new ManufactureService( $mo_orders, $boms, $stock, $guard, $factory );

		$vendors    = new VendorRepository();
		$po_orders  = new PurchaseOrderRepository();
		$po_service = new PurchaseOrderService( $po_orders, $stock, $guard );

		$buildable_report = new BuildableReport( $boms );
		$usage_report     = new ComponentUsageReport( $boms, $ledger );
		$low_stock_report = new LowStockReport( $boms, $po_orders );
		$bom_cost         = new BomCost();
		$margin_report    = new MarginReport( $boms, $matcher, $bom_cost );
		$bom_csv          = new BomCsv( $boms );

		( new ProductBomMetabox( $boms, $matcher, $bom_cost, $mo_orders ) )->register();
		( new DeletionGuard( $boms ) )->register();
		( new OrderSync( $stock, $boms, $matcher ) )->register();
		( new RefundHandler( $stock ) )->register();
		( new ThemeHighEpo() )->register();
		( new CogsProvider( $boms, $matcher, $bom_cost, $mo_orders ) )->register();
		$phantom->register();
		( new StorefrontStock( $phantom, $boms, $matcher ) )->register();
		( new CartPricing( $boms, $matcher ) )->register();
		( new PluginMenu() )->register();
		( new InventoryPage() )->register();
		( new ManufacturePage() )->register();
		( new PurchasingPage() )->register();
		( new ReportsPage() )->register();
		( new EndpointsPage() )->register();
		( new ImportExportHandlers( $bom_csv, $ledger ) )->register();
		( new LowStockDigest( $low_stock_report ) )->register();
		( new SettingsPage() )->register();
		( new GitHubUpdater() )->register();
		( new RecommendedPlugins() )->register();

		add_action(
			'rest_api_init',
			static function () use ( $boms, $stock, $guard, $mo_orders, $mo_service, $vendors, $po_orders, $po_service, $ledger, $buildable_report, $usage_report, $low_stock_report, $margin_report ) {
				( new Api( $boms ) )->register_routes();
				( new InventoryApi( $stock, $boms, $guard, $po_orders ) )->register_routes();
				( new SampleDataApi( new SampleData() ) )->register_routes();
				( new ManufactureApi( $mo_service, $mo_orders, $boms ) )->register_routes();
				( new PurchasingApi( $po_service, $po_orders, $vendors ) )->register_routes();
				( new ReportsApi( $buildable_report, $usage_report, $low_stock_report, $margin_report ) )->register_routes();
				( new LedgerApi( $ledger ) )->register_routes();
			}
		);
	}
}
