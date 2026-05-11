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
	 * JSON-RPC permission. Mirrors the existing monolithic MCP endpoint:
	 * authenticated users with `read` capability.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_jsonrpc() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication required for MCP JSON-RPC.', 'mcp-ai-wpoos-pro' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
		return true;
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
		}

		switch ( $method ) {
			case 'initialize':
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
}
