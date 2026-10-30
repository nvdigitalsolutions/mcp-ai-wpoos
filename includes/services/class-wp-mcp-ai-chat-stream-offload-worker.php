<?php
/**
 * Chat stream offload worker — SSE long-run offload (Phase 4b).
 *
 * When a chat SSE stream is about to exceed proxy idle-read timeouts
 * (Cloudflare/nginx reset connections they consider idle), the REST
 * streaming path hands the remaining agentic work to a background job and
 * closes the stream cleanly (see `job_offload` in
 * `WP_MCP_AI_REST::offload_stream_to_job()`). This worker resumes the loop
 * off the request thread:
 *
 *   - restores the user context from the snapshot (tool capability checks
 *     run as the original user),
 *   - executes the pending tool calls (when the offload happened before a
 *     blocking tool),
 *   - drives the agentic loop (LLM → tool_calls → tools → LLM) bounded by
 *     the remaining iteration budget and a wall-clock cap (240 s default),
 *   - reports progress through WP_MCP_AI_Job_Notifier (the client follows
 *     the job via the existing job-stream machinery), and
 *   - delivers the final assistant message through
 *     WP_MCP_AI_Chat_Session_Frame_Buffer (`chat:resumed`) so the client's
 *     existing session-frame polling renders it.
 *
 * Failures are terminal (the job is queued with zero retries): the notifier
 * records `failed` and a `chat:error` frame lands in the session buffer.
 *
 * @package WP_MCP_AI
 * @since 1.2.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Chat_Stream_Offload_Worker' ) ) {
	/**
	 * Background executor for offloaded chat streams.
	 */
	class WP_MCP_AI_Chat_Stream_Offload_Worker {

		/**
		 * Async job queue type dispatched to this worker.
		 */
		const JOB_TYPE = 'chat_stream_continuation';

		/**
		 * Default wall-clock cap for a resumed loop, in seconds.
		 *
		 * Must stay below the proxy/stream budget the client uses to follow
		 * the job (300 s) so a terminal state always arrives in time.
		 */
		const DEFAULT_TIME_CAP_SECONDS = 240;

		/**
		 * Default heartbeat cadence for job progress updates, in seconds.
		 */
		const DEFAULT_HEARTBEAT_SECONDS = 15;

		/**
		 * Entry point called by the async job queue.
		 *
		 * @param array      $job_data Decoded queue job payload ({ continuation_key, chat_session_id, assistant_id, user_id }).
		 * @param int|string $job_id  Queue job identifier.
		 * @return array Job result ({ message, session_key }).
		 * @throws Exception Always on failure — every failure path records a
		 *                   terminal state in the notifier and session buffer
		 *                   first (see fail_terminal()), then throws so the
		 *                   queue marks the job failed (queued with zero
		 *                   retries).
		 */
		public static function run( $job_data, $job_id ) {
			$job_id           = (string) $job_id;
			$continuation_key = isset( $job_data['continuation_key'] ) ? (string) $job_data['continuation_key'] : '';

			// Fallbacks from the offload payload so terminal failures can still
			// reach the notifier and the session buffer when the snapshot
			// itself is missing or expired.
			$fallback_session_id   = isset( $job_data['chat_session_id'] ) ? (string) $job_data['chat_session_id'] : '';
			$fallback_assistant_id = isset( $job_data['assistant_id'] ) ? (int) $job_data['assistant_id'] : 0;

			if ( '' === $continuation_key ) {
				self::fail_terminal( $job_id, $fallback_session_id, $fallback_assistant_id, __( 'Chat stream offload job is missing its continuation key.', 'mcp-ai-wpoos' ) );
			}
			if ( ! class_exists( 'WP_MCP_AI_Chat_Continuation_Store' ) ) {
				self::fail_terminal( $job_id, $fallback_session_id, $fallback_assistant_id, __( 'Chat continuation store is unavailable.', 'mcp-ai-wpoos' ) );
			}

			$snapshot = WP_MCP_AI_Chat_Continuation_Store::get( $continuation_key );
			if ( ! is_array( $snapshot ) || empty( $snapshot['messages'] ) ) {
				self::fail_terminal( $job_id, $fallback_session_id, $fallback_assistant_id, __( 'Chat stream offload snapshot is missing or expired.', 'mcp-ai-wpoos' ) );
			}

			$user_id = isset( $snapshot['user_id'] ) ? (int) $snapshot['user_id'] : 0;
			if ( $user_id > 0 ) {
				wp_set_current_user( $user_id );
			}

			return self::resume_agentic_loop( $job_id, $snapshot );
		}

		/**
		 * Resume the agentic loop from the snapshot.
		 *
		 * @param string $job_id   Queue job identifier.
		 * @param array  $snapshot Continuation snapshot (normalized store row).
		 * @return array Job result.
		 * @throws Exception When the runtime dependencies are unavailable.
		 */
		protected static function resume_agentic_loop( $job_id, array $snapshot ) {
			$messages          = isset( $snapshot['messages'] ) && is_array( $snapshot['messages'] ) ? $snapshot['messages'] : array();
			$options           = isset( $snapshot['options'] ) && is_array( $snapshot['options'] ) ? $snapshot['options'] : array();
			$assistant_id      = isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0;
			$assistant_config  = isset( $snapshot['assistant_config'] ) && is_array( $snapshot['assistant_config'] ) ? $snapshot['assistant_config'] : array();
			$session_id        = isset( $snapshot['chat_session_id'] ) ? (string) $snapshot['chat_session_id'] : '';
			$user_id           = isset( $snapshot['user_id'] ) ? (int) $snapshot['user_id'] : 0;
			$iteration         = isset( $snapshot['iteration'] ) ? (int) $snapshot['iteration'] : 0;
			$max_iterations    = isset( $snapshot['max_iterations'] ) ? (int) $snapshot['max_iterations'] : 5;
			$pending_tool_call = isset( $snapshot['pending_tool_calls'] ) && is_array( $snapshot['pending_tool_calls'] ) ? $snapshot['pending_tool_calls'] : array();

			$time_cap       = (int) apply_filters( 'wp_mcp_ai_sse_offload_worker_cap_seconds', self::DEFAULT_TIME_CAP_SECONDS );
			$heartbeat      = (int) apply_filters( 'wp_mcp_ai_sse_offload_worker_heartbeat_seconds', self::DEFAULT_HEARTBEAT_SECONDS );
			$started_at     = microtime( true );
			$last_heartbeat = $started_at;

			$router = function_exists( 'wp_mcp_ai_get_language_model_router' ) ? wp_mcp_ai_get_language_model_router() : null;
			if ( ! $router ) {
				throw new Exception( __( 'Language model router is unavailable for the offloaded turn.', 'mcp-ai-wpoos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
			}

			$rest = class_exists( 'WP_MCP_AI_REST' ) ? WP_MCP_AI_REST::get_instance() : null;
			if ( ! $rest ) {
				throw new Exception( __( 'REST controller is unavailable for the offloaded turn.', 'mcp-ai-wpoos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
			}

			$final_text = '';

			while ( true ) {
				if ( ( microtime( true ) - $started_at ) >= (float) $time_cap ) {
					// Throws — the queue marks the job failed.
					self::fail_terminal(
						$job_id,
						$session_id,
						$assistant_id,
						__( 'The background worker hit its time limit before finishing the turn.', 'mcp-ai-wpoos' )
					);
				}

				if ( ! empty( $pending_tool_call ) ) {
					// Offload happened before a blocking tool: execute the batch
					// the live stream never got to.
					foreach ( $pending_tool_call as $tool_call ) {
						$tool_name = isset( $tool_call['function']['name'] ) ? (string) $tool_call['function']['name'] : '';
						if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
							WP_MCP_AI_Job_Notifier::record_step(
								$job_id,
								sprintf(
									/* translators: %s: tool name */
									__( 'Running tool: %s', 'mcp-ai-wpoos' ),
									'' !== $tool_name ? $tool_name : __( 'unknown', 'mcp-ai-wpoos' )
								),
								'running',
								array(
									'context' => array(
										'assistant_id' => $assistant_id,
										'user_id'      => $user_id,
									),
								)
							);
						}

						$tool_message = $rest->execute_offloaded_tool_call(
							$tool_call,
							$assistant_id,
							$assistant_config,
							$user_id,
							$iteration,
							$max_iterations,
							$session_id
						);

						if ( is_wp_error( $tool_message ) ) {
							// Feed the failure back to the model so the resumed
							// turn can react instead of silently diverging.
							$tool_message = array(
								'role'         => 'tool',
								'content'      => wp_json_encode( array( 'error' => $tool_message->get_error_message() ) ),
								'tool_call_id' => isset( $tool_call['id'] ) ? (string) $tool_call['id'] : uniqid( 'tool_', true ),
								'name'         => $tool_name,
							);
						}

						$messages[] = $tool_message;
					}
					$pending_tool_call = array();
					++$iteration;
				}

				$response = $router->create_chat_completion( $messages, $options );
				if ( is_wp_error( $response ) ) {
					// Throws — the queue marks the job failed.
					self::fail_terminal( $job_id, $session_id, $assistant_id, $response->get_error_message() );
				}

				// Attribute the resumed LLM costs to the original assistant/user.
				$rest->calculate_offloaded_response_cost( $response, $options, $assistant_id, $user_id, 'sse offload continuation iteration ' . $iteration );

				$tool_calls = self::extract_tool_calls( $response );
				if ( empty( $tool_calls ) ) {
					$final_text = self::extract_text( $response );
					break;
				}

				$assistant_message = self::extract_assistant_message( $response );
				if ( is_array( $assistant_message ) ) {
					$messages[] = $assistant_message;
				}

				if ( $iteration >= $max_iterations ) {
					// Budget exhausted: deliver whatever text this response
					// carries and stop (mirrors the live loop's strip + finish).
					$final_text = self::extract_text( $response );
					break;
				}

				$pending_tool_call = $tool_calls;

				if ( ( microtime( true ) - $last_heartbeat ) >= (float) $heartbeat && class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
					$progress = min( 95, (int) round( ( microtime( true ) - $started_at ) / $time_cap * 100 ) );
					WP_MCP_AI_Job_Notifier::handle_job_progress(
						$job_id,
						$progress,
						array(
							'context' => array(
								'assistant_id' => $assistant_id,
								'user_id'      => $user_id,
							),
						)
					);
					$last_heartbeat = microtime( true );
				}
			}

			// Deliver the final message through the session frame buffer so the
			// client's existing session-frame polling renders it.
			if ( '' !== $session_id && class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
				WP_MCP_AI_Chat_Session_Frame_Buffer::push(
					$session_id,
					'chat:resumed',
					array(
						'session_id'      => $session_id,
						'job_id'          => $job_id,
						'tool_call_id'    => '',
						'assistant_id'    => $assistant_id,
						'message'         => $final_text,
						'terminal_status' => 'completed',
						'ts'              => time(),
					)
				);
			}

			if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
				WP_MCP_AI_Job_Notifier::handle_job_completed(
					$job_id,
					array(
						'message'     => $final_text,
						'session_key' => $session_id,
					),
					array(
						'context' => array(
							'assistant_id' => $assistant_id,
							'user_id'      => $user_id,
						),
					)
				);
			}

			return array(
				'message'     => $final_text,
				'session_key' => $session_id,
			);
		}

		/**
		 * Record a terminal failure in both the notifier and the session buffer,
		 * then throw so the async queue marks the job row failed.
		 *
		 * @param string $job_id        Queue job identifier.
		 * @param string $session_id    Chat session identifier ('' skips the frame push).
		 * @param int    $assistant_id  Assistant identifier.
		 * @param string $error_message Human-readable error.
		 * @throws Exception Always — the queue marks the job failed (zero retries).
		 */
		protected static function fail_terminal( $job_id, $session_id, $assistant_id, $error_message ) {
			if ( '' !== $session_id && class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
				WP_MCP_AI_Chat_Session_Frame_Buffer::push(
					$session_id,
					'chat:error',
					array(
						'session_id'   => $session_id,
						'job_id'       => $job_id,
						'assistant_id' => $assistant_id,
						'error'        => $error_message,
						'ts'           => time(),
					)
				);
			}

			if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
				WP_MCP_AI_Job_Notifier::handle_job_failed(
					$job_id,
					new WP_Error( 'chat_stream_offload_failed', $error_message ),
					array(
						'context' => array(
							'assistant_id' => (int) $assistant_id,
						),
					)
				);
			}

			throw new Exception( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		/**
		 * Extract tool calls from an LLM response (mirrors the REST extractor).
		 *
		 * @param array $response Raw LLM response.
		 * @return array Tool call objects.
		 */
		protected static function extract_tool_calls( $response ) {
			if ( ! is_array( $response ) ) {
				return array();
			}

			if ( isset( $response['tool_calls'] ) && is_array( $response['tool_calls'] ) ) {
				return $response['tool_calls'];
			}

			if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
				foreach ( $response['choices'] as $choice ) {
					if ( isset( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] ) ) {
						return $choice['message']['tool_calls'];
					}
				}
			}

			return array();
		}

		/**
		 * Extract the assistant message from an LLM response (mirrors the REST
		 * extractor).
		 *
		 * @param array $response Raw LLM response.
		 * @return array|null
		 */
		protected static function extract_assistant_message( $response ) {
			if ( ! is_array( $response ) ) {
				return null;
			}

			if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
				foreach ( $response['choices'] as $choice ) {
					if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
						return $choice['message'];
					}
				}
			}

			if ( isset( $response['role'] ) && 'assistant' === $response['role'] ) {
				return $response;
			}

			return null;
		}

		/**
		 * Extract assistant text from an LLM response (mirrors the re-entry
		 * extractor — handles OpenAI and Anthropic-style content shapes).
		 *
		 * @param array $response Raw LLM response.
		 * @return string
		 */
		protected static function extract_text( $response ) {
			if ( ! empty( $response['choices'][0]['message']['content'] ) ) {
				$raw = $response['choices'][0]['message']['content'];
			} elseif ( ! empty( $response['content'] ) ) {
				$raw = $response['content'];
			} else {
				return '';
			}

			if ( is_array( $raw ) ) {
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
	}
}
