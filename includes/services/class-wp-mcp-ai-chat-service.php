<?php
/**
 * Chat Service
 *
 * Handles chat message processing and orchestration.
 * Extracted from WP_MCP_AI_REST as part of service layer refactoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Service class
 *
 * Responsible for:
 * - Processing chat messages and responses
 * - Orchestrating agentic tool execution loops
 * - Managing chat transcripts
 * - Coordinating with rate limiting and token budgets
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Chat_Service {

	/**
	 * Maximum number of polls for async tool completion
	 *
	 * @var int
	 */
	const MAX_ASYNC_POLLS = 120;

	/**
	 * Interval between async tool completion polls (in seconds)
	 *
	 * @var int
	 */
	const ASYNC_POLL_INTERVAL = 3;

	/**
	 * Buffer time for PHP execution limit extension (in seconds)
	 *
	 * @var int
	 */
	const ASYNC_WAIT_TIME_BUFFER = 60;

	/**
	 * Message sent to LLM when a tool returns a pending/unavailable status.
	 *
	 * This message guides the LLM to use alternative information sources
	 * rather than treating temporary unavailability as a hard failure.
	 *
	 * @var string
	 */
	const PENDING_TOOL_MESSAGE = 'The information source is currently processing your request. Please proceed to assist the user using your general knowledge and other available information sources. Do not report this as a tool failure to the user.';

	/**
	 * Progress logging frequency for async tool waiting (every N polls)
	 *
	 * @var int
	 */
	const ASYNC_PROGRESS_LOG_INTERVAL = 10;

	/**
	 * Language Model Router instance
	 *
	 * @var WP_MCP_AI_Language_Model_Router
	 */
	private $router;

	/**
	 * Rate Limit Manager instance
	 *
	 * @var WP_MCP_AI_Rate_Limit_Manager
	 */
	private $rate_limiter;

	/**
	 * Token Budget Manager instance
	 *
	 * @var WP_MCP_AI_Token_Budget_Manager
	 */
	private $token_budget_manager;

	/**
	 * Tool Registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $tool_registry;

	/**
	 * Tool Execution Orchestrator instance
	 *
	 * @var WP_MCP_AI_Tool_Execution_Orchestrator|null
	 */
	private $tool_orchestrator;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Language_Model_Router $router                Language model router.
	 * @param WP_MCP_AI_Rate_Limit_Manager    $rate_limiter          Rate limit manager.
	 * @param WP_MCP_AI_Token_Budget_Manager  $token_budget_manager  Token budget manager.
	 * @param WP_MCP_AI_Tool_Registry         $tool_registry         Tool registry.
	 */
	public function __construct(
		WP_MCP_AI_Language_Model_Router $router,
		WP_MCP_AI_Rate_Limit_Manager $rate_limiter,
		WP_MCP_AI_Token_Budget_Manager $token_budget_manager,
		WP_MCP_AI_Tool_Registry $tool_registry
	) {
		$this->router               = $router;
		$this->rate_limiter         = $rate_limiter;
		$this->token_budget_manager = $token_budget_manager;
		$this->tool_registry        = $tool_registry;
		$this->tool_orchestrator    = null; // Lazy loaded.
	}

	/**
	 * Process a chat request
	 *
	 * Main entry point for chat processing. Handles:
	 * - Validation
	 * - Rate limiting
	 * - Token budget checking
	 * - Message processing
	 * - Agentic tool execution loops
	 * - Transcript recording
	 *
	 * @param int             $assistant_id       Assistant post ID.
	 * @param array           $messages           Chat messages.
	 * @param array           $options            Chat options.
	 * @param array           $assistant_config   Assistant configuration.
	 * @param array           $transcript_context Transcript recording context.
	 * @param int             $user_id            Current user ID.
	 * @param int             $max_iterations     Maximum agentic loop iterations.
	 * @param WP_REST_Request $request            REST request instance.
	 * @return array|WP_Error Chat response or error.
	 */
	public function process_chat_request(
		$assistant_id,
		$messages,
		$options,
		$assistant_config,
		$transcript_context,
		$user_id,
		$max_iterations = 5,
		$request = null
	) {
		// Log orchestration start.
		WP_MCP_AI_Logger::log_event(
			'debug',
			'Chat orchestration starting',
			array(
				'assistant_id'   => $assistant_id,
				'user_id'        => $user_id,
				'message_count'  => count( $messages ),
				'max_iterations' => $max_iterations,
				'has_tools'      => ! empty( $assistant_config['tools'] ),
			)
		);

		// Apply filters to options before processing.
		$options = apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, null );

		// Fire action before chat request.
		do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, null );

		// Get language model client.
		$client = $this->router->get_client( $assistant_config );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Track timing for transcripts.
		$transcript_context['request_started_at'] = microtime( true );

		// Send initial chat request.
		$response = $client->create_chat_completion( $messages, $options );

		$transcript_context['response_completed_at'] = microtime( true );

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			$this->log_chat_error( $response, $assistant_id, $user_id );
			return $response;
		}

		// Check for failed response that needs conversion.
		$response = $this->maybe_convert_failed_chat_response( $response );
		if ( is_wp_error( $response ) ) {
			$this->log_chat_error( $response, $assistant_id, $user_id );
			return $response;
		}

		// Log initial response received.
		WP_MCP_AI_Logger::log_event(
			'debug',
			'Initial chat response received',
			array(
				'assistant_id'   => $assistant_id,
				'has_tool_calls' => ! empty( $this->extract_tool_calls_from_response( $response ) ),
				'response_time'  => round( ( $transcript_context['response_completed_at'] - $transcript_context['request_started_at'] ) * 1000, 2 ) . 'ms',
			)
		);

		// Execute agentic loop if tools are requested.
		$iteration             = 0;
		$tool_result_messages  = array();
		$agentic_tool_messages = array(); // Track intermediate assistant messages with tool_calls for conversation state preservation.

		while ( $iteration < $max_iterations && ! is_wp_error( $response ) ) {
			$tool_calls    = $this->extract_tool_calls_from_response( $response );
			$finish_reason = $this->extract_finish_reason_from_response( $response );

			// Exit loop if no tool calls or if model indicates completion.
			// finish_reason of 'stop' means the model has completed its response,
			// even if tool_calls are present in the message (for context/history).
			if ( empty( $tool_calls ) || 'stop' === $finish_reason ) {
				if ( WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled() ) {
					$exit_reason = 'stop' === $finish_reason ? 'finish_reason: stop' : 'no more tool calls';
					WP_MCP_AI_Logger::log_event(
						'debug',
						'Agentic loop completed - ' . $exit_reason,
						array(
							'iteration'        => $iteration,
							'total_iterations' => $iteration,
							'finish_reason'    => $finish_reason,
							'has_tool_calls'   => ! empty( $tool_calls ),
							'assistant_id'     => $assistant_id,
						)
					);
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[WP oOS Agentic Loop] Completed after %d iterations - %s', $iteration, $exit_reason ) );
				}
				break; // Final response ready.
			}

			if ( WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled() ) {
				$tool_names = array_map(
					function ( $call ) {
						return isset( $call['function']['name'] ) ? $call['function']['name'] : 'unknown';
					},
					$tool_calls
				);

				WP_MCP_AI_Logger::log_event(
					'debug',
					'Agentic loop iteration starting',
					array(
						'iteration'      => $iteration,
						'max_iterations' => $max_iterations,
						'tool_count'     => count( $tool_calls ),
						'tool_names'     => $tool_names,
						'assistant_id'   => $assistant_id,
					)
				);

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP oOS Agentic Loop] Iteration %d/%d - Executing %d tool(s): %s',
						$iteration + 1,
						$max_iterations,
						count( $tool_calls ),
						implode( ', ', $tool_names )
					)
				);
			}

			// Add assistant message with tool_calls to conversation.
			$assistant_tool_message  = array(
				'role'       => 'assistant',
				'content'    => isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '',
				'tool_calls' => $tool_calls,
			);
			$messages[]              = $assistant_tool_message;
			$agentic_tool_messages[] = $assistant_tool_message;

			// Execute each tool with iteration context for flow stage validation.
			$iteration_start_time = microtime( true );
			$tool_results         = $this->execute_tool_calls( $tool_calls, $assistant_id, $assistant_config, $iteration, $max_iterations );
			$iteration_duration   = microtime( true ) - $iteration_start_time;

			if ( WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled() ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Tool execution completed for iteration',
					array(
						'iteration'      => $iteration,
						'tool_count'     => count( $tool_calls ),
						'result_count'   => count( $tool_results ),
						'execution_time' => round( $iteration_duration * 1000, 2 ) . 'ms',
						'assistant_id'   => $assistant_id,
					)
				);

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP oOS Agentic Loop] Iteration %d - Tool execution completed in %sms (%d results)',
						$iteration + 1,
						round( $iteration_duration * 1000, 2 ),
						count( $tool_results )
					)
				);
			}

			// Add tool results to conversation.
			// Two versions are maintained:
			// 1. Full results for frontend display (tool_result_messages[]).
			// 2. Sanitized results for LLM to reduce token usage (messages[]).
			foreach ( $tool_results as $tool_result ) {
				// Keep full result for frontend display (includes base64 content).
				$tool_result_messages[] = $tool_result;

				// Create sanitized version for LLM (strips large base64 content to save tokens).
				$sanitized_result = $this->sanitize_tool_result_for_llm( $tool_result, $assistant_config );
				$messages[]       = $sanitized_result;
			}

			// Extract any images from tool results and add them as a user message
			// so vision models can "see" them in subsequent agentic loop iterations.
			$image_message = $this->extract_images_from_tool_results( $tool_results );
			if ( $image_message ) {
				$messages[] = $image_message;

				if ( WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled() ) {
					WP_MCP_AI_Logger::log_event(
						'debug',
						'Added image user message for vision model',
						array(
							'iteration'    => $iteration,
							'image_count'  => count( $image_message['content'] ) - 1, // Subtract text segment.
							'assistant_id' => $assistant_id,
						)
					);
				}
			}

			++$iteration;

			if ( WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled() ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Sending follow-up request with tool results',
					array(
						'iteration'      => $iteration - 1,
						'next_iteration' => $iteration,
						'message_count'  => count( $messages ),
						'assistant_id'   => $assistant_id,
					)
				);

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP oOS Agentic Loop] Iteration %d - Sending follow-up request with %d messages',
						$iteration,
						count( $messages )
					)
				);
			}

			// Send follow-up request with tool results.
			$response = $client->create_chat_completion( $messages, $options );

			if ( is_wp_error( $response ) ) {
				$this->log_chat_error( $response, $assistant_id, $user_id );
				return $response;
			}

			$response = $this->maybe_convert_failed_chat_response( $response );
			if ( is_wp_error( $response ) ) {
				$this->log_chat_error( $response, $assistant_id, $user_id );
				return $response;
			}
		}

		// Update response completion timestamp after agentic loop.
		$transcript_context['response_completed_at'] = microtime( true );

		// Log orchestration completion.
		WP_MCP_AI_Logger::log_event(
			'debug',
			'Chat orchestration completed',
			array(
				'assistant_id'                => $assistant_id,
				'total_iterations'            => $iteration,
				'max_iterations'              => $max_iterations,
				'tool_results_count'          => count( $tool_result_messages ),
				'agentic_tool_messages_count' => count( $agentic_tool_messages ),
				'total_messages'              => count( $messages ),
				'total_time'                  => round( ( microtime( true ) - $transcript_context['request_started_at'] ) * 1000, 2 ) . 'ms',
			)
		);

		// Add tool results to response for frontend display.
		if ( ! empty( $tool_result_messages ) ) {
			$response['tool_results'] = $tool_result_messages;
		}

		// Add intermediate assistant messages with tool_calls to response for conversation state preservation.
		// This ensures the frontend can reconstruct the full conversation history including which tools
		// were called and when, which is essential for agentic flow continuity and transcript storage.
		if ( ! empty( $agentic_tool_messages ) ) {
			$response['agentic_tool_messages'] = $agentic_tool_messages;
		}

		// Record transcript if needed.
		if ( ! empty( $transcript_context['save_transcript'] ) ) {
			$this->save_chat_transcript(
				$assistant_id,
				$messages,
				$options,
				$response,
				$transcript_context,
				$user_id,
				$request
			);
		}

		// Fire action after successful chat.
		do_action( 'wp_mcp_ai_after_chat_request', $assistant_id, $messages, $response, null );

		return $response;
	}

	/**
	 * Extract tool calls from LLM response
	 *
	 * @param array $response LLM response.
	 * @return array Tool calls.
	 */
	private function extract_tool_calls_from_response( $response ) {
		if ( empty( $response['choices'][0]['message']['tool_calls'] ) ) {
			return array();
		}

		return $response['choices'][0]['message']['tool_calls'];
	}

	/**
	 * Extract finish reason from LLM response
	 *
	 * @param array $response LLM response.
	 * @return string Finish reason ('stop', 'tool_calls', 'length', etc.) or empty string.
	 */
	private function extract_finish_reason_from_response( $response ) {
		if ( empty( $response['choices'][0]['finish_reason'] ) ) {
			return '';
		}

		return (string) $response['choices'][0]['finish_reason'];
	}

	/**
	 * Execute tool calls
	 *
	 * @param array $tool_calls       Tool calls from LLM.
	 * @param int   $assistant_id     Assistant ID.
	 * @param array $assistant_config Assistant configuration.
	 * @param int   $iteration        Current iteration number.
	 * @param int   $max_iterations   Maximum iterations.
	 * @return array Tool result messages.
	 */
	private function execute_tool_calls( $tool_calls, $assistant_id, $assistant_config, $iteration = 0, $max_iterations = 5 ) {
		$results = array();

		foreach ( $tool_calls as $tool_call ) {
			$tool_name = $tool_call['function']['name'] ?? '';
			$tool_id   = $tool_call['id'] ?? '';

			// Parse arguments.
			$arguments_json = $tool_call['function']['arguments'] ?? '{}';
			$arguments      = json_decode( $arguments_json, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$results[] = array(
					'role'         => 'tool',
					'tool_call_id' => $tool_id,
					'name'         => $tool_name,
					'content'      => wp_json_encode(
						array(
							'error' => 'Invalid tool arguments JSON',
						)
					),
				);
				continue;
			}

			// Execute tool via orchestrator (routes between sync/async).
			$orchestrator = $this->get_tool_orchestrator();
			$tool_result  = $orchestrator->execute_tool(
				$tool_name,
				$arguments,
				array(
					'assistant_id'     => $assistant_id,
					'assistant_config' => $assistant_config,
					'iteration'        => $iteration,
					'max_iterations'   => $max_iterations,
					'user_id'          => get_current_user_id(),
				)
			);

			// If tool returned async result, wait for completion in agentic loop.
			// This prevents the LLM from seeing "pending" status and ensures it gets
			// the actual tool result (e.g., video URL) for proper response generation.
			if ( $this->is_async_tool_result( $tool_result ) ) {
				$tool_result = $this->wait_for_async_tool_completion( $tool_result['job_id'], $tool_name );
			}

			// Format result.
			if ( is_wp_error( $tool_result ) ) {
				// Check if this is a pending/temporary error (e.g., HTTP 202 from web search).
				// These should be handled differently - the LLM should know to use alternative sources
				// rather than treating it as a hard failure.
				$error_data = $tool_result->get_error_data();
				$is_pending = is_array( $error_data ) && ! empty( $error_data['is_pending'] ) && true === $error_data['is_pending'];

				if ( $is_pending ) {
					// Convert pending error to an informational result for the LLM.
					// This allows the LLM to gracefully handle temporary unavailability
					// by using alternative information sources or general knowledge.
					// Status is "success" (not "unavailable") to prevent LLM from reporting tool failure.
					$result_content = wp_json_encode(
						array(
							'status'  => 'success',
							'message' => self::PENDING_TOOL_MESSAGE,
							'note'    => $tool_result->get_error_message(),
						)
					);
				} else {
					// Regular error - include full error details for the LLM.
					$error_payload = array(
						'error_code'    => $tool_result->get_error_code(),
						'error_message' => $tool_result->get_error_message(),
					);

					// Include error data if available (helps AI understand error context).
					if ( is_array( $error_data ) && ! empty( $error_data ) ) {
						$error_payload['error_data'] = $error_data;
					}

					$result_content = wp_json_encode( $error_payload );
				}
			} else {
				$result_content = wp_json_encode( $tool_result );
			}

			$results[] = array(
				'role'         => 'tool',
				'tool_call_id' => $tool_id,
				'name'         => $tool_name,
				'content'      => $result_content,
			);
		}

		return $results;
	}

	/**
	 * Check if a tool result indicates async execution.
	 *
	 * @param mixed $tool_result Tool execution result.
	 * @return bool True if result is async and has a job_id.
	 */
	private function is_async_tool_result( $tool_result ) {
		return ! is_wp_error( $tool_result ) &&
				is_array( $tool_result ) &&
				isset( $tool_result['async'] ) &&
				$tool_result['async'] &&
				! empty( $tool_result['job_id'] );
	}

	/**
	 * Wait for async tool completion and return final result.
	 *
	 * Polls the async executor for job completion. Used in agentic loops to ensure
	 * the LLM receives the actual tool result (e.g., generated video URL) rather than
	 * a "pending" status message.
	 *
	 * IMPORTANT: This method intentionally uses sleep() to block the PHP process while
	 * waiting for async job completion. This blocking behavior is REQUIRED in the agentic
	 * loop context to ensure the LLM receives the final tool result before generating its
	 * response. Without blocking, the LLM would see only the "pending" status and cannot
	 * produce a meaningful response (e.g., "Here's your video: [URL]").
	 *
	 * This is NOT a performance issue because:
	 * - It only blocks during async tool execution (rare in typical conversations)
	 * - The SSE connection is already held for streaming responses anyway
	 * - The alternative (client-side polling) creates worse UX and never delivers final LLM response
	 *
	 * @param string $job_id   Async job identifier.
	 * @param string $tool_name Tool name for error messages.
	 * @return array|WP_Error Final tool result or error.
	 */
	private function wait_for_async_tool_completion( $job_id, $tool_name ) {
		// Load async executor.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		}

		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Poll configuration (can be overridden via filters).
		$max_polls     = apply_filters( 'wp_mcp_ai_async_max_polls', self::MAX_ASYNC_POLLS );
		$poll_interval = apply_filters( 'wp_mcp_ai_async_poll_interval', self::ASYNC_POLL_INTERVAL );
		$poll_count    = 0;

		// Extend PHP execution time limit to accommodate long polling.
		// Calculate required time: (max_polls * poll_interval) + buffer.
		$required_time = ( $max_polls * $poll_interval ) + self::ASYNC_WAIT_TIME_BUFFER;

		// Attempt to extend timeout if function exists and is not disabled.
		// Log when we can't extend timeout so administrators can adjust server config if needed.
		if ( ! function_exists( 'set_time_limit' ) ) {
			WP_MCP_AI_Logger::log_event(
				'async_tool_wait_timeout_warning',
				'set_time_limit() is not available - async tool wait may hit PHP execution timeout',
				array(
					'tool_name'     => $tool_name,
					'job_id'        => $job_id,
					'required_time' => $required_time,
				)
			);
		} else {
			$old_limit = ini_get( 'max_execution_time' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_set_time_limit
			@set_time_limit( $required_time );

			// Check if it actually changed (some hosts disable this via safe mode or disable_functions).
			// Note: '0' means unlimited execution time, which is considered success.
			$new_limit = ini_get( 'max_execution_time' );
			if ( '0' !== $old_limit && '0' !== $new_limit && absint( $new_limit ) < $required_time ) {
				WP_MCP_AI_Logger::log_event(
					'async_tool_wait_timeout_warning',
					'Unable to extend PHP execution time limit - async tool wait may timeout',
					array(
						'tool_name'     => $tool_name,
						'job_id'        => $job_id,
						'required_time' => $required_time,
						'old_limit'     => $old_limit,
						'new_limit'     => $new_limit,
					)
				);
			}
		}

		WP_MCP_AI_Logger::log_event(
			'async_tool_wait_start',
			sprintf( 'Waiting for async tool completion: %s (job_id: %s)', $tool_name, $job_id ),
			array(
				'tool_name' => $tool_name,
				'job_id'    => $job_id,
			)
		);

		while ( $poll_count < $max_polls ) {
			// Get job status.
			$job_status = $executor->get_result( $job_id );

			// Check for errors.
			if ( is_wp_error( $job_status ) ) {
				WP_MCP_AI_Logger::log_error(
					'Async tool wait failed - job error',
					array(
						'tool_name'     => $tool_name,
						'job_id'        => $job_id,
						'error_code'    => $job_status->get_error_code(),
						'error_message' => $job_status->get_error_message(),
					)
				);

				return new WP_Error(
					'wp_mcp_ai_async_tool_failed',
					sprintf(
						/* translators: 1: tool name, 2: error message */
						__( '%1$s failed: %2$s', 'wp-mcp-ai' ),
						$tool_name,
						$job_status->get_error_message()
					)
				);
			}

			// Check if completed.
			$status = isset( $job_status['status'] ) ? $job_status['status'] : 'unknown';

			if ( 'completed' === $status ) {
				// Job completed successfully - extract result.
				$result = isset( $job_status['result'] ) ? $job_status['result'] : array();

				WP_MCP_AI_Logger::log_event(
					'async_tool_wait_complete',
					sprintf( 'Async tool completed: %s (job_id: %s, polls: %d)', $tool_name, $job_id, $poll_count ),
					array(
						'tool_name'  => $tool_name,
						'job_id'     => $job_id,
						'poll_count' => $poll_count,
						'has_result' => ! empty( $result ),
					)
				);

				// Apply tool's sanitize_for_llm to ensure result is properly formatted for agentic loop.
				// This is critical for tools like generate_veo_video which add display structures (video_url)
				// and need to strip large base64 data before sending to the LLM.
				$result = $this->apply_tool_sanitization( $result, $tool_name );

				return $result;
			}

			if ( 'failed' === $status ) {
				// Job failed.
				$error_msg = isset( $job_status['error'] ) ? $job_status['error'] : __( 'Unknown error', 'wp-mcp-ai' );

				WP_MCP_AI_Logger::log_error(
					'Async tool wait failed - job failed',
					array(
						'tool_name' => $tool_name,
						'job_id'    => $job_id,
						'error'     => $error_msg,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_async_tool_failed',
					sprintf(
						/* translators: 1: tool name, 2: error message */
						__( '%1$s failed: %2$s', 'wp-mcp-ai' ),
						$tool_name,
						$error_msg
					)
				);
			}

			// Job still pending/running - wait before next poll.
			sleep( $poll_interval );
			++$poll_count;

			// Log progress periodically.
			if ( 0 === $poll_count % self::ASYNC_PROGRESS_LOG_INTERVAL ) {
				WP_MCP_AI_Logger::log_event(
					'async_tool_wait_progress',
					sprintf( 'Still waiting for async tool: %s (poll %d/%d)', $tool_name, $poll_count, $max_polls ),
					array(
						'tool_name'  => $tool_name,
						'job_id'     => $job_id,
						'poll_count' => $poll_count,
						'status'     => $status,
					)
				);
			}
		}

		// Timeout reached.
		WP_MCP_AI_Logger::log_error(
			'Async tool wait timeout',
			array(
				'tool_name'  => $tool_name,
				'job_id'     => $job_id,
				'poll_count' => $poll_count,
			)
		);

		// Calculate actual timeout duration for error message.
		$timeout_minutes = ceil( ( $max_polls * $poll_interval ) / 60 );

		return new WP_Error(
			'wp_mcp_ai_async_tool_timeout',
			sprintf(
				/* translators: 1: tool name, 2: timeout in minutes */
				__( '%1$s timed out after %2$d minutes. The job may still be processing in the background.', 'wp-mcp-ai' ),
				$tool_name,
				$timeout_minutes
			)
		);
	}

	/**
	 * Extract images from tool results and create a user message for vision models.
	 *
	 * This allows vision models to "see" images generated by tools like generate_openai_image
	 * in the agentic loop by converting image_url data from tool results into a proper
	 * user message with image_url content blocks.
	 *
	 * @param array $tool_results Array of tool result messages.
	 * @return array|null User message with images, or null if no images found.
	 */
	private function extract_images_from_tool_results( array $tool_results ) {
		$image_content = array();

		foreach ( $tool_results as $tool_result ) {
			if ( ! isset( $tool_result['content'] ) || '' === $tool_result['content'] ) {
				continue;
			}

			// Parse the tool result content (it's JSON-encoded).
			$content = json_decode( $tool_result['content'], true );
			if ( ! is_array( $content ) ) {
				continue;
			}

			// Check if this tool result contains an image_url structure.
			if ( isset( $content['image_url'] ) && is_array( $content['image_url'] ) && isset( $content['image_url']['url'] ) ) {
				$image_url = esc_url_raw( $content['image_url']['url'] );

				if ( '' !== $image_url ) {
					$image_content[] = array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => $image_url,
						),
					);
				}
			}
		}

		// If we found images, create a user message with them.
		if ( ! empty( $image_content ) ) {
			// Add a text segment to provide context.
			array_unshift(
				$image_content,
				array(
					'type' => 'text',
					'text' => __( 'Here are the generated images from the tool execution:', 'wp-mcp-ai' ),
				)
			);

			return array(
				'role'    => 'user',
				'content' => $image_content,
			);
		}

		return null;
	}

	/**
	 * Convert failed chat response to WP_Error
	 *
	 * @param array $response LLM response.
	 * @return array|WP_Error Response or error.
	 */
	private function maybe_convert_failed_chat_response( $response ) {
		// Check for error indicators in response.
		if ( isset( $response['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_chat_error',
				$response['error']['message'] ?? __( 'Chat request failed', 'wp-mcp-ai' ),
				array(
					'status'              => 500,
					'provider_error'      => $response['error'],
					'provider_error_code' => $response['error']['code'] ?? '',
				)
			);
		}

		return $response;
	}

	/**
	 * Log chat error
	 *
	 * @param WP_Error $error        Error object.
	 * @param int      $assistant_id Assistant ID.
	 * @param int      $user_id      User ID.
	 */
	private function log_chat_error( $error, $assistant_id, $user_id ) {
		$context = array(
			'assistant_id' => $assistant_id,
			'user_id'      => $user_id,
			'error_code'   => $error->get_error_code(),
			'error'        => $error->get_error_message(),
		);

		$error_data = $error->get_error_data();
		if ( is_array( $error_data ) && isset( $error_data['provider_error_code'] ) ) {
			$context['provider_error_code'] = $error_data['provider_error_code'];
		}

		WP_MCP_AI_Logger::log_error(
			sprintf(
				'Chat request failed: %s',
				$error->get_error_message()
			),
			$context
		);
	}

	/**
	 * Save chat transcript
	 *
	 * @param int             $assistant_id       Assistant ID.
	 * @param array           $messages           Chat messages.
	 * @param array           $options            Chat options.
	 * @param array           $response           LLM response.
	 * @param array           $transcript_context Transcript context.
	 * @param int             $user_id            User ID.
	 * @param WP_REST_Request $request            REST request instance.
	 */
	private function save_chat_transcript( $assistant_id, $messages, $options, $response, $transcript_context, $user_id, $request ) {
		// Check if chat transcript recorder is available.
		if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			return;
		}

		// If no request object provided, we cannot save the transcript.
		if ( ! $request instanceof WP_REST_Request ) {
			WP_MCP_AI_Logger::log_error(
				'Cannot save chat transcript without WP_REST_Request object',
				array(
					'assistant_id' => $assistant_id,
					'user_id'      => $user_id,
				)
			);
			return;
		}

		// Call the static record method with correct parameters.
		WP_MCP_AI_Chat_Transcript_Recorder::record(
			$assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$user_id,
			$transcript_context
		);
	}

	/**
	 * Check rate limits for chat request
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param int   $user_id      User ID.
	 * @param array $options      Chat options.
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limits( $assistant_id, $user_id, $options ) {
		return $this->rate_limiter->check_rate_limit(
			$user_id,
			'chat',
			array(
				'assistant_id' => $assistant_id,
				'options'      => $options,
			)
		);
	}

	/**
	 * Check token budget for chat request
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param int   $user_id      User ID.
	 * @param array $messages     Chat messages.
	 * @param array $options      Chat options.
	 * @return true|WP_Error True if within budget, WP_Error if exceeded.
	 */
	public function check_token_budget( $assistant_id, $user_id, $messages, $options ) {
		return $this->token_budget_manager->check_budget(
			$user_id,
			$assistant_id,
			$messages,
			$options
		);
	}

	/**
	 * Get tool execution orchestrator instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Execution_Orchestrator
	 */
	private function get_tool_orchestrator() {
		if ( null === $this->tool_orchestrator ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Execution_Orchestrator' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php';
			}
			// Pass registry and null for async_executor (will be lazy-loaded).
			$this->tool_orchestrator = new WP_MCP_AI_Tool_Execution_Orchestrator( $this->tool_registry, null );
		}

		return $this->tool_orchestrator;
	}

	/**
	 * Sanitize tool result for LLM
	 *
	 * Sanitizes tool results before sending them to the LLM in the agentic loop.
	 * This prevents large base64 content from being sent to the LLM, which would
	 * waste tokens and potentially cause errors.
	 *
	 * The full, unsanitized result is preserved in tool_results[] for frontend display.
	 *
	 * @param array $tool_result      Tool result message to sanitize.
	 * @param array $assistant_config Assistant configuration.
	 * @return array Sanitized tool result message.
	 */
	private function sanitize_tool_result_for_llm( $tool_result, $assistant_config = array() ) {
		if ( ! is_array( $tool_result ) || empty( $tool_result['content'] ) ) {
			return $tool_result;
		}

		$tool_name = isset( $tool_result['name'] ) ? $tool_result['name'] : '';

		// Parse the JSON-encoded content.
		$content = $tool_result['content'];
		if ( is_string( $content ) ) {
			$decoded = json_decode( $content, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$content = $decoded;
			}
		}

		// If content is not an array after parsing, return as-is.
		if ( ! is_array( $content ) ) {
			return $tool_result;
		}

		// Apply tool-specific sanitization if available (delegated to helper method).
		$content = $this->apply_tool_sanitization( $content, $tool_name );

		// Apply generic sanitization filters.
		$content = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm', $content, $tool_name, $assistant_config );
		if ( $tool_name ) {
			$content = apply_filters( "wp_mcp_ai_sanitize_tool_result_llm_{$tool_name}", $content, $assistant_config );
		}

		// Re-encode the sanitized content as JSON string.
		// The content should always be JSON-encoded for consistency with the tool result format.
		// However, if a filter returned a string, preserve it to avoid double-encoding.
		$sanitized_result = $tool_result;
		if ( is_string( $content ) ) {
			// Content is already a string (possibly from a filter), use as-is.
			$sanitized_result['content'] = $content;
		} else {
			// Content is an array, encode it to JSON.
			$sanitized_result['content'] = wp_json_encode( $content );
		}

		return $sanitized_result;
	}

	/**
	 * Apply tool's sanitize_for_llm method if tool implements the interface.
	 *
	 * Helper method to reduce code duplication when sanitizing tool results.
	 * Used by both wait_for_async_tool_completion and sanitize_tool_result_for_llm.
	 *
	 * @param mixed  $content   Content to sanitize (typically an array).
	 * @param string $tool_name Tool name.
	 * @return mixed Sanitized content, or original content if tool doesn't implement interface.
	 */
	private function apply_tool_sanitization( $content, $tool_name ) {
		// Return early if no tool name provided.
		if ( empty( $tool_name ) ) {
			return $content;
		}

		// Check if tool is registered.
		if ( ! $this->tool_registry->is_tool_registered( $tool_name ) ) {
			return $content;
		}

		// Get tool instance and check if it implements sanitization interface.
		$tool_instance = $this->tool_registry->get_tool( $tool_name );
		if ( ! $tool_instance || ! ( $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) ) {
			return $content;
		}

		// Apply tool's sanitization method.
		// The tool's implementation will handle content type validation.
		return $tool_instance->sanitize_for_llm( $content );
	}
}
