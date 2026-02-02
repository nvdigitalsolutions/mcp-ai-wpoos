<?php
/**
 * REST API endpoints for Asset Inventory System.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset Inventory REST API class.
 *
 * Provides REST endpoints for ISO 27001 asset inventory management.
 */
class WP_MCP_AI_Asset_Inventory_REST {
	/**
	 * Namespace for REST API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Initialize REST API routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Get asset inventory.
		register_rest_route(
			self::NAMESPACE,
			'/assets/inventory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Trigger asset discovery.
		register_rest_route(
			self::NAMESPACE,
			'/assets/discover',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'trigger_discovery' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Get asset statistics.
		register_rest_route(
			self::NAMESPACE,
			'/assets/statistics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_statistics' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Get assets by classification.
		register_rest_route(
			self::NAMESPACE,
			'/assets/classification/(?P<level>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_by_classification' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'level' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return in_array(
								$param,
								array( 'public', 'internal', 'confidential', 'restricted' ),
								true
							);
						},
					),
				),
			)
		);

		// Get assets by type.
		register_rest_route(
			self::NAMESPACE,
			'/assets/type/(?P<type>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_by_type' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'type' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return in_array(
								$param,
								array( 'api_key', 'user_data', 'chat_transcript', 'code', 'configuration', 'database', 'third_party', 'documentation' ),
								true
							);
						},
					),
				),
			)
		);
	}

	/**
	 * Get asset inventory.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_inventory( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$inventory = WP_MCP_AI_Asset_Inventory::get_instance()->get_asset_inventory();

		if ( ! $inventory ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'No asset inventory found. Please run asset discovery first.',
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'inventory' => $inventory,
			),
			200
		);
	}

	/**
	 * Trigger asset discovery.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function trigger_discovery( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$assets = WP_MCP_AI_Asset_Inventory::get_instance()->discover_assets();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Asset discovery completed successfully.',
				'count'   => count( $assets ),
				'assets'  => $assets,
			),
			200
		);
	}

	/**
	 * Get asset statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_statistics( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$stats = WP_MCP_AI_Asset_Inventory::get_instance()->get_asset_statistics();

		return new WP_REST_Response(
			array(
				'success'    => true,
				'statistics' => $stats,
			),
			200
		);
	}

	/**
	 * Get assets by classification level.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_by_classification( $request ) {
		$level  = $request->get_param( 'level' );
		$assets = WP_MCP_AI_Asset_Inventory::get_instance()->get_assets_by_classification( $level );

		return new WP_REST_Response(
			array(
				'success'        => true,
				'classification' => $level,
				'count'          => count( $assets ),
				'assets'         => $assets,
			),
			200
		);
	}

	/**
	 * Get assets by type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_by_type( $request ) {
		$type   = $request->get_param( 'type' );
		$assets = WP_MCP_AI_Asset_Inventory::get_instance()->get_assets_by_type( $type );

		return new WP_REST_Response(
			array(
				'success' => true,
				'type'    => $type,
				'count'   => count( $assets ),
				'assets'  => $assets,
			),
			200
		);
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user has permission, false otherwise.
	 */
	public function check_admin_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API permission callback signature.
		return current_user_can( 'manage_options' );
	}
}

// Initialize REST API.
new WP_MCP_AI_Asset_Inventory_REST();
