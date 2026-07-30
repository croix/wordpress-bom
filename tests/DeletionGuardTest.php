<?php
/**
 * §9 test scenario 11: trashing/deleting a component referenced by an
 * active BOM is blocked with a clear message.
 *
 * @package WCBOM
 */

declare(strict_types=1);

use WCBOM\Admin\DeletionGuard;
use WCBOM\Bom\BomRepository;

final class DeletionGuardTest extends WCBOM_UnitTestCase {

	public function test_trashing_component_in_active_bom_is_blocked(): void {
		( new DeletionGuard( new BomRepository() ) )->register();

		$blank = $this->create_component( 'Blank', 100 );
		$this->create_made_to_order( 'Pink Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$this->expectException( WPDieException::class );
		try {
			wp_trash_post( $blank );
		} finally {
			$this->assertSame( 'publish', get_post_status( $blank ), 'Blocked trash must leave the product untouched.' );
		}
	}

	public function test_deleting_component_in_active_bom_is_blocked(): void {
		( new DeletionGuard( new BomRepository() ) )->register();

		$blank = $this->create_component( 'Blank', 100 );
		$this->create_made_to_order( 'Pink Tumbler', array( array( 'component_id' => $blank, 'qty' => 1 ) ) );

		$this->expectException( WPDieException::class );
		try {
			wp_delete_post( $blank, true );
		} finally {
			$this->assertNotNull( get_post( $blank ), 'Blocked delete must leave the product in place.' );
		}
	}

	public function test_component_not_in_any_active_bom_can_be_trashed(): void {
		( new DeletionGuard( new BomRepository() ) )->register();

		$unused = $this->create_component( 'Unused Component', 100 );

		$result = wp_trash_post( $unused );

		$this->assertNotFalse( $result );
		$this->assertSame( 'trash', get_post_status( $unused ) );
	}
}
