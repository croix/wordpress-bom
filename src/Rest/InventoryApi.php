<?php
/**
 * REST routes backing the Inventory screen (receive / count / adjust).
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WC_Product;
use WCBOM\Bom\BomRepository;
use WCBOM\Stock\InsufficientStockException;
use WCBOM\Stock\Ledger;
use WCBOM\Stock\OperationGuard;
use WCBOM\Stock\PhantomStock;
use WCBOM\Stock\StockService;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wcbom/v1 /inventory* routes: the component list plus the
 * three receive/count/adjust workflows (BUILD_PLAN.md §5.7), each
 * idempotency-key guarded per §13.4 so a retry-after-timeout can never
 * double-apply. Gated on manage_woocommerce, same as the BOM editor API.
 */
final class InventoryApi {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param StockService   $stock  The single stock-mutation path.
	 * @param BomRepository  $boms   Used for the used-in-N-BOMs column.
	 * @param OperationGuard $guard Idempotency-key claim.
	 */
	public function __construct(
		private readonly StockService $stock,
		private readonly BomRepository $boms,
		private readonly OperationGuard $guard
	) {}

	/**
	 * Registers all /inventory* routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/inventory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_components' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		foreach ( array( 'receive', 'count', 'adjust' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				"/inventory/{$action}",
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_' . $action ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Permission callback shared by every route.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Lists components (or, with ?all=1, every managed-stock product) with
	 * on-hand stock, BOM usage count, and last ledger movement.
	 *
	 * @param WP_REST_Request $request Accepts `search` and `all` query params.
	 */
	public function list_components( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin management screen, not a paginated frontend list; v1 accepts this catalog-size ceiling rather than adding pagination.
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( ! $request->get_param( 'all' ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_wcbom_is_component',
					'value' => 'yes',
				),
			);
		}

		$query   = new WP_Query( $args );
		$ledger  = new Ledger();
		$results = array();

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$recent        = $ledger->for_product( $product->get_id(), 1 );
			$last_movement = $recent[0] ?? null;
			$unit          = get_post_meta( $product->get_id(), '_wcbom_unit', true );
			$results[]     = array(
				'id'            => $product->get_id(),
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'unit'          => '' !== $unit ? $unit : 'ea',
				'stock'         => $product->get_stock_quantity(),
				'used_in_count' => count( $this->boms->used_in( $product->get_id() ) ),
				'last_movement' => $last_movement ? array(
					'reason'     => $last_movement['reason'],
					'delta'      => (float) $last_movement['delta'],
					'created_at' => $last_movement['created_at'],
				) : null,
			);
		}

		return new WP_REST_Response(
			array(
				'components'            => $results,
				'sample_data_installed' => ( new \WCBOM\Install\SampleData() )->is_installed(),
			)
		);
	}

	/**
	 * Receive: additive. Each item's qty is added to current stock.
	 *
	 * @param WP_REST_Request $request Body: op_key, note?, items[{product_id, qty}].
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_receive( WP_REST_Request $request ) {
		$items = $this->validate_items(
			$request,
			static function ( array $item ) {
				return isset( $item['qty'] ) && (float) $item['qty'] > 0;
			},
			__( 'Each item needs a valid product and a positive quantity.', 'wcbom' )
		);

		if ( $items instanceof WP_Error ) {
			return $items;
		}

		$deltas = array();
		foreach ( $items as $item ) {
			$deltas[ $item['product_id'] ] = (float) $item['qty'];
		}

		return $this->apply(
			$request,
			$deltas,
			Ledger::REASON_RECEIVED,
			false
		);
	}

	/**
	 * Count: absolute. Each item's qty is the physically-counted total;
	 * the delta (drift) is computed against current stock and returned so
	 * the UI can surface it prominently.
	 *
	 * @param WP_REST_Request $request Body: op_key, note?, items[{product_id, qty}].
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_count( WP_REST_Request $request ) {
		$items = $this->validate_items(
			$request,
			static function ( array $item ) {
				return isset( $item['qty'] ) && (float) $item['qty'] >= 0;
			},
			__( 'Each item needs a valid product and a non-negative counted quantity.', 'wcbom' )
		);

		if ( $items instanceof WP_Error ) {
			return $items;
		}

		$deltas = array();
		$drift  = array();
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$before                        = (float) $product->get_stock_quantity();
			$counted                       = (float) $item['qty'];
			$deltas[ $item['product_id'] ] = $counted - $before;
			$drift[ $item['product_id'] ]  = array(
				'before'  => $before,
				'counted' => $counted,
				'drift'   => $counted - $before,
			);
		}

		$response = $this->apply( $request, $deltas, Ledger::REASON_CYCLE_COUNT, false );
		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! empty( $data['already_applied'] ) ) {
			return $response;
		}

		$data['drift'] = $drift;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Adjust: signed delta with a required note — the exception path
	 * (damage, shrinkage, found stock). Allows the result to go negative,
	 * since this is the deliberate manual-override path.
	 *
	 * @param WP_REST_Request $request Body: op_key, note (required), items[{product_id, qty}].
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_adjust( WP_REST_Request $request ) {
		if ( '' === trim( (string) $request->get_param( 'note' ) ) ) {
			return new WP_Error( 'wcbom_note_required', __( 'A note is required for manual adjustments.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$items = $this->validate_items(
			$request,
			static function ( array $item ) {
				return isset( $item['qty'] ) && 0.0 !== (float) $item['qty'];
			},
			__( 'Each item needs a valid product and a non-zero adjustment.', 'wcbom' )
		);

		if ( $items instanceof WP_Error ) {
			return $items;
		}

		$deltas = array();
		foreach ( $items as $item ) {
			$deltas[ $item['product_id'] ] = (float) $item['qty'];
		}

		return $this->apply( $request, $deltas, Ledger::REASON_MANUAL_ADJUST, true );
	}

	/**
	 * Shared apply step: claims the idempotency key, then runs the
	 * deltas through StockService and reports each product's new
	 * buildable count (Stock\PhantomStock) alongside the result.
	 *
	 * @param WP_REST_Request  $request        The originating request (for op_key/note).
	 * @param array<int,float> $deltas         Product ID => signed stock delta.
	 * @param string           $reason         One of Ledger::REASON_*.
	 * @param bool             $allow_negative Permit results to go below zero.
	 * @return WP_REST_Response|WP_Error
	 */
	private function apply( WP_REST_Request $request, array $deltas, string $reason, bool $allow_negative ) {
		$op_key = (string) $request->get_param( 'op_key' );
		if ( '' === $op_key ) {
			return new WP_Error( 'wcbom_missing_op_key', __( 'Missing op_key.', 'wcbom' ), array( 'status' => 400 ) );
		}

		if ( ! $this->guard->claim( $op_key, "{$reason} via Inventory screen" ) ) {
			return new WP_REST_Response(
				array(
					'already_applied' => true,
					'message'         => __( 'This operation was already applied — no changes were made.', 'wcbom' ),
				)
			);
		}

		$note = (string) $request->get_param( 'note' );

		try {
			// No ref_id: there's no order/MO entity to point at here. The
			// ledger already captures who did this via its own user_id
			// column (Ledger::record() reads get_current_user_id() itself).
			$results = $this->stock->adjust_many( $deltas, $reason, 'inventory_screen', null, '' !== $note ? $note : null, $allow_negative );
		} catch ( InsufficientStockException $e ) {
			return new WP_Error( 'wcbom_insufficient_stock', $e->getMessage(), array( 'status' => 409 ) );
		}

		$phantom   = new PhantomStock( $this->boms );
		$buildable = array();
		foreach ( array_keys( $results ) as $product_id ) {
			foreach ( $this->boms->products_with_always_line( (int) $product_id ) as $affected_id ) {
				$buildable[ $affected_id ] = $phantom->get_buildable_qty( $affected_id );
			}
		}

		return new WP_REST_Response(
			array(
				'already_applied'   => false,
				'results'           => $results,
				'buildable_changed' => $buildable,
			)
		);
	}

	/**
	 * Validates the shared { items: [{product_id, qty}] } request shape.
	 *
	 * @param WP_REST_Request $request     The request to read `items` from.
	 * @param callable        $item_valid  fn(array $item): bool — per-item validity check.
	 * @param string          $error_message Message used if validation fails.
	 * @return array<int,array{product_id:int,qty:mixed}>|WP_Error
	 */
	private function validate_items( WP_REST_Request $request, callable $item_valid, string $error_message ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error( 'wcbom_invalid_items', __( 'items must be a non-empty array.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['product_id'] ) ) {
				return new WP_Error( 'wcbom_invalid_items', $error_message, array( 'status' => 400 ) );
			}

			$product_id = (int) $item['product_id'];
			if ( $product_id <= 0 || ! wc_get_product( $product_id ) || ! $item_valid( $item ) ) {
				return new WP_Error( 'wcbom_invalid_items', $error_message, array( 'status' => 400 ) );
			}

			$clean[] = array(
				'product_id' => $product_id,
				'qty'        => $item['qty'],
			);
		}

		return $clean;
	}
}
