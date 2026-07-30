<?php
/**
 * Plugin service wiring root.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM;

use WCBOM\Admin\DeletionGuard;
use WCBOM\Admin\InventoryPage;
use WCBOM\Admin\ProductBomMetabox;
use WCBOM\Admin\RecommendedPlugins;
use WCBOM\Admin\Settings;
use WCBOM\Bom\BomRepository;
use WCBOM\Bom\ConditionMatcher;
use WCBOM\Install\SampleData;
use WCBOM\Integrations\ThemeHighEpo;
use WCBOM\Orders\OrderSync;
use WCBOM\Orders\RefundHandler;
use WCBOM\Rest\Api;
use WCBOM\Rest\InventoryApi;
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
		$boms    = new BomRepository();
		$stock   = new StockService( new Ledger() );
		$matcher = new ConditionMatcher();
		$phantom = new PhantomStock( $boms );
		$guard   = new OperationGuard();

		( new ProductBomMetabox( $boms ) )->register();
		( new DeletionGuard( $boms ) )->register();
		( new OrderSync( $stock, $boms, $matcher ) )->register();
		( new RefundHandler( $stock ) )->register();
		( new ThemeHighEpo() )->register();
		$phantom->register();
		( new StorefrontStock( $phantom, $boms, $matcher ) )->register();
		( new InventoryPage() )->register();
		( new Settings() )->register();
		( new GitHubUpdater() )->register();
		( new RecommendedPlugins() )->register();

		add_action(
			'rest_api_init',
			static function () use ( $boms, $stock, $guard ) {
				( new Api( $boms ) )->register_routes();
				( new InventoryApi( $stock, $boms, $guard ) )->register_routes();
				( new SampleDataApi( new SampleData() ) )->register_routes();
			}
		);

		// Phase 4: Manufacture\ManufactureService.
	}
}
