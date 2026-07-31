<?php
/**
 * Persistence for vendors.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wcbom_vendors. No delete — once referenced by a PO a
 * vendor is only ever archived (is_active = 0), so PO history always
 * resolves its vendor; a never-used vendor can still be archived the same
 * way (simplicity over a "safe to hard-delete" special case nothing needs).
 */
final class VendorRepository {

	/**
	 * Creates a vendor.
	 *
	 * @param string      $name    Vendor/company name.
	 * @param string|null $email   Contact email.
	 * @param string|null $phone   Contact phone.
	 * @param string|null $website Vendor website URL.
	 * @param string|null $notes   Free-text notes.
	 */
	public function create( string $name, ?string $email, ?string $phone, ?string $website, ?string $notes ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcbom_vendors',
			array(
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'website'    => $website,
				'notes'      => $notes,
				'is_active'  => 1,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a vendor's editable fields.
	 *
	 * @param int         $vendor_id The vendor to update.
	 * @param string      $name      Vendor/company name.
	 * @param string|null $email     Contact email.
	 * @param string|null $phone     Contact phone.
	 * @param string|null $website   Vendor website URL.
	 * @param string|null $notes     Free-text notes.
	 */
	public function update( int $vendor_id, string $name, ?string $email, ?string $phone, ?string $website, ?string $notes ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wcbom_vendors',
			array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'website' => $website,
				'notes'   => $notes,
			),
			array( 'vendor_id' => $vendor_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Archives (soft-deletes) a vendor. Never a hard delete — see class docblock.
	 *
	 * @param int $vendor_id The vendor to archive.
	 */
	public function archive( int $vendor_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wcbom_vendors',
			array( 'is_active' => 0 ),
			array( 'vendor_id' => $vendor_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * A vendor by ID.
	 *
	 * @param int $vendor_id The vendor's ID.
	 */
	public function get( int $vendor_id ): ?Vendor {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcbom_vendors WHERE vendor_id = %d", $vendor_id ),
			ARRAY_A
		);

		return null !== $row ? Vendor::from_row( $row ) : null;
	}

	/**
	 * Lists vendors, active first then by name.
	 *
	 * @param bool $active_only Omit archived vendors (the picker's default view).
	 * @return array<int,Vendor>
	 */
	public function list( bool $active_only = false ): array {
		global $wpdb;

		$sql = "SELECT * FROM {$wpdb->prefix}wcbom_vendors";
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY is_active DESC, name ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( Vendor::class, 'from_row' ), $rows );
	}
}
