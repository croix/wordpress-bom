<?php
/**
 * Custom table installer.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables (see BUILD_PLAN.md §4).
 * dbDelta is idempotent, so install() doubles as the upgrade routine —
 * bump DB_VERSION whenever the SQL below changes.
 */
final class Schema {

	public const DB_VERSION = '0.1.0';

	private const OPTION_KEY = 'wcbom_db_version';

	/**
	 * Creates/updates the tables and records the installed schema version.
	 */
	public static function install(): void {
		self::create_tables();
		update_option( self::OPTION_KEY, self::DB_VERSION );
	}

	/**
	 * Re-runs the (idempotent) installer if the schema version changed.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_KEY ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Runs the dbDelta SQL for every plugin table.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = "
CREATE TABLE {$prefix}wcbom_boms (
  bom_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY  (bom_id),
  KEY product_active (product_id, is_active)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_bom_items (
  item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bom_id BIGINT UNSIGNED NOT NULL,
  component_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(12,4) NOT NULL,
  condition_type ENUM('always','attribute','addon') NOT NULL DEFAULT 'always',
  condition_key VARCHAR(191) NULL,
  condition_value VARCHAR(191) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY  (item_id),
  KEY bom (bom_id),
  KEY component (component_id)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_manufacture_orders (
  mo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  bom_id BIGINT UNSIGNED NOT NULL,
  qty_built INT NOT NULL,
  qty_reversed INT NOT NULL DEFAULT 0,
  status ENUM('draft','completed','partially_reversed','reversed') NOT NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY  (mo_id),
  KEY product (product_id),
  KEY status (status)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_manufacture_order_items (
  moi_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mo_id BIGINT UNSIGNED NOT NULL,
  component_id BIGINT UNSIGNED NOT NULL,
  qty_per_unit DECIMAL(12,4) NOT NULL,
  qty_total DECIMAL(12,4) NOT NULL,
  unit_cost DECIMAL(12,4) NULL,
  PRIMARY KEY  (moi_id),
  KEY mo (mo_id)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_stock_ledger (
  ledger_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  delta DECIMAL(12,4) NOT NULL,
  stock_after DECIMAL(12,4) NULL,
  reason ENUM('order','order_restore','refund','manufacture','manufacture_reverse','manual_adjust','import') NOT NULL,
  ref_type VARCHAR(32) NULL,
  ref_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (ledger_id),
  KEY product_time (product_id, created_at),
  KEY ref (ref_type, ref_id)
) {$charset_collate};
";

		dbDelta( $sql );
	}
}
