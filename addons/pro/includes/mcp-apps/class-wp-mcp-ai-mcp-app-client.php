<?php
/**
 * MCP App Client.
 *
 * Connects to remote MCP servers via Streamable HTTP transport,
 * discovers tools and UI resources per the MCP 2026-07-28 specification
 * and MCP Apps extension (SEP-1865, 2026-01-26).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @since   1.9.0 Updated for stateless MCP 2026-07-28.
 * @see     https://modelcontextprotocol.io/specification/2026-07-28
 * @see     https://modelcontextprotocol.io/extensions/apps/overview
 * @see     https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for connecting to remote MCP servers and discovering capabilities.
 *
 * Implements the Streamable HTTP transport for JSON-RPC 2.0 communication
 * with remote MCP servers. Supports tool discovery, UI resource fetching,
 * and tool execution proxying.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_MCP_App_Client {

	/**
	 * Maximum response body size in bytes (2 MB).
	 *
	 * @var int
	 */
	const MAX_RESPONSE_SIZE = 2097152;

	/**
	 * MCP protocol version for initialization.
	 *
	 * @var string
	 */
	const PROTOCOL_VERSION = '2026-07-28';

	/**
	 * Server endpoint URL.
	 *
	 * @var string
	 */
	protected $server_url;

	/**
	 * Authentication configuration.
	 *
	 * @var array
	 */
	protected $auth;

	/**
	 * OAuth client instance for automatic token management.
	 *
	 * @var WP_MCP_AI_MCP_App_OAuth_Client|null
	 */
	protected $oauth_client = null;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Whether to verify SSL certificates.
	 *
	 * @var bool
	 */
	protected $verify_ssl;

	/**
	 * JSON-RPC request counter.
	 *
	 * @var int
	 */
	protected $request_id = 0;

	/**
	 * Constructor.
	 *
	 * @since 1.8.0
	 * @param array $config {
	 *     Connection configuration.
	 *
	 *     @type string $server_url  Required. Remote MCP server endpoint URL.
	 *     @type string $auth_type   Authentication type: 'bearer', 'header', 'oauth', or 'none'. Default 'none'.
	 *     @type string $token       Bearer token, header value, or OAuth access token for authentication.
	 *     @type string $header_name Custom header name when auth_type is 'header'.
	 *     @type array  $oauth_data  OAuth token data (access_token, refresh_token, expires_in, issued_at) when auth_type is 'oauth'.
	 *     @type WP_MCP_AI_MCP_App_OAuth_Client $oauth_client Pre-configured OAuth client instance (optional, used for auto-refresh).
	 *     @type int    $timeout     Request timeout in seconds. Default 30.
	 *     @type bool   $verify_ssl  Whether to verify SSL. Default true.
	 * }
	 */
	public function __construct( array $config ) {
		$config = wp_parse_args(
			$config,
			array(
				'server_url'  => '',
				'auth_type'   => 'none',
				'token'       => '',
				'header_name' => '',
				'timeout'     => 30,
				'verify_ssl'  => true,
			)
		);

		$this->server_url = esc_url_raw( $config['server_url'] );
		$this->auth       = array(
			'type'        => sanitize_key( $config['auth_type'] ),
			'token'       => $config['token'],
			'header_name' => sanitize_text_field( $config['header_name'] ),
		);
		$this->timeout    = max( 1, min( 120, absint( $config['timeout'] ) ) );
		$this->verify_ssl = (bool) $config['verify_ssl'];

		// Attach OAuth client if provided.
		if ( isset( $config['oauth_client'] ) && $config['oauth_client'] instanceof WP_MCP_AI_MCP_App_OAuth_Client ) {
			$this->oauth_client = $config['oauth_client'];
			if ( ! empty( $config['oauth_data'] ) && is_array( $config['oauth_data'] ) ) {
				$this->oauth_client->set_token_data( $config['oauth_data'] );
			}
		}
	}

	/**
	 * Initialize the MCP session with the remote server (legacy, pre-2026-07-28).
	 *
	 * Deprecated in favor of discover() per MCP 2026-07-28 (SEP-2575).
	 * Kept for backward compatibility with 2025-era servers.
	 *
	 * @since     1.8.0
	 * @deprecated 1.9.0 Use discover() for 2026-07-28 servers.
	 * @return array|WP_Error Server capabilities on success, WP_Error on failure.
	 */
	public function initialize() {
		$params = array(
			'protocolVersion' => '2025-03-26',
			'capabilities'    => new stdClass(),
			'clientInfo'      => array(
				'name'    => 'NV oOS MCP App Client',
				'version' => defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.9.0',
			),
		);

		$result = $this->send_request( 'initialize', $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Send initialized notification (legacy).
		$this->send_notification( 'notifications/initialized' );

		return $result;
	}

	/**
	 * Discover server capabilities via server/discover RPC (2026-07-28).
	 *
	 * Sends the server/discover JSON-RPC request per MCP 2026-07-28 (SEP-2575)
	 * to probe server capabilities. Replaces the initialize/initialized handshake.
	 *
	 * @since 1.9.0
	 * @return array|WP_Error Server capabilities on success, WP_Error on failure.
	 */
	public function discover() {
		$params = $this->build_request_meta();

		$result = $this->send_request( 'server/discover', $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$server_info  = isset( $result['serverInfo'] ) ? $result['serverInfo'] : array();
		$capabilities = isset( $result['capabilities'] ) ? $result['capabilities'] : array();

		$has_tools     = ! empty( $capabilities['tools'] );
		$has_resources = ! empty( $capabilities['resources'] );

		return array(
			'success'       => true,
			'server_info'   => $server_info,
			'capabilities'  => $capabilities,
			'has_tools'     => $has_tools,
			'has_resources' => $has_resources,
		);
	}

	/**
	 * Build the per-request _meta envelope for MCP 2026-07-28.
	 *
	 * Per SEP-2575, every request must carry protocol version, client identity,
	 * and client capabilities in _meta.
	 *
	 * @since 1.9.0
	 * @return array _meta parameters to merge into request params.
	 */
	protected function build_request_meta() {
		return array(
			'_meta' => array(
				'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
				'io.modelcontextprotocol/clientInfo'      => array(
					'name'    => 'NV oOS MCP App Client',
					'version' => defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.9.0',
				),
				'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
			),
		);
	}

	/**
	 * Discover available tools from the remote MCP server.
	 *
	 * @since 1.8.0
	 * @return array|WP_Error Array of tool definitions on success, WP_Error on failure.
	 */
	public function list_tools() {
		$result = $this->send_request( 'tools/list', new stdClass() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['tools'] ) || ! is_array( $result['tools'] ) ) {
			return array();
		}

		return $result['tools'];
	}

	/**
	 * Execute a tool on the remote MCP server.
	 *
	 * @since 1.8.0
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Tool result on success, WP_Error on failure.
	 */
	public function call_tool( $tool_name, array $arguments = array() ) {
		$params = array(
			'name'      => sanitize_text_field( $tool_name ),
			'arguments' => ! empty( $arguments ) ? $arguments : new stdClass(),
		);

		return $this->send_request( 'tools/call', $params );
	}

	/**
	 * List available resources from the remote MCP server.
	 *
	 * @since 1.8.0
	 * @return array|WP_Error Array of resource definitions, WP_Error on failure.
	 */
	public function list_resources() {
		$result = $this->send_request( 'resources/list', new stdClass() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['resources'] ) || ! is_array( $result['resources'] ) ) {
			return array();
		}

		return $result['resources'];
	}

	/**
	 * Read a specific resource from the remote MCP server.
	 *
	 * @since 1.8.0
	 * @param string $uri Resource URI (e.g., ui://server/resource).
	 * @return array|WP_Error Resource content, WP_Error on failure.
	 */
	public function read_resource( $uri ) {
		$params = array(
			'uri' => sanitize_text_field( $uri ),
		);

		return $this->send_request( 'resources/read', $params );
	}

	/**
	 * Test connectivity to the remote MCP server.
	 *
	 * Performs a server/discover probe with initialize() fallback.
	 *
	 * @since 1.8.0
	 * @since 1.9.0 Uses discover() for 2026-07-28 servers with initialize() fallback.
	 * @return array|WP_Error Connection test result on success, WP_Error on failure.
	 */
	public function test_connection() {
		if ( empty( $this->server_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_missing_url',
				__( 'MCP server URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate URL scheme.
		$scheme = wp_parse_url( $this->server_url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_invalid_scheme',
				__( 'MCP server URL must use HTTP or HTTPS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Try discover() first; fall back to initialize() for 2025-era servers.
		$result = $this->discover();

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$rpc_code   = is_array( $error_data ) && isset( $error_data['rpc_code'] ) ? $error_data['rpc_code'] : 0;
			if ( -32601 === $rpc_code ) {
				// Method not found - server is pre-2026-07-28, fall back to initialize.
				return $this->initialize();
			}
			return $result;
		}

		return $result;
	}

	/**
	 * Send a JSON-RPC 2.0 request to the MCP server.
	 *
	 * Uses the Streamable HTTP transport as recommended by the MCP specification.
	 *
	 * @since 1.8.0
	 * @param string $method JSON-RPC method name.
	 * @param mixed  $params Method parameters.
	 * @return array|WP_Error Decoded result on success, WP_Error on failure.
	 */
	protected function send_request( $method, $params ) {
		++$this->request_id;

		// Inject _meta for every request except initialize and server/discover.
		if ( ! in_array( $method, array( 'initialize', 'server/discover' ), true ) ) {
			$meta   = $this->build_request_meta();
			$params = array_merge( $params, $meta );
		}

		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => $this->request_id,
			'method'  => $method,
			'params'  => $params,
		);

		$headers = $this->get_request_headers( $method, $params );

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$args = array(
			'method'    => 'POST',
			'headers'   => $headers,
			'body'      => wp_json_encode( $payload ),
			'timeout'   => $this->timeout,
			'sslverify' => $this->verify_ssl,
		);

		/**
		 * Filters the MCP App client request arguments before sending.
		 *
		 * @since 1.8.0
		 * @param array  $args       Request arguments.
		 * @param string $method     JSON-RPC method.
		 * @param string $server_url Server URL.
		 */
		$args = apply_filters( 'wp_mcp_ai_mcp_app_request_args', $args, $method, $this->server_url );

		$response = wp_remote_request( $this->server_url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_connection_failed',
				sprintf(
					/* translators: %s: Error message from remote request. */
					__( 'Failed to connect to MCP server: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Server URL. */
					__( 'MCP server returned HTTP %1$d from %2$s.', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$this->server_url
				),
				array( 'status' => $status_code )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_response_too_large',
				__( 'MCP server response exceeds maximum allowed size.', 'mcp-ai-wpoos-pro' )
			);
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_invalid_json',
				__( 'MCP server returned invalid JSON.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Handle JSON-RPC error response.
		if ( isset( $decoded['error'] ) ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unknown MCP server error.', 'mcp-ai-wpoos-pro' );
			$error_code    = isset( $decoded['error']['code'] ) ? (int) $decoded['error']['code'] : -32000;

			return new WP_Error(
				'wp_mcp_ai_mcp_app_rpc_error',
				$error_message,
				array( 'rpc_code' => $error_code )
			);
		}

		if ( ! isset( $decoded['result'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_missing_result',
				__( 'MCP server response missing result field.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $decoded['result'];
	}

	/**
	 * Send a JSON-RPC 2.0 notification (no response expected).
	 *
	 * @since 1.8.0
	 * @param string $method Notification method name.
	 * @param mixed  $params Notification parameters.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	protected function send_notification( $method, $params = null ) {
		$payload = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
		);

		if ( null !== $params ) {
			$payload['params'] = $params;
		}

		$headers = $this->get_request_headers( $method, $params ?? array() );

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$args = array(
			'method'    => 'POST',
			'headers'   => $headers,
			'body'      => wp_json_encode( $payload ),
			'timeout'   => $this->timeout,
			'sslverify' => $this->verify_ssl,
		);

		$response = wp_remote_request( $this->server_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Build request headers including authentication and routing.
	 *
	 * @since 1.8.0
	 * @since 1.9.0 Added $method and $params parameters for SEP-2243 routing headers.
	 *
	 * @param string $method JSON-RPC method name for Mcp-Method header.
	 * @param array  $params Request parameters for Mcp-Name extraction.
	 * @return array|WP_Error Headers array or WP_Error on invalid auth config.
	 */
	protected function get_request_headers( $method = '', $params = array() ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'NV-oOS-MCP-App-Client/' . ( defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.9.0' ),
		);

		// MCP 2026-07-28 routing headers (SEP-2243).
		$headers['MCP-Protocol-Version'] = self::PROTOCOL_VERSION;

		if ( ! empty( $method ) ) {
			$headers['Mcp-Method'] = $method;

			// Mcp-Name required for tools/call, resources/read, prompts/get.
			if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
				$headers['Mcp-Name'] = isset( $params['name'] ) ? $params['name'] : '';
			}
		}

		// Add authentication.
		switch ( $this->auth['type'] ) {
			case 'bearer':
				if ( empty( $this->auth['token'] ) ) {
					return new WP_Error(
						'wp_mcp_ai_mcp_app_missing_token',
						__( 'Bearer token is required for authentication.', 'mcp-ai-wpoos-pro' )
					);
				}
				$headers['Authorization'] = 'Bearer ' . $this->auth['token'];
				break;

			case 'oauth':
				// OAuth 2.0 bearer token — resolve via OAuth client if available.
				$token = $this->resolve_oauth_token();
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				if ( empty( $token ) ) {
					return new WP_Error(
						'wp_mcp_ai_mcp_app_missing_oauth_token',
						__( 'OAuth access token is required. Please complete the web login flow.', 'mcp-ai-wpoos-pro' )
					);
				}
				$headers['Authorization'] = 'Bearer ' . $token;
				break;

			case 'header':
				if ( empty( $this->auth['header_name'] ) || empty( $this->auth['token'] ) ) {
					return new WP_Error(
						'wp_mcp_ai_mcp_app_missing_header_auth',
						__( 'Header name and value are required for header authentication.', 'mcp-ai-wpoos-pro' )
					);
				}
				$headers[ sanitize_text_field( $this->auth['header_name'] ) ] = $this->auth['token'];
				break;

			case 'none':
				// No authentication needed.
				break;

			default:
				return new WP_Error(
					'wp_mcp_ai_mcp_app_invalid_auth_type',
					__( 'Invalid authentication type for MCP App.', 'mcp-ai-wpoos-pro' )
				);
		}

		return $headers;
	}

	/**
	 * Resolve the OAuth access token, refreshing if needed.
	 *
	 * When an oauth_client is attached, automatically refreshes expired tokens.
	 * Falls back to the static token from the auth config.
	 *
	 * @since 1.9.0
	 * @return string|WP_Error Access token or error.
	 */
	protected function resolve_oauth_token() {
		// If we have an OAuth client with auto-refresh capability, use it.
		if ( null !== $this->oauth_client ) {
			return $this->oauth_client->get_access_token();
		}

		// Fallback: use static token from config.
		return $this->auth['token'];
	}

	/**
	 * Handle an OAuth token refresh event.
	 *
	 * After a successful refresh, updates the stored auth token so subsequent
	 * requests use the new token immediately.
	 *
	 * @since 1.9.0
	 * @param array $new_token_data New token data from the OAuth client.
	 * @return void
	 */
	public function update_oauth_token( array $new_token_data ) {
		if ( ! empty( $new_token_data['access_token'] ) ) {
			$this->auth['token'] = $new_token_data['access_token'];
		}
		if ( null !== $this->oauth_client ) {
			$this->oauth_client->set_token_data( $new_token_data );
		}
	}

	/**
	 * Get the OAuth client instance if attached.
	 *
	 * @since 1.9.0
	 * @return WP_MCP_AI_MCP_App_OAuth_Client|null
	 */
	public function get_oauth_client() {
		return $this->oauth_client;
	}

	/**
	 * Get the server URL.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	public function get_server_url() {
		return $this->server_url;
	}
}
