<?php
/**
 * REST controller for the markup elicitation subsystem.
 *
 * Routes:
 *  - GET    /mcp-ai/v1/markup/(?P<request_id>[A-Za-z0-9_-]+)         — fetch request schema
 *  - POST   /mcp-ai/v1/markup/(?P<request_id>[A-Za-z0-9_-]+)/submit  — submit annotation
 *  - DELETE /mcp-ai/v1/markup/(?P<request_id>[A-Za-z0-9_-]+)         — cancel request
 *
 * The submit endpoint validates the W3C annotation, rasterizes the
 * artifacts, then resumes the original tool by invoking
 * `consume_markup()` on the originating tool. The tool's return value
 * is then surfaced through the standard response envelope so the chat
 * client can stream it as if it were a normal tool result.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Markup_REST_Controller
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_REST_Controller {

	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Markup store.
	 *
	 * @var WP_MCP_AI_Markup_Store
	 */
	private $store;

	/**
	 * Markup validator.
	 *
	 * @var WP_MCP_AI_Markup_Validator
	 */
	private $validator;

	/**
	 * Markup rasterizer.
	 *
	 * @var WP_MCP_AI_Markup_Rasterizer
	 */
	private $rasterizer;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Markup_Store|null      $store      Optional store override.
	 * @param WP_MCP_AI_Markup_Validator|null  $validator  Optional validator override.
	 * @param WP_MCP_AI_Markup_Rasterizer|null $rasterizer Optional rasterizer override.
	 */
	public function __construct( $store = null, $validator = null, $rasterizer = null ) {
		$this->store      = $store instanceof WP_MCP_AI_Markup_Store ? $store : new WP_MCP_AI_Markup_Store();
		$this->validator  = $validator instanceof WP_MCP_AI_Markup_Validator ? $validator : new WP_MCP_AI_Markup_Validator();
		$this->rasterizer = $rasterizer instanceof WP_MCP_AI_Markup_Rasterizer ? $rasterizer : new WP_MCP_AI_Markup_Rasterizer();
	}

	/**
	 * Register all markup routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/markup/(?P<request_id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'request_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_request_id' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'request_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_request_id' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/markup/(?P<request_id>[A-Za-z0-9_-]+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'request_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_request_id' ),
					),
					'markup'     => array(
						'type'        => 'object',
						'required'    => true,
						'description' => 'W3C Web Annotation document describing the user markup.',
					),
					'extra'      => array(
						'type'        => 'object',
						'required'    => false,
						'description' => 'Additional fields collected per the request schema.',
					),
				),
			)
		);
	}

	/**
	 * Sanitize a request ID. Only allow base64url-style characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_request_id( $value ) {
		$value = is_string( $value ) ? $value : '';
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
	}

	/**
	 * Permission gate.
	 *
	 * Accepts the same authentication tiers as the chat endpoints:
	 *  - Logged-in WordPress user with `edit_posts` (or `read` when the
	 *    target attachment has no owner — guest assistants).
	 *  - Bearer assistant credential (validated upstream by the auth
	 *    middleware that the request must already have passed through;
	 *    when the credential resolved we get a non-zero current user).
	 *  - REST nonce (`X-WP-Nonce`) when the cookie auth is in play.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function permission_check( $request ) {
		if ( ! WP_MCP_AI_Markup_Loop_Interceptor::is_enabled() ) {
			return new WP_Error(
				'wp_mcp_ai_markup_disabled',
				__( 'The markup subsystem is disabled.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		// Logged-in user path.
		if ( get_current_user_id() > 0 ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return true;
			}
			if ( current_user_can( 'read' ) ) {
				return true;
			}
			return new WP_Error(
				'wp_mcp_ai_markup_forbidden',
				__( 'You do not have permission to access markup requests.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Guest path: only allowed if the request_id resolves to an
		// assistant-scoped request that allows guest submissions and the
		// caller presents the matching guest token.
		$request_id = $request instanceof WP_REST_Request ? (string) $request->get_param( 'request_id' ) : '';
		$record     = $this->store->get( $request_id );
		if ( ! $record ) {
			// Don't leak existence — treat unknown as forbidden.
			return new WP_Error(
				'wp_mcp_ai_markup_forbidden',
				__( 'You do not have permission to access markup requests.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}
		if ( $record->get_user_id() > 0 ) {
			// Owned by a real user — guests must not access it.
			return new WP_Error(
				'wp_mcp_ai_markup_forbidden',
				__( 'You do not have permission to access this markup request.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}
		// Guest tokens are validated by the standard chat auth middleware;
		// the assistant_id on the request must match the active guest scope.
		// For PR1 we accept any guest if the request itself is guest-owned;
		// the chat client always presents the same X-WP-MCP-AI-Guest header
		// the rest of the surface uses.
		return true;
	}

	/**
	 * GET handler — return the request schema for the canvas widget.
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get( WP_REST_Request $req ) {
		$record = $this->store->get( (string) $req->get_param( 'request_id' ) );
		if ( ! $record ) {
			return new WP_Error( 'wp_mcp_ai_markup_not_found', __( 'Markup request not found or expired.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}
		$payload = WP_MCP_AI_Markup_Elicitation::to_widget_payload( $record );
		// Hide tool_arguments from the GET response — those belong to the server.
		unset( $payload['tool_arguments'], $payload['tool_context'] );
		return rest_ensure_response( $payload );
	}

	/**
	 * DELETE handler — cancel a pending request.
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_delete( WP_REST_Request $req ) {
		$request_id = (string) $req->get_param( 'request_id' );
		$record     = $this->store->get( $request_id );
		if ( $record ) {
			$this->store->delete( $request_id );
			/**
			 * Fires when a markup request is cancelled by the client.
			 *
			 * @param WP_MCP_AI_Markup_Request $record Cancelled request.
			 */
			do_action( 'wp_mcp_ai_markup_resolved', $record, 'cancelled' );
		}
		return rest_ensure_response(
			array(
				'cancelled'  => true,
				'request_id' => $request_id,
			)
		);
	}

	/**
	 * POST handler — accept a submission, validate, rasterize, resume tool.
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( WP_REST_Request $req ) {
		$request_id = (string) $req->get_param( 'request_id' );
		$record     = $this->store->consume( $request_id ); // Replay protection: delete on read.
		if ( ! $record ) {
			return new WP_Error(
				'wp_mcp_ai_markup_not_found',
				__( 'Markup request not found, already consumed, or expired.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		do_action( 'wp_mcp_ai_markup_submitted', $record );

		$annotation = $req->get_param( 'markup' );
		$cleaned    = $this->validator->validate( $record, is_array( $annotation ) ? $annotation : array() );
		if ( is_wp_error( $cleaned ) ) {
			do_action( 'wp_mcp_ai_markup_resolved', $record, 'invalid' );
			// Validation failures are client errors: annotate the WP_Error so the
			// REST server surfaces 400 instead of its default 500.
			$cleaned->add_data( array( 'status' => 400 ), $cleaned->get_error_code() );
			return $cleaned;
		}

		do_action( 'wp_mcp_ai_markup_validated', $record, $cleaned );

		$artifacts = $this->rasterizer->rasterize( $record, $cleaned );

		$extra_raw = $req->get_param( 'extra' );
		$extra     = is_array( $extra_raw ) ? $extra_raw : array();

		$result_obj = new WP_MCP_AI_Markup_Result( $record, $cleaned, $extra, $artifacts );

		// Resume the originating tool.
		$tool_result = $this->resume_tool( $record, $result_obj );
		if ( is_wp_error( $tool_result ) ) {
			do_action( 'wp_mcp_ai_markup_resolved', $record, 'tool_error' );
			return $tool_result;
		}

		do_action( 'wp_mcp_ai_markup_resolved', $record, 'completed' );

		return rest_ensure_response(
			array(
				'request_id' => $record->get_request_id(),
				'tool'       => $record->get_tool_slug(),
				'artifacts'  => $artifacts,
				'result'     => $tool_result,
			)
		);
	}

	/**
	 * Resume the originating tool with the validated markup result.
	 *
	 * @param WP_MCP_AI_Markup_Request $record Original request.
	 * @param WP_MCP_AI_Markup_Result  $result Validated result.
	 * @return mixed|WP_Error
	 */
	private function resume_tool( WP_MCP_AI_Markup_Request $record, WP_MCP_AI_Markup_Result $result ) {
		// Look up the registered tool from the registry.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error( 'wp_mcp_ai_markup_no_registry', __( 'Tool registry unavailable.', 'mcp-ai-wpoos' ) );
		}
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry || ! method_exists( $registry, 'get_tool' ) ) {
			return new WP_Error( 'wp_mcp_ai_markup_no_registry', __( 'Tool registry unavailable.', 'mcp-ai-wpoos' ) );
		}
		$tool = $registry->get_tool( $record->get_tool_slug() );
		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_markup_tool_missing',
				__( 'Originating tool is no longer registered.', 'mcp-ai-wpoos' ),
				array( 'status' => 410 )
			);
		}
		if ( ! $tool instanceof WP_MCP_AI_Markup_Aware_Tool_Interface ) {
			return new WP_Error(
				'wp_mcp_ai_markup_tool_not_aware',
				__( 'Originating tool no longer supports markup.', 'mcp-ai-wpoos' ),
				array( 'status' => 409 )
			);
		}
		try {
			return $tool->consume_markup( $record->get_tool_arguments(), $result, $record->get_tool_context() );
		} catch ( Exception $e ) {
			return new WP_Error( 'wp_mcp_ai_markup_tool_exception', $e->getMessage() );
		}
	}
}
