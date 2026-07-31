<?php
/**
 * A supplier a component is purchased from.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Purchasing;

defined( 'ABSPATH' ) || exit;

/**
 * A lightweight custom-table entity — deliberately not a WC product or a
 * CPT (BUILD_PLAN.md §5.13): a vendor is never sellable, never stocked, and
 * a CPT would buy nothing but admin-UI baggage. Soft-archived
 * (is_active = false) rather than deleted once referenced by any PO, so
 * PO history always resolves its vendor.
 */
final class Vendor {

	/**
	 * Constructs an immutable vendor snapshot.
	 *
	 * @param int         $vendor_id  0 for a not-yet-persisted vendor.
	 * @param string      $name       Vendor/company name.
	 * @param string|null $email      Contact email.
	 * @param string|null $phone      Contact phone.
	 * @param string|null $website    Vendor website URL.
	 * @param string|null $notes      Free-text notes.
	 * @param bool        $is_active  False once archived.
	 * @param string      $created_at MySQL datetime, UTC.
	 */
	public function __construct(
		public readonly int $vendor_id,
		public readonly string $name,
		public readonly ?string $email,
		public readonly ?string $phone,
		public readonly ?string $website,
		public readonly ?string $notes,
		public readonly bool $is_active,
		public readonly string $created_at
	) {}

	/**
	 * Builds a Vendor from a raw database row.
	 *
	 * @param array<string,mixed> $row A wcbom_vendors row (ARRAY_A).
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['vendor_id'],
			(string) $row['name'],
			null !== $row['email'] ? (string) $row['email'] : null,
			null !== $row['phone'] ? (string) $row['phone'] : null,
			null !== $row['website'] ? (string) $row['website'] : null,
			null !== $row['notes'] ? (string) $row['notes'] : null,
			'1' === (string) $row['is_active'],
			(string) $row['created_at']
		);
	}
}
