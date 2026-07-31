<?php
/**
 * The "Allow negative component stock" setting (BUILD_PLAN §11.2).
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Resolved 2026-07-30: governs only whether *manual* operations (Inventory
 * adjustments, manufacture-order completion) may take a component below
 * zero without a per-operation override. Deliberately NOT consulted by:
 *
 * - Order consumption — a paid order always proceeds, flagged [SHORTAGE],
 *   because §13.3 (never fatal a checkout) is an invariant, not a setting.
 * - Receive/count operations — receiving negative quantities or counting
 *   a shelf to a negative total is nonsensical regardless of preference,
 *   so those paths keep their hard allow_negative=false.
 *
 * Static like ProductMode: a pure option read with no state to inject.
 */
final class NegativeStockPolicy {

	public const OPTION = 'wcbom_allow_negative_stock';

	/**
	 * Whether manual operations may drive component stock below zero
	 * without an explicit per-operation override.
	 */
	public static function allowed(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}
}
