<?php
/**
 * REST routes backing the Manufacturing screen.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WC_Product_Attribute;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WCBOM\Bom\BomRepository;
use WCBOM\Manufacture\ManufactureOrder;
use WCBOM\Manufacture\ManufactureOrderItem;
use WCBOM\Manufacture\ManufactureRepository;
use WCBOM\Manufacture\ManufactureService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wcbom/v1 /manufacture* routes: list/get manufacture orders,
 * the template/existing-product pickers for the create form, create
 * (draft), complete, reverse, and delete-draft. Completion/reversal are
 * idempotency-key guarded (BUILD_PLAN.md §13.4/§13.6); everything else is
 * naturally safe to retry (draft creation's worst failure is a harmless
 * duplicate draft, per ProductFactory's docblock).
 */
final class ManufactureApi {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param ManufactureService    $manufacture The orchestration service.
	 * @param ManufactureRepository $orders      MO lookup for list/get.
	 * @param BomRepository         $boms        BOM lookup for the draft consumption preview.
	 */
	public function __construct(
		private readonly ManufactureService $manufacture,
		private readonly ManufactureRepository $orders,
		private readonly BomRepository $boms
	) {}

	/**
	 * Registers all /manufacture* routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/manufacture-orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_orders' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_draft' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manufacture-orders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_draft' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manufacture-orders/(?P<id>\d+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manufacture-orders/(?P<id>\d+)/reverse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reverse' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manufacture/templates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manufacture/existing',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_existing' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback shared by every route.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Lists manufacture orders, optionally filtered.
	 *
	 * @param WP_REST_Request $request Accepts `status` and `product_id` query params.
	 */
	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$status     = (string) $request->get_param( 'status' );
		$product_id = $request->get_param( 'product_id' );

		$list = $this->orders->list( '' !== $status ? $status : null, null !== $product_id ? (int) $product_id : null );

		return new WP_REST_Response( array( 'orders' => array_map( array( $this, 'present_order' ), $list ) ) );
	}

	/**
	 * Fetches one manufacture order.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( WP_REST_Request $request ) {
		$mo = $this->orders->get( (int) $request->get_param( 'id' ) );
		if ( null === $mo ) {
			return new WP_Error( 'wcbom_mo_not_found', __( 'Unknown manufacture order.', 'wcbom' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $mo ) ) );
	}

	/**
	 * Creates a draft manufacture order — either restocking an existing
	 * manufactured product, or creating a new one from a template.
	 *
	 * @param WP_REST_Request $request Body: mode ('existing'|'template') + mode-specific fields + qty_built, notes.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_draft( WP_REST_Request $request ) {
		$mode      = (string) $request->get_param( 'mode' );
		$qty_built = (int) $request->get_param( 'qty_built' );
		$notes     = (string) $request->get_param( 'notes' );

		if ( $qty_built <= 0 ) {
			return new WP_Error( 'wcbom_invalid_qty', __( 'qty_built must be a positive integer.', 'wcbom' ), array( 'status' => 400 ) );
		}

		try {
			if ( 'existing' === $mode ) {
				$product_id = (int) $request->get_param( 'product_id' );
				if ( $product_id <= 0 ) {
					return new WP_Error( 'wcbom_invalid_product', __( 'product_id is required.', 'wcbom' ), array( 'status' => 400 ) );
				}
				$mo = $this->manufacture->create_draft_for_existing( $product_id, $qty_built, '' !== $notes ? $notes : null );
			} elseif ( 'template' === $mode ) {
				$template_id = (int) $request->get_param( 'template_product_id' );
				$title       = trim( (string) $request->get_param( 'title' ) );
				$price       = (string) $request->get_param( 'price' );
				$attributes  = $request->get_param( 'attributes' );

				if ( $template_id <= 0 || '' === $title || ! is_array( $attributes ) ) {
					return new WP_Error( 'wcbom_invalid_template_request', __( 'template_product_id, title, and attributes are required.', 'wcbom' ), array( 'status' => 400 ) );
				}

				$mo = $this->manufacture->create_draft_from_template( $template_id, $title, $price, $attributes, $qty_built, '' !== $notes ? $notes : null );
			} else {
				return new WP_Error( 'wcbom_invalid_mode', __( 'mode must be "existing" or "template".', 'wcbom' ), array( 'status' => 400 ) );
			}
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_mo_create_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $mo ) ) );
	}

	/**
	 * Completes a draft manufacture order.
	 *
	 * @param WP_REST_Request $request Route param: id. Body: op_key, allow_negative?.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete( WP_REST_Request $request ) {
		$op_key = (string) $request->get_param( 'op_key' );
		if ( '' === $op_key ) {
			return new WP_Error( 'wcbom_missing_op_key', __( 'Missing op_key.', 'wcbom' ), array( 'status' => 400 ) );
		}

		try {
			$mo = $this->manufacture->complete(
				(int) $request->get_param( 'id' ),
				$op_key,
				(bool) $request->get_param( 'allow_negative' )
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_mo_complete_failed', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wcbom_mo_complete_failed', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $mo ) ) );
	}

	/**
	 * Reverses N units of a completed manufacture order.
	 *
	 * @param WP_REST_Request $request Route param: id. Body: op_key, qty, scrap_component_ids?.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reverse( WP_REST_Request $request ) {
		$op_key = (string) $request->get_param( 'op_key' );
		if ( '' === $op_key ) {
			return new WP_Error( 'wcbom_missing_op_key', __( 'Missing op_key.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$scrap = $request->get_param( 'scrap_component_ids' );
		$scrap = is_array( $scrap ) ? array_map( 'intval', $scrap ) : array();

		try {
			$mo = $this->manufacture->reverse(
				(int) $request->get_param( 'id' ),
				(int) $request->get_param( 'qty' ),
				$op_key,
				$scrap
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_mo_reverse_failed', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wcbom_mo_reverse_failed', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $mo ) ) );
	}

	/**
	 * Deletes a draft manufacture order.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_draft( WP_REST_Request $request ) {
		$deleted = $this->manufacture->delete_draft( (int) $request->get_param( 'id' ) );
		if ( ! $deleted ) {
			return new WP_Error( 'wcbom_mo_delete_failed', __( 'Only draft manufacture orders can be deleted.', 'wcbom' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Lists made-to-order products usable as "new from template" templates,
	 * each with its variation attributes for the option picker.
	 */
	public function list_templates(): WP_REST_Response {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 100, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin picker, not a paginated frontend list.
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wcbom_mode',
						'value' => 'made_to_order',
					),
				),
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$results[] = array(
				'id'         => $product->get_id(),
				'name'       => $product->get_name(),
				'attributes' => $this->variation_attributes( $product ),
			);
		}

		return new WP_REST_Response( array( 'templates' => $results ) );
	}

	/**
	 * Lists existing manufactured-mode products (the "restock existing" picker).
	 */
	public function list_existing(): WP_REST_Response {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 100, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin picker, not a paginated frontend list.
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wcbom_mode',
						'value' => 'manufactured',
					),
				),
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$results[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'stock' => $product->get_stock_quantity(),
			);
		}

		return new WP_REST_Response( array( 'existing' => $results ) );
	}

	/**
	 * The product's variation-level attributes, for the template picker —
	 * same shape ProductBomMetabox already sends the BOM editor.
	 *
	 * @param \WC_Product $product The template product.
	 * @return array<int,array{taxonomy:string,label:string,terms:array<int,array{slug:string,name:string}>}>
	 */
	private function variation_attributes( \WC_Product $product ): array {
		$result = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, WC_Product_Attribute::class ) || ! $attribute->get_variation() || ! $attribute->is_taxonomy() ) {
				continue;
			}

			$taxonomy = $attribute->get_name();
			$terms    = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'all' ) );

			$result[] = array(
				'taxonomy' => $taxonomy,
				'label'    => wc_attribute_label( $taxonomy ),
				'terms'    => array_map(
					static fn( $term ): array => array(
						'slug' => $term->slug,
						'name' => $term->name,
					),
					$terms
				),
			);
		}

		return $result;
	}

	/**
	 * Shapes a ManufactureOrder for the REST response.
	 *
	 * @param ManufactureOrder $mo The manufacture order to present.
	 * @return array<string,mixed>
	 */
	private function present_order( ManufactureOrder $mo ): array {
		$product = wc_get_product( $mo->product_id );

		return array(
			'mo_id'        => $mo->mo_id,
			'product_id'   => $mo->product_id,
			'product_name' => $product ? $product->get_name() : null,
			'bom_id'       => $mo->bom_id,
			'qty_built'    => $mo->qty_built,
			'qty_reversed' => $mo->qty_reversed,
			'remaining'    => $mo->remaining_units(),
			'status'       => $mo->status,
			'notes'        => $mo->notes,
			'created_by'   => $mo->created_by,
			'created_at'   => $mo->created_at,
			'completed_at' => $mo->completed_at,
			'items'        => array_map(
				function ( ManufactureOrderItem $item ): array {
					$component = wc_get_product( $item->component_id );
					$unit      = $component ? get_post_meta( $component->get_id(), '_wcbom_unit', true ) : '';

					return array(
						'component_id' => $item->component_id,
						'name'         => $component ? $component->get_name() : null,
						'unit'         => '' !== $unit ? $unit : 'ea',
						'qty_per_unit' => $item->qty_per_unit,
						'qty_total'    => $item->qty_total,
						'unit_cost'    => $item->unit_cost,
					);
				},
				$mo->items
			),
			'planned'      => ManufactureOrder::STATUS_DRAFT === $mo->status ? $this->planned_consumption( $mo ) : array(),
		);
	}

	/**
	 * For a draft MO, what completing it *would* consume: the BOM's lines
	 * × qty_built, alongside each component's current stock — this is the
	 * "required components vs. on-hand, flags shortages" preview
	 * (BUILD_PLAN.md §5.4). manufacture_order_items itself stays empty
	 * until the MO actually completes, so this is computed fresh each time
	 * rather than read from a snapshot.
	 *
	 * @param ManufactureOrder $mo A draft manufacture order.
	 * @return array<int,array{component_id:int,name:string|null,unit:string,qty_per_unit:float,qty_total:float,available:float,shortage:bool}>
	 */
	private function planned_consumption( ManufactureOrder $mo ): array {
		$bom = $this->boms->get( $mo->bom_id );
		if ( null === $bom ) {
			return array();
		}

		return array_map(
			function ( $line ) use ( $mo ): array {
				$component = wc_get_product( $line->component_id );
				$unit      = $component ? get_post_meta( $component->get_id(), '_wcbom_unit', true ) : '';
				$available = $component ? (float) $component->get_stock_quantity() : 0.0;
				$qty_total = $line->qty * $mo->qty_built;

				return array(
					'component_id' => $line->component_id,
					'name'         => $component ? $component->get_name() : null,
					'unit'         => '' !== $unit ? $unit : 'ea',
					'qty_per_unit' => $line->qty,
					'qty_total'    => $qty_total,
					'available'    => $available,
					'shortage'     => $available < $qty_total,
				);
			},
			$bom->items
		);
	}
}
