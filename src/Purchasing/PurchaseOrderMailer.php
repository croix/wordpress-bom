<?php
/**
 * Emails a purchase order's details to its vendor and/or the current user.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * A plain-text, vendor-facing PO summary — line items at their plain
 * unit_cost (not the amortized landed cost LandedCost computes; that
 * figure is this plugin's own internal analysis, not something to hand a
 * vendor), plus a freight/tax/fees/grand-total footer. Recipients are
 * resolved from records already on file (the vendor's own email, the
 * currently logged-in user's WP account email) — never an address typed
 * into the send form itself, so this can never become an arbitrary-email
 * relay.
 */
final class PurchaseOrderMailer {

	/**
	 * Constructs the mailer.
	 *
	 * @param VendorRepository $vendors Vendor lookup, for the recipient's email on file.
	 */
	public function __construct( private readonly VendorRepository $vendors ) {}

	/**
	 * Sends the PO to whichever of the vendor/current-user is selected and
	 * has a usable email on file. Partial success is reported via
	 * `warnings` rather than failing outright — e.g. "to vendor" was
	 * requested but the vendor has no email, while "to myself" still goes
	 * through.
	 *
	 * @param PurchaseOrder $po        The purchase order to send.
	 * @param bool          $to_vendor Send to the vendor's email on file.
	 * @param bool          $to_myself Send to the current user's WP account email.
	 * @return array{sent_to:array<int,string>,warnings:array<int,string>}
	 *
	 * @throws \RuntimeException If neither option is selected, or no
	 *                           selected recipient has a usable email.
	 */
	public function send( PurchaseOrder $po, bool $to_vendor, bool $to_myself ): array {
		if ( ! $to_vendor && ! $to_myself ) {
			throw new \RuntimeException( esc_html__( 'Select at least one recipient.', 'wcbom' ) );
		}

		$recipients = array();
		$warnings   = array();

		if ( $to_vendor ) {
			$vendor = $this->vendors->get( $po->vendor_id );
			$email  = $vendor ? trim( (string) $vendor->email ) : '';

			if ( '' !== $email && is_email( $email ) ) {
				$recipients[] = $email;
			} else {
				$warnings[] = esc_html__( 'The vendor has no email address on file — nothing was sent to them.', 'wcbom' );
			}
		}

		if ( $to_myself ) {
			$user  = wp_get_current_user();
			$email = $user->exists() ? trim( $user->user_email ) : '';

			if ( '' !== $email && is_email( $email ) ) {
				$recipients[] = $email;
			} else {
				$warnings[] = esc_html__( 'Your account has no email address on file.', 'wcbom' );
			}
		}

		if ( array() === $recipients ) {
			throw new \RuntimeException( esc_html__( 'No valid recipient — check that the vendor and/or your account has an email address on file.', 'wcbom' ) );
		}

		$subject = $this->compose_subject( $po );
		$body    = $this->compose_body( $po );

		foreach ( $recipients as $recipient ) {
			wp_mail( $recipient, $subject, $body );
		}

		return array(
			'sent_to'  => $recipients,
			'warnings' => $warnings,
		);
	}

	/**
	 * The email subject line.
	 *
	 * @param PurchaseOrder $po The purchase order.
	 */
	public function compose_subject( PurchaseOrder $po ): string {
		return sprintf(
			/* translators: 1: purchase order ID, 2: site name */
			__( 'Purchase Order #%1$d — %2$s', 'wcbom' ),
			$po->po_id,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * The email body: a plain-text PO summary.
	 *
	 * @param PurchaseOrder $po The purchase order.
	 */
	public function compose_body( PurchaseOrder $po ): string {
		$vendor = $this->vendors->get( $po->vendor_id );

		$lines   = array();
		$lines[] = sprintf(
			/* translators: %d: purchase order ID */
			__( 'Purchase Order #%d', 'wcbom' ),
			$po->po_id
		);
		$lines[] = sprintf(
			/* translators: %s: vendor name */
			__( 'Vendor: %s', 'wcbom' ),
			$vendor ? $vendor->name : __( '(unknown vendor)', 'wcbom' )
		);
		if ( null !== $po->reference ) {
			$lines[] = sprintf(
				/* translators: %s: vendor reference/invoice number */
				__( 'Reference: %s', 'wcbom' ),
				$po->reference
			);
		}
		if ( null !== $po->expected_date ) {
			$lines[] = sprintf(
				/* translators: %s: expected delivery date */
				__( 'Expected date: %s', 'wcbom' ),
				$po->expected_date
			);
		}
		$lines[] = '';
		$lines[] = __( 'Line items:', 'wcbom' );

		$subtotal = 0.0;
		foreach ( $po->items as $item ) {
			$component = wc_get_product( $item->component_id );
			$name      = $component ? $component->get_name() : __( '(unknown component)', 'wcbom' );
			$unit      = $component ? get_post_meta( $component->get_id(), '_wcbom_unit', true ) : '';
			$unit      = '' !== $unit ? $unit : 'ea';
			$qty       = rtrim( rtrim( number_format( $item->qty_ordered, 4 ), '0' ), '.' );

			if ( null !== $item->unit_cost ) {
				$line_total = $item->qty_ordered * $item->unit_cost;
				$subtotal  += $line_total;

				$lines[] = sprintf(
					'- %1$s: %2$s %3$s @ $%4$s = $%5$s',
					$name,
					$qty,
					$unit,
					number_format( $item->unit_cost, 2 ),
					number_format( $line_total, 2 )
				);
			} else {
				$lines[] = sprintf( '- %1$s: %2$s %3$s', $name, $qty, $unit );
			}
		}

		$freight = $po->freight_cost ?? 0.0;
		$tax     = $po->tax_cost ?? 0.0;
		$fees    = $po->fees_cost ?? 0.0;
		$total   = $subtotal + $freight + $tax + $fees;

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: freight/shipping cost */
			__( 'Freight: $%s', 'wcbom' ),
			number_format( $freight, 2 )
		);
		$lines[] = sprintf(
			/* translators: %s: tax cost */
			__( 'Tax: $%s', 'wcbom' ),
			number_format( $tax, 2 )
		);
		$lines[] = sprintf(
			/* translators: %s: other fees */
			__( 'Fees: $%s', 'wcbom' ),
			number_format( $fees, 2 )
		);
		$lines[] = sprintf(
			/* translators: %s: grand total */
			__( 'Total: $%s', 'wcbom' ),
			number_format( $total, 2 )
		);

		if ( null !== $po->notes && '' !== trim( $po->notes ) ) {
			$lines[] = '';
			$lines[] = __( 'Notes:', 'wcbom' );
			$lines[] = $po->notes;
		}

		return implode( "\n", $lines );
	}
}
