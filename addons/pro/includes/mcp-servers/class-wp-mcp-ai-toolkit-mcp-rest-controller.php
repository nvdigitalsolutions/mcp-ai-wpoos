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
 * Supported JSON-RPC methods (initial scope):
 *   - initialize
 *   - ping
 *   - tools/list
 *   - resources/list
 *   - prompts/list
 *
 * `tools/call`, `resources/read`, `prompts/get` are intentionally not yet
 * implemented; clients should fall back to the monolithic endpoint for
 * execution while we land Phase 1 pilot servers.
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

		switch ( $method ) {
			case 'initialize':
				$result = array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => array(
						'tools'     => (object) array(),
						'resources' => (object) array( 'subscribe' => false, 'listChanged' => false ),
						'prompts'   => (object) array( 'listChanged' => false ),
					),
					'serverInfo'      => array(
						'name'    => $server->get_name(),
						'version' => $server->get_version(),
						'slug'    => $server->get_slug(),
					),
				);
				return rest_ensure_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ) );

			case 'ping':
				return rest_ensure_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => (object) array() ) );

			case 'tools/list':
				$tools = method_exists( $server, 'get_tools' ) ? $server->get_tools() : array();
				return rest_ensure_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'tools' => $tools ) ) );

			case 'resources/list':
				$resources = method_exists( $server, 'get_resources' ) ? $server->get_resources() : array();
				return rest_ensure_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'resources' => $resources ) ) );

			case 'prompts/list':
				$prompts = method_exists( $server, 'get_prompts' ) ? $server->get_prompts() : array();
				return rest_ensure_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'prompts' => $prompts ) ) );

			default:
				return rest_ensure_response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'error'   => array(
							'code'    => -32601,
							'message' => 'Method not found: ' . $method,
							'data'    => array(
								'supported_methods' => array( 'initialize', 'ping', 'tools/list', 'resources/list', 'prompts/list' ),
								'fallback'          => 'For tool execution use the monolithic /mcp-ai/v1/mcp endpoint.',
							),
						),
					)
				);
		}
	}
}
