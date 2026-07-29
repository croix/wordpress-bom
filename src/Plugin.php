<?php
/**
 * Plugin service wiring root.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM;

use WCBOM\Admin\DeletionGuard;
use WCBOM\Admin\ProductBomMetabox;
use WCBOM\Bom\BomRepository;
use WCBOM\Rest\Api;

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
		$boms = new BomRepository();

		( new ProductBomMetabox() )->register();
		( new DeletionGuard( $boms ) )->register();

		add_action(
			'rest_api_init',
			static function () use ( $boms ) {
				( new Api( $boms ) )->register_routes();
			}
		);

		// Phase 2+: Stock\Ledger/StockService wire into Orders\OrderSync;
		// Phase 4: Manufacture\ManufactureService.
	}
}
