<?php
/**
 * NV oOS Graphify REST API Controller.
 *
 * Registers and handles all REST API endpoints for the Graphify
 * Knowledge Graph addon under the nvoos-graphify/v1 namespace.
 *
 * @package NV_oOS_Graphify
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_REST
 *
 * Provides REST API endpoints for querying, searching, and building
 * the knowledge graph.
 *
 * @since 1.0.0
 */
class NV_oOS_Graphify_REST {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NAMESPACE = 'nvoos-graphify/v1';

	/**
	 * Register all REST API routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/graph',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_graph' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/nodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_nodes' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => self::get_nodes_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/nodes/(?P<node_id>[a-zA-Z0-9_:-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_node' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'node_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_build' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
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

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_search' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 2,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && strlen( trim( $value ) ) >= 2;
						},
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Get argument schema for the /nodes endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Argument definitions.
	 */
	private static function get_nodes_args() {
		return array(
			'per_page'  => array(
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'      => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'type'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'community' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'search'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'   => array(
				'type'              => 'string',
				'default'           => 'degree',
				'enum'              => array( 'degree', 'label', 'created_at' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'     => array(
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => array( 'ASC', 'DESC' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Check if the current user has read permission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool True if the user can read.
	 */
	public static function check_read_permission( $request ) {
		return current_user_can( 'read' );
	}

	/**
	 * Check if the current user has admin permission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool True if the user can manage options.
	 */
	public static function check_admin_permission( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle GET /graph — return graph metadata and stats.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function handle_get_graph( $request ) {
		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$meta_table  = NV_oOS_Graphify_Database::get_meta_table();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		// The meta table stores one row per graph_id with direct columns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$meta_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// Live counts from actual tables.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$nodes_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edge_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$edges_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$community_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT community_id) FROM {$nodes_table} WHERE graph_id = %d AND community_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		$data = array(
			'graph_id'        => $graph_id,
			'node_count'      => $node_count,
			'edge_count'      => $edge_count,
			'community_count' => $community_count,
			'last_built'      => $meta ? $meta->last_built : null,
			'build_status'    => $meta ? $meta->build_status : 'idle',
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Handle GET /nodes — list nodes with pagination and filters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function handle_get_nodes( $request ) {
		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();

		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$type     = $request->get_param( 'type' );
		$community = $request->get_param( 'community' );
		$search   = $request->get_param( 'search' );
		$orderby  = $request->get_param( 'orderby' );
		$order    = $request->get_param( 'order' );

		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		$orderby_map = array(
			'degree'     => 'degree',
			'label'      => 'label',
			'created_at' => 'created_at',
		);
		$orderby_col = isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'degree';

		$order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = array( 'graph_id = %d' );
		$values = array( $graph_id );

		if ( ! empty( $type ) ) {
			$where[]  = 'node_type = %s';
			$values[] = $type;
		}

		if ( null !== $community ) {
			$where[]  = 'community_id = %d';
			$values[] = (int) $community;
		}

		if ( ! empty( $search ) ) {
			$where[]  = 'label LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$nodes_table} WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$values
			)
		);

		$total_pages = (int) ceil( $total / $per_page );

		$query_values   = $values;
		$query_values[] = $per_page;
		$query_values[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$nodes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$nodes_table} WHERE {$where_sql} ORDER BY {$orderby_col} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_values
			)
		);

		$data = array(
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'nodes'       => $nodes ? $nodes : array(),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Handle GET /nodes/{node_id} — get a single node with its neighbors.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function handle_get_node( $request ) {
		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();
		$node_id     = sanitize_text_field( $request->get_param( 'node_id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$nodes_table} WHERE graph_id = %d AND node_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
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

		// Get neighbor node IDs from edges where this node is source or target.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_node_id, target_node_id, relation, confidence, confidence_score
				FROM {$edges_table}
				WHERE graph_id = %d AND ( source_node_id = %s OR target_node_id = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				$node_id,
				$node_id
			)
		);

		$neighbor_ids = array();
		$edge_list    = array();
		if ( $edges ) {
			foreach ( $edges as $edge ) {
				$neighbor = ( $edge->source_node_id === $node_id )
					? $edge->target_node_id
					: $edge->source_node_id;

				$neighbor_ids[]  = $neighbor;
				$edge_list[]     = array(
					'source'           => $edge->source_node_id,
					'target'           => $edge->target_node_id,
					'relation'         => $edge->relation,
					'confidence'       => $edge->confidence,
					'confidence_score' => (float) $edge->confidence_score,
				);
			}
		}

		$neighbors = array();
		if ( ! empty( $neighbor_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $neighbor_ids ), '%s' ) );
			$query_args   = array_merge( array( $graph_id ), $neighbor_ids );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$neighbors = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$nodes_table} WHERE graph_id = %d AND node_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$query_args
				)
			);
		}

		$data = array(
			'node'      => $node,
			'edges'     => $edge_list,
			'neighbors' => $neighbors ? $neighbors : array(),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Handle POST /build — trigger a graph build pipeline.
	 *
	 * Runs Detector → Extractor → Builder in sequence.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function handle_build( $request ) {
		$mode        = sanitize_text_field( $request->get_param( 'mode' ) );
		$incremental = ( 'incremental' === $mode );
		$graph_id    = NV_oOS_Graphify::get_graph_id();

		// Update build status to 'building'.
		self::update_build_status( $graph_id, 'building' );

		// Step 1: Detect content.
		$since    = $incremental ? self::get_last_built_time( $graph_id ) : null;
		$detector = new NV_oOS_Graphify_Detector();
		$detected = $detector->detect( $incremental, $since );

		if ( is_wp_error( $detected ) ) {
			self::update_build_status( $graph_id, 'error' );
			return $detected;
		}

		// Step 2: Extract structural data.
		$extractor = new NV_oOS_Graphify_Extractor_Structural( $graph_id );
		$extracted = $extractor->extract( $detected );

		if ( is_wp_error( $extracted ) ) {
			self::update_build_status( $graph_id, 'error' );
			return $extracted;
		}

		// Step 3: Build graph.
		$builder = new NV_oOS_Graphify_Builder( $graph_id );
		$result  = $builder->build( $extracted, $mode );

		if ( is_wp_error( $result ) ) {
			self::update_build_status( $graph_id, 'error' );
			return $result;
		}

		// Update metadata.
		self::update_build_status( $graph_id, 'complete' );
		self::update_meta( $graph_id, 'last_built', current_time( 'mysql', true ) );

		$stats = $builder->get_stats( $graph_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'mode'    => $mode,
				'stats'   => $stats,
			),
			200
		);
	}

	/**
	 * Handle GET /search — search nodes by label.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public static function handle_search( $request ) {
		global $wpdb;

		$graph_id    = NV_oOS_Graphify::get_graph_id();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$query       = sanitize_text_field( $request->get_param( 'q' ) );
		$limit       = (int) $request->get_param( 'limit' );
		$limit       = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, label, node_type, degree, community_id
				FROM {$nodes_table}
				WHERE graph_id = %d AND label LIKE %s
				ORDER BY degree DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				'%' . $wpdb->esc_like( $query ) . '%',
				$limit
			)
		);

		return new WP_REST_Response(
			array(
				'query'   => $query,
				'count'   => count( $results ),
				'results' => $results ? $results : array(),
			),
			200
		);
	}

	/**
	 * Update the build status in the graph meta table.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $graph_id The graph ID.
	 * @param string $status   The build status.
	 * @return void
	 */
	private static function update_build_status( $graph_id, $status ) {
		global $wpdb;

		$meta_table = NV_oOS_Graphify_Database::get_meta_table();
		$status     = sanitize_text_field( $status );

		// Check if a row exists for this graph.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$meta_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$meta_table,
				array( 'build_status' => $status ),
				array( 'graph_id' => $graph_id ),
				array( '%s' ),
				array( '%d' )
			);
		} else {
			$site_id = is_multisite() ? get_current_blog_id() : 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$meta_table,
				array(
					'graph_id'     => $graph_id,
					'site_id'      => $site_id,
					'build_status' => $status,
				),
				array( '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Update meta column and last_built timestamp for the graph.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $graph_id   The graph ID.
	 * @param string $column     The column name to update.
	 * @param string $value      The value to set.
	 * @return void
	 */
	private static function update_meta( $graph_id, $column, $value ) {
		global $wpdb;

		$meta_table     = NV_oOS_Graphify_Database::get_meta_table();
		$allowed_fields = array( 'last_built', 'build_status', 'node_count', 'edge_count', 'community_count', 'settings' );

		if ( ! in_array( $column, $allowed_fields, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$meta_table,
			array( $column => $value ),
			array( 'graph_id' => $graph_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get the last built timestamp from graph meta.
	 *
	 * @since 1.0.0
	 *
	 * @param int $graph_id The graph ID.
	 * @return string|null MySQL datetime or null.
	 */
	private static function get_last_built_time( $graph_id ) {
		global $wpdb;

		$meta_table = NV_oOS_Graphify_Database::get_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_built FROM {$meta_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		return $value ? sanitize_text_field( $value ) : null;
	}
}

add_action( 'rest_api_init', array( 'NV_oOS_Graphify_REST', 'register_routes' ) );
