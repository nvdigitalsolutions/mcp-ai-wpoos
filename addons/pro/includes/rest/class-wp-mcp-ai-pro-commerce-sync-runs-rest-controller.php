<?php
/**
 * Commerce Sync Runs REST controller (Proposal 089 Phase 3c).
 *
 * Read-only receipts for the four commerce sync engines
 * (woocommerce, ezuite, shopify_sync, flowhub), served from the shared
 * WP_MCP_AI_Sync_Log_Manager persistence. The console's commerce pages
 * render the "Sync Runs" tab from this surface — run records carry their
 * idempotency keys and item-level results so operators can verify what a
 * run changed without touching wp-admin.
 *
 * Routes (all READABLE, manage_options):
 *   GET /mcp-ai-pro/v1/commerce/sync-runs?toolkit={slug}
 *   GET /mcp-ai-pro/v1/commerce/sync-runs/{toolkit}/latest
 *   GET /mcp-ai-pro/v1/commerce/sync-runs/{toolkit}/{run_id}
 *
 * No state-changing routes — reconciliation is confirm-gated and stays on
 * the existing sync service classes (Proposal 089 §4.2: money-moving last).
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commerce Sync Runs REST controller.
 */
class WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/commerce/sync-runs';

	/**
	 * Toolkit slugs the Sync Log Manager persists (mirrors its prune list).
	 *
	 * @var string[]
	 */
	const TOOLKIT_SLUGS = array( 'woocommerce', 'ezuite', 'shopify_sync', 'flowhub' );

	/**
	 * Boot the controller (idempotent).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the sync-run routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_runs' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'toolkit' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'   => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					// Proposal 089 Phase B — proxy against a Remote Sites
					// WordPress connection instead of the local logs.
					'remote_site' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<toolkit>[a-z0-9_]+)/latest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_latest_run' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'toolkit' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<toolkit>[a-z0-9_]+)/(?P<run_id>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'toolkit' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'run_id'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission check — sync receipts are administrator-only (the console
	 * surfaces are manage_options-gated).
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /commerce/sync-runs — recent run summaries.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_runs( WP_REST_Request $request ) {
		$toolkit     = $request->get_param( 'toolkit' );
		$remote_site = $request->get_param( 'remote_site' );
		$limit       = absint( $request->get_param( 'limit' ) );
		$limit       = ( $limit > 0 ) ? $limit : 20;

		// Phase B — a remote site id proxies against that site's own
		// sync-runs bridge (read-only, credentials stay server-side).
		if ( '' !== (string) $remote_site ) {
			return $this->get_remote_runs( $remote_site, (string) $toolkit, $limit );
		}

		if ( ! $this->logs_available() ) {
			return $this->unavailable_error();
		}

		$slugs = ( '' !== (string) $toolkit ) ? array( $toolkit ) : self::TOOLKIT_SLUGS;
		$runs  = array();

		foreach ( $slugs as $slug ) {
			if ( ! in_array( $slug, self::TOOLKIT_SLUGS, true ) ) {
				continue;
			}
			$entries = WP_MCP_AI_Sync_Log_Manager::get_runs( $slug, $limit );
			foreach ( $entries as $entry ) {
				$runs[] = $this->sanitize_run_summary( $entry, $slug );
			}
		}

		// Newest first across toolkits (each toolkit's list is already
		// newest-first; merge then stable-sort by started_at descending).
		usort(
			$runs,
			function ( $a, $b ) {
				return strcmp( (string) $b['started_at'], (string) $a['started_at'] );
			}
		);

		$runs = array_slice( $runs, 0, $limit );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'runs' => $runs ),
			)
		);
	}

	/**
	 * Proxy the sync-runs list against a Remote Sites WordPress connection.
	 *
	 * Read-only: the remote site's own controller enforces its permission
	 * check (manage_options on that site) on top of this site's check.
	 *
	 * @since 2.4.0
	 * @param string $remote_site Remote connection id.
	 * @param string $toolkit     Optional toolkit slug.
	 * @param int    $limit       Run limit.
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_remote_runs( $remote_site, $toolkit, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'sync_runs_remote_unavailable',
				__( 'Remote Sites is unavailable on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $remote_site );
		if ( null === $connection ) {
			return new WP_Error(
				'sync_runs_unknown_remote_site',
				__( 'Unknown remote site connection.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'sync_runs_remote_site_disabled',
				__( 'The remote site connection is disabled.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$type = isset( $connection['connection_type'] ) ? sanitize_key( (string) $connection['connection_type'] ) : '';
		if ( 'wordpress' !== $type ) {
			return new WP_Error(
				'sync_runs_remote_site_not_wordpress',
				__( 'Only WordPress remote sites expose sync runs.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$endpoint = add_query_arg(
			array(
				'toolkit' => sanitize_key( $toolkit ),
				'limit'   => $limit,
			),
			'mcp-ai-pro/v1/commerce/sync-runs'
		);

		$response = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'sync_runs_remote_failed',
				sprintf(
					/* translators: %s: remote error message */
					__( 'Remote sync-run request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$runs = ( is_array( $response ) && isset( $response['data']['runs'] ) && is_array( $response['data']['runs'] ) )
			? $response['data']['runs']
			: array();

		// The remote controller already escaped at exit; re-sanitize the
		// passthrough surface defensively (never trust a proxy payload).
		$runs = array_map(
			function ( $run ) {
				if ( ! is_array( $run ) ) {
					return array();
				}
				$toolkit = isset( $run['toolkit'] ) ? sanitize_key( (string) $run['toolkit'] ) : '';
				return $this->sanitize_run_summary( $run, $toolkit );
			},
			$runs
		);

		return rest_ensure_response(
			array(
				'success'     => true,
				'data'        => array(
					'runs'        => $runs,
					'remote_site' => sanitize_key( $remote_site ),
				),
			)
		);
	}

	/**
	 * GET /commerce/sync-runs/{toolkit}/latest — the most recent run.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_latest_run( WP_REST_Request $request ) {
		if ( ! $this->logs_available() ) {
			return $this->unavailable_error();
		}

		$toolkit = $request->get_param( 'toolkit' );
		if ( ! in_array( $toolkit, self::TOOLKIT_SLUGS, true ) ) {
			return $this->unknown_toolkit_error();
		}

		$run = WP_MCP_AI_Sync_Log_Manager::get_latest_run( $toolkit );
		if ( null === $run ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array( 'run' => null ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'run' => $this->sanitize_run_summary( $run, $toolkit ) ),
			)
		);
	}

	/**
	 * GET /commerce/sync-runs/{toolkit}/{run_id} — full run with items.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_run( WP_REST_Request $request ) {
		if ( ! $this->logs_available() ) {
			return $this->unavailable_error();
		}

		$toolkit = $request->get_param( 'toolkit' );
		if ( ! in_array( $toolkit, self::TOOLKIT_SLUGS, true ) ) {
			return $this->unknown_toolkit_error();
		}

		$run = WP_MCP_AI_Sync_Log_Manager::get_run( $toolkit, $request->get_param( 'run_id' ) );
		if ( null === $run ) {
			return new WP_Error(
				'sync_runs_unknown_run',
				__( 'Sync run not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$payload = $this->sanitize_run_summary( $run, $toolkit );
		$items   = array();

		$raw_items = WP_MCP_AI_Sync_Log_Manager::get_run_items( $toolkit, $request->get_param( 'run_id' ) );
		foreach ( $raw_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'operation' => isset( $item['operation'] ) ? sanitize_key( (string) $item['operation'] ) : '',
				'item_key'  => isset( $item['item_key'] ) ? sanitize_text_field( (string) $item['item_key'] ) : '',
				'details'   => isset( $item['details'] ) && is_array( $item['details'] )
					? $this->sanitize_details( $item['details'] )
					: array(),
			);
		}

		$payload['items'] = $items;

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'run' => $payload ),
			)
		);
	}

	/**
	 * Whether the shared Sync Log Manager is loaded (it is when any
	 * commerce toolkit is enabled).
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	private function logs_available() {
		return class_exists( 'WP_MCP_AI_Sync_Log_Manager' );
	}

	/**
	 * Structured error when the sync log layer is absent.
	 *
	 * @since 2.4.0
	 * @return WP_Error
	 */
	private function unavailable_error() {
		return new WP_Error(
			'sync_runs_unavailable',
			__( 'No commerce toolkit sync logs are available on this site.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Structured error for unknown toolkit slugs.
	 *
	 * @since 2.4.0
	 * @return WP_Error
	 */
	private function unknown_toolkit_error() {
		return new WP_Error(
			'sync_runs_unknown_toolkit',
			__( 'Unknown commerce toolkit.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Whitelist-map a run entry to its public summary shape (two-gate rule:
	 * every value escapes at exit).
	 *
	 * @since 2.4.0
	 * @param array  $entry   Raw run entry from the Sync Log Manager.
	 * @param string $toolkit Toolkit slug.
	 * @return array
	 */
	private function sanitize_run_summary( $entry, $toolkit ) {
		$summary = isset( $entry['summary'] ) && is_array( $entry['summary'] ) ? $entry['summary'] : array();

		return array(
			'toolkit'       => sanitize_key( $toolkit ),
			'run_id'        => isset( $entry['run_id'] ) ? sanitize_key( (string) $entry['run_id'] ) : '',
			'connection_id' => isset( $entry['connection_id'] ) ? sanitize_text_field( (string) $entry['connection_id'] ) : '',
			'dry_run'       => ! empty( $entry['dry_run'] ),
			'started_at'    => isset( $entry['started_at'] ) ? sanitize_text_field( (string) $entry['started_at'] ) : '',
			'finished_at'   => isset( $entry['finished_at'] ) ? sanitize_text_field( (string) $entry['finished_at'] ) : '',
			'status'        => isset( $entry['status'] ) ? sanitize_key( (string) $entry['status'] ) : '',
			'summary'       => array(
				'items_updated' => isset( $summary['items_updated'] ) ? absint( $summary['items_updated'] ) : 0,
				'items_skipped' => isset( $summary['items_skipped'] ) ? absint( $summary['items_skipped'] ) : 0,
				'items_errored' => isset( $summary['items_errored'] ) ? absint( $summary['items_errored'] ) : 0,
			),
		);
	}

	/**
	 * Sanitize free-form item details to a scalar-only map.
	 *
	 * @since 2.4.0
	 * @param array $details Raw details array.
	 * @return array
	 */
	private function sanitize_details( $details ) {
		$clean = array();
		foreach ( $details as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
