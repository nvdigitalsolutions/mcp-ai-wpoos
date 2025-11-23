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
		// Log orchestration start
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

		// Log initial response received
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
		$iteration            = 0;
		$tool_result_messages = array();

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
			$messages[] = array(
				'role'       => 'assistant',
				'content'    => isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '',
				'tool_calls' => $tool_calls,
			);

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
			foreach ( $tool_results as $tool_result ) {
				$messages[]             = $tool_result;
				$tool_result_messages[] = $tool_result;
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
							'image_count'  => count( $image_message['content'] ) - 1, // Subtract text segment
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

		// Log orchestration completion
		WP_MCP_AI_Logger::log_event(
			'debug',
			'Chat orchestration completed',
			array(
				'assistant_id'       => $assistant_id,
				'total_iterations'   => $iteration,
				'max_iterations'     => $max_iterations,
				'tool_results_count' => count( $tool_result_messages ),
				'total_messages'     => count( $messages ),
				'total_time'         => round( ( microtime( true ) - $transcript_context['request_started_at'] ) * 1000, 2 ) . 'ms',
			)
		);

		// Add tool results to response for frontend display.
		if ( ! empty( $tool_result_messages ) ) {
			$response['tool_results'] = $tool_result_messages;
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

			// Format result.
			if ( is_wp_error( $tool_result ) ) {
				$error_payload = array(
					'error_code'    => $tool_result->get_error_code(),
					'error_message' => $tool_result->get_error_message(),
				);

				// Include error data if available (helps AI understand error context).
				$error_data = $tool_result->get_error_data();
				if ( is_array( $error_data ) && ! empty( $error_data ) ) {
					$error_payload['error_data'] = $error_data;
				}

				$result_content = wp_json_encode( $error_payload );
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

			// Parse the tool result content (it's JSON-encoded)
			$content = json_decode( $tool_result['content'], true );
			if ( ! is_array( $content ) ) {
				continue;
			}

			// Check if this tool result contains an image_url structure
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

		// If we found images, create a user message with them
		if ( ! empty( $image_content ) ) {
			// Add a text segment to provide context
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
}
