<?php
/**
 * REST routes backing the Reports screen.
 *
 * @package WCBOM
 */

declare(strict_types=1);

namespace WCBOM\Rest;

use WCBOM\Reports\BuildableReport;
use WCBOM\Reports\ComponentUsageReport;
use WCBOM\Reports\LowStockReport;
use WCBOM\Reports\MarginReport;
use WCBOM\Reports\ProfitabilityReport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wcbom/v1 /reports/* read-only routes (BUILD_PLAN.md §5.5),
 * one per report tab on the Reports admin screen. Gated on
 * manage_woocommerce, same as every other admin-facing route.
 */
final class ReportsApi {

	private const NAMESPACE = 'wcbom/v1';

	/**
	 * Constructs the controller.
	 *
	 * @param BuildableReport      $buildable     Buildable-stock report.
	 * @param ComponentUsageReport $usage         Component usage report.
	 * @param LowStockReport       $low_stock     Low-stock report.
	 * @param MarginReport         $margin        Margin report.
	 * @param ProfitabilityReport  $profitability Profitability reports (§5.15).
	 */
	public function __construct(
		private readonly BuildableReport $buildable,
		private readonly ComponentUsageReport $usage,
		private readonly LowStockReport $low_stock,
		private readonly MarginReport $margin,
		private readonly ProfitabilityReport $profitability
	) {}

	/**
	 * Registers all /reports/* routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reports/buildable',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_buildable' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/low-stock',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_low_stock' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/margin',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_margin' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage_all' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/usage/(?P<component_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/profitability/product',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_profitability_product' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/profitability/order',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_profitability_order' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/profitability/trend',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_profitability_trend' ),
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
	 * GET /reports/buildable.
	 */
	public function get_buildable(): WP_REST_Response {
		return new WP_REST_Response( array( 'rows' => $this->buildable->generate() ) );
	}

	/**
	 * GET /reports/low-stock.
	 */
	public function get_low_stock(): WP_REST_Response {
		return new WP_REST_Response( array( 'rows' => $this->low_stock->generate() ) );
	}

	/**
	 * GET /reports/margin.
	 */
	public function get_margin(): WP_REST_Response {
		return new WP_REST_Response( array( 'rows' => $this->margin->generate() ) );
	}

	/**
	 * GET /reports/usage — every component's usage row, for the Reports
	 * screen's list-everything table.
	 */
	public function get_usage_all(): WP_REST_Response {
		return new WP_REST_Response( array( 'rows' => $this->usage->generate() ) );
	}

	/**
	 * GET /reports/usage/{component_id}.
	 *
	 * @param WP_REST_Request $request Must have a component_id route param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_usage( WP_REST_Request $request ) {
		$row = $this->usage->for_component( (int) $request->get_param( 'component_id' ) );
		if ( null === $row ) {
			return new WP_Error( 'wcbom_unknown_component', __( 'Unknown component.', 'pv-bom-stock' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $row );
	}

	/**
	 * GET /reports/profitability/product. Accepts `date_from`/`date_to`
	 * (Y-m-d), defaulting to the trailing 30 days.
	 *
	 * @param WP_REST_Request $request Optional date_from/date_to query params.
	 */
	public function get_profitability_product( WP_REST_Request $request ): WP_REST_Response {
		[ $date_from, $date_to ] = $this->date_range( $request );

		return new WP_REST_Response( array( 'rows' => $this->profitability->product_rows( $date_from, $date_to ) ) );
	}

	/**
	 * GET /reports/profitability/order. Same date-range params as the product view.
	 *
	 * @param WP_REST_Request $request Optional date_from/date_to query params.
	 */
	public function get_profitability_order( WP_REST_Request $request ): WP_REST_Response {
		[ $date_from, $date_to ] = $this->date_range( $request );

		return new WP_REST_Response( array( 'rows' => $this->profitability->order_rows( $date_from, $date_to ) ) );
	}

	/**
	 * GET /reports/profitability/trend — always the trailing 12 calendar
	 * months, independent of the product/order views' date range.
	 */
	public function get_profitability_trend(): WP_REST_Response {
		return new WP_REST_Response( array( 'rows' => $this->profitability->trend_rows() ) );
	}

	/**
	 * The product/order profitability views' shared date-range params,
	 * defaulting to the trailing 30 days when not supplied.
	 *
	 * @param WP_REST_Request $request Optional date_from/date_to (Y-m-d) query params.
	 * @return array{0:string,1:string} date_from, date_to.
	 */
	private function date_range( WP_REST_Request $request ): array {
		$date_from = (string) $request->get_param( 'date_from' );
		$date_to   = (string) $request->get_param( 'date_to' );

		if ( '' === $date_to ) {
			$date_to = current_time( 'Y-m-d' );
		}
		if ( '' === $date_from ) {
			$date_from = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) . ' -30 days' ) );
		}

		return array( $date_from, $date_to );
	}
}
