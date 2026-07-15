<?php
/**
 * NV oOS Cloudways Dashboard — REST Controller
 *
 * Exposes `/wp-json/nvoos-cloudways-dashboard/v1/*` for servers, sites, and
 * toolkits. Delegates actual Cloudways API calls to the Pro toolkit's
 * `WP_MCP_AI_Cloudways_Client` singleton.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller.
 *
 * @since 0.1.0
 */
class NV_oOS_CloudwaysDashboard_REST {

	const REST_NAMESPACE = 'nvoos-cloudways-dashboard/v1';

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// ── Health ───────────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Servers ───────────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/servers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_servers' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'project_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/servers/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_server' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/servers/(?P<id>\d+)/apps',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_server_apps' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Apps / Sites ─────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/apps',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_apps' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_app' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
					'args'                => array(
						'server_id'     => array(
							'type'     => 'integer',
							'required' => true,
						),
						'application'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'app_label'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'project_name'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'toolkit_ids'   => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array(
								'type' => 'string',
							),
						),
						'assistant_ids' => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/apps/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_app' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Toolkits ──────────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/toolkits',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_toolkits' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Settings ──────────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
					'args'                => array(
						'cloudways_email'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'cloudways_api_key' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// ── Dashboard summary ─────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard_summary' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Provisioning Status ────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/apps/(?P<id>\d+)/provisioning',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_provisioning_status' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Site Toolkits ──────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/apps/(?P<id>\d+)/toolkits',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_site_toolkits' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'apply_site_toolkits' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
					'args'                => array(
						'toolkits' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'string',
							),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'remove_site_toolkits' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
					'args'                => array(
						'toolkits' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);

		// ── Toolkit Global Summary ─────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/toolkits/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'toolkit_global_summary' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// ── Projects ──────────────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/projects',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_projects' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	// ── Permission Callbacks ─────────────────────────────────────────

	/**
	 * Admin-only gate.
	 *
	 * @return bool|\WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error(
			'forbidden',
			__( 'You do not have permission to access the Cloudways dashboard.', 'nvoos-cloudways-dashboard' ),
			array( 'status' => 403 )
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Get the Cloudways client or error.
	 *
	 * @return \WP_MCP_AI_Cloudways_Client|\WP_Error
	 */
	private static function get_cw_client() {
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			return new \WP_Error( 'cloudways_unavailable', __( 'Cloudways Pro toolkit is not active.', 'nvoos-cloudways-dashboard' ), array( 'status' => 503 ) );
		}
		$client = \WP_MCP_AI_Cloudways_Client::instance();
		if ( ! $client->is_configured() ) {
			return new \WP_Error( 'cloudways_not_configured', __( 'Cloudways API credentials are not configured.', 'nvoos-cloudways-dashboard' ), array( 'status' => 412 ) );
		}
		return $client;
	}

	/**
	 * Return error response if the value is a WP_Error.
	 *
	 * @param mixed  $result  API result or WP_Error.
	 * @param string $context Error context label.
	 * @return mixed|\WP_Error
	 */
	private static function unwrap( $result, $context = '' ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 500;
			if ( $context ) {
				return new \WP_Error( $result->get_error_code(), sprintf( '[%s] %s', $context, $result->get_error_message() ), array( 'status' => $status ) );
			}
			return $result;
		}
		return $result;
	}

	// ── Endpoints ────────────────────────────────────────────────────

	/**
	 * GET /health
	 *
	 * @return \WP_REST_Response
	 */
	public static function health() {
		$client_status = 'unavailable';
		if ( class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			$c             = \WP_MCP_AI_Cloudways_Client::instance();
			$client_status = $c->is_configured() ? 'connected' : 'not_configured';
		}
		return rest_ensure_response(
			array(
				'status'    => 'ok',
				'version'   => NVOOS_CLOUDWAYS_DASHBOARD_VERSION,
				'cloudways' => $client_status,
			)
		);
	}

	/**
	 * GET /servers
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_servers( $request ) {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$project_id = $request->get_param( 'project_id' ) ? sanitize_text_field( $request->get_param( 'project_id' ) ) : '';
		return self::unwrap( $client->get( '/servers', $project_id ? array( 'project_id' => $project_id ) : array() ), 'list_servers' );
	}

	/**
	 * GET /servers/{id}
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_server( $request ) {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$server_id = absint( $request->get_param( 'id' ) );
		return self::unwrap( $client->get( "/server/{$server_id}" ), 'get_server' );
	}

	/**
	 * GET /servers/{id}/apps
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_server_apps( $request ) {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$server_id = absint( $request->get_param( 'id' ) );
		return self::unwrap( $client->get( "/server/{$server_id}/apps" ), 'list_server_apps' );
	}

	/**
	 * GET /apps
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_apps( $request ) {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		// Fetch all servers first, then aggregate apps.
		$servers = self::list_servers( $request );
		if ( is_wp_error( $servers ) ) {
			return $servers;
		}
		$data        = $servers->get_data();
		$server_list = isset( $data['servers'] ) ? $data['servers'] : array();
		$all_apps    = array();
		foreach ( $server_list as $server ) {
			if ( ! isset( $server['id'] ) ) {
				continue;
			}
			$apps_result = $client->get( "/server/{$server['id']}/apps" );
			if ( is_wp_error( $apps_result ) ) {
				continue;
			}
			$apps = isset( $apps_result['apps'] ) ? $apps_result['apps'] : array();
			foreach ( $apps as &$app ) {
				$app['server_id']   = $server['id'];
				$app['server_name'] = isset( $server['label'] ) ? $server['label'] : '';
			}
			unset( $app );
			$all_apps = array_merge( $all_apps, $apps );
		}
		return rest_ensure_response(
			array(
				'apps'  => $all_apps,
				'count' => count( $all_apps ),
			)
		);
	}

	/**
	 * POST /apps
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_app( $request ) {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$server_id    = absint( $request->get_param( 'server_id' ) );
		$application  = sanitize_text_field( $request->get_param( 'application' ) );
		$app_label    = sanitize_text_field( $request->get_param( 'app_label' ) );
		$project_name = sanitize_text_field( $request->get_param( 'project_name' ) );

		$body = array(
			'server_id'    => (string) $server_id,
			'application'  => $application,
			'app_label'    => $app_label,
			'project_name' => $project_name,
		);

		$result = $client->post( '/app', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record the intent — toolkits/assistants will be applied post-creation.
		$toolkit_ids   = (array) $request->get_param( 'toolkit_ids' );
		$assistant_ids = (array) $request->get_param( 'assistant_ids' );
		$app_id        = isset( $result['app']['id'] ) ? absint( $result['app']['id'] ) : 0;

		if ( $app_id ) {
			// Store pending toolkit configuration.
			if ( ! empty( $toolkit_ids ) || ! empty( $assistant_ids ) ) {
				update_option(
					"nvoos_cw_pending_toolkits_{$app_id}",
					array(
						'toolkit_ids'   => array_map( 'sanitize_key', $toolkit_ids ),
						'assistant_ids' => array_map( 'sanitize_key', $assistant_ids ),
						'created_at'    => time(),
					)
				);
			}

			// Enqueue the provisioning monitor job (async polling + toolkit apply).
			if ( class_exists( 'NV_oOS_CloudwaysDashboard_Provisioning_Job' ) ) {
				NV_oOS_CloudwaysDashboard_Provisioning_Job::enqueue( $app_id, $toolkit_ids, $assistant_ids );
			}
		}

		/**
		 * Filter the create-app response before returning to the SPA.
		 *
		 * @param array $result    Cloudways API response.
		 * @param int   $server_id Target server ID.
		 */
		$result = apply_filters( 'nvoos_cloudways_dashboard_app_created', $result, $server_id );

		return rest_ensure_response( $result );
	}

	/**
	 * GET /apps/{id}
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_app( $request ) {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$app_id = absint( $request->get_param( 'id' ) );
		$result = self::unwrap( $client->get( "/app/{$app_id}" ), 'get_app' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Attach pending toolkit config if present.
		$pending = get_option( "nvoos_cw_pending_toolkits_{$app_id}", null );
		if ( $pending ) {
			$data                     = $result->get_data();
			$data['pending_toolkits'] = $pending;
			$result->set_data( $data );
		}

		return $result;
	}

	/**
	 * GET /toolkits
	 *
	 * @return \WP_REST_Response
	 */
	public static function list_toolkits() {
		$toolkits = array();
		if ( class_exists( 'NV_oOS_Toolkit_Shell_Manifest_Registry' ) ) {
			$all = \NV_oOS_Toolkit_Shell_Manifest_Registry::get_all();
			foreach ( $all as $slug => $manifest ) {
				$toolkits[] = array(
					'slug'        => $slug,
					'label'       => isset( $manifest['label'] ) ? $manifest['label'] : $slug,
					'description' => isset( $manifest['description'] ) ? $manifest['description'] : '',
					'icon'        => isset( $manifest['icon'] ) ? $manifest['icon'] : 'admin-generic',
					'version'     => isset( $manifest['version'] ) ? $manifest['version'] : '1.0',
				);
			}
		}

		/**
		 * Filter the toolkit list returned to the dashboard.
		 *
		 * @param array $toolkits Toolkit summaries.
		 */
		$toolkits = apply_filters( 'nvoos_cloudways_dashboard_toolkits', $toolkits );

		return rest_ensure_response(
			array(
				'toolkits' => $toolkits,
				'count'    => count( $toolkits ),
			)
		);
	}

	/**
	 * GET /settings
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings() {
		$configured   = false;
		$masked_email = '';
		if ( function_exists( 'wp_mcp_ai_cloudways_has_credentials' ) ) {
			$configured = wp_mcp_ai_cloudways_has_credentials();
		}
		if ( $configured ) {
			$raw   = get_option( 'wp_mcp_ai_settings', array() );
			$email = isset( $raw['cloudways_email'] ) ? $raw['cloudways_email'] : '';
			if ( $email && is_string( $email ) ) {
				$parts        = explode( '@', $email );
				$masked_email = ( strlen( $parts[0] ) > 2 ? substr( $parts[0], 0, 2 ) . '***' : $parts[0] ) . '@' . $parts[1];
			}
		}
		return rest_ensure_response(
			array(
				'configured'   => $configured,
				'masked_email' => $masked_email,
			)
		);
	}

	/**
	 * PUT /settings
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_settings( $request ) {
		$email   = $request->get_param( 'cloudways_email' );
		$api_key = $request->get_param( 'cloudways_api_key' );
		if ( ! $email || ! $api_key ) {
			return new \WP_Error( 'missing_fields', __( 'Email and API key are required.', 'nvoos-cloudways-dashboard' ), array( 'status' => 400 ) );
		}
		$settings                      = get_option( 'wp_mcp_ai_settings', array() );
		$settings['cloudways_email']   = sanitize_email( $email );
		$settings['cloudways_api_key'] = sanitize_text_field( $api_key );
		update_option( 'wp_mcp_ai_settings', $settings );

		// Also store the API key in the credentials option so it is not lost
		// when the main settings dashboard splits sensitive keys on next save.
		$credentials                        = get_option( 'wp_mcp_ai_credentials', array() );
		$credentials['cloudways_api_key']   = sanitize_text_field( $api_key );
		update_option( 'wp_mcp_ai_credentials', $credentials, false );

		// Force re-auth.
		if ( class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			\WP_MCP_AI_Cloudways_Client::instance()->disconnect();
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * GET /summary — dashboard aggregate counts
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function dashboard_summary() {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$servers_resp = self::list_servers( new \WP_REST_Request() );
		if ( is_wp_error( $servers_resp ) ) {
			return $servers_resp;
		}
		$server_data = $servers_resp->get_data();
		$servers     = isset( $server_data['servers'] ) ? $server_data['servers'] : array();

		$total_apps      = 0;
		$running_servers = 0;
		foreach ( $servers as $s ) {
			if ( isset( $s['status'] ) && 'running' === strtolower( $s['status'] ) ) {
				++$running_servers;
			}
			// Count apps lazily from cached data if available.
		}

		// Get total apps via a lightweight aggregation.
		$apps_resp = self::list_apps( new \WP_REST_Request() );
		if ( ! is_wp_error( $apps_resp ) ) {
			$apps_data  = $apps_resp->get_data();
			$total_apps = isset( $apps_data['count'] ) ? (int) $apps_data['count'] : 0;
		}

		return rest_ensure_response(
			array(
				'total_servers'   => count( $servers ),
				'running_servers' => $running_servers,
				'total_apps'      => $total_apps,
				'total_toolkits'  => count( self::list_toolkits()->get_data()['toolkits'] ?? array() ),
			)
		);
	}

	/**
	 * GET /projects
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_projects() {
		$client = self::get_cw_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return self::unwrap( $client->get( '/projects' ), 'list_projects' );
	}

	/**
	 * GET /apps/{id}/provisioning
	 *
	 * Returns the current provisioning status for an app.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_provisioning_status( $request ) {
		$app_id = absint( $request->get_param( 'id' ) );
		$status = get_option( \NV_oOS_CloudwaysDashboard_Provisioning_Job::status_key( $app_id ), array() );

		// Also fetch latest Cloudways app data if the client is available.
		$app_data = null;
		$client   = self::get_cw_client();
		if ( ! is_wp_error( $client ) ) {
			$result = $client->get( "/app/{$app_id}" );
			if ( ! is_wp_error( $result ) ) {
				$app_data = isset( $result['app'] ) ? $result['app'] : $result;
			}
		}

		// Merge pending toolkit info.
		$pending = get_option( "nvoos_cw_pending_toolkits_{$app_id}", null );

		return rest_ensure_response(
			array(
				'app_id'           => $app_id,
				'status'           => isset( $status['status'] ) ? $status['status'] : 'unknown',
				'attempt'          => isset( $status['attempt'] ) ? $status['attempt'] : 0,
				'started_at'       => isset( $status['started_at'] ) ? $status['started_at'] : null,
				'last_poll_at'     => isset( $status['last_poll_at'] ) ? $status['last_poll_at'] : null,
				'error'            => isset( $status['error'] ) ? $status['error'] : null,
				'results'          => isset( $status['results'] ) ? $status['results'] : null,
				'app_data'         => $app_data,
				'pending_toolkits' => $pending,
			)
		);
	}

	// ── Site Toolkit Endpoints ──────────────────────────────────────

	/**
	 * GET /apps/{id}/toolkits
	 *
	 * Get toolkits applied to a specific site.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_site_toolkits( $request ) {
		$app_id   = absint( $request->get_param( 'id' ) );
		$toolkits = \NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_site_toolkits( $app_id );
		return rest_ensure_response(
			array(
				'app_id'   => $app_id,
				'toolkits' => $toolkits,
				'count'    => count( $toolkits ),
			)
		);
	}

	/**
	 * POST /apps/{id}/toolkits
	 *
	 * Apply toolkits to a specific site.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function apply_site_toolkits( $request ) {
		$app_id        = absint( $request->get_param( 'id' ) );
		$toolkit_slugs = (array) $request->get_param( 'toolkits' );
		if ( empty( $toolkit_slugs ) ) {
			return new \WP_Error( 'no_toolkits', __( 'At least one toolkit slug is required.', 'nvoos-cloudways-dashboard' ), array( 'status' => 400 ) );
		}
		$results = \NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( $app_id, $toolkit_slugs );
		return rest_ensure_response(
			array(
				'app_id'  => $app_id,
				'results' => $results,
			)
		);
	}

	/**
	 * PUT /apps/{id}/toolkits
	 *
	 * Remove toolkits from a specific site.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_site_toolkits( $request ) {
		$app_id        = absint( $request->get_param( 'id' ) );
		$toolkit_slugs = (array) $request->get_param( 'toolkits' );
		if ( empty( $toolkit_slugs ) ) {
			return new \WP_Error( 'no_toolkits', __( 'At least one toolkit slug is required.', 'nvoos-cloudways-dashboard' ), array( 'status' => 400 ) );
		}
		$results = \NV_oOS_CloudwaysDashboard_Toolkit_Manager::remove_toolkits( $app_id, $toolkit_slugs );
		return rest_ensure_response(
			array(
				'app_id'  => $app_id,
				'results' => $results,
			)
		);
	}

	/**
	 * GET /toolkits/summary
	 *
	 * Get a global summary of all toolkits.
	 *
	 * @return \WP_REST_Response
	 */
	public static function toolkit_global_summary() {
		return rest_ensure_response( \NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_global_summary() );
	}
}
