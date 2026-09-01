<?php
/**
 * Chat compat REST controller for the Content Graph AI addon.
 *
 * Ports the base plugin's `mcp-ai/v1/chat` route surface
 * (`includes/rest/class-wp-mcp-ai-rest-chat-controller.php`
 * `/chat` route + `get_chat_endpoint_args()`) (behaviour-preserving;
 * base copies retained permanently — ecosystem port plan D-NOBASE).
 * Route paths/methods, the declared args (assistant_id / messages /
 * options / professional_prompt), and the messages-array validation
 * rules keep their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - Auth is CG-AI's own (`edit_posts`). Token/guest scoping stays with
 *   the base hub in monolith installs.
 * - The handler translates the base's nested `options` envelope
 *   (provider/model/temperature/stream/response_format) into the flat
 *   CG-AI chat params and delegates to `ChatController::handleChat()`
 *   (composition) — the response envelope (`success` / `data` /
 *   `tool_results` / `iterations` / `cost` / `cache_metadata`) already
 *   matches the base's.
 * - `assistant_id` is accepted for wire compatibility but not consulted
 *   (CG-AI has no assistant runtime yet — D-Assistants gap).
 * - `options.response_format` is accepted but not applied (the CG-AI
 *   orchestrator has no structured-output path yet).
 * - `professional_prompt` is accepted and sanitized but not applied
 *   (the profession runtime is a Pro feature that has not ported).
 * - Message `content` parts (arrays) are JSON-encoded for the CG-AI
 *   providers; image/file part rendering is a tracked gap.
 * - `GET /mcp-ai/v1/chat` (the base's SSE handshake) answers the
 *   documented `wp_mcp_ai_sse_chat_deferred` error (501) — streaming
 *   chat is available via POST with `options.stream = true`.
 * - `registerRoutes()` is called standalone-only by `Plugin.php` — the
 *   base plugin owns the same route in monolith installs.
 * - Provider errors surface inside the success envelope (`success: true`
 *   with `data.code`) because the pre-existing `/ai/chat` path wraps
 *   orchestrator-normalized errors that way; the compat route reproduces
 *   that behaviour byte-for-byte (tracked CG-AI quirk, not a D5d
 *   deviation).
 *
 * @package NvoosContentGraphAi\Rest
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the base-compatible chat route.
 *
 * @since 1.1.0
 */
class ChatCompatController {

	/**
	 * REST namespace (byte-identical to the base plugin).
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Chat controller used for the chat delegation (composition).
	 *
	 * @var ChatController
	 */
	private $chat_controller;

	/**
	 * Constructor.
	 *
	 * @param ChatController|null $chat_controller Chat controller (injectable for tests).
	 */
	public function __construct( ?ChatController $chat_controller = null ) {
		$this->chat_controller = $chat_controller ?: new ChatController();
	}

	/**
	 * Register the chat compat routes.
	 *
	 * Route paths, methods, permission wiring, and POST args are
	 * byte-identical to the base plugin's chat controller.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_request' ),
					'args'                => $this->get_chat_endpoint_args(),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_get_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant to use for SSE handshake.', 'nvoos-content-graph-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Chat endpoint arguments (byte-identical to the base).
	 *
	 * @return array Endpoint arguments.
	 */
	protected function get_chat_endpoint_args() {
		return array(
			'assistant_id'        => array(
				'description'       => __( 'ID of the assistant to use for this chat. Can be an integer assistant ID or a string like "profession_123" for profession testing. Defaults to the site default assistant.', 'nvoos-content-graph-ai' ),
				'type'              => array( 'integer', 'string' ),
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'messages'            => array(
				'description'       => __( 'Array of message objects with role and content.', 'nvoos-content-graph-ai' ),
				'type'              => 'array',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_messages_array' ),
			),
			// NOTE: no 'attachments' arg is declared here (byte-identical to
			// the base): attachments are embedded in message content segments
			// and undeclared params are ignored by the REST layer.
			'options'             => array(
				'description' => __( 'Optional request options to override assistant defaults.', 'nvoos-content-graph-ai' ),
				'type'        => 'object',
				'required'    => false,
				'properties'  => array(
					'provider'        => array(
						'type' => 'string',
					),
					'model'           => array(
						'type' => 'string',
					),
					// Out-of-range temperatures are intentionally NOT rejected
					// at the schema layer: the adapter clamps values into
					// [0, 2] (base clamp contract).
					'temperature'     => array(
						'type' => 'number',
					),
					'stream'          => array(
						'type' => 'boolean',
					),
					'response_format' => array(
						'description' => __( 'Response format configuration (e.g., for JSON mode).', 'nvoos-content-graph-ai' ),
						'type'        => 'object',
						'properties'  => array(
							'type'        => array(
								'type' => 'string',
								'enum' => array( 'text', 'json_object', 'json_schema' ),
							),
							'json_schema' => array(
								'type' => 'object',
							),
						),
					),
				),
			),
			'professional_prompt' => array(
				'description'       => __( 'Optional professional role prompt to prepend to the system prompt. Used when a professional is dynamically selected via professional selector.', 'nvoos-content-graph-ai' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Permission check for the chat compat routes.
	 *
	 * Guest tokens (X-WP-MCP-AI-Guest) are honoured first — a valid token
	 * scoped to the request's assistant grants access to public chat
	 * surfaces (base permissions_check semantics). Otherwise the standard
	 * `edit_posts` capability applies.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check( \WP_REST_Request $request ) {
		// Guest token access (scoped to the request's assistant when given).
		$guest_assistant = \NvoosContentGraphAi\Chat\GuestToken::validate_request_guest_access(
			$request,
			absint( $request->get_param( 'assistant_id' ) )
		);

		if ( false !== $guest_assistant ) {
			return true;
		}

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
	 * Validate the messages array structure (base validator rules).
	 *
	 * @param mixed           $value   The messages array to validate.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_messages_array( $value, $request, $param ) {
		unset( $request );

		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( 'The "%s" parameter must be an array.', 'nvoos-content-graph-ai' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'The "messages" array cannot be empty. At least one message is required.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_messages' => __( 'Include at least one message object with "role" and "content" properties.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		foreach ( $value as $index => $message ) {
			if ( ! is_array( $message ) ) {
				return new \WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d must be an object/array.', 'nvoos-content-graph-ai' ),
						$index
					),
					array( 'status' => 400 )
				);
			}

			// Validate role field.
			if ( ! isset( $message['role'] ) ) {
				return new \WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d is missing required "role" property.', 'nvoos-content-graph-ai' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_role' => __( 'Each message must include a "role" property with one of: "system", "user", "assistant", or "tool".', 'nvoos-content-graph-ai' ),
						),
					)
				);
			}

			// Role VALUES are intentionally not enforced here (base parity):
			// semantic role validation lives in the sanitize layer.
			$role = $message['role'];

			// Validate content field (required for most roles).
			if ( ! isset( $message['content'] ) && 'assistant' !== $role ) {
				return new \WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d is missing required "content" property.', 'nvoos-content-graph-ai' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_content' => __( 'Each message must include a "content" property (string or array of content parts).', 'nvoos-content-graph-ai' ),
						),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Handle /chat request (MCP remote clients).
	 *
	 * Translates the base's request envelope into the CG-AI chat params
	 * and delegates to `ChatController::handleChat()`.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_request( \WP_REST_Request $request ) {
		$messages = $request->get_param( 'messages' );

		// Entry-gate defence (the route arg gate handles this at dispatch
		// time; this guards direct invocation).
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'The "messages" array cannot be empty. At least one message is required.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$delegate_request = $this->translate_request( $request );

		return $this->chat_controller->handleChat( $delegate_request );
	}

	/**
	 * Translate the base chat request envelope into the CG-AI chat params.
	 *
	 * Public so the characterization tests can exercise the mapping
	 * without invoking a provider.
	 *
	 * @param WP_REST_Request $request Base-shaped chat request.
	 * @return WP_REST_Request Delegate request for ChatController::handleChat().
	 */
	public function translate_request( \WP_REST_Request $request ): \WP_REST_Request {
		$options    = $request->get_param( 'options' );
		$options    = is_array( $options ) ? $options : array();
		$messages   = $this->normalize_messages_for_delegate( $request->get_param( 'messages' ) );
		$delegate   = new \WP_REST_Request( 'POST', '/nvoos-content-graph/v1/ai/chat' );
		$delegate->set_param( 'messages', $this->chat_controller->sanitizeMessages( $messages ) );

		// Fixed delegate defaults — the delegate route's declared defaults
		// only apply at REST dispatch, not on hand-built requests.
		$delegate->set_param( 'provider', '' );
		$delegate->set_param( 'model', '' );
		$delegate->set_param( 'temperature', null );
		$delegate->set_param( 'tools', array() );
		$delegate->set_param( 'stream', false );

		if ( isset( $options['provider'] ) && is_string( $options['provider'] ) ) {
			$delegate->set_param( 'provider', sanitize_text_field( $options['provider'] ) );
		}

		if ( isset( $options['model'] ) && is_string( $options['model'] ) ) {
			$delegate->set_param( 'model', sanitize_text_field( $options['model'] ) );
		}

		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			// Base clamp contract: out-of-range temperatures clamp into
			// [0, 2] instead of being rejected.
			$temperature = max( 0.0, min( 2.0, (float) $options['temperature'] ) );
			$delegate->set_param( 'temperature', $temperature );
		}

		if ( isset( $options['stream'] ) ) {
			$delegate->set_param( 'stream', (bool) $options['stream'] );
		}

		// Fixed delegate defaults (CG-AI settings drive the system prompt;
		// graph context retrieval stays opt-in like /ai/chat).
		$delegate->set_param( 'system_prompt', true );
		$delegate->set_param( 'include_context', false );
		$delegate->set_param( 'cache_system_prompt', false );

		// assistant_id / professional_prompt / options.response_format are
		// accepted for wire compatibility but not consulted (documented in
		// the class docblock).

		return $delegate;
	}

	/**
	 * Normalize message content to strings for the CG-AI providers.
	 *
	 * Content-part arrays (input_text / input_image / input_file segments)
	 * are JSON-encoded; image/file part rendering is a tracked gap.
	 *
	 * @param array $messages Raw messages.
	 * @return array
	 */
	protected function normalize_messages_for_delegate( array $messages ): array {
		$normalized = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
				$message['content'] = (string) \wp_json_encode( $message['content'] );
			}

			$normalized[] = $message;
		}

		return $normalized;
	}

	/**
	 * Handle GET /chat (the base's SSE handshake).
	 *
	 * SSE chat handshakes are deferred; streaming chat is available via
	 * POST with `options.stream = true` (documented gap).
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_Error
	 */
	public function handle_chat_get_request( \WP_REST_Request $request ) {
		unset( $request );

		return new \WP_Error(
			'wp_mcp_ai_sse_chat_deferred',
			__( 'SSE chat handshakes are not available in the content graph AI addon yet. Use POST /mcp-ai/v1/chat with options.stream = true for streaming responses.', 'nvoos-content-graph-ai' ),
			array(
				'status'  => 501,
				'actions' => array(
					'use_stream_option' => __( 'Send your chat as POST /mcp-ai/v1/chat with "options": { "stream": true }.', 'nvoos-content-graph-ai' ),
				),
			)
		);
	}
}
