<?php
/**
 * NV oOS Graphify — REST API Controller
 *
 * Exposes the knowledge graph via the WordPress REST API at:
 *   /wp-json/nvoos-graphify/v1/
 *
 * Routes:
 *   GET  /graph          — metadata and stats
 *   GET  /nodes          — paginated, filterable node list
 *   GET  /nodes/{node_id} — single node with neighbor edges
 *   POST /build          — trigger a graph build (manage_options)
 *   GET  /search         — label search
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the Graphify addon.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'nvoos-graphify/v1';

	/**
	 * Register hooks.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		// GET /graph — metadata + stats.
		register_rest_route(
			self::NAMESPACE,
			'/graph',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_graph' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
			)
		);

		// GET /nodes — paginated node list.
		register_rest_route(
			self::NAMESPACE,
			'/nodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_nodes' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'per_page'     => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200, 'sanitize_callback' => 'absint' ),
					'page'         => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
					'type'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'community_id' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'search'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		// GET /nodes/{node_id} — single node.
		register_rest_route(
			self::NAMESPACE,
			'/nodes/(?P<node_id>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_node' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'node_id' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true ),
				),
			)
		);

		// POST /build — trigger a build.
		register_rest_route(
			self::NAMESPACE,
			'/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'trigger_build' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'incremental' => array( 'type' => 'boolean', 'default' => false ),
					'semantic'    => array( 'type' => 'boolean', 'default' => true ),
					'reset'       => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		// GET /search — label search.
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search_nodes' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'q'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'type'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'limit' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		// GET /export — export in various formats.
		register_rest_route(
			self::NAMESPACE,
			'/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export_graph' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'format'     => array( 'type' => 'string', 'default' => 'json', 'enum' => array( 'json', 'html', 'graphml', 'csv', 'neo4j', 'obsidian' ) ),
					'max_nodes'  => array( 'type' => 'integer', 'default' => 2000, 'minimum' => 1, 'maximum' => 5000, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Route callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /graph — Return metadata and stats.
	 *
	 * @since 0.5.0
	 *
	 * @return WP_REST_Response
	 */
	public static function get_graph() {
		$stats      = NV_oOS_Graphify_DB::get_stats();
		$last_build = NV_oOS_Graphify_DB::get_meta( 'last_build_completed', 'never' );
		$status     = NV_oOS_Graphify_DB::get_meta( 'build_status', 'idle' );

		return rest_ensure_response(
			array(
				'version'      => NVOOS_GRAPHIFY_VERSION,
				'stats'        => $stats,
				'last_build'   => $last_build,
				'build_status' => $status,
			)
		);
	}

	/**
	 * GET /nodes — Paginated, filterable node list.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_nodes( WP_REST_Request $request ) {
		$per_page     = $request->get_param( 'per_page' );
		$page         = $request->get_param( 'page' );
		$offset       = ( $page - 1 ) * $per_page;
		$type         = $request->get_param( 'type' );
		$community_id = $request->get_param( 'community_id' );
		$search       = $request->get_param( 'search' );

		$nodes = NV_oOS_Graphify_DB::list_nodes(
			array(
				'limit'        => $per_page,
				'offset'       => $offset,
				'type'         => $type,
				'community_id' => $community_id,
				'search'       => $search,
			)
		);

		$response = rest_ensure_response( $nodes );
		$response->header( 'X-WP-Page', $page );
		$response->header( 'X-WP-PerPage', $per_page );

		return $response;
	}

	/**
	 * GET /nodes/{node_id} — Single node with neighbors.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_node( WP_REST_Request $request ) {
		$node_id = sanitize_text_field( $request->get_param( 'node_id' ) );
		$node    = NV_oOS_Graphify_DB::get_node( $node_id );

		if ( ! $node ) {
			return new WP_Error( 'nvoos_graphify_not_found', __( 'Node not found.', 'nvoos-graphify' ), array( 'status' => 404 ) );
		}

		$edges     = NV_oOS_Graphify_DB::get_edges_for_node( $node_id );
		$neighbors = array();
		foreach ( $edges as $edge ) {
			$nid          = ( $edge->source_node_id === $node_id ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr          = NV_oOS_Graphify_DB::get_node( $nid );
			$neighbors[]  = array(
				'node_id'  => $nid,
				'label'    => $nbr ? $nbr->label : $nid,
				'type'     => $nbr ? $nbr->type : '',
				'relation' => $edge->relation,
				'direction'=> ( $edge->source_node_id === $node_id ) ? 'outgoing' : 'incoming',
			);
		}

		return rest_ensure_response(
			array(
				'node'      => $node,
				'neighbors' => $neighbors,
			)
		);
	}

	/**
	 * POST /build — Trigger a graph build.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function trigger_build( WP_REST_Request $request ) {
		$result = NV_oOS_Graphify_Builder::build(
			array(
				'incremental'    => (bool) $request->get_param( 'incremental' ),
				'semantic'       => (bool) $request->get_param( 'semantic' ),
				'async_semantic' => true,
				'reset'          => (bool) $request->get_param( 'reset' ),
			)
		);
		return rest_ensure_response( $result );
	}

	/**
	 * GET /search — Node label search.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function search_nodes( WP_REST_Request $request ) {
		$query = sanitize_text_field( $request->get_param( 'q' ) );
		$type  = sanitize_text_field( $request->get_param( 'type' ) );
		$limit = absint( $request->get_param( 'limit' ) );

		$results = NV_oOS_Graphify_DB::search_nodes( $query, $type, $limit );

		return rest_ensure_response(
			array(
				'query'   => $query,
				'results' => $results,
				'count'   => count( $results ),
			)
		);
	}

	/**
	 * GET /export — Export graph.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function export_graph( WP_REST_Request $request ) {
		$format    = sanitize_key( $request->get_param( 'format' ) );
		$max_nodes = absint( $request->get_param( 'max_nodes' ) );

		$result = NV_oOS_Graphify_Exporter::export( $format, array( 'max_nodes' => $max_nodes ) );

		return rest_ensure_response(
			array(
				'format' => $format,
				'data'   => $result,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Check read permission: authenticated user with at least 'read' capability.
	 *
	 * @since 0.5.0
	 *
	 * @return bool|WP_Error
	 */
	public static function check_read_permission() {
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}
		// Allow public access if the base plugin guest token is valid.
		if ( function_exists( 'wp_mcp_ai_validate_guest_token' ) && wp_mcp_ai_validate_guest_token() ) {
			return true;
		}
		return new WP_Error( 'nvoos_graphify_forbidden', __( 'You must be logged in to access the knowledge graph.', 'nvoos-graphify' ), array( 'status' => 401 ) );
	}

	/**
	 * Check admin permission: manage_options capability required.
	 *
	 * @since 0.5.0
	 *
	 * @return bool|WP_Error
	 */
	public static function check_admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'nvoos_graphify_forbidden', __( 'Administrator access required.', 'nvoos-graphify' ), array( 'status' => 403 ) );
	}
}
