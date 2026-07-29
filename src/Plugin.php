<?php
/**
 * Plugin service wiring root.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM;

defined( 'ABSPATH' ) || exit;

/**
 * Service wiring root. Feature modules (ledger, BOM editor, order sync,
 * manufacture orders, admin screens) register themselves from here as
 * each phase lands — Phase 0 only needs the schema and HPOS declaration.
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
		// Phase 1+: Stock\Ledger, Stock\StockService, Admin\ProductBomMetabox, etc.
	}
}
