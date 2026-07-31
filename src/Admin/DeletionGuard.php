<?php
/**
 * Blocks deleting a product that's in active use as a BOM component.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Admin;

use WCBOM\Bom\BomRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks WordPress's pre_trash_post/pre_delete_post short-circuit filters to
 * stop a component from being trashed or deleted out from under an active
 * BOM — a silent stock-tracking gap otherwise.
 */
final class DeletionGuard {

	/**
	 * Constructs the guard.
	 *
	 * @param BomRepository $boms Used to check component-in-use status.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * Hooks the trash/delete short-circuit filters.
	 */
	public function register(): void {
		add_filter( 'pre_trash_post', array( $this, 'block_if_in_use' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'block_if_in_use' ), 10, 2 );
	}

	/**
	 * Blocks the trash/delete if the post is a component in active use.
	 *
	 * @param mixed   $check Short-circuit value; non-null bypasses default handling.
	 * @param WP_Post $post  The post being trashed/deleted.
	 * @return mixed
	 */
	public function block_if_in_use( $check, WP_Post $post ) {
		if ( null !== $check ) {
			return $check;
		}

		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return $check;
		}

		if ( ! $this->boms->is_component_in_use( $post->ID ) ) {
			return $check;
		}

		wp_die(
			esc_html__( "This product is used as a component in an active Bill of Materials and can't be deleted. Remove it from that BOM first.", 'pv-bom-stock' ),
			esc_html__( 'Component in use', 'pv-bom-stock' ),
			array( 'back_link' => true )
		);
	}
}
