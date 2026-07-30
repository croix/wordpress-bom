<?php
/**
 * REST routes for installing/removing the sample catalog.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WCBOM\Install\SampleData;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the Inventory screen's "Install sample products" / "Remove sample
 * products" buttons. No idempotency keys needed here: install is naturally
 * guarded (409 when samples already exist) and removing nothing removes
 * nothing.
 */
final class SampleDataApi {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param SampleData $sample_data The installer/remover.
	 */
	public function __construct( private readonly SampleData $sample_data ) {}

	/**
	 * Registers the /sample-data routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/sample-data/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sample-data/remove',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'remove' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback shared by both routes.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Installs the sample catalog.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function install() {
		try {
			$result = $this->sample_data->install();
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_samples_exist', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Removes the sample catalog.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove() {
		try {
			$removed = $this->sample_data->remove();
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_samples_in_use', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'removed' => $removed ) );
	}
}
