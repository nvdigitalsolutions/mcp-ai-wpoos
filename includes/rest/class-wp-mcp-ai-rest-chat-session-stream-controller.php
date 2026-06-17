<?php
/**
 * Chat Session Stream REST Controller.
 *
 * Provides a long-lived SSE channel scoped to a single chat session so that
 * async tool continuations can be delivered in real-time — even when the
 * original request that started the async tool has long since returned.
 *
 * Route:
 *   GET /mcp-ai/v1/chat-sessions/{session_id}/stream
 *
 * Authentication:
 *   Accepts the same authentication matrix as the /chat-client endpoint:
 *   - WordPress Nonce (X-WP-Nonce header)
 *   - Assistant credentials (Authorization: Bearer cred_...)
 *   - Guest token (X-WP-MCP-AI-Guest header)
 *
 * SSE frame types:
 *   - `chat:resumed`     — assistant continuation message after async job done
 *   - `chat:tool_result` — non-LLM result notification (failed/cancelled job)
 *   - `chat:error`       — non-recoverable error on the continuation path
 *   - `ping`             — heartbeat every 15 seconds
 *
 * Last-Event-ID support:
 *   Clients may send the `Last-Event-ID` header (or `last_event_id` query
 *   param) on reconnect.  The controller will replay all buffered frames with
 *   id > Last-Event-ID before entering the normal polling loop.
 *
 * @package WP_MCP_AI
 * @since   1.9.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_REST_Chat_Session_Stream_Controller' ) ) {
	/**
	 * REST controller for the per-session SSE stream.
	 */
	class WP_MCP_AI_REST_Chat_Session_Stream_Controller extends WP_MCP_AI_REST_Controller_Base {

		/**
		 * Seconds between poll ticks while waiting for new frames.
		 */
		const POLL_INTERVAL = 2;

		/**
		 * Number of poll ticks between heartbeat pings.
		 * Default: every 15 s (POLL_INTERVAL × PING_EVERY_N_TICKS = 15 s).
		 */
		const PING_EVERY_N_TICKS = 8;

		/**
		 * Maximum number of poll ticks per connection (keeps connections bounded).
		 * 900 ticks × 2 s = 1800 s = 30 min.
		 */
		const MAX_TICKS = 900;

		/**
		 * SSE handler for framing / flushing.
		 *
		 * @var WP_MCP_AI_SSE_Handler
		 */
		protected $sse_handler;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_REST_Authenticator|null $authenticator Optional DI authenticator.
		 * @param WP_MCP_AI_REST_Validator|null     $validator     Optional DI validator.
		 */
		public function __construct( $authenticator = null, $validator = null ) {
			parent::__construct( $authenticator, $validator );
			$this->sse_handler = new WP_MCP_AI_SSE_Handler();
		}

		/**
		 * Register REST API routes.
		 */
		public function register_routes() {
			register_rest_route(
				self::REST_NAMESPACE,
				'/chat-sessions/(?P<session_id>[a-zA-Z0-9_\-]{1,64})/stream',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_stream' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'session_id'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'last_event_id' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
		}

		/**
		 * Permission callback.
		 *
		 * Accepts nonce, bearer token, or guest token — identical to the
		 * /chat-client permission matrix.
		 *
		 * @param WP_REST_Request $request REST request.
		 *
		 * @return true|WP_Error
		 */
		public function permissions_check( WP_REST_Request $request ) {
			return $this->permissions_check_authenticated( $request );
		}

		/**
		 * Handle GET /chat-sessions/{session_id}/stream.
		 *
		 * Opens a long-lived SSE connection that:
		 * 1. Replays buffered frames with id > Last-Event-ID.
		 * 2. Polls the frame buffer for new frames every POLL_INTERVAL seconds.
		 * 3. Sends a `ping` heartbeat every PING_EVERY_N_TICKS polls.
		 *
		 * @param WP_REST_Request $request REST request.
		 *
		 * @return void Streams SSE events and exits.
		 */
		public function handle_stream( WP_REST_Request $request ) {
			$session_id = WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id(
				(string) $request->get_param( 'session_id' )
			);

			if ( '' === $session_id ) {
				$this->sse_handler->send_sse_headers();
				$this->sse_handler->send_sse_event(
					'chat:error',
					array( 'error' => __( 'Invalid session identifier.', 'mcp-ai-wpoos' ) )
				);
				$this->sse_handler->send_sse_done();
				$this->sse_handler->finish();
				return;
			}

			// Parse Last-Event-ID from header (preferred) or query param (fallback
			// for transports that strip headers).
			$last_event_id = 0;
			$header_value  = $request->get_header( 'last_event_id' );
			if ( null === $header_value && isset( $_SERVER['HTTP_LAST_EVENT_ID'] ) ) {
				$header_value = sanitize_text_field( wp_unslash( $_SERVER['HTTP_LAST_EVENT_ID'] ) );
			}
			if ( null === $header_value ) {
				$header_value = $request->get_param( 'last_event_id' );
			}
			if ( is_scalar( $header_value ) ) {
				$last_event_id = max( 0, (int) $header_value );
			}

			$this->sse_handler->send_sse_headers();

			// Extend execution time: 30 min ceiling keeps sessions bounded.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( ( self::MAX_TICKS * self::POLL_INTERVAL ) + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort.
			}
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$event_id_seq = $last_event_id;
			$tick_count   = 0;

			/**
			 * Fires when a chat-session SSE stream is established.
			 *
			 * @since 1.9.4
			 *
			 * @param string $session_id    Chat session identifier.
			 * @param int    $last_event_id Last-Event-ID from the client (0 if fresh).
			 */
			do_action( 'wp_mcp_ai_chat_session_stream_opened', $session_id, $last_event_id );

			// Replay any frames the client missed.
			$replay_frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session_id, $last_event_id );
			foreach ( $replay_frames as $frame ) {
				++$event_id_seq;
				$this->emit_frame( $frame['event'], $frame['data'], $event_id_seq );
			}

			// Main polling loop.
			while ( $tick_count < self::MAX_TICKS ) {
				if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
					break;
				}

				sleep( self::POLL_INTERVAL );
				++$tick_count;

				// Pick up any frames pushed since the last tick.
				$new_frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session_id, $event_id_seq );
				foreach ( $new_frames as $frame ) {
					++$event_id_seq;
					$this->emit_frame( $frame['event'], $frame['data'], $event_id_seq );
				}

				// Heartbeat ping.
				if ( 0 === $tick_count % self::PING_EVERY_N_TICKS ) {
					++$event_id_seq;
					$this->sse_handler->send_sse_event_with_id(
						'ping',
						array(
							'session_id' => $session_id,
							'ts'         => time(),
						),
						(string) $event_id_seq
					);
				}
			}

			$this->sse_handler->send_sse_done();

			/**
			 * Fires when a chat-session SSE stream closes (max ticks reached or
			 * connection aborted).
			 *
			 * @since 1.9.4
			 *
			 * @param string $session_id Chat session identifier.
			 * @param int    $tick_count Number of polling ticks completed.
			 */
			do_action( 'wp_mcp_ai_chat_session_stream_closed', $session_id, $tick_count );

			$this->sse_handler->finish();
		}

		// -----------------------------------------------------------------------
		// Private helpers
		// -----------------------------------------------------------------------

		/**
		 * Emit a single SSE frame with a monotonic ID.
		 *
		 * The frame data is passed through the `wp_mcp_ai_chat_session_stream_frame`
		 * filter (already applied when the frame was pushed to the buffer, but we
		 * honour a second pass here for late-binding modifications such as adding
		 * authentication tokens for signed deliveries).
		 *
		 * @param string $event    SSE event name.
		 * @param array  $data     Frame payload.
		 * @param int    $frame_id Monotonic frame ID.
		 */
		protected function emit_frame( $event, array $data, $frame_id ) {
			$this->sse_handler->send_sse_event_with_id( $event, $data, (string) $frame_id );
		}
	}
}
