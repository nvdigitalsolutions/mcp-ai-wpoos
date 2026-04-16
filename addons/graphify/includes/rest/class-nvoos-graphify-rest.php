<?php
/**
 * NV oOS Graphify — REST API Controller
 *
 * Provides REST endpoints for graph nodes, edges, communities,
 * reports, builds, exports, and search.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the Graphify addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_REST {

	/**
	 * Namespace for all endpoints.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'nvoos-graphify/v1';

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {

		// GET /graph — graph metadata.
		register_rest_route(
			self::NAMESPACE_V1,
			'/graph',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_graph' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
			)
		);

		// GET /nodes — list nodes.
		register_rest_route(
			self::NAMESPACE_V1,
			'/nodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_nodes' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'per_page'     => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'         => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'type'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'community_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'search'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /nodes/<id> — single node with neighbors.
		register_rest_route(
			self::NAMESPACE_V1,
			'/nodes/(?P<id>[\\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_node' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /edges — list edges.
		register_rest_route(
			self::NAMESPACE_V1,
			'/edges',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_edges' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'per_page'   => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'       => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'relation'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'confidence' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /communities — list communities.
		register_rest_route(
			self::NAMESPACE_V1,
			'/communities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_communities' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
			)
		);

		// GET /communities/<id> — community detail.
		register_rest_route(
			self::NAMESPACE_V1,
			'/communities/(?P<id>\\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_community' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /report — generate or get cached report.
		register_rest_route(
			self::NAMESPACE_V1,
			'/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_report' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
			)
		);

		// POST /build — trigger graph build.
		register_rest_route(
			self::NAMESPACE_V1,
			'/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'build_graph' ),
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'args'                => array(
					'mode' => array(
						'type'              => 'string',
						'default'           => 'full',
						'enum'              => array( 'full', 'incremental' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /export/<format> — export graph.
		register_rest_route(
			self::NAMESPACE_V1,
			'/export/(?P<format>[a-z]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export_graph' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'format' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'json', 'csv', 'graphml' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /search — search nodes by label.
		register_rest_route(
			self::NAMESPACE_V1,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search_nodes' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Check read permission (edit_posts).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function check_read_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check write permission (manage_options).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function check_write_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Endpoint callbacks.
	// ------------------------------------------------------------------

	/**
	 * GET /graph — Return graph metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_graph( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$meta = NV_oOS_Graphify_DB::get_or_create_graph_meta();

		if ( ! $meta ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Unable to retrieve graph metadata.', 'nvoos-graphify' ) ),
				500
			);
		}

		return new WP_REST_Response( $meta, 200 );
	}

	/**
	 * GET /nodes — List nodes, paginated.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_nodes( $request ) {
		global $wpdb;

		$tables   = NV_oOS_Graphify_DB::get_table_names();
		$per_page = min( (int) $request->get_param( 'per_page' ), 100 );
		$page     = max( (int) $request->get_param( 'page' ), 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$values = array();

		// Filter by node_type.
		$type = $request->get_param( 'type' );
		if ( ! empty( $type ) ) {
			$where[]  = 'node_type = %s';
			$values[] = $type;
		}

		// Filter by community_id.
		$community_id = $request->get_param( 'community_id' );
		if ( null !== $community_id && '' !== $community_id ) {
			$where[]  = 'community_id = %d';
			$values[] = absint( $community_id );
		}

		// Search by label.
		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$where[]  = 'label LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// Count total.
		$count_values = array_merge( array( $tables['nodes'] ), $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count_values
			)
		);

		// Fetch items.
		$query_values = array_merge( array( $tables['nodes'] ), $values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				"SELECT * FROM %i WHERE {$where_sql} ORDER BY degree DESC, label ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_values
			)
		);

		$pages = (int) ceil( $total / $per_page );

		$response = new WP_REST_Response(
			array(
				'items' => $items ? $items : array(),
				'total' => $total,
				'pages' => $pages,
			),
			200
		);

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $pages );

		return $response;
	}

	/**
	 * GET /nodes/<id> — Get single node with neighbors.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_node( $request ) {
		global $wpdb;

		$tables  = NV_oOS_Graphify_DB::get_table_names();
		$node_id = $request->get_param( 'id' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE node_id = %s',
				$tables['nodes'],
				$node_id
			)
		);

		if ( ! $node ) {
			return new WP_Error(
				'not_found',
				__( 'Node not found.', 'nvoos-graphify' ),
				array( 'status' => 404 )
			);
		}

		// Get edges where this node is source or target.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.*, n.label AS neighbor_label, n.node_type AS neighbor_type
				FROM %i AS e
				LEFT JOIN %i AS n ON (
					CASE WHEN e.source_node_id = %s THEN e.target_node_id ELSE e.source_node_id END = n.node_id
				)
				WHERE e.source_node_id = %s OR e.target_node_id = %s
				ORDER BY e.confidence_score DESC',
				$tables['edges'],
				$tables['nodes'],
				$node_id,
				$node_id,
				$node_id
			)
		);

		$node->neighbors = $edges ? $edges : array();

		return new WP_REST_Response( $node, 200 );
	}

	/**
	 * GET /edges — List edges, paginated.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_edges( $request ) {
		global $wpdb;

		$tables   = NV_oOS_Graphify_DB::get_table_names();
		$per_page = min( (int) $request->get_param( 'per_page' ), 100 );
		$page     = max( (int) $request->get_param( 'page' ), 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$values = array();

		$relation = $request->get_param( 'relation' );
		if ( ! empty( $relation ) ) {
			$where[]  = 'relation = %s';
			$values[] = $relation;
		}

		$confidence = $request->get_param( 'confidence' );
		if ( ! empty( $confidence ) ) {
			$where[]  = 'confidence = %s';
			$values[] = $confidence;
		}

		$where_sql = implode( ' AND ', $where );

		$count_values = array_merge( array( $tables['edges'] ), $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count_values
			)
		);

		$query_values = array_merge( array( $tables['edges'] ), $values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				"SELECT * FROM %i WHERE {$where_sql} ORDER BY confidence_score DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_values
			)
		);

		$pages = (int) ceil( $total / $per_page );

		$response = new WP_REST_Response(
			array(
				'items' => $items ? $items : array(),
				'total' => $total,
				'pages' => $pages,
			),
			200
		);

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $pages );

		return $response;
	}

	/**
	 * GET /communities — List communities with member counts.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_communities( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wpdb;

		$tables = NV_oOS_Graphify_DB::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT community_id, COUNT(*) AS member_count
				FROM %i
				WHERE community_id IS NOT NULL
				GROUP BY community_id
				ORDER BY member_count DESC',
				$tables['nodes']
			)
		);

		$communities = array();

		if ( is_array( $groups ) ) {
			foreach ( $groups as $group ) {
				$cid = (int) $group->community_id;

				// Top node label for community name.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$label = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT label FROM %i WHERE community_id = %d ORDER BY degree DESC LIMIT 1',
						$tables['nodes'],
						$cid
					)
				);

				// Top 5 members by degree.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$members = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT node_id, label, degree FROM %i WHERE community_id = %d ORDER BY degree DESC LIMIT 5',
						$tables['nodes'],
						$cid
					)
				);

				$communities[] = array(
					'community_id' => $cid,
					'label'        => $label ? $label : __( 'Unnamed', 'nvoos-graphify' ),
					'member_count' => (int) $group->member_count,
					'members'      => $members ? $members : array(),
				);
			}
		}

		return new WP_REST_Response( $communities, 200 );
	}

	/**
	 * GET /communities/<id> — Get community detail.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_community( $request ) {
		global $wpdb;

		$tables       = NV_oOS_Graphify_DB::get_table_names();
		$community_id = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$nodes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE community_id = %d ORDER BY degree DESC',
				$tables['nodes'],
				$community_id
			)
		);

		if ( empty( $nodes ) ) {
			return new WP_Error(
				'not_found',
				__( 'Community not found.', 'nvoos-graphify' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$label = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT label FROM %i WHERE community_id = %d ORDER BY degree DESC LIMIT 1',
				$tables['nodes'],
				$community_id
			)
		);

		return new WP_REST_Response(
			array(
				'community_id' => $community_id,
				'label'        => $label ? $label : __( 'Unnamed', 'nvoos-graphify' ),
				'member_count' => count( $nodes ),
				'nodes'        => $nodes,
			),
			200
		);
	}

	/**
	 * GET /report — Generate or retrieve cached report.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_report( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$report_obj = new NV_oOS_Graphify_Report();
		$cached     = $report_obj->get_cached_report();

		if ( $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$report = $report_obj->generate();

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * POST /build — Trigger graph build.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function build_graph( $request ) {
		$mode    = $request->get_param( 'mode' );
		$builder = new NV_oOS_Graphify_Builder();

		if ( 'incremental' === $mode ) {
			$result = $builder->build_incremental( 0 );
		} else {
			$result = $builder->build_full();
		}

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /export/<format> — Export graph in various formats.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function export_graph( $request ) {
		global $wpdb;

		$tables = NV_oOS_Graphify_DB::get_table_names();
		$format = $request->get_param( 'format' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$nodes = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY degree DESC', $tables['nodes'] ),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edges = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY confidence_score DESC', $tables['edges'] ),
			ARRAY_A
		);

		if ( ! is_array( $nodes ) ) {
			$nodes = array();
		}
		if ( ! is_array( $edges ) ) {
			$edges = array();
		}

		switch ( $format ) {
			case 'json':
				return self::export_json( $nodes, $edges );
			case 'csv':
				return self::export_csv( $nodes, $edges );
			case 'graphml':
				return self::export_graphml( $nodes, $edges );
			default:
				return new WP_Error(
					'invalid_format',
					__( 'Unsupported export format.', 'nvoos-graphify' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * GET /search — Search nodes by label.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function search_nodes( $request ) {
		global $wpdb;

		$tables = NV_oOS_Graphify_DB::get_table_names();
		$query  = $request->get_param( 'q' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT node_id, label, node_type, community_id, degree FROM %i WHERE label LIKE %s ORDER BY degree DESC LIMIT 50',
				$tables['nodes'],
				'%' . $wpdb->esc_like( $query ) . '%'
			)
		);

		return new WP_REST_Response(
			array( 'items' => $results ? $results : array() ),
			200
		);
	}

	// ------------------------------------------------------------------
	// Export helpers.
	// ------------------------------------------------------------------

	/**
	 * Export graph as JSON in NetworkX node-link format.
	 *
	 * @since 0.1.0
	 *
	 * @param array $nodes All node rows.
	 * @param array $edges All edge rows.
	 * @return WP_REST_Response
	 */
	private static function export_json( $nodes, $edges ) {
		$links = array();
		foreach ( $edges as $edge ) {
			$links[] = array(
				'source'           => $edge['source_node_id'],
				'target'           => $edge['target_node_id'],
				'relation'         => $edge['relation'],
				'confidence'       => $edge['confidence'],
				'confidence_score' => (float) $edge['confidence_score'],
			);
		}

		return new WP_REST_Response(
			array(
				'nodes' => $nodes,
				'links' => $links,
			),
			200
		);
	}

	/**
	 * Export graph as CSV (two arrays with header rows).
	 *
	 * @since 0.1.0
	 *
	 * @param array $nodes All node rows.
	 * @param array $edges All edge rows.
	 * @return WP_REST_Response
	 */
	private static function export_csv( $nodes, $edges ) {
		$node_headers = array( 'node_id', 'label', 'node_type', 'source_type', 'source_id', 'source_url', 'community_id', 'degree' );
		$nodes_csv    = array( $node_headers );
		foreach ( $nodes as $node ) {
			$row = array();
			foreach ( $node_headers as $header ) {
				$row[] = isset( $node[ $header ] ) ? $node[ $header ] : '';
			}
			$nodes_csv[] = $row;
		}

		$edge_headers = array( 'source_node_id', 'target_node_id', 'relation', 'confidence', 'confidence_score' );
		$edges_csv    = array( $edge_headers );
		foreach ( $edges as $edge ) {
			$row = array();
			foreach ( $edge_headers as $header ) {
				$row[] = isset( $edge[ $header ] ) ? $edge[ $header ] : '';
			}
			$edges_csv[] = $row;
		}

		return new WP_REST_Response(
			array(
				'nodes_csv' => $nodes_csv,
				'edges_csv' => $edges_csv,
			),
			200
		);
	}

	/**
	 * Export graph as GraphML XML string.
	 *
	 * @since 0.1.0
	 *
	 * @param array $nodes All node rows.
	 * @param array $edges All edge rows.
	 * @return WP_REST_Response
	 */
	private static function export_graphml( $nodes, $edges ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<graphml xmlns="http://graphml.graphdrawing.org/xmlns">' . "\n";
		$xml .= '  <key id="label" for="node" attr.name="label" attr.type="string"/>' . "\n";
		$xml .= '  <key id="node_type" for="node" attr.name="node_type" attr.type="string"/>' . "\n";
		$xml .= '  <key id="community" for="node" attr.name="community" attr.type="int"/>' . "\n";
		$xml .= '  <key id="degree" for="node" attr.name="degree" attr.type="int"/>' . "\n";
		$xml .= '  <key id="relation" for="edge" attr.name="relation" attr.type="string"/>' . "\n";
		$xml .= '  <key id="confidence" for="edge" attr.name="confidence" attr.type="string"/>' . "\n";
		$xml .= '  <key id="confidence_score" for="edge" attr.name="confidence_score" attr.type="double"/>' . "\n";
		$xml .= '  <graph id="G" edgedefault="directed">' . "\n";

		foreach ( $nodes as $node ) {
			$xml .= '    <node id="' . esc_attr( $node['node_id'] ) . '">' . "\n";
			$xml .= '      <data key="label">' . esc_html( $node['label'] ) . '</data>' . "\n";
			$xml .= '      <data key="node_type">' . esc_html( $node['node_type'] ) . '</data>' . "\n";
			$xml .= '      <data key="community">' . intval( $node['community_id'] ) . '</data>' . "\n";
			$xml .= '      <data key="degree">' . intval( $node['degree'] ) . '</data>' . "\n";
			$xml .= '    </node>' . "\n";
		}

		$edge_idx = 0;
		foreach ( $edges as $edge ) {
			$xml .= '    <edge id="e' . $edge_idx . '" source="' . esc_attr( $edge['source_node_id'] ) . '" target="' . esc_attr( $edge['target_node_id'] ) . '">' . "\n";
			$xml .= '      <data key="relation">' . esc_html( $edge['relation'] ) . '</data>' . "\n";
			$xml .= '      <data key="confidence">' . esc_html( $edge['confidence'] ) . '</data>' . "\n";
			$xml .= '      <data key="confidence_score">' . floatval( $edge['confidence_score'] ) . '</data>' . "\n";
			$xml .= '    </edge>' . "\n";
			++$edge_idx;
		}

		$xml .= '  </graph>' . "\n";
		$xml .= '</graphml>';

		return new WP_REST_Response( array( 'graphml' => $xml ), 200 );
	}
}

// Initialize.
NV_oOS_Graphify_REST::init();
