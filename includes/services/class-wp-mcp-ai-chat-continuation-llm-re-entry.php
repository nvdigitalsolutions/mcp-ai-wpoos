<?php
/**
 * Chat Continuation — LLM Re-Entry.
 *
 * Listens to `wp_mcp_ai_chat_continuation_ready` (fired by the dispatcher's
 * cron worker after the tool-result message has been appended to the
 * conversation) and:
 *
 *  1. Calls the language model with the updated messages array.
 *  2. Extracts the assistant's text response.
 *  3. Pushes a `chat:resumed` frame to the session's frame buffer so the
 *     chat-session SSE channel can deliver it in real-time (or on reconnect).
 *  4. Fires `wp_mcp_ai_after_chat_response` and the `_dispatched` + `_resumed`
 *     OTel hooks for observability parity with the live chat path.
 *
 * This class is intentionally thin — it does not persist the transcript
 * (that can be added in a follow-up slice once the REST extract for the
 * transcript recorder is finalized) and it does not attempt to run a full
 * agentic loop iteration (deferred to avoid recursive async queuing until
 * the agentic-loop re-entry slice is production-tested).
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

if ( ! class_exists( 'WP_MCP_AI_Chat_Continuation_LLM_Re_Entry' ) ) {
	/**
	 * Drives the LLM re-engagement after an async tool finishes.
	 */
	class WP_MCP_AI_Chat_Continuation_LLM_Re_Entry {

		/**
		 * Register the hook listener.  Idempotent.
		 */
		public static function init() {
			static $initialized = false;
			if ( $initialized ) {
				return;
			}
			$initialized = true;

			// Priority 10 — default; runs after dispatcher fires the ready action.
			add_action( 'wp_mcp_ai_chat_continuation_ready', array( __CLASS__, 'on_continuation_ready' ), 10, 3 );
		}

		/**
		 * Reset for tests.
		 *
		 * @internal PHPUnit only.
		 */
		public static function reset_for_tests() {
			remove_action( 'wp_mcp_ai_chat_continuation_ready', array( __CLASS__, 'on_continuation_ready' ), 10 );
		}

		/**
		 * Handler for `wp_mcp_ai_chat_continuation_ready`.
		 *
		 * @param array  $snapshot        Continuation snapshot (messages already include tool result).
		 * @param string $terminal_status One of completed|failed|cancelled.
		 * @param array  $terminal_result Job result data.
		 *
		 * @return bool True on success, false on any bail.
		 */
		public static function on_continuation_ready( $snapshot, $terminal_status, $terminal_result ) {
			if ( ! is_array( $snapshot ) ) {
				return false;
			}

			// Defer LLM call on failed / cancelled — push a slim notification
			// frame so the client can render a meaningful status message instead
			// of seeing nothing.
			if ( 'completed' !== $terminal_status ) {
				return self::push_non_success_frame( $snapshot, $terminal_status );
			}

			$messages = isset( $snapshot['messages'] ) && is_array( $snapshot['messages'] )
				? $snapshot['messages']
				: array();

			if ( empty( $messages ) ) {
				return false;
			}

			$options      = isset( $snapshot['options'] ) && is_array( $snapshot['options'] )
				? $snapshot['options']
				: array();
			$assistant_id = isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0;
			$session_id   = isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '';
			$job_id       = isset( $snapshot['job_id'] ) ? (string) $snapshot['job_id'] : '';
			$tool_call_id = isset( $snapshot['tool_call_id'] ) ? (string) $snapshot['tool_call_id'] : '';

			// Ensure provider/model are in options so the router picks the right client.
			if ( isset( $snapshot['provider'] ) && ! isset( $options['provider'] ) ) {
				$options['provider'] = (string) $snapshot['provider'];
			}
			if ( isset( $snapshot['model'] ) && ! isset( $options['model'] ) ) {
				$options['model'] = (string) $snapshot['model'];
			}

			// Call the language model.
			$llm_response = self::call_llm( $messages, $options, $assistant_id );

			if ( is_wp_error( $llm_response ) ) {
				return self::push_error_frame(
					$session_id,
					$job_id,
					$tool_call_id,
					$llm_response->get_error_message(),
					$snapshot
				);
			}

			$assistant_text = self::extract_text( $llm_response );

			/**
			 * Fires after the resumed assistant message is ready and before it is
			 * buffered for delivery.  Mirrors the existing `wp_mcp_ai_after_chat_response`
			 * signal so OTel / analytics hooks can observe continuations the same
			 * way they observe live chat turns.
			 *
			 * @since 1.9.4
			 *
			 * @param int   $assistant_id  Assistant identifier.
			 * @param array $llm_response  Raw LLM response array.
			 * @param null  $request       Always null for cron-driven continuations.
			 */
			do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $llm_response, null );

			// Buffer the chat:resumed frame so the SSE channel can deliver it.
			if ( '' !== $session_id && class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
				$frame_data = array(
					'session_id'      => $session_id,
					'job_id'          => $job_id,
					'tool_call_id'    => $tool_call_id,
					'assistant_id'    => $assistant_id,
					'message'         => $assistant_text,
					'terminal_status' => $terminal_status,
					'ts'              => time(),
				);

				/**
				 * Filter the outbound chat:resumed SSE frame payload before it
				 * is buffered.
				 *
				 * @since 1.9.4
				 *
				 * @param array  $frame_data      Frame data array.
				 * @param array  $snapshot        Continuation snapshot.
				 * @param array  $llm_response    Raw LLM response.
				 */
				$frame_data = apply_filters(
					'wp_mcp_ai_chat_session_stream_frame',
					$frame_data,
					$snapshot,
					$llm_response
				);

				if ( is_array( $frame_data ) ) {
					WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session_id, 'chat:resumed', $frame_data );
				}
			}

			/**
			 * Fires once the continuation has been fully driven: LLM responded,
			 * frame buffered.  OTel span `nvoos.chat.continuation.resumed`.
			 *
			 * @since 1.9.4
			 *
			 * @param string $job_id      Async job identifier.
			 * @param array  $snapshot    Continuation snapshot.
			 * @param string $message     Assistant response text.
			 */
			do_action( 'wp_mcp_ai_chat_continuation_resumed', $job_id, $snapshot, $assistant_text );

			return true;
		}

		// -----------------------------------------------------------------------
		// Private helpers
		// -----------------------------------------------------------------------

		/**
		 * Call the language model router with the updated messages.
		 *
		 * @param array $messages     Conversation messages.
		 * @param array $options      Provider options.
		 * @param int   $assistant_id Assistant identifier (for logging).
		 *
		 * @return array|WP_Error LLM response or error.
		 */
		protected static function call_llm( array $messages, array $options, $assistant_id ) {
			if ( ! function_exists( 'wp_mcp_ai_get_language_model_router' ) ) {
				return new WP_Error(
					'wp_mcp_ai_continuation_no_router',
					__( 'Language model router is not available.', 'mcp-ai-wpoos' )
				);
			}

			try {
				$router = wp_mcp_ai_get_language_model_router();
				if ( ! $router ) {
					return new WP_Error(
						'wp_mcp_ai_continuation_no_router',
						__( 'Language model router returned null.', 'mcp-ai-wpoos' )
					);
				}

				$response = $router->create_chat_completion( $messages, $options );
			} catch ( Exception $e ) {
				WP_MCP_AI_Logger::log_error(
					'chat_continuation_llm_exception',
					array(
						'assistant_id' => $assistant_id,
						'exception'    => $e->getMessage(),
					)
				);
				return new WP_Error(
					'wp_mcp_ai_continuation_llm_exception',
					$e->getMessage()
				);
			}

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'chat_continuation_llm_error',
					array(
						'assistant_id' => $assistant_id,
						'error_code'   => $response->get_error_code(),
						'error'        => $response->get_error_message(),
					)
				);
			}

			return $response;
		}

		/**
		 * Extract the assistant's text content from a raw LLM response.
		 *
		 * Handles both the OpenAI `choices[0].message.content` shape and the
		 * legacy flat `content` key used by some internal adapters.
		 *
		 * @param array $response Raw LLM response.
		 *
		 * @return string Assistant text (may be empty if the model returned nothing).
		 */
		protected static function extract_text( array $response ) {
			if ( ! empty( $response['choices'][0]['message']['content'] ) ) {
				$raw = $response['choices'][0]['message']['content'];
			} elseif ( ! empty( $response['content'] ) ) {
				$raw = $response['content'];
			} else {
				return '';
			}

			if ( is_array( $raw ) ) {
				// Anthropic-style content blocks: [ { type:text, text:'...' }, ... ].
				$parts = array();
				foreach ( $raw as $block ) {
					if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
						$parts[] = $block['text'];
					}
				}
				return implode( "\n", $parts );
			}

			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * Push a chat:tool_result frame for non-success terminal statuses.
		 *
		 * When the job failed or was cancelled, we still want the client to
		 * surface a message rather than seeing nothing.
		 *
		 * @param array  $snapshot        Continuation snapshot.
		 * @param string $terminal_status failed|cancelled.
		 *
		 * @return bool
		 */
		protected static function push_non_success_frame( array $snapshot, $terminal_status ) {
			$session_id   = isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '';
			$job_id       = isset( $snapshot['job_id'] ) ? (string) $snapshot['job_id'] : '';
			$tool_call_id = isset( $snapshot['tool_call_id'] ) ? (string) $snapshot['tool_call_id'] : '';

			if ( '' === $session_id || ! class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
				return false;
			}

			$message = 'failed' === $terminal_status
				/* translators: Job status message shown in chat when a background task fails. */
				? __( 'The background task failed to complete.', 'mcp-ai-wpoos' )
				/* translators: Job status message shown in chat when a background task is cancelled. */
				: __( 'The background task was cancelled.', 'mcp-ai-wpoos' );

			$frame_data = array(
				'session_id'      => $session_id,
				'job_id'          => $job_id,
				'tool_call_id'    => $tool_call_id,
				'assistant_id'    => isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0,
				'message'         => $message,
				'terminal_status' => $terminal_status,
				'ts'              => time(),
			);

			WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session_id, 'chat:tool_result', $frame_data );

			return true;
		}

		/**
		 * Push a chat:error frame when the LLM call itself failed.
		 *
		 * @param string $session_id   Session identifier.
		 * @param string $job_id       Job identifier.
		 * @param string $tool_call_id Tool call identifier.
		 * @param string $error_msg    Human-readable error.
		 * @param array  $snapshot     Continuation snapshot (for context).
		 *
		 * @return bool
		 */
		protected static function push_error_frame( $session_id, $job_id, $tool_call_id, $error_msg, array $snapshot ) {
			if ( '' === $session_id || ! class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
				return false;
			}

			$frame_data = array(
				'session_id'   => $session_id,
				'job_id'       => $job_id,
				'tool_call_id' => $tool_call_id,
				'assistant_id' => isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0,
				'error'        => $error_msg,
				'ts'           => time(),
			);

			WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session_id, 'chat:error', $frame_data );

			/**
			 * Fires when a continuation LLM call errors before any frame was
			 * delivered.  OTel span: `nvoos.chat.continuation.errored`.
			 *
			 * @since 1.9.4
			 *
			 * @param string $job_id    Async job identifier.
			 * @param array  $snapshot  Continuation snapshot.
			 * @param string $error_msg Error message.
			 */
			do_action( 'wp_mcp_ai_chat_continuation_errored', $job_id, $snapshot, $error_msg );

			return true;
		}
	}
}
