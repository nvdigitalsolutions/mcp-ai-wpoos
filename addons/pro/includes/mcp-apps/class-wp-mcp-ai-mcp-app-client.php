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
	 * Session ID issued by sessionful (pre-2026-07-28) Streamable HTTP servers.
	 *
	 * Captured from the Mcp-Session-Id response header during the initialize
	 * handshake and echoed on every subsequent request to the same server.
	 *
	 * @var string
	 */
	protected $session_id = '';

	/**
	 * Protocol version negotiated with a sessionful server via initialize().
	 *
	 * Empty while speaking the stateless 2026-07-28 dialect. Once a legacy
	 * server reports its own protocolVersion (e.g. 2025-11-25), every
	 * subsequent request must advertise that version instead of the client's
	 * default — servers reject requests stamped with a version they do not
	 * implement.
	 *
	 * @var string
	 */
	protected $negotiated_protocol_version = '';

	/**
	 * Constructor.
	 *
	 * @since 1.8.0
	 * @param array $config {
	 *     Connection configuration.
	 *
	 *     @type string $server_url  Required. Remote MCP server endpoint URL.
	 *     @type string $auth_type   Authentication type: 'bearer', 'basic', 'header', 'oauth', or 'none'. Default 'none'.
	 *     @type string $token       Bearer token, Basic credential (base64 or raw user:pass), header value, or OAuth access token for authentication.
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

		// Remember the version the server negotiated so subsequent requests
		// advertise it instead of the client's 2026-07-28 default.
		if ( isset( $result['protocolVersion'] ) ) {
			$this->negotiated_protocol_version = sanitize_text_field( $result['protocolVersion'] );
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

		$start_time = microtime( true );

		// Try discover() first; fall back to initialize() for sessionful
		// (pre-2026-07-28) servers.
		$handshake_method = 'discover';
		$result           = $this->discover();

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$rpc_code   = is_array( $error_data ) && isset( $error_data['rpc_code'] ) ? $error_data['rpc_code'] : 0;

			// Fall back to the legacy initialize handshake when the server
			// does not implement server/discover (-32601) or rejects the
			// stateless request (e.g. -32600 "Missing Mcp-Session-Id header").
			$message = strtolower( $result->get_error_message() );
			if ( -32601 !== $rpc_code && -32600 !== $rpc_code && false === strpos( $message, 'session' ) ) {
				return $result;
			}

			$handshake_method = 'initialize';
			$result           = $this->initialize();

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Normalize the handshake result into a canonical payload. discover()
		// returns pre-extracted keys while initialize() returns the raw result.
		if ( 'initialize' === $handshake_method ) {
			$protocol     = isset( $result['protocolVersion'] ) ? sanitize_text_field( $result['protocolVersion'] ) : '2025-03-26';
			$server_info  = isset( $result['serverInfo'] ) ? $result['serverInfo'] : array();
			$capabilities = isset( $result['capabilities'] ) ? $result['capabilities'] : array();
		} else {
			$protocol     = self::PROTOCOL_VERSION;
			$server_info  = isset( $result['server_info'] ) ? $result['server_info'] : array();
			$capabilities = isset( $result['capabilities'] ) ? $result['capabilities'] : array();
		}

		// Enumerate tools so the result reflects whether the app can actually
		// be used, not just whether the handshake succeeded.
		$tool_count = null;
		$tool_error = '';
		$tools      = $this->list_tools();

		if ( is_wp_error( $tools ) ) {
			$tool_error = $tools->get_error_message();
		} else {
			$tool_count = count( $tools );
		}

		return array(
			'success'        => true,
			'handshake'      => $handshake_method,
			'protocol'       => $protocol,
			'server_info'    => $server_info,
			'capabilities'   => $capabilities,
			'has_tools'      => ! empty( $capabilities['tools'] ),
			'tool_count'     => $tool_count,
			'tool_error'     => $tool_error,
			'session_active' => ! empty( $this->session_id ),
			'latency_ms'     => (int) round( ( microtime( true ) - $start_time ) * 1000 ),
		);
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
		// The _meta envelope is a 2026-07-28 construct — skip it when a legacy
		// session negotiated an older protocol version.
		if ( ! in_array( $method, array( 'initialize', 'server/discover' ), true ) && '' === $this->negotiated_protocol_version ) {
			// list_tools() and friends pass new stdClass() as params; the
			// _meta envelope requires an array before merging.
			if ( ! is_array( $params ) ) {
				$params = array();
			}
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

		$response = $this->dispatch_request( $payload, $headers, $args );

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

		// Capture a session ID issued by sessionful (pre-2026-07-28) servers so
		// subsequent requests can echo it via the Mcp-Session-Id header.
		$session_id = $this->retrieve_header_case_insensitive( $response, 'Mcp-Session-Id' );
		if ( ! empty( $session_id ) ) {
			$this->session_id = sanitize_text_field( $session_id );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
			return new WP_Error(
				'wp_mcp_ai_mcp_app_response_too_large',
				__( 'MCP server response exceeds maximum allowed size.', 'mcp-ai-wpoos-pro' )
			);
		}

		$decoded = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			// Some servers return a JSON-RPC error with a non-2xx status (e.g.
			// HTTP 400 "Missing Mcp-Session-Id header"). Surface the RPC code
			// so callers can apply protocol fallbacks instead of receiving a
			// generic HTTP error.
			if ( is_array( $decoded ) && isset( $decoded['error']['code'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_mcp_app_rpc_error',
					isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unknown MCP server error.', 'mcp-ai-wpoos-pro' ),
					array(
						'rpc_code' => (int) $decoded['error']['code'],
						'status'   => $status_code,
					)
				);
			}

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

		$response = $this->dispatch_request( $payload, $headers, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Dispatch a JSON-RPC request, in-process for same-site REST routes.
	 *
	 * When the server URL points at this WordPress site and the derived REST
	 * route is registered, the request is executed through the internal REST
	 * dispatch instead of an outbound HTTP call. A self-request over the
	 * public hostname can deadlock a small PHP-FPM pool (the outer request
	 * holds a worker while the inner request waits for one) or stall on a
	 * missing hairpin NAT — in-process dispatch avoids both.
	 *
	 * Falls back to wp_remote_request() when the host is remote, the route is
	 * not a registered REST route, or the escape-hatch filter disables the
	 * bridge.
	 *
	 * @since 1.9.2
	 * @param array $payload JSON-RPC payload.
	 * @param array $headers Outbound request headers.
	 * @param array $args    wp_remote_request()-style arguments.
	 * @return array|WP_Error wp_remote_request()-style response array or WP_Error.
	 */
	protected function dispatch_request( $payload, $headers, $args ) {
		/**
		 * Filters whether the in-process same-origin bridge is disabled.
		 *
		 * @since 1.9.2
		 * @param bool   $disabled   Whether to disable the bridge (default false).
		 * @param string $server_url MCP server URL.
		 */
		$bridge_disabled = apply_filters( 'wp_mcp_ai_mcp_app_disable_inprocess_bridge', false, $this->server_url );

		if ( ! $bridge_disabled && $this->is_internal_same_origin() ) {
			$internal = $this->dispatch_internal_rest( $payload, $headers );

			// An array (synthesized response) or WP_Error means the route was
			// handled in-process; false means "not routable" — fall through.
			if ( is_array( $internal ) || is_wp_error( $internal ) ) {
				return $internal;
			}
		}

		return wp_remote_request( $this->server_url, $args );
	}

	/**
	 * Check whether the server URL points at this WordPress site.
	 *
	 * @since 1.9.2
	 * @return bool True when the hosts match (loopback).
	 */
	protected function is_internal_same_origin() {
		$remote_host = strtolower( (string) wp_parse_url( $this->server_url, PHP_URL_HOST ) );
		$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $remote_host && $remote_host === $site_host;
	}

	/**
	 * Derive the REST route from a same-site server URL.
	 *
	 * Handles both the pretty-permalink form (/wp-json/ns/route) and the
	 * query-parameter form (?rest_route=/ns/route).
	 *
	 * @since 1.9.2
	 * @return string REST route with a leading slash, empty when not derivable.
	 */
	protected function get_internal_rest_route() {
		$url = $this->server_url;

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$rest_prefix = rest_get_url_prefix();

		// Pretty-permalink form: https://host/wp-json/ns/route.
		$needle = '/' . $rest_prefix . '/';
		$pos    = strpos( $url, $needle );
		if ( false !== $pos ) {
			$route = substr( $url, $pos + strlen( $needle ) - 1 );
			$route = preg_replace( '/[?#].*$/', '', $route );

			return '/' . ltrim( $route, '/' );
		}

		// Query-parameter form: https://host/?rest_route=/ns/route.
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query );
			if ( isset( $query['rest_route'] ) ) {
				return '/' . ltrim( (string) $query['rest_route'], '/' );
			}
		}

		return '';
	}

	/**
	 * Dispatch a request through WordPress' internal REST server.
	 *
	 * Only registered REST routes are dispatched in-process; anything else
	 * returns false so the caller falls back to HTTP.
	 *
	 * @since 1.9.2
	 * @param array $payload JSON-RPC payload.
	 * @param array $headers Outbound request headers.
	 * @return array|WP_Error|false Synthesized wp_remote_request()-style response,
	 *                              WP_Error on dispatch failure, or false when
	 *                              the route is not registered.
	 */
	protected function dispatch_internal_rest( $payload, $headers ) {
		if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
			return false;
		}

		$route = $this->get_internal_rest_route();
		if ( '' === $route ) {
			return false;
		}

		$server  = rest_get_server();
		$routes  = $server->get_routes();
		$trimmed = '/' . trim( $route, '/' );
		if ( ! isset( $routes[ $trimmed ] ) && ! isset( $routes[ $trimmed . '/' ] ) ) {
			return false;
		}

		$request = new WP_REST_Request( 'POST', $trimmed );
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$request->set_header( $name, (string) $value );
			}
		}
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_do_request( $request );

		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			$body = is_string( $data ) ? $data : wp_json_encode( $data );

			return array(
				'headers'  => $response->get_headers(),
				'body'     => $body,
				'response' => array(
					'code'    => $response->get_status(),
					'message' => get_status_header_desc( $response->get_status() ),
				),
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return false;
	}

	/**
	 * Retrieve a response header by name, case-insensitively.
	 *
	 * The HTTP API lowercases header keys in real responses (Requests 2.x),
	 * while pre_http_request-shortcircuited responses may carry arbitrary
	 * casing. This helper normalizes both shapes so header lookups like
	 * Mcp-Session-Id never miss on casing.
	 *
	 * @since 1.9.1
	 * @param array|WP_Error $response wp_remote_request()-style response.
	 * @param string         $name     Header name to look up.
	 * @return string Header value, empty string when absent.
	 */
	protected function retrieve_header_case_insensitive( $response, $name ) {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) ) {
			if ( method_exists( $headers, 'getAll' ) ) {
				$headers = $headers->getAll();
			} else {
				return '';
			}
		}

		if ( ! is_array( $headers ) ) {
			return '';
		}

		foreach ( $headers as $key => $value ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return is_string( $value ) ? $value : '';
			}
		}

		return '';
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
			'Accept'       => 'application/json, text/event-stream',
			'User-Agent'   => 'NV-oOS-MCP-App-Client/' . ( defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.9.0' ),
		);

		// Echo the session ID issued by sessionful (pre-2026-07-28) servers.
		if ( ! empty( $this->session_id ) ) {
			$headers['Mcp-Session-Id'] = $this->session_id;
		}

		// MCP 2026-07-28 routing headers (SEP-2243). When a legacy session
		// negotiated an older protocol version, advertise that version so the
		// server accepts the request.
		$headers['MCP-Protocol-Version'] = '' !== $this->negotiated_protocol_version ? $this->negotiated_protocol_version : self::PROTOCOL_VERSION;

		if ( ! empty( $method ) ) {
			$headers['Mcp-Method'] = $method;

			// Mcp-Name required for tools/call, resources/read, prompts/get.
			if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
				$headers['Mcp-Name'] = isset( $params['name'] ) ? $params['name'] : '';
			}
		}

		// Add authentication.
		switch ( $this->auth['type'] ) {
			case 'basic':
				if ( empty( $this->auth['token'] ) ) {
					return new WP_Error(
						'wp_mcp_ai_mcp_app_missing_token',
						__( 'Basic auth credentials are required for authentication.', 'mcp-ai-wpoos-pro' )
					);
				}
				$credential = $this->auth['token'];
				// Accept either a raw "user:password" pair or a pre-encoded
				// base64 credential. A ':' cannot appear in base64 output.
				if ( false !== strpos( $credential, ':' ) ) {
					$credential = base64_encode( $credential ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic auth per RFC 7617.
				}
				$headers['Authorization'] = 'Basic ' . $credential;
				break;

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

	/**
	 * Get the captured session ID, if any.
	 *
	 * @since 1.9.1
	 * @return string
	 */
	public function get_session_id() {
		return $this->session_id;
	}
}
