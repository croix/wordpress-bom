<?php
/**
 * Idempotency-key guard against duplicate submits.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Protects mutating operations against the retry-after-timeout duplicate
 * (BUILD_PLAN.md §13.4): a gateway timeout doesn't kill PHP, so the
 * original request often completes after the browser gave up — and the
 * user's retry would apply the operation twice. Callers claim a
 * client-generated key before applying; the INSERT-first pattern makes the
 * check atomic (the primary key races for us), so two concurrent requests
 * with the same key can never both win.
 */
final class OperationGuard {

	private const PURGE_AFTER_DAYS  = 7;
	private const PURGE_PROBABILITY = 50; // 1-in-N requests also purge old rows.

	/**
	 * Attempts to claim an operation key. Returns true exactly once per
	 * key — a duplicate (replayed) request gets false and must not apply
	 * the operation again.
	 *
	 * @param string      $op_key  Client-generated key, e.g. a UUID. Max 64 chars.
	 * @param string|null $summary Short human-readable label for debugging.
	 */
	public function claim( string $op_key, ?string $summary = null ): bool {
		global $wpdb;

		$op_key = substr( trim( $op_key ), 0, 64 );
		if ( '' === $op_key ) {
			return false;
		}

		$this->maybe_purge();

		// INSERT-first: the primary key decides the race. suppress_errors
		// keeps the expected duplicate-key failure out of the error log.
		$suppressing = $wpdb->suppress_errors( true );
		$inserted    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}wcbom_ops (op_key, created_at, user_id, summary) VALUES (%s, %s, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$op_key,
				current_time( 'mysql', true ),
				get_current_user_id(),
				null !== $summary ? substr( $summary, 0, 255 ) : null
			)
		);
		$wpdb->suppress_errors( $suppressing );

		return 1 === $inserted;
	}

	/**
	 * Releases a claimed key so a later request can claim it again.
	 *
	 * Only call this when the caller synchronously *knows* the operation
	 * did not apply — e.g. a claimed manufacture-order completion that
	 * then failed an insufficient-stock check. That's a different failure
	 * mode from the one this class exists for (§13.4's "the response was
	 * lost but the request actually succeeded"): here there's no
	 * ambiguity, so permanently burning the key would only block a
	 * legitimate retry once the underlying problem is fixed.
	 *
	 * @param string $op_key The key to release.
	 */
	public function release( string $op_key ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'wcbom_ops', array( 'op_key' => substr( trim( $op_key ), 0, 64 ) ), array( '%s' ) );
	}

	/**
	 * Opportunistically deletes keys old enough that no legitimate retry
	 * of their operation can still be in flight.
	 */
	private function maybe_purge(): void {
		if ( 0 !== wp_rand( 0, self::PURGE_PROBABILITY - 1 ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wcbom_ops WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - self::PURGE_AFTER_DAYS * DAY_IN_SECONDS )
			)
		);
	}
}
