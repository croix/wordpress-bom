<?php
/**
 * Top-level "BOM & Stock" admin menu.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's own top-level admin menu — Inventory,
 * Manufacturing, Reports, Endpoints, and Settings all live under here as
 * submenus, rather than scattered across WooCommerce's own menu (which is
 * where they lived through Phase 5). This class only creates the parent
 * entry; each page class still owns its own submenu registration, just
 * parented to self::SLUG instead of 'woocommerce'.
 */
final class PluginMenu {

	public const SLUG = 'wcbom';

	/**
	 * Hooks the admin menu callback.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the top-level menu entry. Its own page callback is never
	 * called directly: Admin\InventoryPage registers a submenu using this
	 * same slug (BUILD_PLAN.md convention — see that class), which is
	 * WordPress's standard way of making the top-level link itself open a
	 * specific submenu instead of a generic duplicate first entry.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'BOM & Stock', 'wcbom' ),
			__( 'BOM & Stock', 'wcbom' ),
			'manage_woocommerce',
			self::SLUG,
			'__return_null',
			'dashicons-archive',
			56
		);
	}
}
