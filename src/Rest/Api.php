<?php
/**
 * REST routes backing the BOM editor.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WCBOM\Bom\Bom;
use WCBOM\Bom\BomItem;
use WCBOM\Bom\BomRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wcbom/v1 routes: read/save a product's BOM, search/create
 * components, and a buildable-qty + cost preview. All gated on
 * manage_woocommerce — this is an admin editing surface, not storefront API.
 */
final class Api {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param BomRepository $boms Repository used by every route below.
	 */
	public function __construct( private readonly BomRepository $boms ) {}

	/**
	 * Registers all wcbom/v1 routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/boms/(?P<product_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bom' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_bom' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/buildable/(?P<product_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_buildable' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/components/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_components' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/components',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_component' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback shared by every route: admin/shop-manager only.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Returns the active BOM for a product, or null if it has none.
	 *
	 * @param WP_REST_Request $request Must have a product_id route param.
	 */
	public function get_bom( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'product_id' );
		$bom        = $this->boms->get_active_for_product( $product_id );

		return new WP_REST_Response( array( 'bom' => null !== $bom ? $this->present_bom( $bom ) : null ) );
	}

	/**
	 * Validates and saves a new BOM version for a product.
	 *
	 * @param WP_REST_Request $request Must have a product_id route param and
	 *                                 an `items` body param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_bom( WP_REST_Request $request ) {
		$product_id = (int) $request->get_param( 'product_id' );

		if ( ! wc_get_product( $product_id ) ) {
			return new WP_Error( 'wcbom_unknown_product', __( 'Unknown product.', 'wcbom' ), array( 'status' => 404 ) );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'wcbom_invalid_items', __( 'items must be an array.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $items as $item ) {
			$component_id = isset( $item['component_id'] ) ? (int) $item['component_id'] : 0;
			if ( $component_id <= 0 || ! wc_get_product( $component_id ) ) {
				return new WP_Error( 'wcbom_invalid_component', __( 'Every line needs a valid component.', 'wcbom' ), array( 'status' => 400 ) );
			}

			$condition_type = isset( $item['condition_type'] ) ? (string) $item['condition_type'] : BomItem::CONDITION_ALWAYS;
			if ( ! in_array( $condition_type, array( BomItem::CONDITION_ALWAYS, BomItem::CONDITION_ATTRIBUTE, BomItem::CONDITION_ADDON ), true ) ) {
				return new WP_Error( 'wcbom_invalid_condition', __( 'Invalid condition_type.', 'wcbom' ), array( 'status' => 400 ) );
			}

			$surcharge = null;
			if ( isset( $item['surcharge'] ) && '' !== $item['surcharge'] ) {
				if ( ! is_numeric( $item['surcharge'] ) || (float) $item['surcharge'] < 0 ) {
					return new WP_Error( 'wcbom_invalid_surcharge', __( 'Surcharge must be a non-negative number.', 'wcbom' ), array( 'status' => 400 ) );
				}
				$surcharge = (float) $item['surcharge'];
			}

			$clean[] = array(
				'component_id'    => $component_id,
				'qty'             => isset( $item['qty'] ) ? (float) $item['qty'] : 0.0,
				'condition_type'  => $condition_type,
				'condition_key'   => BomItem::CONDITION_ALWAYS === $condition_type
					? null
					: sanitize_key( (string) ( $item['condition_key'] ?? '' ) ),
				'condition_value' => BomItem::CONDITION_ALWAYS === $condition_type
					? null
					: sanitize_title( (string) ( $item['condition_value'] ?? '' ) ),
				'surcharge'       => $surcharge,
			);
		}

		try {
			$bom = $this->boms->save( $product_id, $clean, get_current_user_id() );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_bom_save_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'bom' => $this->present_bom( $bom ) ) );
	}

	/**
	 * A Phase 1 buildable-quantity + cost preview, considering only the
	 * BOM's unconditional ("always") lines.
	 *
	 * @param WP_REST_Request $request Must have a product_id route param.
	 */
	public function get_buildable( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'product_id' );
		$bom        = $this->boms->get_active_for_product( $product_id );

		if ( null === $bom || array() === $bom->items ) {
			return new WP_REST_Response(
				array(
					'buildable_qty'  => 0,
					'bottleneck'     => null,
					'estimated_cost' => 0.0,
					'note'           => __( 'No BOM lines yet.', 'wcbom' ),
				)
			);
		}

		// Phase 1 preview: only "always" lines are considered (a real
		// per-option buildable count needs the phantom-stock work in
		// Phase 3, which also accounts for reserved/on-hold stock).
		$always     = $bom->always_items();
		$buildable  = null;
		$bottleneck = null;
		$cost       = 0.0;

		foreach ( $always as $item ) {
			$component = wc_get_product( $item->component_id );
			if ( ! $component ) {
				continue;
			}

			$cost += $item->qty * (float) $component->get_regular_price();

			if ( $item->qty <= 0 ) {
				continue;
			}

			$possible = (int) floor( (float) $component->get_stock_quantity() / $item->qty );
			if ( null === $buildable || $possible < $buildable ) {
				$buildable  = $possible;
				$bottleneck = array(
					'id'   => $component->get_id(),
					'name' => $component->get_name(),
				);
			}
		}

		return new WP_REST_Response(
			array(
				'buildable_qty'  => $buildable ?? 0,
				'bottleneck'     => $bottleneck,
				'estimated_cost' => round( $cost, 2 ),
				'note'           => array() === $always
					? __( 'Only conditional lines are set — buildable count needs at least one always-consumed line.', 'wcbom' )
					: null,
			)
		);
	}

	/**
	 * Searches products flagged as components by title.
	 *
	 * @param WP_REST_Request $request Expects a `term` query param.
	 */
	public function search_components( WP_REST_Request $request ): WP_REST_Response {
		$term = (string) $request->get_param( 'term' );

		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				's'              => $term,
				'posts_per_page' => 20,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wcbom_is_component',
						'value' => 'yes',
					),
				),
			)
		);

		$results = array_map(
			fn( WP_Post $post ): array => $this->present_component( wc_get_product( $post->ID ) ),
			$query->posts
		);

		return new WP_REST_Response( array( 'components' => array_values( array_filter( $results ) ) ) );
	}

	/**
	 * Quick-creates a hidden component product (the picker's "create new"
	 * inline flow).
	 *
	 * @param WP_REST_Request $request Expects `name` and optional `unit` body params.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_component( WP_REST_Request $request ) {
		$name = trim( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'wcbom_invalid_name', __( 'Component name is required.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$unit_param = $request->get_param( 'unit' );
		$unit       = ( null !== $unit_param && '' !== $unit_param ) ? (string) $unit_param : 'ea';

		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '0' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		update_post_meta( $product_id, '_wcbom_is_component', 'yes' );
		update_post_meta( $product_id, '_wcbom_unit', $unit );

		return new WP_REST_Response( array( 'component' => $this->present_component( wc_get_product( $product_id ) ) ) );
	}

	/**
	 * Shapes a Bom for the REST response, enriching each line with its
	 * component's display data.
	 *
	 * @param Bom $bom The BOM to present.
	 * @return array<string,mixed>
	 */
	private function present_bom( Bom $bom ): array {
		return array(
			'bom_id'     => $bom->bom_id,
			'product_id' => $bom->product_id,
			'version'    => $bom->version,
			'created_at' => $bom->created_at,
			'items'      => array_map(
				function ( BomItem $item ): array {
					$component = wc_get_product( $item->component_id );

					return array(
						'item_id'         => $item->item_id,
						'component_id'    => $item->component_id,
						'component'       => $component ? $this->present_component( $component ) : null,
						'qty'             => $item->qty,
						'condition_type'  => $item->condition_type,
						'condition_key'   => $item->condition_key,
						'condition_value' => $item->condition_value,
						'sort_order'      => $item->sort_order,
						'surcharge'       => $item->surcharge,
					);
				},
				$bom->items
			),
		);
	}

	/**
	 * Shapes a product as a component for the REST response.
	 *
	 * @param \WC_Product|false $product Result of a wc_get_product() call.
	 * @return array<string,mixed>|null
	 */
	private function present_component( $product ): ?array {
		if ( ! $product ) {
			return null;
		}

		$unit = get_post_meta( $product->get_id(), '_wcbom_unit', true );

		return array(
			'id'     => $product->get_id(),
			'name'   => $product->get_name(),
			'sku'    => $product->get_sku(),
			'unit'   => '' !== $unit ? $unit : 'ea',
			'stock'  => $product->get_stock_quantity(),
			'price'  => $product->get_regular_price(),
			'weight' => $product->get_weight(),
		);
	}
}
