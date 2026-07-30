<?php
/**
 * REST routes backing the Reports screen's ledger browser.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WCBOM\Stock\Ledger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the read-only wcbom/v1 /ledger route: filtered, paginated
 * ledger rows for the Reports screen's browser (BUILD_PLAN.md §5.5). CSV
 * export is a separate admin-post.php download, not a REST route — a
 * file-download response doesn't fit the JSON-response REST shape.
 */
final class LedgerApi {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param Ledger $ledger Filtered/paginated ledger query.
	 */
	public function __construct( private readonly Ledger $ledger ) {}

	/**
	 * Registers the /ledger route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ledger',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ledger' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /ledger — accepts product_id, reason, ref_type, date_from,
	 * date_to, page, per_page query params, all optional.
	 *
	 * @param WP_REST_Request $request The REST request.
	 */
	public function get_ledger( WP_REST_Request $request ): WP_REST_Response {
		$filters  = $this->filters_from_request( $request );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 200, $per_page ) : 50;

		$rows = $this->ledger->query( $filters, $page, $per_page );

		return new WP_REST_Response(
			array(
				'rows'     => array_map( array( $this, 'present_row' ), $rows ),
				'total'    => $this->ledger->count( $filters ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Extracts the shared filter shape from request query params.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return array{product_id?:int,reason?:string,ref_type?:string,date_from?:string,date_to?:string}
	 */
	private function filters_from_request( WP_REST_Request $request ): array {
		$filters = array();

		$product_id = $request->get_param( 'product_id' );
		if ( null !== $product_id && '' !== $product_id ) {
			$filters['product_id'] = (int) $product_id;
		}

		foreach ( array( 'reason', 'ref_type', 'date_from', 'date_to' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$filters[ $key ] = (string) $value;
			}
		}

		return $filters;
	}

	/**
	 * Shapes one ledger row for the REST response, enriching it with the
	 * product's current display name (the ledger only stores the ID).
	 *
	 * @param array<string,mixed> $row A wcbom_stock_ledger row.
	 * @return array<string,mixed>
	 */
	private function present_row( array $row ): array {
		$product = wc_get_product( (int) $row['product_id'] );

		return array(
			'ledger_id'    => (int) $row['ledger_id'],
			'product_id'   => (int) $row['product_id'],
			'product_name' => $product ? $product->get_name() : sprintf( '#%d', (int) $row['product_id'] ),
			'delta'        => (float) $row['delta'],
			'stock_after'  => null !== $row['stock_after'] ? (float) $row['stock_after'] : null,
			'reason'       => (string) $row['reason'],
			'ref_type'     => $row['ref_type'],
			'ref_id'       => null !== $row['ref_id'] ? (int) $row['ref_id'] : null,
			'note'         => $row['note'],
			'created_at'   => (string) $row['created_at'],
		);
	}
}
