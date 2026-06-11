<?php
declare(strict_types=1);

namespace NvoosGraphify\Rest;

use NvoosGraphify\Graph\Builder;
use NvoosGraphify\Graph\Db;
use NvoosGraphify\Graph\Exporter;
use NvoosGraphify\Plugin;
use NvoosGraphify\Schema;
use NvoosGraphify\Tools\ListRemoteSources;
use NvoosGraphify\Tools\ResolveExternal;
use NvoosGraphify\Tools\RetrieveContext;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function absint;
use function class_exists;
use function count;
use function current_user_can;
use function function_exists;
use function is_user_logged_in;
use function is_wp_error;
use function json_decode;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_key;
use function sanitize_text_field;

/**
 * REST API controller for the NV oOS Graphify plugin.
 *
 * Exposes the knowledge graph via the WordPress REST API at
 * /wp-json/nvoos-graphify/v1/
 *
 * Routes:
 *   GET    /graph              — metadata and stats
 *   GET    /nodes              — paginated, filterable node list
 *   GET    /nodes/{node_id}    — single node with neighbor edges
 *   POST   /build              — trigger a graph build (manage_options)
 *   GET    /search             — label search
 *   GET    /export             — export in various formats
 *   POST   /retrieve           — RAG context retrieval
 *   GET    /resolve            — resolve external entity
 *   GET    /sources            — list remote sources
 *   POST   /sources            — create a remote source
 *   DELETE /sources/{slug}     — delete a remote source
 *   POST   /sources/{slug}/sync — trigger manual sync
 *   POST   /sources/{slug}/test — test connection
 *   POST   /webhooks/{slug}    — receive a webhook payload
 *
 * @since 1.0.0
 */
class Controller {

	/**
	 * Register all REST routes.
	 *
	 * Called once on rest_api_init by {@see Plugin::register()}.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// GET /graph — metadata + stats.
		register_rest_route(
			Schema::REST_NAMESPACE,
			'/graph',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getGraph' ),
				'permission_callback' => array( $this, 'checkReadPermission' ),
			)
		);

		// GET /nodes — paginated node list.
		register_rest_route(
			Schema::REST_NAMESPACE,
			'/nodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getNodes' ),
				'permission_callback' => array( $this, 'checkReadPermission' ),
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
			Schema::REST_NAMESPACE,
			'/nodes/(?P<node_id>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getNode' ),
				'permission_callback' => array( $this, 'checkReadPermission' ),
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
			Schema::REST_NAMESPACE,
			'/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'triggerBuild' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
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
			Schema::REST_NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'searchNodes' ),
				'permission_callback' => array( $this, 'checkReadPermission' ),
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
			Schema::REST_NAMESPACE,
			'/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'exportGraph' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
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
			Schema::REST_NAMESPACE,
			'/retrieve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'retrieveContext' ),
				'permission_callback' => array( $this, 'checkReadPermission' ),
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
			Schema::REST_NAMESPACE,
			'/resolve',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolveExternal' ),
				'permission_callback' => array( $this, 'checkReadPermission' ),
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
			Schema::REST_NAMESPACE,
			'/sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getSources' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
			)
		);

		// POST /sources — create a remote source.
		register_rest_route(
			Schema::REST_NAMESPACE,
			'/sources',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createSource' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
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
			Schema::REST_NAMESPACE,
			'/sources/(?P<slug>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'deleteSource' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
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
			Schema::REST_NAMESPACE,
			'/sources/(?P<slug>[a-z0-9_\-]+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'syncSource' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
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
			Schema::REST_NAMESPACE,
			'/sources/(?P<slug>[a-z0-9_\-]+)/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'testSource' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
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
			Schema::REST_NAMESPACE,
			'/webhooks/(?P<slug>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receiveWebhook' ),
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
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function getGraph(): WP_REST_Response {
		$stats     = Db::getStats();
		$lastBuild = Db::getMeta( 'last_build_completed', 'never' );
		$status    = Db::getMeta( 'build_status', 'idle' );

		return rest_ensure_response(
			array(
				'version'      => \NVOOS_GRAPHIFY_VERSION,
				'stats'        => $stats,
				'last_build'   => $lastBuild,
				'build_status' => $status,
			)
		);
	}

	/**
	 * GET /nodes — Paginated, filterable node list.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function getNodes( WP_REST_Request $request ): WP_REST_Response {
		$perPage     = $request->get_param( 'per_page' );
		$page        = $request->get_param( 'page' );
		$offset      = ( $page - 1 ) * $perPage;
		$type        = $request->get_param( 'type' );
		$communityId = $request->get_param( 'community_id' );
		$search      = $request->get_param( 'search' );

		$nodes = Db::listNodes(
			array(
				'limit'        => $perPage,
				'offset'       => $offset,
				'type'         => $type,
				'community_id' => $communityId,
				'search'       => $search,
			)
		);

		$response = rest_ensure_response( $nodes );
		$response->header( 'X-WP-Page', $page );
		$response->header( 'X-WP-PerPage', $perPage );

		return $response;
	}

	/**
	 * GET /nodes/{node_id} — Single node with neighbors.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getNode( WP_REST_Request $request ) {
		$nodeId = sanitize_text_field( $request->get_param( 'node_id' ) );
		$node   = Db::getNode( $nodeId );

		if ( ! $node ) {
			return new WP_Error( 'nvoos_graphify_not_found', __( 'Node not found.', 'nvoos-graphify' ), array( 'status' => 404 ) );
		}

		$edges     = Db::getEdgesForNode( $nodeId );
		$neighbors = array();
		foreach ( $edges as $edge ) {
			$nid         = ( $edge->source_node_id === $nodeId ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr         = Db::getNode( $nid );
			$neighbors[] = array(
				'node_id'   => $nid,
				'label'     => $nbr ? $nbr->label : $nid,
				'type'      => $nbr ? $nbr->type : '',
				'relation'  => $edge->relation,
				'direction' => ( $edge->source_node_id === $nodeId ) ? 'outgoing' : 'incoming',
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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function triggerBuild( WP_REST_Request $request ): WP_REST_Response {
		$result = Builder::build(
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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function searchNodes( WP_REST_Request $request ): WP_REST_Response {
		$query   = sanitize_text_field( $request->get_param( 'q' ) );
		$type    = sanitize_text_field( $request->get_param( 'type' ) );
		$limit   = absint( $request->get_param( 'limit' ) );
		$results = Db::searchNodes( $query, $type, $limit );

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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function exportGraph( WP_REST_Request $request ): WP_REST_Response {
		$format   = sanitize_key( $request->get_param( 'format' ) );
		$maxNodes = absint( $request->get_param( 'max_nodes' ) );

		$result = Exporter::export( $format, array( 'max_nodes' => $maxNodes ) );

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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function retrieveContext( WP_REST_Request $request ): WP_REST_Response {
		$tool   = new RetrieveContext();
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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function resolveExternal( WP_REST_Request $request ): WP_REST_Response {
		$tool   = new ResolveExternal();
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
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function getSources(): WP_REST_Response {
		$tool   = new ListRemoteSources();
		$result = $tool->execute( array(), array() );
		return rest_ensure_response( $result );
	}

	/**
	 * POST /sources — Create a remote source.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createSource( WP_REST_Request $request ) {
		$config = $request->get_param( 'config' );
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		$result = Db::saveRemoteSource(
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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function deleteSource( WP_REST_Request $request ): WP_REST_Response {
		Db::deleteRemoteSource( sanitize_key( $request->get_param( 'slug' ) ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /sources/{slug}/sync — Trigger a manual source sync.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function syncSource( WP_REST_Request $request ) {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$async    = (bool) $request->get_param( 'async' );
		$enricher = new \NvoosGraphify\Remote\Enricher();
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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function testSource( WP_REST_Request $request ) {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$dbSource = Db::getRemoteSource( $slug );

		if ( ! $dbSource || empty( $dbSource->enabled ) ) {
			return new WP_Error( 'not_found', __( 'Source not found or not enabled.', 'nvoos-graphify' ), array( 'status' => 404 ) );
		}

		$registry = Plugin::instance()->getRemoteRegistry();
		$driver   = $registry->getDriver( $dbSource->driver ?? '' );
		if ( ! $driver ) {
			return new WP_Error( 'no_driver', __( 'Driver not found.', 'nvoos-graphify' ), array( 'status' => 500 ) );
		}

		$driver->setConfig( (array) $dbSource );
		$result = $driver->testConnection();

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

	/**
	 * POST /webhooks/{slug} — Receive a verified webhook payload for a webhook-source.
	 *
	 * The configured per-source `webhook_secret` is used to validate the
	 * `X-NVOOS-Signature` header (HMAC-SHA256 of the raw request body, hex,
	 * optionally prefixed with `sha256=`). On success, ingested nodes are
	 * upserted and entity resolution is attempted for each new node.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receiveWebhook( WP_REST_Request $request ) {
		$slug = sanitize_key( $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'webhook_invalid_slug', __( 'Invalid source slug.', 'nvoos-graphify' ), array( 'status' => 400 ) );
		}

		$dbSource = Db::getRemoteSource( $slug );
		if ( ! $dbSource || empty( $dbSource->enabled ) ) {
			return new WP_Error( 'webhook_unknown_source', __( 'Unknown or disabled source.', 'nvoos-graphify' ), array( 'status' => 404 ) );
		}
		if ( 'webhook' !== sanitize_key( $dbSource->driver ) ) {
			return new WP_Error( 'webhook_wrong_driver', __( 'Source is not configured as a webhook receiver.', 'nvoos-graphify' ), array( 'status' => 400 ) );
		}

		// Decrypt config so we can read the secret + field map.
		$config = array();
		if ( ! empty( $dbSource->config_json ) ) {
			$decoded = json_decode( $dbSource->config_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $k => $v ) {
					if ( is_string( $v ) && class_exists( \NvoosGraphify\Crypto::class ) && \NvoosGraphify\Crypto::isSensitiveKey( $k ) ) {
						$decoded[ $k ] = \NvoosGraphify\Crypto::decrypt( $v );
					}
				}
				$config = $decoded;
			}
		}
		$config['_slug'] = $slug;

		$driver = new \NvoosGraphify\Remote\Drivers\Webhook();
		$driver->setConfig( $config );

		$rawBody   = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x-nvoos-signature' );
		if ( ! $driver->verifySignature( $rawBody, $signature ) ) {
			return new WP_Error( 'webhook_bad_signature', __( 'Invalid or missing signature.', 'nvoos-graphify' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $rawBody, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'webhook_invalid_json', __( 'Body must be valid JSON.', 'nvoos-graphify' ), array( 'status' => 400 ) );
		}

		$nodes    = $driver->payloadToNodes( $payload );
		$ingested = 0;
		$resolved = 0;
		foreach ( $nodes as $node ) {
			if ( Db::upsertNode( $node ) ) {
				++$ingested;
				if ( ! empty( $node['node_id'] ) && class_exists( \NvoosGraphify\Graph\EntityResolver::class ) ) {
					$resolved += \NvoosGraphify\Graph\EntityResolver::resolveNode( $node, $slug );
				}
			}
		}

		Db::updateRemoteSourceSync( $slug );

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
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error
	 */
	public function checkReadPermission() {
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}
		// Allow public access if the base plugin guest token is valid.
		if ( function_exists( '\wp_mcp_ai_validate_guest_token' ) && \wp_mcp_ai_validate_guest_token() ) {
			return true;
		}
		return new WP_Error( 'nvoos_graphify_forbidden', __( 'You must be logged in to access the knowledge graph.', 'nvoos-graphify' ), array( 'status' => 401 ) );
	}

	/**
	 * Check admin permission: manage_options capability required.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error
	 */
	public function checkAdminPermission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'nvoos_graphify_forbidden', __( 'Administrator access required.', 'nvoos-graphify' ), array( 'status' => 403 ) );
	}
}
