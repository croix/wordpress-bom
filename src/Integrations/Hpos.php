<?php
/**
 * HPOS compatibility declaration.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Integrations;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Declares High-Performance Order Storage and cart/checkout-blocks
 * compatibility. Hooked from before_woocommerce_init in the bootstrap file.
 */
final class Hpos {

	/**
	 * Declares compatibility with HPOS and cart/checkout blocks.
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', WCBOM_PLUGIN_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCBOM_PLUGIN_FILE, true );
	}
}
