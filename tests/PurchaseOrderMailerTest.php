<?php
/**
 * "Send PO" — emails the PO to its vendor and/or the current user
 * (BUILD_PLAN.md §5.13 addendum, added 2026-07-31). Recipients are
 * resolved from records already on file, never a typed-in address.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Purchasing\PurchaseOrderMailer;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\PurchaseOrderService;
use WCBOM\Purchasing\VendorRepository;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\StockService;

final class PurchaseOrderMailerTest extends WCBOM_UnitTestCase {

	public function test_sends_to_both_vendor_and_self_when_both_selected(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'merchant@example.test' ) );
		wp_set_current_user( $user_id );

		$po = $this->make_po( 'vendor@example.test' );

		$sent = array();
		add_filter(
			'wp_mail',
			function ( $args ) use ( &$sent ) {
				$sent[] = $args;
				return $args;
			}
		);

		$result = ( new PurchaseOrderMailer( new VendorRepository() ) )->send( $po, true, true );

		$this->assertSame( array( 'vendor@example.test', 'merchant@example.test' ), $result['sent_to'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertCount( 2, $sent, 'One wp_mail() call per recipient.' );
		$this->assertSame( 'vendor@example.test', $sent[0]['to'] );
		$this->assertSame( 'merchant@example.test', $sent[1]['to'] );
	}

	/**
	 * to_vendor=false must skip the vendor entirely — not just "vendor has
	 * no email" (that's a different path, tested below), but a vendor
	 * that *does* have a valid email simply isn't contacted because it
	 * wasn't requested. No warning either, since nothing was actually
	 * asked of that recipient.
	 */
	public function test_self_only_sends_without_vendor_checked(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'merchant@example.test' ) );
		wp_set_current_user( $user_id );

		$po = $this->make_po( 'vendor@example.test' ); // Vendor has a valid email, but to_vendor will be false.

		$result = ( new PurchaseOrderMailer( new VendorRepository() ) )->send( $po, false, true );

		$this->assertSame( array( 'merchant@example.test' ), $result['sent_to'] );
		$this->assertSame( array(), $result['warnings'], 'Skipping the vendor by choice must not produce a warning.' );
	}

	public function test_vendor_with_no_email_warns_but_self_still_sends(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'merchant@example.test' ) );
		wp_set_current_user( $user_id );

		$po = $this->make_po( null );

		$result = ( new PurchaseOrderMailer( new VendorRepository() ) )->send( $po, true, true );

		$this->assertSame( array( 'merchant@example.test' ), $result['sent_to'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'vendor has no email', $result['warnings'][0] );
	}

	public function test_neither_option_selected_throws(): void {
		$po = $this->make_po( 'vendor@example.test' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Select at least one recipient.' );

		( new PurchaseOrderMailer( new VendorRepository() ) )->send( $po, false, false );
	}

	public function test_no_resolvable_recipient_throws(): void {
		$po = $this->make_po( null ); // No vendor email, and to_myself not requested.

		$this->expectException( \RuntimeException::class );

		( new PurchaseOrderMailer( new VendorRepository() ) )->send( $po, true, false );
	}

	public function test_body_contains_po_number_vendor_line_items_and_totals(): void {
		$po = $this->make_po( 'vendor@example.test' );

		$mailer = new PurchaseOrderMailer( new VendorRepository() );
		$body   = $mailer->compose_body( $po );
		$subject = $mailer->compose_subject( $po );

		$this->assertStringContainsString( (string) $po->po_id, $subject );
		$this->assertStringContainsString( 'Acme Blanks Co', $body );
		$this->assertStringContainsString( 'Blank', $body );
		$this->assertStringContainsString( '100', $body );
		$this->assertStringContainsString( '$2.00', $body );
		$this->assertStringContainsString( 'Freight: $30.00', $body );
		$this->assertStringContainsString( 'Total: $235.00', $body ); // (100 × $2.00) + $30 freight + $5 tax.
	}

	/**
	 * A placed PO for one component, with a vendor whose email may or may
	 * not be on file, plus freight/tax set — real objects via the actual
	 * services, not hand-built value objects, so this also exercises
	 * update_costs().
	 */
	private function make_po( ?string $vendor_email ): \WCBOM\Purchasing\PurchaseOrder {
		$blank  = $this->create_component( 'Blank', 0, 'ea', '2.00' );
		$vendor = new VendorRepository();
		$orders = new PurchaseOrderRepository();
		$service = new PurchaseOrderService( $orders, new StockService( new Ledger() ), new OperationGuard() );

		$vendor_id = $vendor->create( 'Acme Blanks Co', $vendor_email, null, null, null );
		$po        = $service->create_draft( $vendor_id, array( array( 'component_id' => $blank, 'qty_ordered' => 100, 'unit_cost' => 2.00 ) ), 'INV-1', null, null );
		$po        = $service->place( $po->po_id );

		return $service->update_costs( $po->po_id, 30.0, 5.0, 0.0 );
	}
}
