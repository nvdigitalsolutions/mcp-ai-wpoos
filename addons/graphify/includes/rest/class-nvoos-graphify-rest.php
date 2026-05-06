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
					'per_page'     => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 200,
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
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
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
					'node_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'required'          => true,
					),
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
					'incremental' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'semantic'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'reset'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
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
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
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
					'format'    => array(
						'type'    => 'string',
						'default' => 'json',
						'enum'    => array( 'json', 'html', 'graphml', 'csv', 'neo4j', 'obsidian' ),
					),
					'max_nodes' => array(
						'type'              => 'integer',
						'default'           => 2000,
						'minimum'           => 1,
						'maximum'           => 5000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /retrieve — RAG context retrieval.
		register_rest_route(
			self::NAMESPACE,
			'/retrieve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'retrieve_context' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'question'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'k'             => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 20,
						'sanitize_callback' => 'absint',
					),
					'hops'          => array(
						'type'              => 'integer',
						'default'           => 2,
						'minimum'           => 1,
						'maximum'           => 3,
						'sanitize_callback' => 'absint',
					),
					'use_vectors'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'include_edges' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// GET /resolve — resolve external entity.
		register_rest_route(
			self::NAMESPACE,
			'/resolve',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'resolve_external' ),
				'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				'args'                => array(
					'ref'         => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'auto_ingest' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// GET /sources — list remote sources.
		register_rest_route(
			self::NAMESPACE,
			'/sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sources' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
			)
		);

		// POST /sources — create a remote source.
		register_rest_route(
			self::NAMESPACE,
			'/sources',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_source' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'slug'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'driver'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'label'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'enabled' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'config'  => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		// DELETE /sources/{slug} — delete a remote source.
		register_rest_route(
			self::NAMESPACE,
			'/sources/(?P<slug>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_source' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'required'          => true,
					),
				),
			)
		);

		// POST /sources/{slug}/sync — trigger manual sync.
		register_rest_route(
			self::NAMESPACE,
			'/sources/(?P<slug>[a-z0-9_\-]+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sync_source' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'slug'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'required'          => true,
					),
					'async' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// POST /sources/{slug}/test — test connection.
		register_rest_route(
			self::NAMESPACE,
			'/sources/(?P<slug>[a-z0-9_\-]+)/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_source' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'required'          => true,
					),
				),
			)
		);

		// POST /webhooks/{slug} — receive a webhook payload for a configured webhook source.
		// Authentication is via per-source HMAC-SHA256 (X-NVOOS-Signature header), so the
		// permission_callback intentionally returns true and verification happens in the handler.
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<slug>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive_webhook' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'required'          => true,
					),
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
			$nid         = ( $edge->source_node_id === $node_id ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr         = NV_oOS_Graphify_DB::get_node( $nid );
			$neighbors[] = array(
				'node_id'   => $nid,
				'label'     => $nbr ? $nbr->label : $nid,
				'type'      => $nbr ? $nbr->type : '',
				'relation'  => $edge->relation,
				'direction' => ( $edge->source_node_id === $node_id ) ? 'outgoing' : 'incoming',
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

	/**
	 * POST /retrieve — RAG context retrieval.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function retrieve_context( WP_REST_Request $request ) {
		$tool   = new NV_oOS_Graphify_Tool_Retrieve_Context();
		$result = $tool->execute(
			array(
				'question'      => $request->get_param( 'question' ),
				'k'             => $request->get_param( 'k' ),
				'hops'          => $request->get_param( 'hops' ),
				'use_vectors'   => (bool) $request->get_param( 'use_vectors' ),
				'include_edges' => (bool) $request->get_param( 'include_edges' ),
			),
			array()
		);
		return rest_ensure_response( $result );
	}

	/**
	 * GET /resolve — Resolve an external entity ref to a local node.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function resolve_external( WP_REST_Request $request ) {
		$tool   = new NV_oOS_Graphify_Tool_Resolve_External();
		$result = $tool->execute(
			array(
				'ref'         => $request->get_param( 'ref' ),
				'auto_ingest' => (bool) $request->get_param( 'auto_ingest' ),
			),
			array()
		);
		return rest_ensure_response( $result );
	}

	/**
	 * GET /sources — List configured remote sources.
	 *
	 * @since 0.6.0
	 *
	 * @return WP_REST_Response
	 */
	public static function get_sources() {
		$tool   = new NV_oOS_Graphify_Tool_List_Remote_Sources();
		$result = $tool->execute( array(), array() );
		return rest_ensure_response( $result );
	}

	/**
	 * POST /sources — Create a remote source.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_source( WP_REST_Request $request ) {
		$config = $request->get_param( 'config' );
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		$result = NV_oOS_Graphify_DB::save_remote_source(
			array(
				'slug'    => $request->get_param( 'slug' ),
				'driver'  => $request->get_param( 'driver' ),
				'label'   => $request->get_param( 'label' ) ?? '',
				'enabled' => (bool) $request->get_param( 'enabled' ),
				'config'  => $config,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'slug'    => $request->get_param( 'slug' ),
			)
		);
	}

	/**
	 * DELETE /sources/{slug} — Delete a remote source.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function delete_source( WP_REST_Request $request ) {
		NV_oOS_Graphify_DB::delete_remote_source( sanitize_key( $request->get_param( 'slug' ) ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /sources/{slug}/sync — Trigger a manual source sync.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function sync_source( WP_REST_Request $request ) {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$async    = (bool) $request->get_param( 'async' );
		$enricher = new NV_oOS_Graphify_Remote_Enricher();
		$summary  = $enricher->sync_source( $slug, $async );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'slug'    => $slug,
				'async'   => $async,
				'summary' => $summary,
			)
		);
	}

	/**
	 * POST /sources/{slug}/test — Test a source connection.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test_source( WP_REST_Request $request ) {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$sources  = $registry->get_active_sources();
		$source   = null;
		foreach ( $sources as $s ) {
			if ( ( $s['_slug'] ?? '' ) === $slug ) {
				$source = $s;
				break;
			}
		}
		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Source not found or not enabled.', 'nvoos-graphify' ), array( 'status' => 404 ) );
		}
		$driver = $registry->get_driver( $source['driver'] ?? '' );
		if ( ! $driver ) {
			return new WP_Error( 'no_driver', __( 'Driver not found.', 'nvoos-graphify' ), array( 'status' => 500 ) );
		}
		$result = $driver->test_connection( $source );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'result'  => $result,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * POST /webhooks/{slug} — Receive a verified webhook payload for a webhook-source.
	 *
	 * The configured per-source `webhook_secret` is used to validate the
	 * `X-NVOOS-Signature` header (HMAC-SHA256 of the raw request body, hex,
	 * optionally prefixed with `sha256=`). On success, ingested nodes are
	 * upserted and entity resolution is attempted for each new node.
	 *
	 * @since 0.7.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive_webhook( WP_REST_Request $request ) {
		$slug = sanitize_key( $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'webhook_invalid_slug', __( 'Invalid source slug.', 'nvoos-graphify' ), array( 'status' => 400 ) );
		}

		$db_source = NV_oOS_Graphify_DB::get_remote_source( $slug );
		if ( ! $db_source || empty( $db_source->enabled ) ) {
			return new WP_Error( 'webhook_unknown_source', __( 'Unknown or disabled source.', 'nvoos-graphify' ), array( 'status' => 404 ) );
		}
		if ( 'webhook' !== sanitize_key( $db_source->driver ) ) {
			return new WP_Error( 'webhook_wrong_driver', __( 'Source is not configured as a webhook receiver.', 'nvoos-graphify' ), array( 'status' => 400 ) );
		}

		// Decrypt config so we can read the secret + field map.
		$config = array();
		if ( ! empty( $db_source->config_json ) ) {
			$decoded = json_decode( $db_source->config_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $k => $v ) {
					if ( is_string( $v ) && NV_oOS_Graphify_Crypto::is_sensitive_key( $k ) ) {
						$decoded[ $k ] = NV_oOS_Graphify_Crypto::decrypt( $v );
					}
				}
				$config = $decoded;
			}
		}
		$config['_slug'] = $slug;

		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$driver->set_config( $config );

		$raw_body  = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x-nvoos-signature' );
		if ( ! $driver->verify_signature( $raw_body, $signature ) ) {
			return new WP_Error( 'webhook_bad_signature', __( 'Invalid or missing signature.', 'nvoos-graphify' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'webhook_invalid_json', __( 'Body must be valid JSON.', 'nvoos-graphify' ), array( 'status' => 400 ) );
		}

		$nodes    = $driver->payload_to_nodes( $payload );
		$ingested = 0;
		$resolved = 0;
		foreach ( $nodes as $node ) {
			if ( NV_oOS_Graphify_DB::upsert_node( $node ) ) {
				++$ingested;
				if ( ! empty( $node['node_id'] ) && class_exists( 'NV_oOS_Graphify_Entity_Resolver' ) ) {
					$resolved += NV_oOS_Graphify_Entity_Resolver::resolve_node( $node, $slug );
				}
			}
		}

		NV_oOS_Graphify_DB::update_remote_source_sync( $slug );

		return rest_ensure_response(
			array(
				'success'        => true,
				'received'       => count( $nodes ),
				'ingested'       => $ingested,
				'sameAs_emitted' => $resolved,
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
