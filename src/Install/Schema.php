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

	public const DB_VERSION = '0.7.0';

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
  surcharge DECIMAL(12,4) NULL,
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
  reason VARCHAR(32) NOT NULL,
  ref_type VARCHAR(32) NULL,
  ref_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (ledger_id),
  KEY product_time (product_id, created_at),
  KEY ref (ref_type, ref_id)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_ops (
  op_key VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  summary VARCHAR(255) NULL,
  PRIMARY KEY  (op_key),
  KEY created (created_at)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_vendors (
  vendor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(64) NULL,
  website VARCHAR(191) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (vendor_id),
  KEY active (is_active)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_purchase_orders (
  po_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  reference VARCHAR(191) NULL,
  expected_date DATE NULL,
  notes TEXT NULL,
  freight_cost DECIMAL(12,4) NULL,
  tax_cost DECIMAL(12,4) NULL,
  fees_cost DECIMAL(12,4) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  ordered_at DATETIME NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY  (po_id),
  KEY vendor (vendor_id),
  KEY status (status)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_po_items (
  poi_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id BIGINT UNSIGNED NOT NULL,
  component_id BIGINT UNSIGNED NOT NULL,
  qty_ordered DECIMAL(12,4) NOT NULL,
  qty_received DECIMAL(12,4) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,4) NULL,
  PRIMARY KEY  (poi_id),
  KEY po (po_id),
  KEY component (component_id)
) {$charset_collate};

CREATE TABLE {$prefix}wcbom_order_item_costs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,4) NOT NULL,
  unit_cost DECIMAL(12,4) NULL,
  cost_source VARCHAR(32) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_item (order_item_id),
  KEY order_ref (order_id)
) {$charset_collate};
";

		dbDelta( $sql );
	}
}
