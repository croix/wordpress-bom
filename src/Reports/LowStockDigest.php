<?php
/**
 * Daily low-stock digest email.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Per BUILD_PLAN.md §5.5: a digest that understands *components*, not
 * just WC's native per-product low-stock notice — "Pink glitter below
 * threshold — blocks 3 products" is the whole point, since a merchant
 * watching only the finished-good products would never see the glitter
 * itself go short. Opt-in via Admin\Settings (default off); scheduling
 * reacts to that setting rather than assuming activation-time state, so
 * toggling it in wp-admin takes effect without needing to reactivate.
 */
final class LowStockDigest {

	public const HOOK           = 'wcbom_low_stock_digest';
	public const OPTION_ENABLED = 'wcbom_low_stock_digest_enabled';
	public const OPTION_EMAIL   = 'wcbom_low_stock_digest_email';

	/**
	 * Constructs the digest job.
	 *
	 * @param LowStockReport $report The report the digest summarizes.
	 */
	public function __construct( private readonly LowStockReport $report ) {}

	/**
	 * Hooks the cron callback and keeps the schedule in sync with the
	 * enabled setting.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'send' ) );
		add_action( 'update_option_' . self::OPTION_ENABLED, array( $this, 'reconcile_schedule' ) );
		$this->reconcile_schedule();
	}

	/**
	 * Schedules or clears the daily event to match the current setting.
	 * Idempotent and cheap (wp_next_scheduled() is a single cached-option
	 * check), so running it on every request is fine — it's the only way
	 * to notice the setting changed without a dedicated save handler.
	 */
	public function reconcile_schedule(): void {
		$enabled   = 'yes' === get_option( self::OPTION_ENABLED, 'no' );
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( $enabled && false === $scheduled ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! $enabled && false !== $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );
		}
	}

	/**
	 * Builds and sends the digest — a no-op (no email) when nothing is
	 * currently low, so the merchant only ever hears from this when it
	 * matters.
	 */
	public function send(): void {
		$rows = $this->report->generate();
		if ( array() === $rows ) {
			return;
		}

		$to = (string) get_option( self::OPTION_EMAIL );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}

		$lines = array();
		foreach ( $rows as $row ) {
			$line = sprintf(
				/* translators: 1: component name, 2: current stock, 3: threshold, 4: number of products blocked */
				__( '%1$s: %2$s on hand (threshold %3$s) — blocks %4$d product(s)', 'wcbom' ),
				$row['name'],
				rtrim( rtrim( number_format( $row['stock'], 4 ), '0' ), '.' ),
				rtrim( rtrim( number_format( $row['threshold'], 4 ), '0' ), '.' ),
				$row['blocks_products']
			);

			// Present only when VendorsFeature is enabled (§5.13) — never
			// hides the row, just adds context so the merchant knows not to
			// reorder something already on its way.
			if ( isset( $row['on_order'] ) ) {
				$line .= ' — ' . ( null !== $row['on_order_expected']
					? sprintf(
						/* translators: 1: quantity on order, 2: expected delivery date */
						__( '%1$s on order, expected %2$s', 'wcbom' ),
						rtrim( rtrim( number_format( $row['on_order'], 4 ), '0' ), '.' ),
						$row['on_order_expected']
					)
					: sprintf(
						/* translators: %s: quantity on order */
						__( '%s on order', 'wcbom' ),
						rtrim( rtrim( number_format( $row['on_order'], 4 ), '0' ), '.' )
					) );
			}

			$lines[] = $line;
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: 1: site name, 2: number of low-stock components */
				__( '[%1$s] %2$d component(s) low on stock', 'wcbom' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				count( $rows )
			),
			implode( "\n", $lines )
		);
	}
}
