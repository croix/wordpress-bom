<?php
/**
 * The "Enable vendors & purchase orders" feature gate.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.13: the load-bearing design constraint of this
 * whole feature. Default off, and every surface this feature adds — the
 * Purchasing admin page, its REST routes, the on-order columns in
 * LowStockReport/LowStockDigest/the Inventory screen, the audit check —
 * checks this before registering or computing anything, so a merchant who
 * never turns it on sees literally nothing different. The three tables
 * (Vendor/PurchaseOrder/PurchaseOrderItem) are still created unconditionally
 * by Schema::install() — turning the feature off hides the section, it
 * never destroys data — but nothing reads or writes them while this is off.
 */
final class VendorsFeature {

	public const OPTION = 'wcbom_vendors_enabled';

	/**
	 * Whether the vendors & purchase orders feature is enabled.
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}
}
