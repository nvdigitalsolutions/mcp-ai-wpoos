<?php
/**
 * MCP JSON-RPC controller for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's MCP surface
 * (`includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` route
 * registration + `includes/class-wp-mcp-ai-rest-mcp-methods.php` trait
 * handlers) (behaviour-preserving; base copies retained permanently —
 * ecosystem port plan D-NOBASE). Route paths/methods, the JSON-RPC 2.0
 * envelope, error codes, CORS headers, the discovery payload, filters,
 * and method names keep their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - Auth is CG-AI's own (`edit_posts` on every surface). Bearer tokens,
 *   OAuth 2.0 scopes, mesh keys, and guest tokens stay with the base hub
 *   in monolith installs — `check_main_mcp_scope()` is a documented no-op
 *   stub and `apply_token_assistant_scope()` passes the requested
 *   assistant through unchanged.
 * - `tools/list` delegates to `ToolsController` (constructor composition)
 *   which resolves the registry per install mode; `McpController` adds the
 *   MCP annotations + `ttlMs`/`cacheScope` contract on top.
 * - `tools/call` executes through the per-install-mode registry seam
 *   (base registry monolith / nvoos-core registry standalone) with
 *   capability checks, assistant scoping when an `assistant_id` is
 *   supplied, the base's tool rate limiter, the before/after execution
 *   hooks, and MCP content shaping — Wave D8 Cluster 0
 *   (docs/project/plans/d8-tool-execution-port-plan.md). Documented
 *   deviations: no default-assistant resolution (an assistant is
 *   optional here; the base requires one), no async orchestrator (tools
 *   execute synchronously — the base's agentic-loop semantics), and the
 *   async polling helpers land with a standalone queue (E2).
 * - SSE transports are deferred: `GET /mcp` always returns discovery
 *   JSON (the base's `?stream=true` / Accept-header negotiation and the
 *   legacy HTTP+SSE session store/handler are not ported), `GET /sse`
 *   returns the discovery payload instead of an event stream, and the
 *   discovery advertises `capabilities.sse.enabled = false`. Tracked as
 *   an SSE-session gap.
 * - `resolve_assistant_id()` returns the raw parameter (no site-default
 *   assistant resolution — CG-AI has no default-assistant setting yet).
 * - `registerRoutes()` is called standalone-only by `Plugin.php` — the
 *   base plugin owns the same routes in monolith installs.
 *
 * @package NvoosContentGraphAi\Rest
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Rest;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the MCP JSON-RPC protocol endpoints.
 *
 * @since 1.1.0
 */
class McpController {

	/**
	 * REST namespace (byte-identical to the base plugin).
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Assistant post type slug (byte-identical to the base plugin).
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Default tool rate-limit window in seconds (byte-identical fallback
	 * to the base plugin; the `tool_rate_limit_window` setting overrides).
	 */
	const TOOL_RATE_LIMIT_WINDOW = 60;

	/**
	 * Default maximum tool executions per window (byte-identical fallback
	 * to the base plugin; the `tool_rate_limit_max` setting overrides;
	 * 0 disables the limiter).
	 */
	const TOOL_RATE_LIMIT_MAX = 60;

	/**
	 * Tools controller used for the tools/list delegation (composition).
	 *
	 * @var ToolsController
	 */
	private $tools_controller;

	/**
	 * Constructor.
	 *
	 * @param ToolsController|null $tools_controller Tools listing controller
	 *                                              (injectable for tests).
	 */
	public function __construct( ?ToolsController $tools_controller = null ) {
		$this->tools_controller = $tools_controller ?: new ToolsController();
	}

	/**
	 * Register the MCP protocol routes.
	 *
	 * Route paths, methods, and permission wiring are byte-identical to the
	 * base plugin's MCP controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// /mcp — JSON-RPC 2.0 protocol endpoint with discovery on GET.
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp',
			array(
				// POST — JSON-RPC 2.0 requests.
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_mcp' ),
					'callback'            => array( $this, 'handle_mcp_request' ),
					'args'                => array(
						'jsonrpc'    => array(
							'description'       => __( 'JSON-RPC version. Must be "2.0".', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							// Deliberately not `required`: JSON-RPC 2.0 mandates
							// that a malformed request be answered with a
							// -32600 error inside a JSON-RPC envelope (mirrors
							// the base plugin's rationale).
							'enum'              => array( '2.0' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'id'         => array(
							'description' => __( 'Request identifier. Omit for notifications.', 'nvoos-content-graph-ai' ),
							'oneOf'       => array(
								array( 'type' => 'string' ),
								array( 'type' => 'integer' ),
							),
							'required'    => false,
						),
						'method'     => array(
							'description'       => __( 'MCP method name to invoke.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							// Not `required` for the same reason as `jsonrpc`.
							'validate_callback' => array( $this, 'validate_mcp_method' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'params'     => array(
							'description' => __( 'Method parameters object.', 'nvoos-content-graph-ai' ),
							'type'        => 'object',
							'required'    => false,
							'default'     => array(),
							// The base plugin runs a dedicated MCP params
							// validator here; CG-AI has no validator class
							// yet, so per-method validation happens inside the
							// JSON-RPC handlers (documented deviation).
						),
						'session_id' => array(
							'description'       => __( 'Legacy SSE session ID from the GET handshake.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_sse_session_id' ),
						),
					),
				),
				// GET — discovery JSON (MCP 2024-11-05 Streamable HTTP).
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_mcp_get_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'Optional assistant ID for SSE stream.', 'nvoos-content-graph-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
				// OPTIONS — CORS preflight. `__return_true` is correct here:
				// preflight must be answered without authentication; the
				// handler only emits CORS headers.
				array(
					'methods'             => 'OPTIONS',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'handle_mcp_options' ),
				),
			),
			true
		);

		// /no-sse — legacy endpoint for clients that don't support SSE.
		register_rest_route(
			self::REST_NAMESPACE,
			'/no-sse',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_assistant_list' ),
					'callback'            => array( $this, 'handle_no_sse_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant for directory listing.', 'nvoos-content-graph-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);

		// /sse — dedicated SSE endpoint. SSE sessions are deferred in this
		// wave; the handler returns the discovery payload instead of an
		// event stream (documented gap).
		register_rest_route(
			self::REST_NAMESPACE,
			'/sse',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_assistant_list' ),
					'callback'            => array( $this, 'handle_sse_handshake' ),
				),
			),
			true
		);
	}

	/**
	 * Permission check for the discovery GET surface.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check( \WP_REST_Request $request ) {
		unset( $request );
		return $this->check_edit_posts();
	}

	/**
	 * Permission check for the JSON-RPC POST surface.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_mcp( \WP_REST_Request $request ) {
		unset( $request );
		return $this->check_edit_posts();
	}

	/**
	 * Permission check for the assistant listing surface (no-sse/sse).
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_assistant_list( \WP_REST_Request $request ) {
		unset( $request );
		return $this->check_edit_posts();
	}

	/**
	 * Shared capability gate — CG-AI has no token/guest auth yet, so every
	 * MCP surface requires `edit_posts` (documented deviation from the
	 * base hub's layered auth).
	 *
	 * @return bool|WP_Error
	 */
	protected function check_edit_posts() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate the MCP method name for the /mcp endpoint.
	 *
	 * Performs structural validation only; unknown-but-well-formed method
	 * names pass through to the handler, which answers with a spec-
	 * compliant JSON-RPC -32601 "method not found" error.
	 *
	 * @param string          $value   Raw method name from the request.
	 * @param WP_REST_Request $request Current REST request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error True when the method name is structurally valid.
	 */
	public function validate_mcp_method( $value, $request, $param ) {
		unset( $request, $param ); // Context only; not used for structural checks.

		if ( ! is_string( $value ) || '' === $value ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_method',
				__( 'The MCP method name must be a non-empty string.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $value ) > 200 ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_method',
				__( 'The MCP method name exceeds the maximum length of 200 characters.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^[a-z0-9_\-\/.]+$/i', $value ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_method',
				__( 'The MCP method name contains characters that are not allowed.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Sanitize a legacy SSE session ID query/body parameter.
	 *
	 * Kept so the POST route args stay byte-identical to the base; the SSE
	 * session message path itself is deferred.
	 *
	 * @param mixed $value Raw session ID value.
	 * @return string Empty string when the value is not a valid session ID.
	 */
	public function sanitize_sse_session_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[a-f0-9-]{8,64}$/i', $value ) ) {
			return '';
		}

		return strtolower( $value );
	}

	/**
	 * Handle MCP protocol requests using JSON-RPC 2.0 format.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_mcp_request( \WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Empty request body' );
		}

		$message = json_decode( $body, true );

		if ( null === $message ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Invalid JSON' );
		}

		// JSON-RPC batching: if the decoded body is a sequential array,
		// process each message.
		if ( is_array( $message ) && isset( $message[0] ) ) {
			return $this->handle_mcp_batch( $message, $request );
		}

		if ( ! is_array( $message ) ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Invalid JSON' );
		}

		return $this->process_single_mcp_message( $message, $request );
	}

	/**
	 * Handle a JSON-RPC batch request per MCP 2026-07-28 specification.
	 *
	 * @param array           $messages Array of JSON-RPC message arrays.
	 * @param WP_REST_Request $request  REST request instance.
	 * @return WP_REST_Response
	 */
	protected function handle_mcp_batch( array $messages, \WP_REST_Request $request ) {
		if ( empty( $messages ) ) {
			return $this->mcp_error_response( null, -32600, 'Invalid Request: Empty batch array' );
		}

		/**
		 * Filter the maximum number of messages allowed in a single
		 * JSON-RPC batch. Default 20 (byte-identical to the base).
		 *
		 * @param int $max_batch_size Maximum batch size.
		 */
		$max_batch_size = apply_filters( 'wp_mcp_ai_max_batch_size', 20 );

		if ( count( $messages ) > $max_batch_size ) {
			return $this->mcp_error_response(
				null,
				-32600,
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Invalid Request: Batch too large. Maximum %d messages allowed.', 'nvoos-content-graph-ai' ),
					$max_batch_size
				)
			);
		}

		$results = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				$results[] = array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Invalid Request: Each batch element must be a JSON object',
					),
				);
				continue;
			}

			$resp = $this->process_single_mcp_message( $msg, $request );

			// Notifications return 202 with null data — skip them in batch results.
			if ( $resp instanceof \WP_REST_Response && 202 === $resp->get_status() ) {
				continue;
			}

			if ( $resp instanceof \WP_REST_Response ) {
				$results[] = $resp->get_data();
			}
		}

		// If all messages were notifications, return 202 with no body.
		if ( empty( $results ) ) {
			$response = new \WP_REST_Response( null, 202 );
			$response->header( 'Content-Type', 'application/json; charset=utf-8' );
			$this->add_cors_headers( $response );
			return $response;
		}

		$response = new \WP_REST_Response( $results, 200 );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		$this->add_cors_headers( $response );
		return $response;
	}

	/**
	 * Process a single JSON-RPC message.
	 *
	 * @param array           $message JSON-RPC message.
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	protected function process_single_mcp_message( array $message, \WP_REST_Request $request ) {
		// Validate JSON-RPC 2.0 structure.
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return $this->mcp_error_response(
				isset( $message['id'] ) ? $message['id'] : null,
				-32600,
				'Invalid Request: jsonrpc field must be "2.0"'
			);
		}

		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return $this->mcp_error_response(
				isset( $message['id'] ) ? $message['id'] : null,
				-32600,
				'Invalid Request: method field is required and must be a string'
			);
		}

		$method = $message['method'];
		$params = isset( $message['params'] ) ? $message['params'] : array();
		$id     = isset( $message['id'] ) ? $message['id'] : null;

		// Extract request-level priority from X-Priority header or _meta
		// param. Supported values: realtime, high, normal, low, batch.
		$header_priority = $request->get_header( 'X-Priority' );
		if ( null !== $header_priority ) {
			$priority = self::normalize_priority( $header_priority );
		} elseif ( isset( $params['_meta']['priority'] ) ) {
			$priority = self::normalize_priority( $params['_meta']['priority'] );
		} else {
			$priority = null;
		}
		if ( null !== $priority ) {
			$request->set_param( '_priority', $priority );
		}

		// Validate Mcp-Method header against body method.
		$header_method = $request->get_header( 'Mcp-Method' );
		if ( ! empty( $header_method ) && $header_method !== $method ) {
			return $this->mcp_error_response(
				$id,
				-32020,
				'Header mismatch: Mcp-Method does not match body method'
			);
		}

		// Route to appropriate handler based on method.
		$result = $this->route_mcp_method( $method, $params, $request );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			return $this->mcp_error_response(
				$id,
				'wp_mcp_ai_method_not_found' === $error_code ? -32601 : -32603,
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// If this is a notification (no id), return 202 Accepted with no body.
		if ( null === $id ) {
			$response = new \WP_REST_Response( null, 202 );
			$response->header( 'Content-Type', 'application/json; charset=utf-8' );
			$this->add_cors_headers( $response );
			return $response;
		}

		// Stamp _meta with server identity per MCP 2026-07-28.
		if ( is_array( $result ) ) {
			$result['_meta'] = array(
				'io.modelcontextprotocol/serverInfo' => array(
					'name'    => 'NV oOS',
					'version' => $this->get_version(),
				),
			);
		}

		// Return successful JSON-RPC response.
		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );

		// Emit Mcp-Method header on every response for gateway routing.
		$response->header( 'Mcp-Method', $method );

		// Emit Mcp-Name header for tools/call, resources/read, prompts/get.
		if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
			$name = isset( $params['name'] ) ? $params['name'] : '';
			$response->header( 'Mcp-Name', $name );
		}

		$this->add_cors_headers( $response );
		return $response;
	}

	/**
	 * Route MCP method to appropriate handler.
	 *
	 * @param string          $method  MCP method name.
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return mixed|WP_Error Result or error.
	 */
	protected function route_mcp_method( $method, $params, \WP_REST_Request $request ) {
		// OAuth scope enforcement — CG-AI has no authenticator yet; this
		// seam is a documented no-op until OAuth lands (see
		// check_main_mcp_scope()).
		$scope_error = $this->check_main_mcp_scope( $method );
		if ( null !== $scope_error ) {
			return $scope_error;
		}

		switch ( $method ) {
			case 'initialize':
				// Legacy shim: route 2024/2025-era initialize to server/discover.
				return $this->mcp_server_discover( $params, $request );

			case 'server/discover':
				return $this->mcp_server_discover( $params, $request );

			case 'ping':
				return $this->mcp_ping();

			case 'tools/list':
				return $this->mcp_tools_list( $params, $request );

			case 'tools/call':
				return $this->mcp_tools_call( $params, $request );

			case 'resources/list':
				return $this->mcp_resources_list( $params, $request );

			case 'resources/read':
				return $this->mcp_resources_read( $params, $request );

			case 'prompts/list':
				return $this->mcp_prompts_list( $params, $request );

			case 'prompts/get':
				return $this->mcp_prompts_get( $params, $request );

			case 'completion/complete':
				return $this->mcp_completion_complete( $params, $request );

			case 'logging/setLevel':
				return $this->mcp_logging_set_level( $params );

			case 'notifications/cancelled':
				return $this->mcp_notifications_cancelled( $params );

			case 'notifications/initialized':
				// No-op: retired in MCP 2026-07-28 (SEP-2575).
				// Return empty result for legacy client compatibility.
				return new \stdClass();

			default:
				return new \WP_Error(
					'wp_mcp_ai_method_not_found',
					sprintf(
						/* translators: %s: method name */
						__( 'MCP method not found: %s', 'nvoos-content-graph-ai' ),
						$method
					),
					array(
						'status'  => 404,
						'actions' => array(
							'check_method' => __( 'Verify the method name is spelled correctly and supported by this server.', 'nvoos-content-graph-ai' ),
							'list_methods' => __( 'Supported methods: initialize, ping, tools/list, tools/call, resources/list, resources/read, prompts/list, prompts/get, completion/complete, logging/setLevel, notifications/cancelled, notifications/initialized', 'nvoos-content-graph-ai' ),
						),
					)
				);
		}
	}

	/**
	 * Check OAuth scope for methods on the main MCP endpoint.
	 *
	 * Documented no-op: CG-AI has no OAuth authenticator yet, so no scope
	 * is enforced. Kept as a seam so the base's read/write scope mapping
	 * can slot in when OAuth lands.
	 *
	 * @param string $method JSON-RPC method name.
	 * @return WP_Error|null Always null in this wave.
	 */
	protected function check_main_mcp_scope( $method ) {
		unset( $method ); // Reserved for the future OAuth scope mapping.
		return null;
	}

	/**
	 * Supported MCP protocol versions in descending order (newest first).
	 *
	 * @return array Supported versions, newest first.
	 */
	protected function get_supported_protocol_versions() {
		return array(
			'2026-07-28',
			'2025-06-18',
			'2025-03-26',
			'2024-11-05',
		);
	}

	/**
	 * Handle MCP server/discover RPC (2026-07-28).
	 *
	 * @param array           $params  Discovery parameters. Accepts optional 'assistant_id'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array Discover result payload.
	 */
	protected function mcp_server_discover( $params, \WP_REST_Request $request ) {
		// Resolve assistant identity from params (no default-assistant
		// resolution and no token scoping in CG-AI yet).
		$assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( ! is_wp_error( $scoped_id ) ) {
			$assistant_id = $scoped_id;
		}

		// Build instructions: assistant-scoped when available, generic site-level otherwise.
		if ( $assistant_id ) {
			$assistant_config = $this->get_assistant_configuration( $assistant_id );
			$instructions     = $this->build_assistant_instructions( $assistant_config, $assistant_id );
		} else {
			$assistant_config = array();
			$site_name = get_bloginfo( 'name' );
			$site_desc = get_bloginfo( 'description' );

			if ( ! empty( $site_desc ) ) {
				$instructions = sprintf(
					/* translators: 1: site name, 2: site description */
					__( 'This is a WordPress site (%1$s). %2$s. You can use the available tools to interact with WordPress content, users, and functionality.', 'nvoos-content-graph-ai' ),
					$site_name,
					$site_desc
				);
			} else {
				$instructions = sprintf(
					/* translators: %s: site name */
					__( 'This is a WordPress site (%s). You can use the available tools to interact with WordPress content, users, and functionality.', 'nvoos-content-graph-ai' ),
					$site_name
				);
			}
		}

		$negotiated_version = $this->negotiate_protocol_version( $params );

		$response = array(
			'protocolVersion' => $negotiated_version,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => true ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => true,
				),
				'prompts'   => array( 'listChanged' => true ),
			),
			'serverInfo'      => array(
				'name'    => $assistant_id ? get_the_title( $assistant_id ) : 'NV oOS',
				'version' => $this->get_version(),
			),
			'instructions'    => $instructions,
		);

		// Include model preferences when the assistant has them configured.
		if ( $assistant_id && ! empty( $assistant_config['model'] ) ) {
			$model_prefs = array();
			if ( ! empty( $assistant_config['model'] ) ) {
				$model_prefs['model'] = $assistant_config['model'];
			}
			if ( isset( $assistant_config['temperature'] ) && null !== $assistant_config['temperature'] && '' !== $assistant_config['temperature'] ) {
				$model_prefs['temperature'] = $assistant_config['temperature'];
			}
			if ( ! empty( $model_prefs ) ) {
				$response['modelPreferences'] = $model_prefs;
			}
		}

		/**
		 * Filter the instructions returned in the MCP server/discover response.
		 *
		 * @param string $instructions     The assembled instructions string.
		 * @param int    $assistant_id     Resolved assistant post ID (0 when generic).
		 * @param array  $assistant_config Full assistant configuration (empty when generic).
		 */
		$response['instructions'] = apply_filters(
			'wp_mcp_ai_mcp_discover_instructions',
			$response['instructions'],
			$assistant_id,
			$assistant_id ? $assistant_config : array()
		);

		/**
		 * Filter to optionally include tools in the discover response.
		 *
		 * @param bool            $include_tools Whether to include tools in discover response.
		 * @param array           $params        Discover method parameters.
		 * @param WP_REST_Request $request       REST request instance.
		 */
		$include_tools = apply_filters( 'wp_mcp_ai_discover_include_tools', true, $params, $request );

		if ( $include_tools ) {
			$tools_result = $this->mcp_tools_list( $params, $request );

			if ( ! is_wp_error( $tools_result ) && isset( $tools_result['tools'] ) ) {
				$response['tools'] = $tools_result['tools'];
			}
		}

		// OAuth 2.0 discovery (_meta) is not advertised — CG-AI has no
		// OAuth server yet (documented deviation).

		return $response;
	}

	/**
	 * Negotiate the MCP protocol version with the client.
	 *
	 * @param array $params Client's initialize/discover params.
	 * @return string Negotiated protocol version.
	 */
	protected function negotiate_protocol_version( $params ) {
		// Collect all versions the client claims to support.
		$client_versions = array();

		if ( isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ) {
			$client_versions[] = $params['protocolVersion'];
		}

		if ( isset( $params['supportedProtocolVersions'] ) && is_array( $params['supportedProtocolVersions'] ) ) {
			foreach ( $params['supportedProtocolVersions'] as $v ) {
				if ( is_string( $v ) ) {
					$client_versions[] = $v;
				}
			}
		}

		$client_versions = array_unique( $client_versions );

		if ( empty( $client_versions ) ) {
			// No version info from client — default to 2024-11-05 for max compatibility.
			return '2024-11-05';
		}

		// Pick the highest version both support (server list is newest-first).
		foreach ( $this->get_supported_protocol_versions() as $server_version ) {
			if ( in_array( $server_version, $client_versions, true ) ) {
				return $server_version;
			}
		}

		// No overlap — fall back to oldest widely-supported version.
		return '2024-11-05';
	}

	/**
	 * Build complete MCP instructions from assistant configuration.
	 *
	 * @param array $assistant_config Full assistant configuration.
	 * @param int   $assistant_id     Assistant post ID for reading additional meta.
	 * @return string Complete MCP system prompt for the discovery handshake.
	 */
	protected function build_assistant_instructions( array $assistant_config, $assistant_id ) {
		unset( $assistant_id ); // Reserved for future use (per-assistant instruction customisation).
		$instructions = '';

		// 1. System prompt — the canonical personality definition.
		if ( ! empty( $assistant_config['system_prompt'] ) ) {
			$instructions = $assistant_config['system_prompt'];
		}

		// 2. Model and configuration notes.
		$config_notes = array();
		if ( ! empty( $assistant_config['model'] ) ) {
			$config_notes[] = sprintf(
				/* translators: %s: model identifier */
				__( 'Model: %s', 'nvoos-content-graph-ai' ),
				$assistant_config['model']
			);
		}
		if ( isset( $assistant_config['temperature'] ) && null !== $assistant_config['temperature'] && '' !== $assistant_config['temperature'] ) {
			$config_notes[] = sprintf(
				/* translators: %s: temperature value */
				__( 'Temperature: %s', 'nvoos-content-graph-ai' ),
				$assistant_config['temperature']
			);
		}
		if ( ! empty( $config_notes ) ) {
			$instructions .= "\n\n---\n\n## " . __( 'Configuration', 'nvoos-content-graph-ai' ) . "\n\n";
			$instructions .= implode( "\n", $config_notes );
		}

		// 3. Knowledge base references.
		$kb_notes = array();
		if ( ! empty( $assistant_config['vector_store_id'] ) ) {
			$kb_notes[] = sprintf(
				/* translators: %s: vector store identifier */
				__( 'Vector store: %s', 'nvoos-content-graph-ai' ),
				$assistant_config['vector_store_id']
			);
		}
		if ( ! empty( $assistant_config['preferred_datasets'] ) && is_array( $assistant_config['preferred_datasets'] ) ) {
			$kb_notes[] = sprintf(
				/* translators: %s: comma-separated dataset names */
				__( 'Preferred datasets: %s', 'nvoos-content-graph-ai' ),
				implode( ', ', $assistant_config['preferred_datasets'] )
			);
		}
		if ( ! empty( $kb_notes ) ) {
			$instructions .= "\n\n---\n\n## " . __( 'Knowledge Base', 'nvoos-content-graph-ai' ) . "\n\n";
			$instructions .= implode( "\n", $kb_notes );
		}

		return $instructions;
	}

	/**
	 * Handle MCP tools/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_tools_list( $params, \WP_REST_Request $request ) {
		$assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		// Delegate the list build to ToolsController (composition): it
		// resolves the registry per install mode and applies assistant
		// scoping, access validation, and the listing cache.
		if ( $assistant_id ) {
			$request->set_param( 'assistant_id', $assistant_id );
		}

		$response = $this->tools_controller->handle_tools_list( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data  = $response->get_data();
		$tools = isset( $data['tools'] ) && is_array( $data['tools'] ) ? $data['tools'] : array();

		// Add MCP annotations from capability flags (MCP 2024-11-05).
		foreach ( $tools as $index => $entry ) {
			if ( ! isset( $entry['name'] ) ) {
				continue;
			}

			$tool        = $this->get_registry_tool( $entry['name'] );
			$annotations = $tool ? $this->build_tool_annotations( $tool ) : array();

			if ( ! empty( $annotations ) ) {
				$tools[ $index ]['annotations'] = $annotations;
			}
		}

		/**
		 * Filter the tools exposed to the current MCP client.
		 *
		 * @param array           $mcp_tools MCP-format tool entries.
		 * @param WP_REST_Request $request   Current REST request.
		 */
		$mcp_tools = apply_filters( 'wp_mcp_ai_mcp_tools_list', $tools, $request );

		/**
		 * Filter the cache TTL (in milliseconds) for the tools/list response.
		 * Default 0 means no caching.
		 *
		 * @param int $ttl_ms Cache TTL in milliseconds. Default 0.
		 */
		$ttl_ms = apply_filters( 'wp_mcp_ai_tools_list_cache_ttl_ms', 0 );

		return array(
			'tools'      => $mcp_tools,
			'ttlMs'      => $ttl_ms,
			'cacheScope' => 'private',
		);
	}

	/**
	 * Handle MCP tools/call request.
	 *
	 * Wave D8 Cluster 0: the execution path is ported. Parameter
	 * validation, per-install-mode registry resolution, assistant
	 * scoping (when an `assistant_id` is supplied), the tool rate
	 * limiter, the before/after execution hooks, and MCP content
	 * shaping mirror the base plugin's chain in
	 * `WP_MCP_AI_REST_MCP_Methods::mcp_tools_call()` /
	 * `WP_MCP_AI_REST::handle_tool_request()`.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_tools_call( $params, \WP_REST_Request $request ) {
		if ( ! isset( $params['name'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: name. MCP tools/call requires a tool name to execute.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_tool_name' => __( 'Include the "name" parameter in your tools/call request params with the slug of the tool you want to execute.', 'nvoos-content-graph-ai' ),
						'list_available'    => __( 'Call the tools/list method first to see available tools and their names.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$tool_name = sanitize_text_field( $params['name'] );

		// Validate arguments is an object/array if provided.
		if ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'The "arguments" parameter must be an object/array.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'fix_arguments_type' => __( 'Ensure the "arguments" field contains a JSON object with key-value pairs for the tool parameters.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

		// Resolve and validate the assistant when one is supplied. Unlike
		// the base there is no default-assistant resolution yet, so an
		// assistant is optional here (documented deviation).
		$assistant_id     = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
		$assistant_config = array();

		if ( $assistant_id ) {
			$scoped_id = $this->apply_token_assistant_scope( $this->resolve_assistant_id( $assistant_id ) );
			if ( is_wp_error( $scoped_id ) ) {
				return $scoped_id;
			}

			$assistant_post = $this->validate_assistant_access( $scoped_id );
			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}

			$assistant_config = $this->get_assistant_configuration( $scoped_id );
		}

		$candidates = $this->generate_tool_slug_candidates( $tool_name );

		if ( $assistant_id ) {
			$allowed_tools = isset( $assistant_config['tools'] ) && is_array( $assistant_config['tools'] )
				? $assistant_config['tools']
				: array();

			$tool_slug = $this->resolve_tool_slug_from_candidates( $candidates, $allowed_tools );

			// Byte-identical guard: the requested tool must be on the
			// assistant's allow-list (the base auto-injects its utility
			// tools into the config before this check).
			if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
				return new \WP_Error(
					'wp_mcp_ai_tool_forbidden',
					__( 'This assistant is not allowed to execute the requested tool.', 'nvoos-content-graph-ai' ),
					array( 'status' => 403 )
				);
			}
		} else {
			$tool_slug = isset( $candidates[0] ) ? $candidates[0] : '';
		}

		if ( '' === $tool_slug || ! $this->registry_has_tool( $tool_slug ) ) {
			return new \WP_Error(
				'wp_mcp_ai_tool_missing',
				__( 'The requested tool is not registered.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();

		// Enforce per-user, per-tool rate limiting (byte-identical
		// transient keys, filters, error code and envelope).
		$rate_check = $this->check_tool_rate_limit( $user_id );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$context = array(
			'user_id'      => $user_id,
			'assistant_id' => $assistant_id,
			'request'      => $request,
		);

		if ( $assistant_id ) {
			$context['assistant_config'] = $assistant_config;
		}

		/**
		 * Fires immediately before executing a registered tool.
		 *
		 * @param string $tool_slug Tool identifier.
		 * @param array  $arguments Arguments passed in the request.
		 * @param array  $context   Execution context including user_id and assistant_id.
		 */
		try {
			do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context );
		} catch ( \WP_MCP_AI_Destructive_Confirmation_Required $wp_mcp_ai_gate_exception ) {
			// Destructive-ops gate (base parity): surface the confirmation
			// request as a WP_Error envelope (HTTP 428).
			return $wp_mcp_ai_gate_exception->to_wp_error();
		} catch ( \WP_MCP_AI_Concurrency_Limit_Reached $wp_mcp_ai_concurrency_exception ) {
			return $wp_mcp_ai_concurrency_exception->to_wp_error();
		} catch ( \WP_MCP_AI_Cost_Budget_Exceeded $wp_mcp_ai_cost_exception ) {
			return $wp_mcp_ai_cost_exception->to_wp_error();
		}

		$wp_mcp_ai_tool_start = microtime( true );
		$result               = $this->execute_registry_tool( $tool_slug, $arguments, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Filters the result of a registered tool before MCP shaping.
		 *
		 * @param mixed  $result    Tool result.
		 * @param string $tool_slug Tool identifier.
		 * @param array  $arguments Arguments passed in the request.
		 * @param array  $context   Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $arguments, $context );

		// Adjust the result to fit token-budget constraints (per-mode seam).
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			$result = \WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget( $result, $tool_slug, $context );
		} elseif ( class_exists( 'NvoosContentGraphAi\Analytics\ToolTokenLimits' ) ) {
			$result = \NvoosContentGraphAi\Analytics\ToolTokenLimits::adjust_tool_result_for_budget( $result, $tool_slug, $context );
		}

		/**
		 * Fires after a registered tool has completed execution.
		 *
		 * @param string $tool_slug  Tool identifier.
		 * @param array  $arguments  Arguments passed in the request.
		 * @param array  $context    Execution context including user_id and assistant_id.
		 * @param mixed  $result     Tool result after filters have been applied.
		 * @param array  $descriptor Normalised lifecycle descriptor
		 *                           ({success, error_code, data_type, duration_ms}).
		 *                           Subscribers with `accepted_args = 4` ignore this.
		 */
		do_action(
			'wp_mcp_ai_after_tool_execution',
			$tool_slug,
			$arguments,
			$context,
			$result,
			$this->build_tool_lifecycle_descriptor( $result, $wp_mcp_ai_tool_start, $tool_slug, $context )
		);

		// Check if the tool already returned MCP-compatible structured content.
		if ( $this->is_mcp_content_array( $result ) ) {
			return array( 'content' => $result );
		}

		// Convert the tool result to MCP text content.
		$text_content = $this->convert_to_text_content( $result );

		if ( is_wp_error( $text_content ) ) {
			return $text_content;
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text_content,
				),
			),
		);
	}

	// ─── Wave D8 Cluster 0 — tool execution helpers ────────────────

	/**
	 * Check whether a tool slug exists in the active registry
	 * (per-install-mode seam).
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	protected function registry_has_tool( $slug ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return (bool) \WP_MCP_AI_Tool_Registry::get_instance()->get_tool( $slug );
		}

		return null !== CoreBridge::instance()->tools->get( (string) $slug );
	}

	/**
	 * Execute a registered tool (per-install-mode seam).
	 *
	 * The nvoos-core registry enforces capability checks, guards, and
	 * pre/post events internally; the WordPress auth provider is supplied
	 * so required capabilities resolve against the acting user.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed Tool result or WP_Error.
	 */
	protected function execute_registry_tool( $tool_slug, array $arguments, array $context ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$tool = \WP_MCP_AI_Tool_Registry::get_instance()->get_tool( $tool_slug );
			return $tool ? $tool->execute( $arguments, $context ) : new \WP_Error(
				'wp_mcp_ai_tool_missing',
				__( 'The requested tool is not registered.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}

		$context['auth_provider'] = new \Nvoos\WordPress\Adapter\AuthProvider();
		return CoreBridge::instance()->tools->execute( (string) $tool_slug, $arguments, $context );
	}

	/**
	 * Enforce the per-user tool execution rate limit.
	 *
	 * Byte-identical transient keys, filters, error code, and envelope to
	 * the base plugin's `WP_MCP_AI_REST::check_tool_rate_limit()`.
	 * Credential-token exemption does not apply here — CG-AI's MCP
	 * surface is capability-based with no token auth.
	 *
	 * @param int $user_id Acting user ID (0 = guest, IP-keyed).
	 * @return true|WP_Error
	 */
	protected function check_tool_rate_limit( $user_id ) {
		$settings = $this->get_settings();

		$window_default = isset( $settings['tool_rate_limit_window'] ) ? absint( $settings['tool_rate_limit_window'] ) : self::TOOL_RATE_LIMIT_WINDOW;
		$window_default = max( 10, $window_default );

		$max_default = isset( $settings['tool_rate_limit_max'] ) ? absint( $settings['tool_rate_limit_max'] ) : self::TOOL_RATE_LIMIT_MAX;
		$max_default = max( 0, $max_default );

		/**
		 * Filters the tool rate limit window in seconds.
		 *
		 * @param int $window Window in seconds. Defaults to the
		 *                    tool_rate_limit_window setting (60).
		 */
		$window = apply_filters( 'wp_mcp_ai_tool_rate_limit_window', $window_default );

		/**
		 * Filters the maximum tool executions per window.
		 *
		 * @param int $max Maximum executions. Defaults to the
		 *                 tool_rate_limit_max setting (60). 0 = unlimited.
		 */
		$max = apply_filters( 'wp_mcp_ai_tool_rate_limit_max', $max_default );

		$window = max( 10, absint( $window ) );
		$max    = max( 0, absint( $max ) );

		// 0 disables the limiter.
		if ( 0 === $max ) {
			return true;
		}

		// Guests (user_id=0) get an IP-based key to prevent one attacker
		// from exhausting the global quota.
		if ( $user_id > 0 ) {
			$transient_key = 'wp_mcp_ai_tool_rl_' . $user_id;
		} else {
			$client_ip     = isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: 'unknown';
			$transient_key = 'wp_mcp_ai_tool_rl_ip_' . md5( $client_ip . wp_salt( 'nonce' ) );
		}

		$current_count = get_transient( $transient_key );

		if ( false === $current_count ) {
			// First request in this time window, start counting.
			set_transient( $transient_key, 1, $window );
			return true;
		}

		if ( $current_count >= $max ) {
			return new \WP_Error(
				'wp_mcp_ai_tool_rate_limit_exceeded',
				sprintf(
					/* translators: 1: Maximum executions allowed, 2: Time window in seconds */
					__( 'Tool rate limit exceeded. Maximum %1$d tool executions allowed per %2$d seconds.', 'nvoos-content-graph-ai' ),
					$max,
					$window
				),
				array(
					'status'        => 429,
					'retry_after'   => $window,
					'max'           => $max,
					'window'        => $window,
					'current_count' => $current_count,
				)
			);
		}

		// Increment the counter.
		set_transient( $transient_key, $current_count + 1, $window );
		return true;
	}

	/**
	 * Build the lifecycle descriptor for the after-execution hook.
	 *
	 * Uses the base plugin's descriptor when present (monolith); builds
	 * the same {success, error_code, data_type, duration_ms} shape inline
	 * in standalone mode.
	 *
	 * @param mixed      $result       Tool execution result.
	 * @param float      $start_micros High-resolution start timestamp.
	 * @param string     $tool_slug    Tool slug.
	 * @param array      $context      Execution context.
	 * @return array
	 */
	protected function build_tool_lifecycle_descriptor( $result, $start_micros, $tool_slug, array $context ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Lifecycle_Descriptor' ) ) {
			return \WP_MCP_AI_Tool_Lifecycle_Descriptor::build( $result, $start_micros, $tool_slug, $context );
		}

		$is_error = is_wp_error( $result );

		$descriptor = array(
			'success'     => ! $is_error,
			'error_code'  => $is_error ? (string) $result->get_error_code() : null,
			'data_type'   => null,
			'duration_ms' => null,
		);

		if ( $is_error ) {
			$descriptor['error_code'] = (string) $result->get_error_code();
		}

		if ( is_array( $result ) ) {
			$descriptor['data_type'] = isset( $result['produces'] ) && is_string( $result['produces'] ) && '' !== $result['produces']
				? sanitize_key( $result['produces'] )
				: 'array';
		} elseif ( is_string( $result ) ) {
			$descriptor['data_type'] = 'string';
		} elseif ( is_bool( $result ) ) {
			$descriptor['data_type'] = 'bool';
		} elseif ( is_int( $result ) ) {
			$descriptor['data_type'] = 'int';
		} elseif ( is_float( $result ) ) {
			$descriptor['data_type'] = 'float';
		} elseif ( null === $result ) {
			$descriptor['data_type'] = 'null';
		} elseif ( is_object( $result ) ) {
			$descriptor['data_type'] = 'object';
		} else {
			$descriptor['data_type'] = 'generic';
		}

		if ( null !== $start_micros && is_numeric( $start_micros ) ) {
			$descriptor['duration_ms'] = round( ( microtime( true ) - (float) $start_micros ) * 1000.0, 3 );
			if ( $descriptor['duration_ms'] < 0 ) {
				$descriptor['duration_ms'] = 0.0;
			}
		}

		return $descriptor;
	}

	/**
	 * Build a list of potential tool slugs based on the supplied identifier
	 * (byte-identical to the base plugin's candidate generation).
	 *
	 * @param mixed $tool_name Raw tool identifier from the MCP request.
	 * @return array
	 */
	protected function generate_tool_slug_candidates( $tool_name ) {
		if ( ! is_string( $tool_name ) ) {
			$tool_name = '';
		}

		$tool_name = trim( $tool_name );

		if ( '' === $tool_name ) {
			return array();
		}

		$candidates = array();

		$primary = sanitize_key( $tool_name );
		if ( '' !== $primary ) {
			$candidates[] = $primary;
		}

		$variants = array(
			str_replace( array( '-', ' ' ), '_', $tool_name ),
		);

		$camel_split = preg_replace( '/(?<=\p{Ll})(\p{Lu})/u', '_$1', $tool_name );

		if ( is_string( $camel_split ) && '' !== $camel_split ) {
			$lower_camel = strtolower( $camel_split );
			$variants[]  = $lower_camel;
			$variants[]  = str_replace( array( '-', ' ' ), '_', $lower_camel );
		}

		foreach ( $variants as $variant ) {
			if ( ! is_string( $variant ) ) {
				continue;
			}

			$variant = trim( $variant );

			if ( '' === $variant ) {
				continue;
			}

			$sanitized = sanitize_key( $variant );
			if ( '' !== $sanitized ) {
				$candidates[] = $sanitized;
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Resolve the requested tool slug by comparing candidates against the
	 * assistant's allow-list (byte-identical to the base plugin).
	 *
	 * @param array $candidates    Candidate tool slugs derived from the payload.
	 * @param array $allowed_tools Assistant tool allow-list.
	 * @return string
	 */
	protected function resolve_tool_slug_from_candidates( array $candidates, array $allowed_tools ) {
		if ( empty( $candidates ) ) {
			return '';
		}

		$allowed_lookup = array();
		foreach ( $allowed_tools as $slug ) {
			$sanitized = sanitize_key( $slug );

			if ( '' === $sanitized ) {
				continue;
			}

			$allowed_lookup[ $sanitized ] = $sanitized;
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $allowed_lookup[ $candidate ] ) ) {
				return $allowed_lookup[ $candidate ];
			}
		}

		// If a candidate ends with `_validated`, also try its base slug.
		// This handles the registry auto-upgrade where get_tool( 'web_search' )
		// transparently returns the web_search_validated instance, and the MCP
		// tools/list endpoint reports the validated slug while the assistant
		// config stores the base slug.
		foreach ( $candidates as $candidate ) {
			if ( substr( $candidate, -10 ) === '_validated' ) {
				$base = substr( $candidate, 0, -10 );
				if ( '' !== $base && isset( $allowed_lookup[ $base ] ) ) {
					return $allowed_lookup[ $base ];
				}
			}
		}

		if ( ! empty( $allowed_lookup ) ) {
			$normalised_candidates = array();
			foreach ( $candidates as $candidate ) {
				$normalised_candidates[] = preg_replace( '/[_-]/', '', $candidate );
			}

			$normalised_candidates = array_values( array_filter( array_unique( $normalised_candidates ) ) );

			if ( ! empty( $normalised_candidates ) ) {
				foreach ( $allowed_lookup as $slug ) {
					$normalised_slug = preg_replace( '/[_-]/', '', $slug );

					if ( in_array( $normalised_slug, $normalised_candidates, true ) ) {
						return $slug;
					}
				}
			}
		}

		return $candidates[0];
	}

	/**
	 * Check if a value is a valid MCP content array
	 * (byte-identical to the base plugin).
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	protected function is_mcp_content_array( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		// Empty arrays are not valid MCP content.
		if ( empty( $value ) ) {
			return false;
		}

		// Check if this is a numeric array (content items).
		if ( ! isset( $value[0] ) ) {
			return false;
		}

		// All items must have a 'type' field.
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'] ) ) {
				return false;
			}

			switch ( $item['type'] ) {
				case 'text':
					if ( ! isset( $item['text'] ) ) {
						return false;
					}
					break;
				case 'image':
					if ( ! isset( $item['data'] ) && ! isset( $item['url'] ) ) {
						return false;
					}
					break;
				case 'resource':
				case 'embedded_resource':
					if ( ! isset( $item['resource'] ) ) {
						return false;
					}
					break;
				default:
					// Unknown type - could be valid for future MCP versions.
					break;
			}
		}

		return true;
	}

	/**
	 * Convert a tool result to text content for MCP
	 * (byte-identical to the base plugin).
	 *
	 * @param mixed $tool_result The tool result to convert.
	 * @return string|WP_Error
	 */
	protected function convert_to_text_content( $tool_result ) {
		// Handle string results directly.
		if ( is_string( $tool_result ) ) {
			return $tool_result;
		}

		// Handle scalar values (int, float, bool, null).
		if ( is_scalar( $tool_result ) || is_null( $tool_result ) ) {
			$text_content = wp_json_encode( $tool_result );
			if ( false === $text_content ) {
				return new \WP_Error(
					'wp_mcp_ai_encoding_failed',
					sprintf(
						/* translators: %s: data type */
						__( 'Failed to encode scalar tool result of type: %s', 'nvoos-content-graph-ai' ),
						gettype( $tool_result )
					),
					array(
						'status'  => 500,
						'actions' => array(
							'check_result' => __( 'This is an internal error. The tool returned data that could not be encoded.', 'nvoos-content-graph-ai' ),
						),
					)
				);
			}
			return $text_content;
		}

		// Handle arrays and objects - encode as JSON.
		if ( is_array( $tool_result ) || is_object( $tool_result ) ) {
			// Try pretty printing first for better readability.
			$text_content = wp_json_encode( $tool_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( false === $text_content ) {
				// Fallback to basic encoding.
				$text_content = wp_json_encode( $tool_result );

				if ( false === $text_content ) {
					// Encoding failed completely - return structured error.
					return new \WP_Error(
						'wp_mcp_ai_encoding_failed',
						__( 'Unable to encode tool result to JSON. The tool may have returned circular references or invalid data.', 'nvoos-content-graph-ai' ),
						array(
							'status'  => 500,
							'actions' => array(
								'check_tool'   => __( 'This is likely a bug in the tool implementation. Check if the tool is returning circular references or non-serializable data.', 'nvoos-content-graph-ai' ),
								'report_issue' => __( 'Please report this to the plugin administrator with the tool name you were trying to execute.', 'nvoos-content-graph-ai' ),
							),
						)
					);
				}
			}

			return $text_content;
		}

		// Unexpected type - return error.
		return new \WP_Error(
			'wp_mcp_ai_invalid_result_type',
			sprintf(
				/* translators: %s: data type */
				__( 'Tool result has unexpected type: %s', 'nvoos-content-graph-ai' ),
				gettype( $tool_result )
			),
			array(
				'status'  => 500,
				'actions' => array(
					'report_issue' => __( 'This is an internal error. The tool returned an unexpected data type. Please report this to the plugin administrator.', 'nvoos-content-graph-ai' ),
				),
			)
		);
	}

	/**
	 * Handle MCP resources/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_resources_list( $params, \WP_REST_Request $request ) {
		unset( $request ); // Required by MCP protocol method signature.
		$assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;
		$resources    = array();

		if ( $assistant_id ) {
			$assistant_post = $this->validate_assistant_access( $assistant_id );

			if ( ! is_wp_error( $assistant_post ) ) {
				$config = $this->get_assistant_configuration( $assistant_id );

				// Add memory files as resources.
				if ( isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) {
					foreach ( $config['memory_files'] as $file_id ) {
						$file_id = absint( $file_id );
						if ( ! $file_id ) {
							continue;
						}

						$attachment = get_post( $file_id );

						// Validate that the post exists and is an attachment.
						if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
							continue;
						}

						// Get attachment URL and skip if unavailable.
						$attachment_url = wp_get_attachment_url( $file_id );
						if ( false === $attachment_url || empty( $attachment_url ) ) {
							continue;
						}

						$resources[] = array(
							'uri'         => $attachment_url,
							'name'        => get_the_title( $attachment ),
							'description' => get_post_field( 'post_excerpt', $attachment ),
							'mimeType'    => get_post_mime_type( $attachment ),
						);
					}
				}
			}
		}

		return array( 'resources' => $resources );
	}

	/**
	 * Handle MCP resources/read request.
	 *
	 * Only serves files that are in the assistant's memory_files allowlist.
	 *
	 * @param array           $params  Method parameters. Must include 'uri'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error MCP contents response or error.
	 */
	protected function mcp_resources_read( $params, \WP_REST_Request $request ) {
		if ( ! isset( $params['uri'] ) || ! is_string( $params['uri'] ) || '' === $params['uri'] ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: uri. Provide the URI of the resource to read.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_uri'    => __( 'Include the "uri" parameter in your resources/read request.', 'nvoos-content-graph-ai' ),
						'list_resources' => __( 'Call resources/list first to discover available resource URIs.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$uri = esc_url_raw( $params['uri'] );

		// Resolve assistant scope same as resources/list.
		$assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		if ( ! $assistant_id ) {
			return new \WP_Error(
				'wp_mcp_ai_no_assistant',
				__( 'No assistant context available. Provide an assistant_id or authenticate with an assistant-scoped token.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_assistant' => __( 'Include "assistant_id" in the request params or use an assistant-scoped bearer token.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$assistant_post = $this->validate_assistant_access( $assistant_id );

		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		$config = $this->get_assistant_configuration( $assistant_id );

		// Validate the URI is in the assistant's memory_files allowlist.
		$memory_files = isset( $config['memory_files'] ) && is_array( $config['memory_files'] )
			? $config['memory_files']
			: array();

		$matched_file_id = 0;

		foreach ( $memory_files as $file_id ) {
			$file_id = absint( $file_id );
			if ( ! $file_id ) {
				continue;
			}

			$attachment_url = wp_get_attachment_url( $file_id );
			if ( false !== $attachment_url && $attachment_url === $uri ) {
				$matched_file_id = $file_id;
				break;
			}
		}

		if ( ! $matched_file_id ) {
			return new \WP_Error(
				'wp_mcp_ai_resource_not_found',
				__( 'The requested resource URI was not found among this assistant\'s memory files.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 404,
					'actions' => array(
						'check_uri'      => __( 'Verify the URI matches one returned by resources/list.', 'nvoos-content-graph-ai' ),
						'list_resources' => __( 'Call resources/list to see available resources for this assistant.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$attachment = get_post( $matched_file_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'wp_mcp_ai_resource_not_found',
				__( 'The attachment for this resource no longer exists.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}

		$mime_type = get_post_mime_type( $attachment );
		$file_path = get_attached_file( $matched_file_id );

		// Security: Validate the file path is within ABSPATH.
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_resource_unavailable',
				__( 'The resource file is not available on disk.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}

		$real_path    = realpath( $file_path );
		$real_abspath = realpath( ABSPATH );

		if ( false === $real_path || false === $real_abspath || 0 !== strpos( $real_path, $real_abspath ) ) {
			return new \WP_Error(
				'wp_mcp_ai_resource_unavailable',
				__( 'The resource file path is outside the allowed directory.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		// Enforce 1 MB size limit.
		$max_size  = 1048576; // 1 MB.
		$file_size = filesize( $file_path );

		if ( false === $file_size || $file_size > $max_size ) {
			return new \WP_Error(
				'wp_mcp_ai_resource_too_large',
				__( 'The resource file exceeds the maximum allowed size of 1 MB.', 'nvoos-content-graph-ai' ),
				array(
					'status' => 413,
					'size'   => $file_size,
				)
			);
		}

		// Determine if this is a text-based or binary MIME type.
		$text_mime_prefixes = array( 'text/', 'application/json', 'application/xml', 'application/javascript', 'application/x-yaml', 'application/csv' );
		$is_text            = false;

		foreach ( $text_mime_prefixes as $prefix ) {
			if ( 0 === strpos( $mime_type, $prefix ) ) {
				$is_text = true;
				break;
			}
		}

		$contents = array();

		if ( $is_text ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local attachment file, not a URL.
			$file_contents = file_get_contents( $file_path );

			if ( false === $file_contents ) {
				return new \WP_Error(
					'wp_mcp_ai_resource_read_failed',
					__( 'Failed to read the resource file.', 'nvoos-content-graph-ai' ),
					array( 'status' => 500 )
				);
			}

			$contents[] = array(
				'uri'      => $uri,
				'mimeType' => $mime_type,
				'text'     => $file_contents,
			);
		} else {
			// Binary content: base64-encode it.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local attachment file, not a URL.
			$file_contents = file_get_contents( $file_path );

			if ( false === $file_contents ) {
				return new \WP_Error(
					'wp_mcp_ai_resource_read_failed',
					__( 'Failed to read the resource file.', 'nvoos-content-graph-ai' ),
					array( 'status' => 500 )
				);
			}

			$contents[] = array(
				'uri'      => $uri,
				'mimeType' => $mime_type,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by MCP protocol for binary resource content.
				'blob'     => base64_encode( $file_contents ),
			);
		}

		/**
		 * Filters the resources/read response contents before returning.
		 *
		 * @param array           $contents     MCP contents array.
		 * @param string          $uri          Requested resource URI.
		 * @param int             $assistant_id Assistant ID.
		 * @param WP_REST_Request $request      REST request instance.
		 */
		$contents = apply_filters( 'wp_mcp_ai_resources_read', $contents, $uri, $assistant_id, $request );

		return array( 'contents' => $contents );
	}

	/**
	 * Handle MCP prompts/list request.
	 *
	 * Prompts are scoped to the assistant resolved for the caller; without
	 * a resolvable assistant, no prompts are exposed.
	 *
	 * @param array           $params  Method parameters. Optionally includes 'assistant_id'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error MCP prompts response or error.
	 */
	protected function mcp_prompts_list( $params, \WP_REST_Request $request ) {
		unset( $request ); // Required by MCP protocol method signature.

		$assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		// Without a resolvable assistant, expose no prompts rather than
		// every assistant on the site.
		if ( ! $assistant_id ) {
			return array( 'prompts' => array() );
		}

		$assistant_post = $this->validate_assistant_access( $assistant_id );

		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		// Prompts represent published assistants only, matching the
		// prompts/get lookup which is limited to published posts.
		if ( 'publish' !== $assistant_post->post_status ) {
			return array( 'prompts' => array() );
		}

		$default_arguments = array(
			array(
				'name'        => 'context',
				'description' => __( 'Additional context or instructions to incorporate when rendering this prompt.', 'nvoos-content-graph-ai' ),
				'required'    => false,
			),
		);

		/**
		 * Filters the arguments for an individual prompt in prompts/list.
		 *
		 * @param array   $arguments    Prompt arguments schema.
		 * @param WP_Post $post         Assistant post object.
		 */
		$arguments = apply_filters( 'wp_mcp_ai_prompt_arguments', $default_arguments, $assistant_post );

		$prompts = array(
			array(
				'name'        => $assistant_post->post_name,
				'description' => get_the_title( $assistant_post ),
				'arguments'   => $arguments,
			),
		);

		return array( 'prompts' => $prompts );
	}

	/**
	 * Handle MCP prompts/get request.
	 *
	 * @param array           $params  Method parameters. Must include 'name'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error MCP prompt response or error.
	 */
	protected function mcp_prompts_get( $params, \WP_REST_Request $request ) {
		if ( ! isset( $params['name'] ) || ! is_string( $params['name'] ) || '' === $params['name'] ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: name. Provide the name (slug) of the prompt to retrieve.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_name' => __( 'Include the "name" parameter matching an assistant slug from prompts/list.', 'nvoos-content-graph-ai' ),
						'list_prompts' => __( 'Call prompts/list first to discover available prompt names.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$name = sanitize_title( $params['name'] );

		// Look up the assistant by post_name (slug).
		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'name'                   => $name,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return new \WP_Error(
				'wp_mcp_ai_prompt_not_found',
				sprintf(
					/* translators: %s: prompt name */
					__( 'Prompt not found: %s', 'nvoos-content-graph-ai' ),
					$name
				),
				array(
					'status'  => 404,
					'actions' => array(
						'check_name'   => __( 'Verify the prompt name matches a published assistant slug.', 'nvoos-content-graph-ai' ),
						'list_prompts' => __( 'Call prompts/list to see available prompts.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$post = $query->posts[0];

		// Token assistant scope: CG-AI has no local tokens yet, so no
		// enforcement (documented deviation — the base hides other
		// assistants' prompts behind the same not-found error).

		$config        = $this->get_assistant_configuration( $post->ID );
		$system_prompt = isset( $config['system_prompt'] ) ? $config['system_prompt'] : '';

		// If a context argument was provided, append it to the system prompt content.
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$context   = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';

		if ( ! empty( $context ) ) {
			$system_prompt .= "\n\n" . $context;
		}

		$prompt_content = array(
			'description' => get_the_title( $post ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $system_prompt,
					),
				),
			),
		);

		/**
		 * Filters the prompts/get response before returning.
		 *
		 * @param array           $prompt_content Prompt response with description and messages.
		 * @param WP_Post         $post           Assistant post object.
		 * @param array           $arguments      Request arguments.
		 * @param WP_REST_Request $request        REST request instance.
		 */
		$prompt_content = apply_filters( 'wp_mcp_ai_prompts_get', $prompt_content, $post, $arguments, $request );

		return $prompt_content;
	}

	/**
	 * Handle MCP ping request.
	 *
	 * Returns an empty result object per the MCP specification.
	 *
	 * @return stdClass Empty result object.
	 */
	protected function mcp_ping() {
		return new \stdClass();
	}

	/**
	 * Handle MCP completion/complete request.
	 *
	 * @param array           $params  Method parameters including 'ref' and 'argument'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error Completion result with values array.
	 */
	protected function mcp_completion_complete( $params, \WP_REST_Request $request ) {
		unset( $request ); // Required by MCP protocol method signature.
		if ( ! isset( $params['ref'] ) || ! is_array( $params['ref'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: ref. Must include type and name.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $params['argument'] ) || ! is_array( $params['argument'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: argument. Must include name and value.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$ref      = $params['ref'];
		$argument = $params['argument'];

		$ref_type = isset( $ref['type'] ) ? sanitize_text_field( $ref['type'] ) : '';
		$ref_name = isset( $ref['name'] ) ? sanitize_text_field( $ref['name'] ) : '';
		$arg_name = isset( $argument['name'] ) ? sanitize_text_field( $argument['name'] ) : '';
		$arg_val  = isset( $argument['value'] ) ? sanitize_text_field( $argument['value'] ) : '';

		$values   = array();
		$has_more = false;

		if ( 'ref/tool' === $ref_type ) {
			$values = $this->complete_tool_argument( $ref_name, $arg_name, $arg_val );
		} elseif ( 'ref/prompt' === $ref_type ) {
			$values = $this->complete_prompt_argument( $ref_name, $arg_name, $arg_val );
		}

		// Cap returned values at 100 per MCP spec recommendation.
		if ( count( $values ) > 100 ) {
			$values   = array_slice( $values, 0, 100 );
			$has_more = true;
		}

		/**
		 * Filter the completion result before returning.
		 *
		 * @param array  $result   The completion result array.
		 * @param array  $ref      The reference object (type, name).
		 * @param array  $argument The argument object (name, value).
		 * @param string $ref_type Resolved reference type.
		 * @param string $ref_name Resolved reference name.
		 */
		$result = apply_filters(
			'wp_mcp_ai_mcp_completion_complete',
			array(
				'completion' => array(
					'values'  => $values,
					'hasMore' => $has_more,
					'total'   => count( $values ),
				),
			),
			$ref,
			$argument,
			$ref_type,
			$ref_name
		);

		return $result;
	}

	/**
	 * Complete a tool argument based on the tool's parameter schema.
	 *
	 * @param string $tool_name Tool slug.
	 * @param string $arg_name  Argument name.
	 * @param string $arg_value Partial value to complete.
	 * @return array Array of completion value strings.
	 */
	protected function complete_tool_argument( $tool_name, $arg_name, $arg_value ) {
		$tool = $this->get_registry_tool( $tool_name );
		if ( ! $tool ) {
			return array();
		}

		$schema = $tool->get_parameters_schema();
		if ( ! is_array( $schema ) || ! isset( $schema['properties'][ $arg_name ] ) ) {
			return array();
		}

		$prop = $schema['properties'][ $arg_name ];

		// If the property has an enum, filter by partial match.
		if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) ) {
			$matches      = array();
			$arg_value_lc = strtolower( $arg_value );
			foreach ( $prop['enum'] as $candidate ) {
				$candidate_str = (string) $candidate;
				if ( '' === $arg_value || 0 === strpos( strtolower( $candidate_str ), $arg_value_lc ) ) {
					$matches[] = $candidate_str;
				}
			}
			return $matches;
		}

		// For boolean types, suggest true/false.
		if ( isset( $prop['type'] ) && 'boolean' === $prop['type'] ) {
			$booleans = array( 'true', 'false' );
			if ( '' === $arg_value ) {
				return $booleans;
			}
			return array_values(
				array_filter(
					$booleans,
					function ( $b ) use ( $arg_value ) {
						return 0 === strpos( $b, strtolower( $arg_value ) );
					}
				)
			);
		}

		return array();
	}

	/**
	 * Complete a prompt argument.
	 *
	 * @param string $prompt_name Prompt (assistant) slug.
	 * @param string $arg_name    Argument name.
	 * @param string $arg_value   Partial value to complete.
	 * @return array Array of completion value strings.
	 */
	protected function complete_prompt_argument( $prompt_name, $arg_name, $arg_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- Reserved for future argument completions.
		unset( $prompt_name ); // Reserved for future argument completions.

		// Currently prompts don't have completable arguments beyond the name itself.
		// Return matching assistant slugs if arg_name is empty (completing the prompt name).
		if ( empty( $arg_name ) || 'name' === $arg_name ) {
			$assistants = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			$matches      = array();
			$arg_value_lc = strtolower( $arg_value );
			foreach ( $assistants as $assistant ) {
				$slug = $assistant->post_name;
				if ( '' === $arg_value || 0 === strpos( strtolower( $slug ), $arg_value_lc ) ) {
					$matches[] = $slug;
				}
			}
			return $matches;
		}

		return array();
	}

	/**
	 * Handle MCP logging/setLevel request.
	 *
	 * @param array $params Method parameters. Must include 'level'.
	 * @return stdClass|WP_Error Empty result on success.
	 */
	protected function mcp_logging_set_level( $params ) {
		if ( ! isset( $params['level'] ) || ! is_string( $params['level'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: level', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$valid_levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );
		$level        = strtolower( sanitize_text_field( $params['level'] ) );

		if ( ! in_array( $level, $valid_levels, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_params',
				sprintf(
					/* translators: %s: comma-separated list of valid log levels */
					__( 'Invalid log level. Must be one of: %s', 'nvoos-content-graph-ai' ),
					implode( ', ', $valid_levels )
				),
				array( 'status' => 400 )
			);
		}

		/**
		 * Fires when an MCP client sets the logging level.
		 *
		 * @param string $level The requested log level.
		 */
		do_action( 'wp_mcp_ai_mcp_logging_set_level', $level );

		return new \stdClass();
	}

	/**
	 * Handle MCP notifications/cancelled notification.
	 *
	 * @param array $params Notification parameters. Should include 'requestId' and optionally 'reason'.
	 * @return stdClass Empty result (notification response handled by caller).
	 */
	protected function mcp_notifications_cancelled( $params ) {
		$request_id = isset( $params['requestId'] ) ? sanitize_text_field( $params['requestId'] ) : '';
		$reason     = isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : '';

		/**
		 * Fires when an MCP client cancels a request.
		 *
		 * @param string $request_id The ID of the request to cancel.
		 * @param string $reason     Optional reason for cancellation.
		 */
		do_action( 'wp_mcp_ai_mcp_request_cancelled', $request_id, $reason );

		if ( ! empty( $request_id ) ) {
			$this->log_event(
				'info',
				'MCP request cancelled by client',
				array(
					'request_id' => $request_id,
					'reason'     => $reason,
				)
			);
		}

		return new \stdClass();
	}

	/**
	 * Build MCP tool annotations from the tool's capability flags.
	 *
	 * Maps the base plugin's WP_MCP_AI_Tool_Capability_Flags_Interface flags
	 * to the MCP 2024-11-05 tool annotation format. Standalone core tools
	 * have no capability flags and produce empty annotations.
	 *
	 * @param object $tool Tool instance.
	 * @return array Annotation key-value pairs, empty if tool has no flags.
	 */
	protected function build_tool_annotations( $tool ) {
		if ( $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
		} elseif ( is_object( $tool ) && method_exists( $tool, 'get_capability_flags' ) ) {
			// Duck-typed seam for standalone wrappers.
			$flags = $tool->get_capability_flags();
		} else {
			return array();
		}

		if ( ! is_array( $flags ) || empty( $flags ) ) {
			return array();
		}

		$annotations = array();

		// Map capability flags to MCP annotation fields.
		$annotations['readOnlyHint'] = in_array( 'read-only', $flags, true );

		// destructiveHint: true if tool is write/state-changing and NOT marked read-only.
		$is_write = in_array( 'write', $flags, true ) || in_array( 'state-changing', $flags, true );
		if ( $is_write ) {
			$annotations['destructiveHint'] = ! in_array( 'reversible', $flags, true );
		}

		// idempotentHint from idempotent flag.
		if ( in_array( 'idempotent', $flags, true ) ) {
			$annotations['idempotentHint'] = true;
		}

		// openWorldHint: true if tool calls external APIs.
		if ( in_array( 'external-api', $flags, true ) || in_array( 'network-dependent', $flags, true ) ) {
			$annotations['openWorldHint'] = true;
		} elseif ( in_array( 'local-only', $flags, true ) ) {
			$annotations['openWorldHint'] = false;
		}

		/**
		 * Filter the MCP annotations for a tool.
		 *
		 * @param array  $annotations MCP annotation key-value pairs.
		 * @param object $tool        Tool instance.
		 * @param array  $flags       Raw capability flags array.
		 */
		return apply_filters( 'wp_mcp_ai_tool_annotations', $annotations, $tool, $flags );
	}

	/**
	 * Create a JSON-RPC error response.
	 *
	 * JSON-RPC errors are transport-independent: the HTTP status defaults to
	 * 200 with the error carried in the JSON-RPC envelope (byte-identical to
	 * the base — several MCP client SDKs silently drop non-2xx bodies).
	 *
	 * @param mixed  $id      Request ID or null.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Additional error data.
	 * @return WP_REST_Response
	 */
	protected function mcp_error_response( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		/**
		 * Filter the HTTP status used for MCP JSON-RPC error responses.
		 * Defaults to 200 so client SDKs that drop non-2xx bodies still
		 * relay the JSON-RPC error to the agent.
		 *
		 * @param int   $status HTTP status code. Default 200.
		 * @param int   $code   JSON-RPC error code (e.g. -32603).
		 * @param mixed $id     Request identifier.
		 */
		$status = apply_filters( 'wp_mcp_ai_mcp_error_http_status', 200, $code, $id );

		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			),
			$status
		);
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		$this->add_cors_headers( $response );
		return $response;
	}

	/**
	 * Add CORS headers to MCP responses.
	 *
	 * By default allows the site's own origin; `cors_allow_origin = star`
	 * in the settings allows all origins (byte-identical to the base).
	 *
	 * @param WP_REST_Response $response Response object to modify.
	 * @return void
	 */
	public function add_cors_headers( $response ) {
		/**
		 * Filter the Access-Control-Allow-Origin header value.
		 *
		 * @param string $origin The origin value to allow. Default: from
		 *                       settings or get_site_url().
		 */
		$settings       = $this->get_settings();
		$cors_setting   = isset( $settings['cors_allow_origin'] ) ? $settings['cors_allow_origin'] : 'site';
		$default_origin = ( 'star' === $cors_setting ) ? '*' : get_site_url();
		$allow_origin   = apply_filters( 'wp_mcp_ai_cors_allow_origin', $default_origin );

		$response->header( 'Access-Control-Allow-Origin', $allow_origin );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest, Accept, Mcp-Method, Mcp-Name, MCP-Protocol-Version' );
		$response->header( 'Access-Control-Expose-Headers', 'Mcp-Method, Mcp-Name, MCP-Protocol-Version' );
		$response->header( 'Access-Control-Max-Age', '3600' );

		// The base also applies the security manager's response headers
		// here. CG-AI's ported CSP headers are admin-context only, and the
		// base security manager is monolith-only — nothing to apply
		// (documented deviation).
	}

	/**
	 * Normalize a raw priority string to a valid priority level.
	 *
	 * @param string $raw Raw priority value.
	 * @return string|null Normalized priority or null if unrecognized.
	 */
	protected static function normalize_priority( $raw ) {
		$valid = array( 'realtime', 'high', 'normal', 'low', 'batch' );
		$raw   = strtolower( trim( (string) $raw ) );
		return in_array( $raw, $valid, true ) ? $raw : null;
	}

	/**
	 * Handle GET requests to /mcp endpoint.
	 *
	 * Returns MCP server discovery information as JSON. The base's
	 * `?stream=true` / Accept-header SSE negotiation is not ported yet —
	 * SSE sessions are deferred (documented gap).
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public function handle_mcp_get_request( \WP_REST_Request $request ) {
		// Default behavior: Return discovery JSON.
		// This is compatible with LM Studio and other Streamable HTTP (JSON-RPC) clients.
		return $this->return_discovery_info( $request );
	}

	/**
	 * Handle MCP OPTIONS request for CORS.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public function handle_mcp_options( \WP_REST_Request $request ) {
		unset( $request );

		$response = new \WP_REST_Response( null, 204 );
		$this->add_cors_headers( $response );

		return $response;
	}

	/**
	 * Handle no-sse endpoint (non-SSE assistant directory).
	 *
	 * Since GET /mcp always returns discovery, this endpoint provides
	 * the assistant directory without SSE streaming.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_no_sse_request( \WP_REST_Request $request ) {
		// Return assistant directory as JSON (no SSE) — delegated to the
		// ported assistant directory controller (composition).
		$assistant_controller = new AssistantController();
		return $assistant_controller->handle_assistants_index( $request );
	}

	/**
	 * Handle SSE handshake.
	 *
	 * SSE sessions are deferred in this wave; the endpoint returns the
	 * discovery payload instead of an event stream (documented gap).
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public function handle_sse_handshake( \WP_REST_Request $request ) {
		// Self-contained fallback: Return discovery info instead of SSE.
		return $this->return_discovery_info( $request );
	}

	/**
	 * Return MCP endpoint discovery information.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	protected function return_discovery_info( \WP_REST_Request $request ) {
		unset( $request );

		$response_data = array(
			'name'            => 'NV oOS MCP Server',
			'version'         => $this->get_version(),
			'protocolVersion' => '2026-07-28',
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => true ),
				'resources' => array(
					'subscribe'   => true,
					'listChanged' => true,
				),
				'prompts'   => array( 'listChanged' => true ),
				'sse'       => array(
					'enabled' => false,
					'default' => false,
					'note'    => 'SSE sessions are not ported to the content graph AI addon yet; GET /mcp and GET /sse return this discovery JSON.',
				),
			),
			'transports'      => array(
				'streamable_http' => array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/mcp' ),
					'methods'  => array( 'GET', 'POST' ),
					'default'  => true,
					'note'     => 'MCP 2026-07-28 Streamable HTTP - GET for discovery (JSON), POST for JSON-RPC 2.0',
				),
				'sse'             => array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/sse' ),
					'methods'  => array( 'GET' ),
					'default'  => false,
					'note'     => 'SSE streaming deferred - the endpoint returns discovery JSON in this wave',
				),
				'stdio'           => array(
					'command' => 'wp mcp-ai stdio',
					'args'    => array( '--path=/path/to/wordpress', '--assistant-id=<id>' ),
					'default' => false,
					'note'    => 'STDIO transport is served by the base plugin in monolith installs; the content graph AI CLI wave (D6) will provide its own',
				),
			),
			'endpoints'       => array(
				'mcp'        => rest_url( self::REST_NAMESPACE . '/mcp' ),
				'sse'        => rest_url( self::REST_NAMESPACE . '/sse' ),
				'assistants' => rest_url( self::REST_NAMESPACE . '/assistants' ),
				'chat'       => rest_url( self::REST_NAMESPACE . '/chat' ),
				'tools'      => rest_url( self::REST_NAMESPACE . '/tools' ),
			),
			'usage'           => array(
				'discovery' => 'GET /mcp (default - returns this discovery JSON for LM Studio, etc.)',
				'jsonrpc'   => 'POST /mcp (JSON-RPC 2.0 protocol for tool execution)',
				'no_sse'    => 'GET /no-sse (assistant directory as JSON)',
			),
		);

		$response = new \WP_REST_Response( $response_data, 200 );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );

		// Add CORS headers.
		$this->add_cors_headers( $response );

		return $response;
	}

	/**
	 * Resolve the server/plugin version stamp.
	 *
	 * Prefers the base plugin version when present (byte-identical output
	 * in monolith installs); standalone uses the content graph AI version.
	 *
	 * @return string
	 */
	protected function get_version() {
		if ( defined( 'WP_MCP_AI_VERSION' ) ) {
			return WP_MCP_AI_VERSION;
		}

		return defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : 'dev';
	}

	// ─── Seams (per-install-mode) ────────────────────────────────────
	//
	// The seams below mirror ToolsController/AssistantController. They are
	// deliberately duplicated per the codebase's established Rest-controller
	// pattern; a shared trait can consolidate them in a later refactor
	// (tracked in the ecosystem port plan).

	/**
	 * Read the active settings map (per-install-mode seam).
	 *
	 * @return array
	 */
	protected function get_settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			return is_array( $settings ) ? $settings : array();
		}

		// Standalone: CG settings store (nvoos_content_graph_settings).
		$all = CoreBridge::instance()->settings->all();
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Read the assistant configuration (per-install-mode seam).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected function get_assistant_configuration( $assistant_id ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		}

		return $this->read_assistant_configuration_standalone( $assistant_id );
	}

	/**
	 * Standalone assistant configuration reader (same meta keys as base).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected function read_assistant_configuration_standalone( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return array();
		}

		$config = array(
			'tools'               => get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true ),
			'model'               => get_post_meta( $assistant_id, '_wp_mcp_ai_model', true ),
			'temperature'         => get_post_meta( $assistant_id, '_wp_mcp_ai_temperature', true ),
			'system_prompt'       => get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true ),
			'memory_files'        => get_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', true ),
			'vector_store_id'     => get_post_meta( $assistant_id, '_wp_mcp_ai_vector_store_id', true ),
			'preferred_datasets'  => get_post_meta( $assistant_id, '_wp_mcp_ai_preferred_datasets', true ),
		);

		if ( ! is_array( $config['tools'] ) ) {
			$config['tools'] = array();
		}

		if ( ! is_string( $config['model'] ) ) {
			$config['model'] = '';
		} else {
			$config['model'] = sanitize_text_field( $config['model'] );
		}

		return $config;
	}

	/**
	 * Ensure the current user can access the requested assistant post.
	 *
	 * Mirrors AssistantController::validate_assistant_access.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return WP_Post|WP_Error
	 */
	protected function validate_assistant_access( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		$assistant_post = $assistant_id ? get_post( $assistant_id ) : null;

		if ( ! $assistant_post || self::POST_TYPE !== $assistant_post->post_type ) {
			return new \WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( 'publish' !== $assistant_post->post_status && ! current_user_can( 'read_post', $assistant_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		return $assistant_post;
	}

	/**
	 * Resolve a single tool by slug (per-install-mode seam).
	 *
	 * Mirrors ToolsController::get_registry_tool.
	 *
	 * @param string $slug Tool slug.
	 * @return object|null
	 */
	protected function get_registry_tool( $slug ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $slug );
			return $tool ? $tool : null;
		}

		$tool = CoreBridge::instance()->tools->get( (string) $slug );
		return $tool ? $this->wrap_tool( $tool ) : null;
	}

	/**
	 * Wrap an nvoos/core tool (camelCase) in the base snake_case surface.
	 *
	 * Mirrors ToolsController::wrap_tool.
	 *
	 * @param object $tool Core tool instance.
	 * @return object
	 */
	protected function wrap_tool( $tool ) {
		return new class( $tool ) {
			private $tool;

			public function __construct( $tool ) {
				$this->tool = $tool;
			}

			public function get_slug() {
				return $this->tool->getSlug();
			}

			public function get_description() {
				return $this->tool->getDescription();
			}

			public function get_parameters_schema() {
				return $this->tool->getParametersSchema();
			}
		};
	}

	/**
	 * Resolve the assistant ID for a request.
	 *
	 * CG-AI has no site-default assistant setting yet, so the raw
	 * parameter is returned unchanged (documented deviation — the base
	 * resolves the default assistant when no ID is provided).
	 *
	 * @param int $assistant_id Requested assistant ID.
	 * @return int
	 */
	protected function resolve_assistant_id( $assistant_id ) {
		return absint( $assistant_id );
	}

	/**
	 * Apply the token-scoped assistant for this request.
	 *
	 * CG-AI has no bearer tokens yet, so the requested assistant passes
	 * through unchanged (documented deviation — the base applies
	 * credential-scoped assistant resolution).
	 *
	 * @param int $assistant_id Requested assistant ID.
	 * @return int|WP_Error
	 */
	protected function apply_token_assistant_scope( $assistant_id ) {
		return absint( $assistant_id );
	}

	/**
	 * Forward a log event to the base logger (monolith installs only).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected function log_event( $event, $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $data );
		}
	}
}
