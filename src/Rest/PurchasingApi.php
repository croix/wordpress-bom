<?php
/**
 * REST routes backing the Purchasing screen (vendors + purchase orders).
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WCBOM\Purchasing\PurchaseOrder;
use WCBOM\Purchasing\PurchaseOrderItem;
use WCBOM\Purchasing\PurchaseOrderRepository;
use WCBOM\Purchasing\PurchaseOrderService;
use WCBOM\Purchasing\Vendor;
use WCBOM\Purchasing\VendorRepository;
use WCBOM\Purchasing\VendorsFeature;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wcbom/v1 /vendors* and /purchase-orders* routes — but only
 * when VendorsFeature is enabled (BUILD_PLAN.md §5.13's gating
 * requirement: "its REST routes are not registered" when off, not merely
 * inert). Same manage_woocommerce + nonce gate as every other controller.
 */
final class PurchasingApi {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param PurchaseOrderService    $purchasing The orchestration service.
	 * @param PurchaseOrderRepository $orders     PO lookup for list/get.
	 * @param VendorRepository        $vendors    Vendor CRUD.
	 */
	public function __construct(
		private readonly PurchaseOrderService $purchasing,
		private readonly PurchaseOrderRepository $orders,
		private readonly VendorRepository $vendors
	) {}

	/**
	 * Registers all /vendors* and /purchase-orders* routes — a no-op
	 * entirely when the feature is off, per class docblock.
	 */
	public function register_routes(): void {
		if ( ! VendorsFeature::enabled() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/vendors',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_vendors' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_vendor' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/vendors/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_vendor' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'archive_vendor' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/purchase-orders',
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
			'/purchase-orders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_draft' ),
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
			'/purchase-orders/(?P<id>\d+)/place',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'place' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/receive',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel' ),
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
	 * Lists vendors.
	 *
	 * @param WP_REST_Request $request Accepts `active_only` query param.
	 */
	public function list_vendors( WP_REST_Request $request ): WP_REST_Response {
		$active_only = (bool) $request->get_param( 'active_only' );

		return new WP_REST_Response( array( 'vendors' => array_map( array( $this, 'present_vendor' ), $this->vendors->list( $active_only ) ) ) );
	}

	/**
	 * Creates a vendor.
	 *
	 * @param WP_REST_Request $request Body: name, email?, phone?, website?, notes?.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_vendor( WP_REST_Request $request ) {
		$name = trim( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'wcbom_invalid_vendor', __( 'A vendor name is required.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$vendor_id = $this->vendors->create(
			$name,
			$this->optional_string( $request, 'email' ),
			$this->optional_string( $request, 'phone' ),
			$this->optional_string( $request, 'website' ),
			$this->optional_string( $request, 'notes' )
		);

		return new WP_REST_Response( array( 'vendor' => $this->present_vendor( $this->vendors->get( $vendor_id ) ) ) );
	}

	/**
	 * Updates a vendor's editable fields.
	 *
	 * @param WP_REST_Request $request Route param: id. Body: name, email?, phone?, website?, notes?.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_vendor( WP_REST_Request $request ) {
		$vendor_id = (int) $request->get_param( 'id' );
		if ( null === $this->vendors->get( $vendor_id ) ) {
			return new WP_Error( 'wcbom_vendor_not_found', __( 'Unknown vendor.', 'wcbom' ), array( 'status' => 404 ) );
		}

		$name = trim( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'wcbom_invalid_vendor', __( 'A vendor name is required.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$this->vendors->update(
			$vendor_id,
			$name,
			$this->optional_string( $request, 'email' ),
			$this->optional_string( $request, 'phone' ),
			$this->optional_string( $request, 'website' ),
			$this->optional_string( $request, 'notes' )
		);

		return new WP_REST_Response( array( 'vendor' => $this->present_vendor( $this->vendors->get( $vendor_id ) ) ) );
	}

	/**
	 * Archives (soft-deletes) a vendor.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 */
	public function archive_vendor( WP_REST_Request $request ): WP_REST_Response {
		$this->vendors->archive( (int) $request->get_param( 'id' ) );

		return new WP_REST_Response( array( 'archived' => true ) );
	}

	/**
	 * Lists purchase orders, optionally filtered.
	 *
	 * @param WP_REST_Request $request Accepts `status` and `vendor_id` query params.
	 */
	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$status    = (string) $request->get_param( 'status' );
		$vendor_id = $request->get_param( 'vendor_id' );

		$list = $this->orders->list( '' !== $status ? $status : null, null !== $vendor_id ? (int) $vendor_id : null );

		return new WP_REST_Response( array( 'orders' => array_map( array( $this, 'present_order' ), $list ) ) );
	}

	/**
	 * Fetches one purchase order.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( WP_REST_Request $request ) {
		$po = $this->orders->get( (int) $request->get_param( 'id' ) );
		if ( null === $po ) {
			return new WP_Error( 'wcbom_po_not_found', __( 'Unknown purchase order.', 'wcbom' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $po ) ) );
	}

	/**
	 * Creates a draft purchase order.
	 *
	 * @param WP_REST_Request $request Body: vendor_id, items[], reference?, expected_date?, notes?.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_draft( WP_REST_Request $request ) {
		$items = $this->parse_items( $request );
		if ( $items instanceof WP_Error ) {
			return $items;
		}

		try {
			$po = $this->purchasing->create_draft(
				(int) $request->get_param( 'vendor_id' ),
				$items,
				$this->optional_string( $request, 'reference' ),
				$this->optional_string( $request, 'expected_date' ),
				$this->optional_string( $request, 'notes' )
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_po_create_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $po ) ) );
	}

	/**
	 * Replaces a draft PO's header + line items.
	 *
	 * @param WP_REST_Request $request Route param: id. Body: vendor_id, items[], reference?, expected_date?, notes?.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_draft( WP_REST_Request $request ) {
		$items = $this->parse_items( $request );
		if ( $items instanceof WP_Error ) {
			return $items;
		}

		try {
			$po = $this->purchasing->update_draft(
				(int) $request->get_param( 'id' ),
				(int) $request->get_param( 'vendor_id' ),
				$items,
				$this->optional_string( $request, 'reference' ),
				$this->optional_string( $request, 'expected_date' ),
				$this->optional_string( $request, 'notes' )
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_po_update_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $po ) ) );
	}

	/**
	 * Places a draft PO with its vendor.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function place( WP_REST_Request $request ) {
		try {
			$po = $this->purchasing->place( (int) $request->get_param( 'id' ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_po_place_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $po ) ) );
	}

	/**
	 * Records a receipt against one or more lines.
	 *
	 * @param WP_REST_Request $request Route param: id. Body: op_key, receipts: {poi_id: qty}.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( WP_REST_Request $request ) {
		$op_key = (string) $request->get_param( 'op_key' );
		if ( '' === $op_key ) {
			return new WP_Error( 'wcbom_missing_op_key', __( 'Missing op_key.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$receipts = $request->get_param( 'receipts' );
		if ( ! is_array( $receipts ) || array() === $receipts ) {
			return new WP_Error( 'wcbom_invalid_receipts', __( 'receipts must be a non-empty map of poi_id to quantity.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$parsed = array();
		foreach ( $receipts as $poi_id => $qty ) {
			$parsed[ (int) $poi_id ] = (float) $qty;
		}

		try {
			$po = $this->purchasing->receive( (int) $request->get_param( 'id' ), $parsed, $op_key );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_po_receive_failed', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'wcbom_po_receive_failed', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $po ) ) );
	}

	/**
	 * Cancels a PO.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		try {
			$po = $this->purchasing->cancel( (int) $request->get_param( 'id' ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'wcbom_po_cancel_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $po ) ) );
	}

	/**
	 * Deletes a draft PO.
	 *
	 * @param WP_REST_Request $request Route param: id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_draft( WP_REST_Request $request ) {
		$deleted = $this->purchasing->delete_draft( (int) $request->get_param( 'id' ) );
		if ( ! $deleted ) {
			return new WP_Error( 'wcbom_po_delete_failed', __( 'Only draft purchase orders can be deleted.', 'wcbom' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Parses and validates the `items` body param shared by create/update.
	 *
	 * @param WP_REST_Request $request Body: items[{component_id, qty_ordered, unit_cost?}].
	 * @return array<int,array{component_id:int,qty_ordered:float,unit_cost:float|null}>|WP_Error
	 */
	private function parse_items( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error( 'wcbom_invalid_items', __( 'items must be a non-empty array.', 'wcbom' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['component_id'], $item['qty_ordered'] ) ) {
				return new WP_Error( 'wcbom_invalid_items', __( 'Each item needs a component_id and qty_ordered.', 'wcbom' ), array( 'status' => 400 ) );
			}

			$component_id = (int) $item['component_id'];
			$qty_ordered  = (float) $item['qty_ordered'];
			if ( $component_id <= 0 || ! wc_get_product( $component_id ) || $qty_ordered <= 0 ) {
				return new WP_Error( 'wcbom_invalid_items', __( 'Each item needs a valid component and a positive quantity.', 'wcbom' ), array( 'status' => 400 ) );
			}

			$clean[] = array(
				'component_id' => $component_id,
				'qty_ordered'  => $qty_ordered,
				'unit_cost'    => isset( $item['unit_cost'] ) && '' !== $item['unit_cost'] ? (float) $item['unit_cost'] : null,
			);
		}

		return $clean;
	}

	/**
	 * A body param, trimmed to null when absent/blank.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $key     Param name.
	 */
	private function optional_string( WP_REST_Request $request, string $key ): ?string {
		$value = trim( (string) $request->get_param( $key ) );

		return '' !== $value ? $value : null;
	}

	/**
	 * Shapes a Vendor for the REST response.
	 *
	 * @param Vendor|null $vendor The vendor to present.
	 * @return array<string,mixed>|null
	 */
	private function present_vendor( ?Vendor $vendor ): ?array {
		if ( null === $vendor ) {
			return null;
		}

		return array(
			'vendor_id'  => $vendor->vendor_id,
			'name'       => $vendor->name,
			'email'      => $vendor->email,
			'phone'      => $vendor->phone,
			'website'    => $vendor->website,
			'notes'      => $vendor->notes,
			'is_active'  => $vendor->is_active,
			'created_at' => $vendor->created_at,
		);
	}

	/**
	 * Shapes a PurchaseOrder for the REST response.
	 *
	 * @param PurchaseOrder $po The purchase order to present.
	 * @return array<string,mixed>
	 */
	private function present_order( PurchaseOrder $po ): array {
		$vendor = $this->vendors->get( $po->vendor_id );

		return array(
			'po_id'         => $po->po_id,
			'vendor_id'     => $po->vendor_id,
			'vendor_name'   => $vendor ? $vendor->name : null,
			'status'        => $po->status,
			'reference'     => $po->reference,
			'expected_date' => $po->expected_date,
			'notes'         => $po->notes,
			'created_by'    => $po->created_by,
			'created_at'    => $po->created_at,
			'ordered_at'    => $po->ordered_at,
			'closed_at'     => $po->closed_at,
			'items'         => array_map(
				function ( PurchaseOrderItem $item ): array {
					$component = wc_get_product( $item->component_id );
					$unit      = $component ? get_post_meta( $component->get_id(), '_wcbom_unit', true ) : '';

					return array(
						'poi_id'          => $item->poi_id,
						'component_id'    => $item->component_id,
						'name'            => $component ? $component->get_name() : null,
						'unit'            => '' !== $unit ? $unit : 'ea',
						'qty_ordered'     => $item->qty_ordered,
						'qty_received'    => $item->qty_received,
						'qty_outstanding' => $item->qty_outstanding(),
						'unit_cost'       => $item->unit_cost,
					);
				},
				$po->items
			),
		);
	}
}
