<?php
/**
 * REST controller — chat-client ⇄ memory bridge.
 *
 * Exposes a minimal, permission-aware proxy under `/mcp-ai/v1/chat-memory/*`
 * so the chat client can call `wake_up_context`, `recall_memory`,
 * `store_agent_context`, and `manage_context_lifecycle` without having
 * generic tool-execution permission. Each route runs the authenticated
 * permission check, then delegates to the existing tool implementation.
 *
 * Routes:
 *  - GET    /chat-memory/preferences           Read per-user toggles.
 *  - POST   /chat-memory/preferences           Update per-user toggles.
 *  - GET    /chat-memory/wake-up               Build a wake-up system block.
 *  - GET    /chat-memory/recall                Hierarchical recall (wing/room/query).
 *  - POST   /chat-memory/store                 Store a verbatim user-driven memory.
 *  - GET    /chat-memory/audit                 Read-only audit-log feed (drawer Audit tab).
 *  - GET    /chat-memory/sessions/{session_id} Read-only session replay feed (drawer Session Replay tab).
 *  - PUT    /chat-memory/(?P<context_id>...)   Update an existing memory.
 *  - DELETE /chat-memory/(?P<context_id>...)   Delete an existing memory.
 *
 * A site-wide kill-switch is exposed through the
 * `wp_mcp_ai_chat_memory_enabled` filter so site owners can disable the
 * surface entirely (e.g. for hardened deployments). The per-user
 * "Use long-term memory" toggle (user meta `wp_mcp_ai_chat_memory_enabled`)
 * gates the same surface for end users.
 *
 * @package WP_MCP_AI
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the chat-client ⇄ memory bridge.
 */
class WP_MCP_AI_REST_Chat_Memory_Controller extends WP_MCP_AI_REST_Controller_Base {

	/**
	 * User-meta key for the per-user "Use long-term memory" toggle.
	 */
	const USER_META_ENABLED = 'wp_mcp_ai_chat_memory_enabled';

	/**
	 * User-meta key for the per-user "Auto-summarize this chat into memory" toggle.
	 */
	const USER_META_AUTOSUMMARIZE = 'wp_mcp_ai_chat_memory_autosummarize';

	/**
	 * Default per-user values (opt-in, no auto-summarisation).
	 *
	 * @var array<string,bool>
	 */
	const DEFAULT_PREFERENCES = array(
		'enabled'       => true,
		'autosummarize' => false,
	);

	/**
	 * Minimum content length, in bytes, before LLM summarisation is attempted.
	 * Below this, the round-trip costs more tokens than it would save.
	 */
	const SUMMARIZE_MIN_INPUT_BYTES = 200;

	/**
	 * Hard cap on input size sent to the OpenAI summarisation endpoint.
	 * Defends against malicious callers running up an unbounded token bill.
	 */
	const SUMMARIZE_MAX_INPUT_BYTES = 16384;

	/**
	 * Apply the site-wide `enable_chat_memory` admin setting.
	 *
	 * Hooked to `wp_mcp_ai_chat_memory_enabled` at priority 5. When the admin
	 * has disabled the feature via Orchestration → Settings, this overrides
	 * the per-user preference and returns `false` for every user.
	 *
	 * @param bool $enabled Per-user enabled state.
	 * @return bool
	 */
	public static function apply_site_setting( $enabled ) {
		return $enabled && (bool) WP_MCP_AI_Settings_Registry::get_setting( 'enable_chat_memory', true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register_routes() {
		if ( ! has_filter( 'wp_mcp_ai_chat_memory_enabled', array( 'WP_MCP_AI_REST_Chat_Memory_Controller', 'apply_site_setting' ) ) ) {
			add_filter( 'wp_mcp_ai_chat_memory_enabled', array( 'WP_MCP_AI_REST_Chat_Memory_Controller', 'apply_site_setting' ), 5 );
		}
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/preferences',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_logged_in' ),
					'callback'            => array( $this, 'handle_get_preferences' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_logged_in' ),
					'callback'            => array( $this, 'handle_update_preferences' ),
					'args'                => array(
						'enabled'       => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'autosummarize' => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/wake-up',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_logged_in' ),
					'callback'            => array( $this, 'handle_wake_up' ),
					'args'                => $this->common_scope_args(),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/recall',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_logged_in' ),
					'callback'            => array( $this, 'handle_recall' ),
					'args'                => array_merge(
						$this->common_scope_args(),
						array(
							'query' => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'limit' => array(
								'type'              => 'integer',
								'required'          => false,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/store',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_can_write' ),
					'callback'            => array( $this, 'handle_store' ),
					'args'                => array_merge(
						$this->common_scope_args(),
						array(
							'title'        => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'content'      => array(
								'type'     => 'string',
								'required' => true,
							),
							'context_type' => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_key',
							),
							'importance'   => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_key',
								'enum'              => array( 'low', 'medium', 'high', 'critical' ),
							),
							'tags'         => array(
								'type'     => 'array',
								'required' => false,
								'items'    => array( 'type' => 'string' ),
							),
							'verbatim'     => array(
								'type'     => 'boolean',
								'required' => false,
								'default'  => true,
							),
							'summarize'    => array(
								'type'     => 'boolean',
								'required' => false,
								'default'  => false,
							),
						)
					),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/audit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_logged_in' ),
					'callback'            => array( $this, 'handle_audit' ),
					'args'                => array(
						'agent_id'    => array(
							'type'     => array( 'integer', 'string' ),
							'required' => false,
						),
						'limit'       => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'action_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'enum'              => array( 'create', 'update', 'delete', 'access' ),
						),
					),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/sessions/(?P<session_id>[a-zA-Z0-9_\-]{1,64})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_logged_in' ),
					'callback'            => array( $this, 'handle_session_replay' ),
					'args'                => array(
						'session_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_session_id' ),
						),
						'limit'      => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-memory/(?P<context_id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => array( $this, 'permissions_check_can_write' ),
					'callback'            => array( $this, 'handle_update' ),
					'args'                => array(
						'context_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_context_id' ),
						),
						'agent_id'   => array(
							'type'     => array( 'integer', 'string' ),
							'required' => false,
						),
						'title'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'content'    => array(
							'type'     => 'string',
							'required' => false,
						),
						'tags'       => array(
							'type'     => 'array',
							'required' => false,
							'items'    => array( 'type' => 'string' ),
						),
						'importance' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'enum'              => array( 'low', 'medium', 'high', 'critical' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => array( $this, 'permissions_check_can_write' ),
					'callback'            => array( $this, 'handle_delete' ),
					'args'                => array(
						'context_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_context_id' ),
						),
						'agent_id'   => array(
							'type'     => array( 'integer', 'string' ),
							'required' => false,
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Common scope args (assistant_id / agent_id / wing / room).
	 *
	 * @return array
	 */
	protected function common_scope_args() {
		return array(
			'agent_id' => array(
				'type'     => array( 'integer', 'string' ),
				'required' => false,
			),
			'wing'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'room'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Sanitize a context ID (`ctx_<hex>` shape).
	 *
	 * @param mixed $value Raw value.
	 * @return string Sanitized value.
	 */
	public function sanitize_context_id( $value ) {
		$value = is_string( $value ) ? $value : '';
		// Allow alphanumerics, underscore, dash; everything else is dropped.
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );
	}

	/**
	 * Sanitize a session ID (`[A-Za-z0-9_-]{1,64}` shape).
	 *
	 * @param mixed $value Raw value.
	 * @return string Sanitized value.
	 */
	public function sanitize_session_id( $value ) {
		$value = is_string( $value ) ? $value : '';
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );
	}

	/**
	 * Permission callback — must be logged in (no guests) and feature must be enabled.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check_logged_in( WP_REST_Request $request ) {
		$auth = $this->permissions_check_authenticated( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$user_id = $this->get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->error(
				'rest_forbidden',
				__( 'Long-term memory is disabled for guest sessions.', 'mcp-ai-wpoos' ),
				403
			);
		}

		if ( ! self::is_chat_memory_enabled( $user_id ) ) {
			return $this->error(
				'chat_memory_disabled',
				__( 'Long-term memory is disabled for this site or user.', 'mcp-ai-wpoos' ),
				403
			);
		}

		return true;
	}

	/**
	 * Permission callback for state-changing routes — also requires `edit_posts`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check_can_write( WP_REST_Request $request ) {
		$check = $this->permissions_check_logged_in( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error(
				'rest_forbidden',
				__( 'You do not have permission to write to long-term memory.', 'mcp-ai-wpoos' ),
				403
			);
		}

		return true;
	}

	/**
	 * Determine whether the chat-memory surface is enabled for the given user.
	 *
	 * Honours both the site-wide `wp_mcp_ai_chat_memory_enabled` filter and the
	 * per-user `wp_mcp_ai_chat_memory_enabled` user meta.
	 *
	 * @param int $user_id User ID. 0 means the current visitor (always disabled).
	 * @return bool
	 */
	public static function is_chat_memory_enabled( $user_id = 0 ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		$preferences = self::get_preferences( $user_id );

		/**
		 * Filter whether the chat-client memory surface is enabled.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Default enabled state (combined site + user).
		 * @param int  $user_id User being checked.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_chat_memory_enabled', $preferences['enabled'], $user_id );
	}

	/**
	 * Read per-user preferences merged with defaults.
	 *
	 * @param int $user_id User ID.
	 * @return array{enabled:bool,autosummarize:bool}
	 */
	public static function get_preferences( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return self::DEFAULT_PREFERENCES;
		}

		$enabled       = get_user_meta( $user_id, self::USER_META_ENABLED, true );
		$autosummarize = get_user_meta( $user_id, self::USER_META_AUTOSUMMARIZE, true );

		return array(
			'enabled'       => '' === $enabled ? self::DEFAULT_PREFERENCES['enabled'] : (bool) $enabled,
			'autosummarize' => '' === $autosummarize ? self::DEFAULT_PREFERENCES['autosummarize'] : (bool) $autosummarize,
		);
	}

	/**
	 * GET /chat-memory/preferences
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_get_preferences( WP_REST_Request $request ) {
		unset( $request );
		return $this->success( self::get_preferences( $this->get_current_user_id() ) );
	}

	/**
	 * POST /chat-memory/preferences
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_preferences( WP_REST_Request $request ) {
		$user_id = $this->get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->error( 'rest_forbidden', __( 'You must be logged in.', 'mcp-ai-wpoos' ), 403 );
		}

		if ( null !== $request->get_param( 'enabled' ) ) {
			update_user_meta( $user_id, self::USER_META_ENABLED, $request->get_param( 'enabled' ) ? 1 : 0 );
		}
		if ( null !== $request->get_param( 'autosummarize' ) ) {
			update_user_meta( $user_id, self::USER_META_AUTOSUMMARIZE, $request->get_param( 'autosummarize' ) ? 1 : 0 );
		}

		return $this->success( self::get_preferences( $user_id ) );
	}

	/**
	 * GET /chat-memory/wake-up
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_wake_up( WP_REST_Request $request ) {
		$agent_id = $this->resolve_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$args = array(
			'agent_id'        => $agent_id,
			'wing'            => (string) $request->get_param( 'wing' ),
			'room'            => (string) $request->get_param( 'room' ),
			'include_content' => true,
		);

		return $this->dispatch_tool( 'wake_up_context', $args );
	}

	/**
	 * GET /chat-memory/recall
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_recall( WP_REST_Request $request ) {
		$agent_id = $this->resolve_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$args  = array(
			'agent_id' => $agent_id,
		);
		$query = $request->get_param( 'query' );
		if ( is_string( $query ) && '' !== $query ) {
			$args['query'] = $query;
		}
		$wing = (string) $request->get_param( 'wing' );
		$room = (string) $request->get_param( 'room' );
		if ( '' !== $wing ) {
			$args['wing'] = $wing;
		}
		if ( '' !== $room ) {
			$args['room'] = $room;
		}
		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit > 0 ) {
			$args['limit'] = min( 50, $limit );
		}

		// Prefer recall_memory when available **and** a wing was supplied —
		// recall_memory hard-requires a wing (MemPalace "this client's
		// drawers" semantics). When the drawer's no-scope case sends an
		// empty wing the caller is really asking "everything we remember
		// about this agent", which is exactly what retrieve_agent_memory
		// answers. Falling through to retrieve_agent_memory also covers
		// Base builds where the hierarchical wrapper might be disabled by
		// a custom registry filter.
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$has_wing  = isset( $args['wing'] ) && '' !== $args['wing'];
		$tool_slug = ( $has_wing && $registry->get_tool( 'recall_memory' ) )
			? 'recall_memory'
			: 'retrieve_agent_memory';

		return $this->dispatch_tool( $tool_slug, $args );
	}

	/**
	 * POST /chat-memory/store
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_store( WP_REST_Request $request ) {
		$agent_id = $this->resolve_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$content = (string) $request->get_param( 'content' );
		// Strip script/style and unsafe HTML; preserve formatting that wp_kses_post allows.
		$content = trim( wp_kses_post( $content ) );
		if ( '' === $content ) {
			return $this->error( 'invalid_content', __( 'Memory content cannot be empty.', 'mcp-ai-wpoos' ), 400 );
		}

		$title = (string) $request->get_param( 'title' );
		if ( '' === $title ) {
			$snippet = trim( wp_strip_all_tags( $content ) );
			$title   = '' === $snippet ? __( 'Untitled memory', 'mcp-ai-wpoos' ) : mb_substr( $snippet, 0, 80 );
		}

		$tags = $request->get_param( 'tags' );
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}
		$tags = array_values( array_filter( array_map( 'sanitize_text_field', $tags ) ) );

		// G6 Phase 2 — optional LLM summarisation. Falls back to verbatim
		// silently on any failure (no API key, HTTP error, malformed JSON)
		// so the auto-capture path on `pagehide` never loses data.
		$summarize        = (bool) $request->get_param( 'summarize' );
		$summary_metadata = null;
		$original_length  = strlen( $content );
		if ( $summarize ) {
			$summary = $this->maybe_summarize_content( $content );
			if ( is_array( $summary ) && ! empty( $summary['text'] ) ) {
				$content = $summary['text'];
				if ( ! in_array( 'summarized', $tags, true ) ) {
					$tags[] = 'summarized';
				}
				$summary_metadata = array(
					'summarized'      => true,
					'original_length' => $original_length,
					'summary_length'  => strlen( $summary['text'] ),
					'model'           => isset( $summary['model'] ) ? (string) $summary['model'] : '',
				);
			}
		}

		$context_data = array(
			'title'      => $title,
			'content'    => $content,
			'importance' => (string) ( $request->get_param( 'importance' ) ? $request->get_param( 'importance' ) : 'medium' ),
			'tags'       => $tags,
		);
		if ( null !== $summary_metadata ) {
			$context_data['summary_metadata'] = $summary_metadata;
		}

		$request_context_type = $request->get_param( 'context_type' );
		$context_type          = (string) ( $request_context_type ? $request_context_type : 'user_note' );

		$args = array(
			'agent_id'     => $agent_id,
			'context_type' => $context_type,
			'context_data' => $context_data,
			'verbatim'     => null === $request->get_param( 'verbatim' ) ? true : (bool) $request->get_param( 'verbatim' ),
		);

		$wing = (string) $request->get_param( 'wing' );
		$room = (string) $request->get_param( 'room' );
		if ( '' !== $wing ) {
			$args['wing'] = $wing;
		}
		if ( '' !== $room ) {
			$args['room'] = $room;
		}

		return $this->dispatch_tool( 'store_agent_context', $args );
	}

	/**
	 * PUT /chat-memory/{context_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update( WP_REST_Request $request ) {
		$agent_id = $this->resolve_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$context_id = (string) $request->get_param( 'context_id' );
		if ( '' === $context_id ) {
			return $this->error( 'missing_context_id', __( 'context_id is required.', 'mcp-ai-wpoos' ), 400 );
		}

		$update = array();
		$title  = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update['title'] = (string) $title;
		}
		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			$update['content'] = trim( wp_kses_post( (string) $content ) );
		}
		$tags = $request->get_param( 'tags' );
		if ( is_array( $tags ) ) {
			$update['tags'] = array_values( array_filter( array_map( 'sanitize_text_field', $tags ) ) );
		}
		$importance = $request->get_param( 'importance' );
		if ( null !== $importance ) {
			$update['importance'] = (string) $importance;
		}

		if ( empty( $update ) ) {
			return $this->error( 'no_changes', __( 'No fields supplied for update.', 'mcp-ai-wpoos' ), 400 );
		}

		$args = array(
			'action'     => 'update',
			'agent_id'   => $agent_id,
			'context_id' => $context_id,
			'options'    => array( 'update_data' => $update ),
		);

		return $this->dispatch_tool( 'manage_context_lifecycle', $args );
	}

	/**
	 * DELETE /chat-memory/{context_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete( WP_REST_Request $request ) {
		$agent_id = $this->resolve_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$context_id = (string) $request->get_param( 'context_id' );
		if ( '' === $context_id ) {
			return $this->error( 'missing_context_id', __( 'context_id is required.', 'mcp-ai-wpoos' ), 400 );
		}

		$args = array(
			'action'     => 'delete',
			'agent_id'   => $agent_id,
			'context_id' => $context_id,
		);

		return $this->dispatch_tool( 'manage_context_lifecycle', $args );
	}

	/**
	 * GET /chat-memory/audit
	 *
	 * Read-only feed of memory audit-log entries for the active agent. Backed by
	 * the existing `memory_audit_trail` tool with `action=get_audit_log`. Used by
	 * the Memory Drawer's "Audit" tab so end users can see the most recent
	 * create/update/delete/access events on their memories without needing
	 * generic tool-execution permission.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_audit( WP_REST_Request $request ) {
		$agent_id = $this->resolve_agent_id( $request );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$options = array();

		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit > 0 ) {
			// Hard-cap to keep the payload small for the drawer.
			$options['limit'] = min( 100, $limit );
		} else {
			$options['limit'] = 25;
		}

		$action_type = (string) $request->get_param( 'action_type' );
		if ( '' !== $action_type ) {
			$options['action_type'] = $action_type;
		}

		$args = array(
			'action'   => 'get_audit_log',
			'agent_id' => $agent_id,
			'options'  => $options,
		);

		return $this->dispatch_tool( 'memory_audit_trail', $args );
	}

	/**
	 * GET /chat-memory/sessions/{session_id}
	 *
	 * Read-only replay feed of buffered per-session chat frames so the Memory
	 * Drawer can show chronology for async continuation events.
	 *
	 * @since 1.1.20
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_session_replay( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
			return $this->error(
				'session_replay_unavailable',
				__( 'Session replay is not available in this build.', 'mcp-ai-wpoos' ),
				503
			);
		}

		$session_id = WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id(
			(string) $request->get_param( 'session_id' )
		);
		if ( '' === $session_id ) {
			return $this->error(
				'invalid_session_id',
				__( 'session_id is required.', 'mcp-ai-wpoos' ),
				400
			);
		}

		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 100;
		}
		$limit = min( 200, $limit );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session_id, 0 );
		if ( ! is_array( $frames ) ) {
			$frames = array();
		}

		usort(
			$frames,
			static function ( $a, $b ) {
				$aid = isset( $a['id'] ) ? (int) $a['id'] : 0;
				$bid = isset( $b['id'] ) ? (int) $b['id'] : 0;
				return $aid - $bid;
			}
		);

		if ( count( $frames ) > $limit ) {
			$frames = array_slice( $frames, -$limit );
		}

		$events = array();
		foreach ( $frames as $frame ) {
			if ( ! is_array( $frame ) ) {
				continue;
			}
			$events[] = array(
				'id'        => isset( $frame['id'] ) ? (int) $frame['id'] : 0,
				'event'     => isset( $frame['event'] ) ? sanitize_text_field( (string) $frame['event'] ) : '',
				'timestamp' => isset( $frame['ts'] ) ? gmdate( 'c', (int) $frame['ts'] ) : '',
				'data'      => isset( $frame['data'] ) && is_array( $frame['data'] ) ? $frame['data'] : array(),
			);
		}

		return $this->success(
			array(
				'session_id'    => $session_id,
				'events'        => $events,
				'total_events'  => count( $events ),
				'latest_event'  => WP_MCP_AI_Chat_Session_Frame_Buffer::latest_id( $session_id ),
				'requested_max' => $limit,
			)
		);
	}


	/**
	 * Resolve the agent_id for the request. Falls back to the assistant's post ID
	 * or, when missing, the current user ID prefixed with `user_`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|string|WP_Error
	 */
	protected function resolve_agent_id( WP_REST_Request $request ) {
		$agent_id = $request->get_param( 'agent_id' );
		if ( is_numeric( $agent_id ) ) {
			$agent_id = absint( $agent_id );
			if ( $agent_id > 0 ) {
				return $agent_id;
			}
		}
		if ( is_string( $agent_id ) && '' !== $agent_id ) {
			$sanitised = sanitize_text_field( $agent_id );
			if ( '' !== $sanitised ) {
				return $sanitised;
			}
		}

		$user_id = $this->get_current_user_id();
		if ( $user_id > 0 ) {
			return 'user_' . $user_id;
		}

		return $this->error( 'missing_agent_id', __( 'agent_id is required.', 'mcp-ai-wpoos' ), 400 );
	}

	/**
	 * Run a registered tool and adapt its result to a REST response.
	 *
	 * @param string $slug Tool slug.
	 * @param array  $args Tool arguments.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function dispatch_tool( $slug, array $args ) {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $slug );
		if ( ! $tool ) {
			return $this->error( 'tool_unavailable', sprintf( /* translators: %s: tool slug */ __( 'Required memory tool "%s" is not registered.', 'mcp-ai-wpoos' ), $slug ), 503 );
		}

		try {
			$result = $tool->execute(
				$args,
				array(
					'user_id' => $this->get_current_user_id(),
					'source'  => 'chat-memory-rest',
				)
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'tool_execution_failed', $e->getMessage(), 500 );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] ) {
			$message = isset( $result['message'] ) ? (string) $result['message'] : __( 'Memory tool reported failure.', 'mcp-ai-wpoos' );
			return $this->error( 'tool_failed', $message, 400, isset( $result['actions'] ) ? (array) $result['actions'] : array() );
		}

		return $this->success( is_array( $result ) ? $result : array( 'result' => $result ) );
	}

	/**
	 * Summarise the given content using the configured OpenAI key.
	 *
	 * Used by `handle_store()` (G6 Phase 2) when the caller passes
	 * `summarize=true`. Designed to be a graceful enhancement: any failure
	 * (missing API key, content too short, HTTP error, malformed JSON,
	 * timeout) returns `null` so the calling code can fall through to
	 * verbatim storage and never lose the user's transcript.
	 *
	 * The input is hard-capped at {@see self::SUMMARIZE_MAX_INPUT_BYTES}
	 * before sending so a malicious caller can't run up an arbitrary token
	 * bill on a single request.
	 *
	 * @since 1.1.14
	 *
	 * @param string $content Raw memory content (already kses-sanitised).
	 * @return array<string,string>|null `array{ text:string, model:string }` on success, `null` on any failure.
	 */
	protected function maybe_summarize_content( $content ) {
		// Don't bother summarising trivially short content — round-trip
		// would cost more tokens than it saves.
		if ( strlen( $content ) < self::SUMMARIZE_MIN_INPUT_BYTES ) {
			return null;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = is_array( $settings ) && isset( $settings['openai_api_key'] ) ? (string) $settings['openai_api_key'] : '';
		if ( '' === $api_key ) {
			return null;
		}

		// Cap input so a single beacon can't trigger an unbounded token bill.
		// Use mb_strcut() so we don't split a multi-byte UTF-8 character.
		if ( strlen( $content ) > self::SUMMARIZE_MAX_INPUT_BYTES ) {
			if ( function_exists( 'mb_strcut' ) ) {
				$content = mb_strcut( $content, 0, self::SUMMARIZE_MAX_INPUT_BYTES, 'UTF-8' );
			} else {
				$content = substr( $content, 0, self::SUMMARIZE_MAX_INPUT_BYTES );
			}
		}

		$model = 'gpt-4o-mini';
		$body  = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You summarise chat transcripts into a single concise paragraph (max 4 sentences) capturing the key topic, decisions, and any actionable follow-ups. Plain text only — no markdown, no headings.',
				),
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'temperature' => 0.3,
			'max_tokens'  => 300,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, gated by WP_DEBUG.
				error_log( '[NV oOS Chat Memory] Summarisation HTTP error: ' . $response->get_error_message() );
			}
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['choices'][0]['message']['content'] ) ) {
			return null;
		}

		$summary = trim( (string) $decoded['choices'][0]['message']['content'] );
		if ( '' === $summary ) {
			return null;
		}

		return array(
			'text'  => $summary,
			'model' => isset( $decoded['model'] ) ? (string) $decoded['model'] : $model,
		);
	}
}
