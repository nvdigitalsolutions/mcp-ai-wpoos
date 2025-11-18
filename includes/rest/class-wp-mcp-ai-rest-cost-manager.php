<?php
/**
 * REST API Cost Manager Controller
 *
 * Handles cost-related REST API endpoints.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cost Manager REST API controller class.
 *
 * Provides endpoints for accessing cost data and analytics.
 */
class WP_MCP_AI_REST_Cost_Manager {

	/**
	 * Namespace for cost endpoints.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST API routes.
	 *
	 * Registers cost tracking and analysis REST API endpoints:
	 * - GET /users/{id}/cost-breakdown: Get detailed cost breakdown for a specific user
	 * - GET /cost/total: Get site-wide cost breakdown across all users
	 * - GET /cost/by-provider: Get cost breakdown by AI provider (OpenAI, Gemini, Ollama)
	 * - GET /cost/trend: Get cost trends over time for budget forecasting
	 * - GET /users/{id}/roi: Calculate return on investment metrics for a user
	 * - GET /cost/dashboard-summary: Get summary data for admin cost dashboard
	 *
	 * Permission levels:
	 * - User cost breakdown: Users can access their own data, admins can access all
	 * - Site-wide costs: Requires 'manage_options' capability (admin-only)
	 * - Provider costs: Requires 'manage_options' capability (admin-only)
	 * - Cost trends: Requires 'manage_options' capability (admin-only)
	 * - User ROI: Users can access their own data, admins can access all
	 * - Dashboard summary: Requires 'manage_options' capability (admin-only)
	 *
	 * Cost data includes:
	 * - Token usage costs (input tokens, output tokens, cached tokens)
	 * - Tool execution costs (API calls, processing time)
	 * - Breakdown by model, assistant, and time period
	 * - Formatted cost strings in configured currency
	 * - Historical trends and forecasting data
	 *
	 * @since 1.0.0
	 */
	public static function register_routes() {
		// Get user cost breakdown.
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>[\d]+)/cost-breakdown',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_cost_breakdown' ),
				'permission_callback' => array( __CLASS__, 'check_cost_access_permission' ),
				'args'                => array(
					'id'         => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'start_date' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					),
					'end_date'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => gmdate( 'Y-m-d' ),
					),
				),
			)
		);

		// Get site-wide cost breakdown.
		register_rest_route(
			self::NAMESPACE,
			'/cost/total',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_site_cost_breakdown' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'start_date' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					),
					'end_date'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => gmdate( 'Y-m-d' ),
					),
				),
			)
		);

		// Get cost by provider.
		register_rest_route(
			self::NAMESPACE,
			'/cost/by-provider',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_cost_by_provider' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'days' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 30,
					),
				),
			)
		);

		// Get cost trend data.
		register_rest_route(
			self::NAMESPACE,
			'/cost/trend',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_cost_trend' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'days' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 30,
					),
				),
			)
		);

		// Get user ROI.
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>[\d]+)/roi',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_roi' ),
				'permission_callback' => array( __CLASS__, 'check_cost_access_permission' ),
				'args'                => array(
					'id'               => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'time_saved_hours' => array(
						'required'          => false,
						'type'              => 'number',
						'sanitize_callback' => 'floatval',
						'default'           => 0,
					),
					'tasks_automated'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'hourly_rate'      => array(
						'required'          => false,
						'type'              => 'number',
						'sanitize_callback' => 'floatval',
						'default'           => 50.0,
					),
					'days'             => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 30,
					),
				),
			)
		);

		// Get dashboard cost summary.
		register_rest_route(
			self::NAMESPACE,
			'/cost/dashboard-summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_dashboard_summary' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'days' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 7,
					),
				),
			)
		);
	}

	/**
	 * Get user cost breakdown.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function get_user_cost_breakdown( $request ) {
		$user_id    = $request->get_param( 'id' );
		$start_date = $request->get_param( 'start_date' );
		$end_date   = $request->get_param( 'end_date' );

		$breakdown = WP_MCP_AI_Cost_Tracking_Service::get_user_cost_breakdown( $user_id, $start_date, $end_date );

		return rest_ensure_response(
			array(
				'user_id'    => $user_id,
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'breakdown'  => $breakdown,
				'total_cost' => $breakdown['total_cost'],
				'formatted'  => WP_MCP_AI_Cost_Calculator::format_cost( $breakdown['total_cost'] ),
			)
		);
	}

	/**
	 * Get site-wide cost breakdown.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function get_site_cost_breakdown( $request ) {
		$start_date = $request->get_param( 'start_date' );
		$end_date   = $request->get_param( 'end_date' );

		$breakdown = WP_MCP_AI_Cost_Tracking_Service::get_site_cost_breakdown( $start_date, $end_date );

		return rest_ensure_response(
			array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'breakdown'  => $breakdown,
				'total_cost' => $breakdown['total_cost'],
				'formatted'  => WP_MCP_AI_Cost_Calculator::format_cost( $breakdown['total_cost'] ),
			)
		);
	}

	/**
	 * Get cost by provider.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function get_cost_by_provider( $request ) {
		$days = $request->get_param( 'days' );

		$data = WP_MCP_AI_Cost_Tracking_Service::get_cost_by_provider_data( $days );

		return rest_ensure_response(
			array(
				'days' => $days,
				'data' => $data,
			)
		);
	}

	/**
	 * Get cost trend data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function get_cost_trend( $request ) {
		$days = $request->get_param( 'days' );

		$data = WP_MCP_AI_Cost_Tracking_Service::get_cost_trend_data( $days );

		return rest_ensure_response(
			array(
				'days' => $days,
				'data' => $data,
			)
		);
	}

	/**
	 * Get user ROI.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function get_user_roi( $request ) {
		$user_id = $request->get_param( 'id' );

		$metrics = array(
			'time_saved_hours' => $request->get_param( 'time_saved_hours' ),
			'tasks_automated'  => $request->get_param( 'tasks_automated' ),
			'hourly_rate'      => $request->get_param( 'hourly_rate' ),
		);

		$days = $request->get_param( 'days' );

		$roi = WP_MCP_AI_Cost_Tracking_Service::get_user_roi( $user_id, $metrics, $days );

		return rest_ensure_response(
			array(
				'user_id' => $user_id,
				'days'    => $days,
				'roi'     => $roi,
			)
		);
	}

	/**
	 * Get dashboard cost summary.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function get_dashboard_summary( $request ) {
		$days = $request->get_param( 'days' );

		$summary = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( $days );

		return rest_ensure_response(
			array(
				'days'    => $days,
				'summary' => $summary,
			)
		);
	}

	/**
	 * Check if user has permission to access cost data.
	 *
	 * Users can access their own data, admins can access all data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, error otherwise.
	 */
	public static function check_cost_access_permission( $request ) {
		$user_id         = $request->get_param( 'id' );
		$current_user_id = get_current_user_id();

		// Must be logged in.
		if ( ! $current_user_id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to access cost data.', 'wp-mcp-ai' ),
				array( 'status' => 401 )
			);
		}

		// Admins can access all data.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Users can access their own data.
		if ( $current_user_id === $user_id ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this cost data.', 'wp-mcp-ai' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @return bool|WP_Error True if authorized, error otherwise.
	 */
	public static function check_admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access site-wide cost data.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate user ID.
	 *
	 * @param int             $user_id User ID.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_user_id( $user_id, $request, $param ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return true;
	}
}
