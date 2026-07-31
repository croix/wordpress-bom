<?php
/**
 * Fires on plugin deletion from wp-admin. Table data is kept by default —
 * set the wcbom_purge_data_on_uninstall option to 'yes' to drop everything.
 *
 * @package WCBOM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drops the plugin's custom tables and options, if the user opted in.
 */
function wcbom_run_uninstall(): void {
	if ( 'yes' !== get_option( 'wcbom_purge_data_on_uninstall', 'no' ) ) {
		return;
	}

	global $wpdb;

	foreach (
		array(
			'wcbom_stock_ledger',
			'wcbom_manufacture_order_items',
			'wcbom_manufacture_orders',
			'wcbom_bom_items',
			'wcbom_boms',
			'wcbom_ops',
		) as $wcbom_table
	) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$wcbom_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	delete_option( 'wcbom_db_version' );
	delete_option( 'wcbom_purge_data_on_uninstall' );
	delete_option( 'wcbom_recommended_plugins_dismissed' );
	delete_option( 'wcbom_low_stock_digest_enabled' );
	delete_option( 'wcbom_low_stock_digest_email' );
	delete_option( 'wcbom_allow_negative_stock' );
}

wcbom_run_uninstall();
