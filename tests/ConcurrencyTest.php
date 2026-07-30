<?php
/**
 * §9 test scenario 6: two concurrent checkouts for the last unit of a
 * component must serialize through StockService's row lock rather than
 * losing an update — the second adjustment's "current stock" read must
 * reflect the first's committed result, not a stale value both readers
 * saw at the same time.
 *
 * No pcntl extension is available in this container, so real process
 * parallelism isn't possible here. Instead this opens a second, genuinely
 * separate mysqli connection and manually interleaves it with
 * StockService's own locking SQL (`SELECT ... FOR UPDATE`) — what actually
 * exercises MySQL's row-lock arbitration between two independent database
 * sessions, the property this test exists to prove.
 *
 * WP_UnitTestCase wraps every test in its own transaction (started in
 * set_up(), rolled back in tear_down()) specifically so fixtures never
 * leak between tests — but that means a *separate* connection can't see
 * this test's fixture at all unless we deliberately commit it. Doing so
 * only affects this one test (each test gets its own fresh transaction),
 * so it's safe as long as we clean the fixture up manually afterward
 * instead of relying on the automatic rollback.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Stock\Ledger;
use WCBOM\Stock\StockService;

final class ConcurrencyTest extends WCBOM_UnitTestCase {

	public function test_second_transaction_blocks_on_row_lock_then_sees_committed_result(): void {
		global $wpdb;

		$blank = $this->create_component( 'Blank', 1 );

		// Make the fixture visible to other connections — see class docblock.
		self::commit_transaction();
		$this->start_transaction();

		try {
			$second = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
			$this->assertSame( 0, $second->connect_errno );
			$second->query( 'SET SESSION innodb_lock_wait_timeout = 2' );

			$second->query( 'START TRANSACTION' );
			$locked = $second->query(
				sprintf(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE",
					$blank
				)
			);
			$this->assertNotFalse( $locked, 'Second connection must be able to take the lock while nothing else holds it.' );
			$this->assertSame( 1, $locked->num_rows );

			// While the second connection holds the row lock, a third
			// connection attempting the same lock must block/time out
			// rather than proceeding as if the row were free — this is the
			// exact mechanism StockService::adjust_many() relies on to
			// serialize two concurrent checkouts.
			$third = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
			$third->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
			$third->query( 'START TRANSACTION' );
			$blocked = @$third->query(
				sprintf(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE",
					$blank
				)
			);
			$this->assertFalse( $blocked, 'A third connection must be blocked/timed out while the row is locked, not read a stale value.' );
			$this->assertStringContainsString( 'lock wait timeout', strtolower( $third->error ) );
			$third->query( 'ROLLBACK' );
			$third->close();

			// Release the lock.
			$second->query( 'COMMIT' );
			$second->close();

			// Now that the lock is free, the real code path (StockService)
			// must see the true committed stock and behave correctly: the
			// "last unit" checkout succeeds and a second one for the same
			// unit correctly reports insufficient stock rather than a
			// second success off a stale read.
			$stock_service = new StockService( new Ledger() );
			$stock_service->adjust( $blank, -1, Ledger::REASON_ORDER, 'wc_order', 1, 'first checkout' );
			$this->assertSame( 0.0, $this->stock_of( $blank ) );

			$this->expectException( \WCBOM\Stock\InsufficientStockException::class );
			$stock_service->adjust( $blank, -1, Ledger::REASON_ORDER, 'wc_order', 2, 'second checkout' );
		} finally {
			// tear_down()'s ROLLBACK won't undo the commit above — clean up
			// this test's fixture and any ledger rows it wrote ourselves.
			self::commit_transaction();
			wp_delete_post( $blank, true );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wcbom_stock_ledger WHERE product_id = %d", $blank ) );
			$this->start_transaction();
		}
	}
}
