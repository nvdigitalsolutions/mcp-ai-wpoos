<?php
/**
 * Toolkit MCP REST Controller
 *
 * Exposes per-toolkit MCP JSON-RPC endpoints under the namespace `mcp-ai-pro/v1`,
 * leaving the existing monolithic `/mcp-ai/v1/mcp` endpoint untouched.
 *
 * Routes:
 *   GET  /mcp-ai-pro/v1/mcp                — discovery descriptor for ALL servers.
 *   GET  /mcp-ai-pro/v1/mcp/{slug}         — per-server descriptor.
 *   POST /mcp-ai-pro/v1/mcp/{slug}         — JSON-RPC 2.0 entry point.
 *
 * Supported JSON-RPC methods (Phase 3a):
 *   - initialize
 *   - ping
 *   - tools/list
 *   - tools/call            (Phase 3a)
 *   - resources/list
 *   - resources/read        (Phase 3a)
 *   - prompts/list
 *   - prompts/get           (Phase 3a)
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for per-toolkit MCP servers.
 */
class WP_MCP_AI_Toolkit_MCP_REST_Controller {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Singleton.
	 *
	 * @var WP_MCP_AI_Toolkit_MCP_REST_Controller|null
	 */
	private static $instance = null;

	/**
	 * Authenticator instance for scope checking (lazy-loaded).
	 *
	 * @var WP_MCP_AI_REST_Authenticator|null
	 */
	private $authenticator = null;

	/**
	 * Get singleton.
	 *
	 * @return WP_MCP_AI_Toolkit_MCP_REST_Controller
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the authenticator instance (lazy-loaded).
	 *
	 * @since 1.7.0
	 *
	 * @return WP_MCP_AI_REST_Authenticator
	 */
	private function get_auth() {
		if ( null === $this->authenticator ) {
			$this->authenticator = new WP_MCP_AI_REST_Authenticator();
		}
		return $this->authenticator;
	}

	/**
	 * Hook setup.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list_descriptors' ),
					'permission_callback' => array( $this, 'permission_read' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp/(?P<slug>[a-z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_descriptor' ),
					'permission_callback' => array( $this, 'permission_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_jsonrpc' ),
					'permission_callback' => array( $this, 'permission_jsonrpc' ),
				),
			)
		);

		// Phase 3d — token management routes (manage_options only).
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp/(?P<slug>[a-z0-9_\-]+)/token',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_token_list' ),
					'permission_callback' => array( $this, 'permission_manage_tokens' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_token_generate' ),
					'permission_callback' => array( $this, 'permission_manage_tokens' ),
					'args'                => array(
						'label' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp/(?P<slug>[a-z0-9_\-]+)/token/(?P<prefix>[a-f0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_token_revoke' ),
					'permission_callback' => array( $this, 'permission_manage_tokens' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp-audit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_audit_log' ),
					'permission_callback' => array( $this, 'permission_audit' ),
					'args'                => array(
						'limit'        => array(
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 200,
							'sanitize_callback' => 'absint',
						),
						'consumer'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'source'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'summary_only' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Discovery descriptor read permission. Public-readable list (descriptor
	 * does not contain sensitive data; capability gates apply at JSON-RPC level).
	 *
	 * @return bool
	 */
	public function permission_read() {
		return true;
	}

	/**
	 * JSON-RPC permission.
	 *
	 * Accepts either:
	 *  (a) A valid per-server bearer token (`Authorization: Bearer mcptk_…`)
	 *      issued via the token management REST endpoints (Phase 3d), or
	 *  (b) An authenticated WordPress user session with the `read` capability.
	 *
	 * @since 1.2.0
	 * @since 1.5.0 Also accepts per-server bearer tokens (Phase 3d).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_jsonrpc( WP_REST_Request $request ) {
				$auth_header = $request->get_header( 'Authorization' );
				$slug        = sanitize_key( (string) $request->get_param( 'slug' ) );

				// Build the canonical MCP server URL for audience validation.
				$mcp_url = rest_url( self::REST_NAMESPACE . '/mcp/' . $slug );

				// Bearer token authentication.
		if ( $auth_header && 0 === strpos( $auth_header, 'Bearer ' ) ) {
			$raw_token = substr( $auth_header, 7 );

			// Phase 3d — per-server bearer token check (mcptk_…).
			if (
				class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) &&
				0 === strpos( $raw_token, WP_MCP_AI_Pro_Toolkit_Server_Token::TOKEN_PREFIX )
			) {
				if ( WP_MCP_AI_Pro_Toolkit_Server_Token::validate( $slug, $raw_token ) ) {
					return true;
				}
				return new WP_Error(
					'rest_forbidden',
					__( 'Invalid server token.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 401 )
				);
			}

			// OAuth 2.0 access token (mcp_at_…) — validate with audience check.
			if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
				$oauth  = WP_MCP_AI_OAuth_Server::get_instance();
				$result = $oauth->validate_token( $raw_token, $mcp_url );
				if ( null !== $result ) {
					if ( $result['user_id'] > 0 ) {
						wp_set_current_user( $result['user_id'] );
					}
					return true;
				}
				if ( 0 === strpos( $raw_token, 'mcp_at_' ) ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'Invalid or expired OAuth token.', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 401 )
					);
				}
			}
		}

				// WordPress Basic auth / session / nonce.
		if ( ! is_user_logged_in() ) {
			$error = new WP_Error(
				'rest_forbidden',
				__( 'Authentication required. Provide a Bearer token, use OAuth 2.0, or authenticate with a WordPress Application Password (Basic auth).', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 401 )
			);
			// Per MCP spec: include WWW-Authenticate header.
			if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
				$error->add_data(
					array(
						'www_authenticate' => WP_MCP_AI_OAuth_Server::build_www_authenticate( $mcp_url ),
					)
				);
			}
			return $error;
		}
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
				return true;
	}

	/**
	 * Token management permission — requires `manage_options`.
	 *
	 * @since 1.5.0
	 *
	 * @return bool|WP_Error
	 */
	public function permission_manage_tokens() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Audit log read permission — requires `manage_options`.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_audit() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /mcp-audit — return cross-mount audit log entries.
	 *
	 * Query params: limit (1-200), consumer (slug filter), source (slug filter),
	 * summary_only (bool — return grouped summary instead of raw entries).
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_audit_log( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Audit_Log' ) ) {
			return rest_ensure_response(
				array(
					'entries' => array(),
					'total'   => 0,
				)
			);
		}

		$log          = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance();
		$summary_only = (bool) $request->get_param( 'summary_only' );

		if ( $summary_only ) {
			return rest_ensure_response( array( 'summary' => $log->get_summary() ) );
		}

		$limit    = (int) $request->get_param( 'limit' );
		$consumer = (string) $request->get_param( 'consumer' );
		$source   = (string) $request->get_param( 'source' );

		$filter       = '';
		$filter_field = '';
		if ( '' !== $consumer ) {
			$filter       = $consumer;
			$filter_field = 'consumer';
		} elseif ( '' !== $source ) {
			$filter       = $source;
			$filter_field = 'source';
		}

		$entries = $log->get_entries( $limit, $filter, $filter_field );

		return rest_ensure_response(
			array(
				'entries' => $entries,
				'total'   => count( $entries ),
			)
		);
	}

	/**
	 * GET /mcp — list all server descriptors.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_list_descriptors() {
		$registry    = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$descriptors = array();
		foreach ( $registry->all() as $server ) {
			$descriptors[] = $server->get_descriptor();
		}
		return rest_ensure_response(
			array(
				'protocolVersion' => '2025-06-18',
				'servers'         => $descriptors,
			)
		);
	}

	/**
	 * GET /mcp/{slug} — single server descriptor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_descriptor( WP_REST_Request $request ) {
		$slug   = sanitize_key( $request['slug'] );
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug );
		if ( null === $server ) {
			return new WP_Error( 'mcp_server_not_found', __( 'Toolkit MCP server not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $server->get_descriptor() );
	}

	/**
	 * POST /mcp/{slug} — JSON-RPC 2.0 dispatch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_jsonrpc( WP_REST_Request $request ) {
		$slug   = sanitize_key( $request['slug'] );
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug );
		if ( null === $server ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32601,
						'message' => 'Server not found',
					),
				)
			);
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$id      = isset( $payload['id'] ) ? $payload['id'] : null;
		$method  = isset( $payload['method'] ) ? (string) $payload['method'] : '';
		$jsonrpc = isset( $payload['jsonrpc'] ) ? (string) $payload['jsonrpc'] : '';

		if ( '2.0' !== $jsonrpc ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Invalid Request: jsonrpc must be "2.0"',
					),
				)
			);
		}

		// Block all non-initialize/ping calls when the server is disabled.
		if ( ! $server->is_enabled() && ! in_array( $method, array( 'initialize', 'ping' ), true ) ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32601,
						'message' => 'Server disabled by site administrator',
					),
				)
			);
		}

		// Phase 3c — payload + rate limits. Skip for cheap probe methods.
		if ( ! in_array( $method, array( 'initialize', 'ping' ), true ) ) {
			$limit_error = $this->enforce_limits( $server, $request );
			if ( null !== $limit_error ) {
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'error'   => $limit_error,
					)
				);
			}

			// OAuth scope enforcement (mcp:read for reads, mcp:write for writes).
			$scope_error = $this->check_scope_for_method( $method, $slug );
			if ( null !== $scope_error ) {
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'error'   => $scope_error,
					)
				);
			}
		}

		switch ( $method ) {
			case 'initialize':
				$params       = isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();
				$assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;

				$result = array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => array(
						'tools'     => (object) array(),
						'resources' => (object) array(
							'subscribe'   => false,
							'listChanged' => false,
						),
						'prompts'   => (object) array( 'listChanged' => false ),
					),
					'serverInfo'      => array(
						'name'    => $server->get_name(),
						'version' => $server->get_version(),
						'slug'    => $server->get_slug(),
					),
				);

				// When an assistant is provided, inject personality and model preferences.
				if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
					$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

					$instructions = 'You are connected to the ' . $server->get_name() . ' toolkit MCP server.';
					if ( ! empty( $assistant_config['system_prompt'] ) ) {
						$instructions = $assistant_config['system_prompt'] . "\n\n---\n\n" . $instructions;
					}

					// Append toolkit instructions from the server's descriptor.
					$instructions .= "\n\n" . sprintf(
						/* translators: %s: toolkit description */
						__( 'Toolkit: %s', 'mcp-ai-wpoos-pro' ),
						$server->get_description()
					);

					$result['instructions'] = $instructions;

					// Model preferences — community extension for Zed, Claude Desktop, Cursor.
					if ( ! empty( $assistant_config['model'] ) ) {
						$prefs = array( 'model' => $assistant_config['model'] );
						if ( null !== $assistant_config['temperature'] ) {
							$prefs['temperature'] = $assistant_config['temperature'];
						}
						$result['modelPreferences'] = $prefs;
					}

					// Personalize serverInfo with the assistant's display name.
					$assistant_title = get_the_title( $assistant_id );
					if ( ! empty( $assistant_title ) ) {
						$result['serverInfo']['name']        = $assistant_title;
						$result['serverInfo']['assistantId'] = $assistant_id;
					}

					// Inject toolkit grouping metadata from the assistant→server bridge.
					if ( class_exists( 'WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers' ) ) {
						$allowed_servers = WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::get_allowed_servers( $assistant_id );
						if ( ! empty( $allowed_servers ) ) {
							$registry     = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
							$toolkit_meta = array();
							foreach ( $allowed_servers as $server_slug ) {
								$linked = $registry->get( $server_slug );
								if ( null === $linked ) {
									continue;
								}
								$toolkit_meta[] = array(
									'slug'        => $linked->get_slug(),
									'name'        => $linked->get_name(),
									'description' => $linked->get_description(),
									'enabled'     => $linked instanceof WP_MCP_AI_Toolkit_Server_Base && $linked->is_enabled(),
								);
							}
							if ( ! empty( $toolkit_meta ) ) {
												$result['toolkitServers'] = $toolkit_meta;
							}
						}
					}
				}

								// OAuth 2.0 discovery metadata (MCP Authorization Specification 2025-06-18).
								// Advertise the authorization server so MCP clients can offer a
								// browser-based "Connect" flow instead of requiring manual token entry.
				if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
					$result['_meta'] = array(
						'oauth' => WP_MCP_AI_OAuth_Server::get_instance()->get_toolkit_metadata( $server->get_slug() ),
					);
				}

				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'result'  => $result,
					)
				);

			case 'ping':
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'result'  => (object) array(),
					)
				);

			case 'tools/list':
				$tools = method_exists( $server, 'get_tools' ) ? $server->get_tools() : array();
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'result'  => array( 'tools' => $tools ),
					)
				);

			case 'tools/call':
				return $this->handle_tools_call( $server, $id, isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() );

			case 'resources/list':
				$resources = method_exists( $server, 'get_resources' ) ? $server->get_resources() : array();
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'result'  => array( 'resources' => $resources ),
					)
				);

			case 'resources/read':
				return $this->handle_resources_read( $server, $id, isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() );

			case 'prompts/list':
				$prompts = method_exists( $server, 'get_prompts' ) ? $server->get_prompts() : array();
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'result'  => array( 'prompts' => $prompts ),
					)
				);

			case 'prompts/get':
				return $this->handle_prompts_get( $server, $id, isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() );

			default:
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'error'   => array(
							'code'    => -32601,
							'message' => 'Method not found: ' . $method,
							'data'    => array(
								'supported_methods' => array( 'initialize', 'ping', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' ),
							),
						),
					)
				);
		}
	}

	/**
	 * Check OAuth scope for a JSON-RPC method.
	 *
	 * Read methods (tools/list, resources/*, prompts/*) require `mcp:read`.
	 * Write methods (tools/call) require `mcp:write`.
	 *
	 * Returns null when scope is sufficient or the request is not OAuth.
	 * Returns a JSON-RPC error array when scope is insufficient.
	 *
	 * @since 1.7.0
	 *
	 * @param string $method JSON-RPC method name.
	 * @param string $slug   Server slug.
	 * @return array{code:int, message:string, data:array}|null
	 */
	protected function check_scope_for_method( $method, $slug ) {
		// Map methods to required scopes.
		$read_methods  = array( 'tools/list', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' );
		$write_methods = array( 'tools/call' );

		$required = null;
		if ( in_array( $method, $read_methods, true ) ) {
			$required = 'mcp:read';
		} elseif ( in_array( $method, $write_methods, true ) ) {
			$required = 'mcp:write';
		}

		if ( null === $required ) {
			return null;
		}

		// Use the authenticator instance.
		if ( ! class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
			return null;
		}

			$auth    = $this->get_auth();
			$granted = $auth->get_oauth_scope();

		// Scope check.
		if ( ! class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			return null;
		}

		if ( WP_MCP_AI_OAuth_Server::scope_satisfies( $granted, $required ) ) {
			return null;
		}

		// Scope insufficient — return JSON-RPC error per MCP spec.
		$mcp_url = rest_url( self::REST_NAMESPACE . '/mcp/' . $slug );
		return array(
			'code'    => -32001,
			'message' => 'Insufficient scope: ' . $required . ' is required for ' . $method,
			'data'    => array(
				'required_scope'   => $required,
				'granted_scope'    => $granted,
				'www_authenticate' => WP_MCP_AI_OAuth_Server::build_insufficient_scope_www_authenticate( $required, $mcp_url ),
			),
		);
	}

	/**
	 * Enforce per-server payload size + per-user rate-limit.
	 *
	 * Returns a JSON-RPC error envelope's `error` array, or null when the
	 * request is allowed through.
	 *
	 * @since 1.3.0
	 *
	 * @param WP_MCP_AI_Toolkit_Server_Interface $server  Server.
	 * @param WP_REST_Request                    $request Request.
	 * @return array<string,mixed>|null
	 */
	protected function enforce_limits( $server, WP_REST_Request $request ) {
		if ( ! ( $server instanceof WP_MCP_AI_Toolkit_Server_Base ) ) {
			return null;
		}
		$limits = $server->effective_limits();

		// Payload guard.
		if ( $limits['max_payload_bytes'] > 0 ) {
			$body = (string) $request->get_body();
			if ( strlen( $body ) > $limits['max_payload_bytes'] ) {
				return array(
					'code'    => -32098,
					'message' => 'Payload too large',
					'data'    => array(
						'max_payload_bytes' => $limits['max_payload_bytes'],
						'received_bytes'    => strlen( $body ),
					),
				);
			}
		}

		// Rate-limit guard (per-user, per-server, 60-second window via transient).
		if ( $limits['requests_per_minute'] > 0 ) {
			$user_id  = (int) get_current_user_id();
			$bucket_k = sprintf( 'wp_mcp_ai_tk_mcp_rl_%s_%d_%d', $server->get_slug(), $user_id, (int) floor( time() / 60 ) );
			$bucket   = (int) get_transient( $bucket_k );
			if ( $bucket >= $limits['requests_per_minute'] ) {
				return array(
					'code'    => -32099,
					'message' => 'Rate limit exceeded',
					'data'    => array(
						'requests_per_minute' => $limits['requests_per_minute'],
						'retry_after_seconds' => 60 - ( time() % 60 ),
					),
				);
			}
			set_transient( $bucket_k, $bucket + 1, MINUTE_IN_SECONDS );
		}

		return null;
	}

	/**
	 * Handle `tools/call` against a per-toolkit MCP server.
	 *
	 * @since 1.3.0
	 *
	 * @param WP_MCP_AI_Toolkit_Server_Interface $server Server.
	 * @param mixed                              $id     JSON-RPC id.
	 * @param array<string,mixed>                $params Params object.
	 * @return WP_REST_Response
	 */
	protected function handle_tools_call( $server, $id, $params ) {
		$name      = isset( $params['name'] ) ? sanitize_key( (string) $params['name'] ) : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32602,
						'message' => 'Invalid params: missing tool name',
					),
				)
			);
		}

		if ( ! ( $server instanceof WP_MCP_AI_Toolkit_Server_Base ) || ! $server->tool_is_allowed( $name ) ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32601,
						'message' => 'Tool not exposed by this server',
						'data'    => array(
							'tool'   => $name,
							'server' => $server->get_slug(),
						),
					),
				)
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32603,
						'message' => 'Internal error: tool registry unavailable',
					),
				)
			);
		}

		/**
		 * Fires before a tool is executed through a per-toolkit MCP server.
		 *
		 * @since 1.3.0
		 *
		 * @param string                              $tool_slug Tool slug.
		 * @param array                               $arguments Tool arguments.
		 * @param WP_MCP_AI_Toolkit_Server_Interface  $server    Server instance.
		 */
		do_action( 'wp_mcp_ai_toolkit_mcp_before_call', $name, $arguments, $server );

		$context = array(
			'source'             => 'toolkit_mcp',
			'toolkit_mcp_server' => $server->get_slug(),
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$result   = $registry->execute_tool( $name, $arguments, $context );

		/**
		 * Fires after a tool is executed through a per-toolkit MCP server.
		 *
		 * @since 1.3.0
		 *
		 * @param string                              $tool_slug Tool slug.
		 * @param array                               $arguments Tool arguments.
		 * @param mixed                               $result    Raw result or WP_Error.
		 * @param WP_MCP_AI_Toolkit_Server_Interface  $server    Server instance.
		 */
		do_action( 'wp_mcp_ai_toolkit_mcp_after_call', $name, $arguments, $result, $server );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32000,
						'message' => $result->get_error_message(),
						'data'    => array(
							'tool' => $name,
							'code' => $result->get_error_code(),
						),
					),
				)
			);
		}

		// Wrap raw result into MCP text content. JSON-encode arrays/objects.
		if ( is_string( $result ) ) {
			$text = $result;
		} else {
			$encoded = wp_json_encode( $result );
			$text    = false === $encoded ? '' : $encoded;
		}

		return rest_ensure_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
					'isError' => false,
				),
			)
		);
	}

	/**
	 * Handle `resources/read` against a per-toolkit MCP server.
	 *
	 * Resource URIs follow the form emitted by `get_resources()`:
	 *  - Native:  `nvoos://{slug}/{entity}`
	 *  - Mounted: `nvoos://{slug}/_mounted/{source}/{entity}`
	 *
	 * For now the resource body is a small descriptor of the underlying
	 * collection — implementing full record materialization is deferred so
	 * we can land the JSON-RPC method shape without tying it to any one
	 * toolkit's storage backend.
	 *
	 * @since 1.3.0
	 *
	 * @param WP_MCP_AI_Toolkit_Server_Interface $server Server.
	 * @param mixed                              $id     JSON-RPC id.
	 * @param array<string,mixed>                $params Params object.
	 * @return WP_REST_Response
	 */
	protected function handle_resources_read( $server, $id, $params ) {
		$uri = isset( $params['uri'] ) ? esc_url_raw( (string) $params['uri'] ) : '';
		if ( '' === $uri ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32602,
						'message' => 'Invalid params: missing uri',
					),
				)
			);
		}

		// Locate the resource in the effective list.
		$resources = method_exists( $server, 'get_resources' ) ? $server->get_resources() : array();
		$match     = null;
		foreach ( $resources as $resource ) {
			if ( isset( $resource['uri'] ) && $resource['uri'] === $uri ) {
				$match = $resource;
				break;
			}
		}
		if ( null === $match ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32602,
						'message' => 'Unknown or revoked resource',
						'data'    => array( 'uri' => $uri ),
					),
				)
			);
		}

		// Build a structured descriptor of the entity collection. Real record
		// materialization belongs to a follow-up phase; for now the payload is
		// enough for clients to know what they're pointing at.
		$entity_type = isset( $match['name'] ) ? (string) $match['name'] : '';
		$is_mounted  = isset( $match['annotations']['readOnly'] ) && true === $match['annotations']['readOnly'];

		$body = array(
			'server'      => $server->get_slug(),
			'uri'         => $uri,
			'entity_type' => $entity_type,
			'description' => isset( $match['description'] ) ? (string) $match['description'] : '',
			'mounted'     => $is_mounted,
		);

		// Suppress write semantics on mounted resources — keep `readOnly` true.
		if ( $is_mounted ) {
			$body['read_only'] = true;
		}

		if ( $is_mounted ) {
			// Determine source slug from the mounted URI: nvoos://{consumer}/_mounted/{source}/{entity}.
			$parsed_source = '';
			$safe_uri      = sanitize_text_field( (string) $uri );
			if ( preg_match( '#^nvoos://[^/]+/_mounted/([^/]+)/#', $safe_uri, $m ) ) {
				$parsed_source = $m[1];
			}

			/**
			 * Fires whenever a mounted resource is read through a per-toolkit MCP server.
			 *
			 * @since 1.4.0
			 *
			 * @param string                             $consumer_slug Consumer server slug.
			 * @param string                             $source_slug   Source (mounted) server slug.
			 * @param string                             $entity_type   Entity type / resource name.
			 * @param string                             $uri           Resource URI.
			 * @param string                             $method        JSON-RPC method ('resources/read').
			 * @param WP_MCP_AI_Toolkit_Server_Interface $server        Consumer server instance.
			 */
			do_action( 'wp_mcp_ai_toolkit_mcp_cross_mount_read', $server->get_slug(), $parsed_source, $entity_type, $uri, 'resources/read', $server );
		}

		$encoded = wp_json_encode( $body );

		return rest_ensure_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'contents' => array(
						array(
							'uri'      => $uri,
							'mimeType' => isset( $match['mimeType'] ) ? $match['mimeType'] : 'application/json',
							'text'     => false === $encoded ? '' : $encoded,
						),
					),
				),
			)
		);
	}

	/**
	 * Handle `prompts/get` against a per-toolkit MCP server.
	 *
	 * Looks up the prompt by name in the server's effective `prompts/list`
	 * output, then returns a single-message envelope describing the surface
	 * the prompt is bound to.
	 *
	 * @since 1.3.0
	 *
	 * @param WP_MCP_AI_Toolkit_Server_Interface $server Server.
	 * @param mixed                              $id     JSON-RPC id.
	 * @param array<string,mixed>                $params Params object.
	 * @return WP_REST_Response
	 */
	protected function handle_prompts_get( $server, $id, $params ) {
		$name = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		if ( '' === $name ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32602,
						'message' => 'Invalid params: missing prompt name',
					),
				)
			);
		}

		$prompts = method_exists( $server, 'get_prompts' ) ? $server->get_prompts() : array();
		$match   = null;
		foreach ( $prompts as $prompt ) {
			if ( isset( $prompt['name'] ) && $prompt['name'] === $name ) {
				$match = $prompt;
				break;
			}
		}
		if ( null === $match ) {
			return rest_ensure_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32602,
						'message' => 'Unknown or revoked prompt',
						'data'    => array( 'name' => $name ),
					),
				)
			);
		}

		$is_mounted = isset( $match['metadata']['mounted'] ) && true === $match['metadata']['mounted'];
		$summary    = isset( $match['description'] ) ? (string) $match['description'] : '';
		$page_slug  = isset( $match['metadata']['page_slug'] ) ? (string) $match['metadata']['page_slug'] : '';
		$entity     = isset( $match['metadata']['entity_type'] ) ? (string) $match['metadata']['entity_type'] : '';

		if ( $is_mounted ) {
			// Derive source slug from mounted prompt name: _mounted/{source}.{type}.{entity}.
			$parsed_source = '';
			$safe_name     = sanitize_text_field( (string) $name );
			if ( preg_match( '#^_mounted/([^.]+)\.#', $safe_name, $m ) ) {
				$parsed_source = $m[1];
			}

			/**
			 * Fires whenever a mounted prompt is read through a per-toolkit MCP server.
			 *
			 * @since 1.4.0
			 *
			 * @param string                             $consumer_slug Consumer server slug.
			 * @param string                             $source_slug   Source (mounted) server slug.
			 * @param string                             $prompt_name   Full prompt name.
			 * @param string                             $uri           Empty string (prompts have no URI).
			 * @param string                             $method        JSON-RPC method ('prompts/get').
			 * @param WP_MCP_AI_Toolkit_Server_Interface $server        Consumer server instance.
			 */
			do_action( 'wp_mcp_ai_toolkit_mcp_cross_mount_read', $server->get_slug(), $parsed_source, $name, '', 'prompts/get', $server );
		}

		$lines = array();
		if ( '' !== $summary ) {
			$lines[] = $summary;
		}
		if ( '' !== $page_slug ) {
			$lines[] = sprintf( 'Ingestion page: %s', $page_slug );
		}
		if ( '' !== $entity ) {
			$lines[] = sprintf( 'Entity type: %s', $entity );
		}
		if ( $is_mounted ) {
			$lines[] = 'Note: this prompt is mounted read-only from another toolkit.';
		}

		return rest_ensure_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'description' => $summary,
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => array(
								'type' => 'text',
								'text' => implode( "\n", $lines ),
							),
						),
					),
				),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Phase 3d — token management handlers
	// -----------------------------------------------------------------------

	/**
	 * GET /mcp/{slug}/token — list token metadata for a server.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_token_list( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
			return new WP_Error( 'token_service_unavailable', __( 'Token service unavailable.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}
		$slug = sanitize_key( $request['slug'] );
		if ( null === WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug ) ) {
			return new WP_Error( 'mcp_server_not_found', __( 'Toolkit MCP server not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'tokens' => WP_MCP_AI_Pro_Toolkit_Server_Token::list_tokens( $slug ),
			)
		);
	}

	/**
	 * POST /mcp/{slug}/token — generate a new bearer token for a server.
	 *
	 * Returns the raw token string in the response body (shown once only).
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_token_generate( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
			return new WP_Error( 'token_service_unavailable', __( 'Token service unavailable.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}
		$slug = sanitize_key( $request['slug'] );
		if ( null === WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug ) ) {
			return new WP_Error( 'mcp_server_not_found', __( 'Toolkit MCP server not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$label  = sanitize_text_field( (string) $request->get_param( 'label' ) );
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( $slug, $label );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 422 ) );
		}

		$response = rest_ensure_response( $result );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * DELETE /mcp/{slug}/token/{prefix} — revoke a bearer token.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_token_revoke( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
			return new WP_Error( 'token_service_unavailable', __( 'Token service unavailable.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}
		$slug   = sanitize_key( $request['slug'] );
		$prefix = sanitize_key( $request['prefix'] );
		if ( null === WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug ) ) {
			return new WP_Error( 'mcp_server_not_found', __( 'Toolkit MCP server not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$removed = WP_MCP_AI_Pro_Toolkit_Server_Token::revoke( $slug, $prefix );
		if ( ! $removed ) {
			return new WP_Error( 'token_not_found', __( 'Token not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'revoked' => true,
				'prefix'  => $prefix,
			)
		);
	}
}
