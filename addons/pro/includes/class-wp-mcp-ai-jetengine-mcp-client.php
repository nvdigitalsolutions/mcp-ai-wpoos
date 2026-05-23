<?php
/**
 * JetEngine MCP Server Client
 *
 * JSON-RPC 2.0 client that communicates with JetEngine 3.8+ MCP Server
 * at /wp-json/jet-engine/v1/mcp/.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-RPC 2.0 client for JetEngine MCP Server.
 *
 * Provides methods to discover tools, call tools, list resources, and
 * manage prompts exposed by JetEngine 3.8+'s built-in MCP server.
 *
 * Uses internal REST dispatch (rest_do_request) for same-site requests
 * and falls back to wp_remote_post() for remote/multisite scenarios.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_JetEngine_MCP_Client {

	/**
	 * JetEngine MCP REST namespace.
	 */
	const REST_NAMESPACE = 'jet-engine/v1/mcp';

	/**
	 * Default cache TTL in seconds (5 minutes).
	 */
	const DEFAULT_CACHE_TTL = 300;

	/**
	 * Transient prefix for cached responses.
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_je_mcp_';

	/**
	 * JSON-RPC version.
	 */
	const JSONRPC_VERSION = '2.0';

	/**
	 * Auto-incrementing request ID.
	 *
	 * @var int
	 */
	private static $request_id = 0;

	/**
	 * Remote site URL for multisite/remote scenarios.
	 *
	 * @var string|null
	 */
	private $remote_url = null;

	/**
	 * Authentication credentials for remote requests.
	 *
	 * @var array|null
	 */
	private $auth = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $remote_url Optional remote site URL for multisite/remote scenarios.
	 * @param array|null  $auth       Optional authentication credentials (username, password).
	 */
	public function __construct( $remote_url = null, $auth = null ) {
		$this->remote_url = $remote_url ? trailingslashit( $remote_url ) : null;
		$this->auth       = $auth;
	}

	/**
	 * Send initialize request to MCP server.
	 *
	 * @param bool $use_cache Whether to use cached response.
	 * @return array|WP_Error Server capabilities or error.
	 */
	public function initialize( $use_cache = true ) {
		$cache_key = self::CACHE_PREFIX . 'init';

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->send_request(
			'initialize',
			array(
				'protocolVersion' => '2024-11-05',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'NV oOS MCP AI',
					'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '2.1.0',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ttl = $this->get_cache_ttl();
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * List available MCP tools.
	 *
	 * @param bool $use_cache Whether to use cached response.
	 * @return array|WP_Error Array of tool definitions or error.
	 */
	public function tools_list( $use_cache = true ) {
		$cache_key = self::CACHE_PREFIX . 'tools_list';

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->send_request( 'tools/list', new \stdClass() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ttl = $this->get_cache_ttl();
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * Call an MCP tool by name.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Tool result or error.
	 */
	public function tools_call( $name, $arguments = array() ) {
		if ( empty( $name ) ) {
			return new WP_Error(
				'mcp_invalid_tool',
				__( 'Tool name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->send_request(
			'tools/call',
			array(
				'name'      => sanitize_text_field( $name ),
				'arguments' => ! empty( $arguments ) ? $arguments : new \stdClass(),
			)
		);
	}

	/**
	 * List available MCP resources.
	 *
	 * @param bool $use_cache Whether to use cached response.
	 * @return array|WP_Error Array of resource definitions or error.
	 */
	public function resources_list( $use_cache = true ) {
		$cache_key = self::CACHE_PREFIX . 'resources_list';

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->send_request( 'resources/list', new \stdClass() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ttl = $this->get_cache_ttl();
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * List available MCP prompts.
	 *
	 * @param bool $use_cache Whether to use cached response.
	 * @return array|WP_Error Array of prompt definitions or error.
	 */
	public function prompts_list( $use_cache = true ) {
		$cache_key = self::CACHE_PREFIX . 'prompts_list';

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->send_request( 'prompts/list', new \stdClass() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ttl = $this->get_cache_ttl();
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * Get a specific MCP prompt by name.
	 *
	 * @param string $name      Prompt name.
	 * @param array  $arguments Prompt arguments.
	 * @return array|WP_Error Prompt content or error.
	 */
	public function prompts_get( $name, $arguments = array() ) {
		if ( empty( $name ) ) {
			return new WP_Error(
				'mcp_invalid_prompt',
				__( 'Prompt name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->send_request(
			'prompts/get',
			array(
				'name'      => sanitize_text_field( $name ),
				'arguments' => ! empty( $arguments ) ? $arguments : new \stdClass(),
			)
		);
	}

	/**
	 * Clear all cached MCP responses.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_PREFIX . 'init' );
		delete_transient( self::CACHE_PREFIX . 'tools_list' );
		delete_transient( self::CACHE_PREFIX . 'resources_list' );
		delete_transient( self::CACHE_PREFIX . 'prompts_list' );
	}

	/**
	 * Check if the MCP server is reachable.
	 *
	 * @return bool True if server responds to initialize.
	 */
	public function is_reachable() {
		$result = $this->initialize( false );
		return ! is_wp_error( $result );
	}

	/**
	 * Send a JSON-RPC 2.0 request to the MCP server.
	 *
	 * @param string       $method JSON-RPC method name.
	 * @param array|object $params Method parameters.
	 * @return array|WP_Error Decoded result or error.
	 */
	private function send_request( $method, $params ) {
		$payload = array(
			'jsonrpc' => self::JSONRPC_VERSION,
			'id'      => ++self::$request_id,
			'method'  => $method,
			'params'  => $params,
		);

		if ( null === $this->remote_url ) {
			return $this->dispatch_internal( $payload );
		}

		return $this->dispatch_remote( $payload );
	}

	/**
	 * Dispatch request internally via rest_do_request().
	 *
	 * @param array $payload JSON-RPC payload.
	 * @return array|WP_Error Decoded result or error.
	 */
	private function dispatch_internal( $payload ) {
		$request = new WP_REST_Request( 'POST', '/' . self::REST_NAMESPACE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return new WP_Error(
				'mcp_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'JetEngine MCP request failed: %s', 'mcp-ai-wpoos-pro' ),
					$error->get_error_message()
				)
			);
		}

		$data = $response->get_data();

		return $this->parse_jsonrpc_response( $data );
	}

	/**
	 * Dispatch request remotely via wp_remote_post().
	 *
	 * @param array $payload JSON-RPC payload.
	 * @return array|WP_Error Decoded result or error.
	 */
	private function dispatch_remote( $payload ) {
		$url = $this->remote_url . 'wp-json/' . self::REST_NAMESPACE;

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);

		// Add authentication if provided.
		if ( ! empty( $this->auth ) ) {
			if ( isset( $this->auth['username'], $this->auth['password'] ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth header per WordPress Application Passwords spec.
				$args['headers']['Authorization'] = 'Basic ' . base64_encode(
					$this->auth['username'] . ':' . $this->auth['password']
				);
			} elseif ( isset( $this->auth['token'] ) ) {
				$args['headers']['Authorization'] = 'Bearer ' . $this->auth['token'];
			}
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mcp_remote_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Remote JetEngine MCP request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mcp_remote_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'JetEngine MCP server returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}

		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error(
				'mcp_invalid_json',
				__( 'JetEngine MCP server returned invalid JSON.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->parse_jsonrpc_response( $data );
	}

	/**
	 * Parse a JSON-RPC 2.0 response.
	 *
	 * @param array|mixed $data Decoded response data.
	 * @return array|WP_Error Parsed result or error.
	 */
	private function parse_jsonrpc_response( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'mcp_invalid_response',
				__( 'JetEngine MCP returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check for JSON-RPC error.
		if ( isset( $data['error'] ) ) {
			$error_code    = isset( $data['error']['code'] ) ? (int) $data['error']['code'] : -1;
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown MCP error.', 'mcp-ai-wpoos-pro' );

			return new WP_Error(
				'mcp_jsonrpc_error',
				sprintf(
					/* translators: 1: error code, 2: error message */
					__( 'JetEngine MCP error (%1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$error_code,
					$error_message
				),
				array( 'code' => $error_code )
			);
		}

		// Return the result portion.
		if ( isset( $data['result'] ) ) {
			return $data['result'];
		}

		// If the response is valid but has no result key, return the data as-is.
		return $data;
	}

	/**
	 * Get the configured cache TTL.
	 *
	 * @return int Cache TTL in seconds.
	 */
	private function get_cache_ttl() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$ttl      = isset( $settings['jetengine_mcp_cache_ttl'] ) ? absint( $settings['jetengine_mcp_cache_ttl'] ) : self::DEFAULT_CACHE_TTL;

		return max( 60, $ttl );
	}
}
