<?php
/**
 * Trait for run_with_tools functionality.
 *
 * This trait provides common logic for executing tools in a recursive loop
 * across different AI provider clients (OpenAI, Gemini, Anthropic, etc.).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Runner
 *
 * Provides shared run_with_tools() functionality for AI clients.
 */
trait WP_MCP_AI_Tool_Runner {

	/**
	 * Run chat completion with embedded function calling support.
	 *
	 * This method provides a recursive tool execution loop that works across
	 * different AI providers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $messages  Message payload to send.
	 * @param array $tools     Array of tool definitions with executable functions.
	 * @param array $options   Additional options:
	 *                         - model: Model to use (default: configured model)
	 *                         - temperature: Temperature setting (0-1)
	 *                         - max_tokens: Maximum tokens to generate
	 *                         - strictValidation: Validate tool arguments before execution (default: true)
	 *                         - maxRecursiveToolRuns: Maximum recursive tool call depth (default: 5)
	 *                         - streamFinalResponse: Return streaming response (default: false)
	 *                         - verbose: Enable verbose logging (default: false)
	 *                         - autoTrimTools: Automatically trim tools based on context (default: false)
	 *                         - timeout: Request timeout in seconds
	 * @return array|WP_Error Response array or error.
	 */
	public function run_with_tools( array $messages, array $tools = array(), array $options = array() ) {
		// Configuration options with defaults.
		$strict_validation     = isset( $options['strictValidation'] ) ? (bool) $options['strictValidation'] : true;
		$max_recursive_runs    = isset( $options['maxRecursiveToolRuns'] ) ? absint( $options['maxRecursiveToolRuns'] ) : 5;
		$stream_final_response = isset( $options['streamFinalResponse'] ) ? (bool) $options['streamFinalResponse'] : false;
		$verbose               = isset( $options['verbose'] ) ? (bool) $options['verbose'] : false;
		$auto_trim_tools       = isset( $options['autoTrimTools'] ) ? (bool) $options['autoTrimTools'] : false;

		$provider_name = $this->get_provider_name();

		if ( $verbose ) {
			WP_MCP_AI_Logger::log_event(
				$provider_name . '_run_with_tools_start',
				sprintf( 'Starting %s embedded function calling.', ucfirst( $provider_name ) ),
				array(
					'message_count'      => count( $messages ),
					'tool_count'         => count( $tools ),
					'strict_validation'  => $strict_validation,
					'max_recursive_runs' => $max_recursive_runs,
					'auto_trim_tools'    => $auto_trim_tools,
				)
			);
		}

		// Validate tools array.
		if ( empty( $tools ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_tools',
				__( 'At least one tool must be provided for embedded function calling.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Auto-trim tools if enabled.
		if ( $auto_trim_tools ) {
			$tools = $this->auto_trim_tools( $messages, $tools, $options );
			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					$provider_name . '_auto_trim_tools',
					'Automatically trimmed tools based on context.',
					array( 'remaining_tool_count' => count( $tools ) )
				);
			}
		}

		// Convert tools to provider format and create tool lookup.
		$tool_definitions = $this->prepare_tool_definitions( $tools );
		$tool_functions   = array();

		foreach ( $tools as $tool ) {
			if ( ! isset( $tool['name'] ) || ! isset( $tool['function'] ) ) {
				continue;
			}

			$tool_name                    = sanitize_text_field( $tool['name'] );
			$tool_functions[ $tool_name ] = $tool['function'];
		}

		// Prepare options with tools.
		$request_options          = $options;
		$request_options['tools'] = $tool_definitions;

		// Execute recursive tool calling loop.
		$conversation_messages = $messages;
		$recursion_count       = 0;

		while ( $recursion_count < $max_recursive_runs ) {
			++$recursion_count;

			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					$provider_name . '_tool_run_iteration',
					sprintf( 'Tool execution iteration %d/%d', $recursion_count, $max_recursive_runs ),
					array( 'message_count' => count( $conversation_messages ) )
				);
			}

			// Make API request.
			$response = $this->create_chat_completion( $conversation_messages, $request_options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract tool calls from response (provider-specific).
			$tool_calls = $this->extract_tool_calls_from_response( $response );

			// If no tool calls, we're done.
			if ( empty( $tool_calls ) ) {
				if ( $verbose ) {
					WP_MCP_AI_Logger::log_event(
						$provider_name . '_run_with_tools_complete',
						'Completed without tool calls.',
						array( 'iterations' => $recursion_count )
					);
				}

				// Return final response (optionally as stream).
				if ( $stream_final_response ) {
					// For PHP, we can't actually stream, so just return the response.
					return $response;
				}

				return $response;
			}

			// Add assistant's message to conversation (provider-specific format).
			$conversation_messages[] = $this->build_assistant_message_with_tool_calls( $response, $tool_calls );

			// Execute each tool call.
			foreach ( $tool_calls as $tool_call ) {
				$function_name = $this->get_tool_call_function_name( $tool_call );
				if ( empty( $function_name ) ) {
					continue;
				}

				$tool_call_id = $this->get_tool_call_id( $tool_call );

				// Check if function exists.
				if ( ! isset( $tool_functions[ $function_name ] ) ) {
					$error_message = sprintf(
						/* translators: %s: function name */
						__( 'Tool function "%s" not found.', 'mcp-ai-wpoos' ),
						$function_name
					);

					$conversation_messages[] = $this->build_tool_response_message(
						$tool_call_id,
						$function_name,
						wp_json_encode( array( 'error' => $error_message ) )
					);

					WP_MCP_AI_Logger::log_error(
						ucfirst( $provider_name ) . ' tool function not found.',
						array(
							'function_name' => $function_name,
							'tool_call_id'  => $tool_call_id,
						)
					);
					continue;
				}

				// Parse arguments.
				$arguments = $this->parse_tool_call_arguments( $tool_call );

				// Validate arguments if strict validation is enabled.
				if ( $strict_validation ) {
					$validation_error = $this->validate_tool_arguments( $function_name, $arguments, $tool_definitions );
					if ( is_wp_error( $validation_error ) ) {
						$conversation_messages[] = $this->build_tool_response_message(
							$tool_call_id,
							$function_name,
							wp_json_encode( array( 'error' => $validation_error->get_error_message() ) )
						);

						WP_MCP_AI_Logger::log_error(
							ucfirst( $provider_name ) . ' tool argument validation failed.',
							array(
								'function_name' => $function_name,
								'error'         => $validation_error->get_error_message(),
							)
						);
						continue;
					}
				}

				// Execute the tool function.
				try {
					$function_callable = $tool_functions[ $function_name ];

					if ( ! is_callable( $function_callable ) ) {
						throw new Exception( 'Tool function is not callable.' );
					}

					$result = call_user_func( $function_callable, $arguments );

					// Convert result to JSON string.
					$result_content = is_string( $result ) ? $result : wp_json_encode( $result );

					$conversation_messages[] = $this->build_tool_response_message(
						$tool_call_id,
						$function_name,
						$result_content
					);

					if ( $verbose ) {
						WP_MCP_AI_Logger::log_event(
							$provider_name . '_tool_executed',
							sprintf( 'Executed tool: %s', $function_name ),
							array(
								'function_name' => $function_name,
								'tool_call_id'  => $tool_call_id,
								'result_length' => strlen( $result_content ),
							)
						);
					}
				} catch ( Exception $e ) {
					$error_message = $e->getMessage();

					$conversation_messages[] = $this->build_tool_response_message(
						$tool_call_id,
						$function_name,
						wp_json_encode( array( 'error' => $error_message ) )
					);

					WP_MCP_AI_Logger::log_error(
						ucfirst( $provider_name ) . ' tool execution failed.',
						array(
							'function_name' => $function_name,
							'error'         => $error_message,
						)
					);
				}
			}
		}

		// Max recursion reached.
		if ( $verbose ) {
			WP_MCP_AI_Logger::log_event(
				$provider_name . '_max_recursion_reached',
				'Maximum recursive tool runs reached.',
				array( 'max_runs' => $max_recursive_runs )
			);
		}

		return new WP_Error(
			'wp_mcp_ai_max_tool_recursion',
			__( 'Maximum recursive tool runs reached without completion.', 'mcp-ai-wpoos' ),
			array(
				'status'         => 500,
				'max_runs'       => $max_recursive_runs,
				'final_messages' => $conversation_messages,
			)
		);
	}

	/**
	 * Validate tool arguments against the tool definition schema.
	 *
	 * @since 1.0.0
	 *
	 * @param string $function_name    Name of the function being called.
	 * @param array  $arguments        Arguments provided by the model.
	 * @param array  $tool_definitions Array of tool definitions.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_tool_arguments( $function_name, $arguments, $tool_definitions ) {
		// Find the tool definition.
		$tool_schema = null;
		foreach ( $tool_definitions as $tool_def ) {
			$def_name = $this->get_tool_definition_name( $tool_def );
			if ( $def_name === $function_name ) {
				$tool_schema = $this->get_tool_definition_parameters( $tool_def );
				break;
			}
		}

		if ( null === $tool_schema ) {
			return true; // No schema to validate against.
		}

		// Check required parameters.
		if ( isset( $tool_schema['required'] ) && is_array( $tool_schema['required'] ) ) {
			foreach ( $tool_schema['required'] as $required_param ) {
				if ( ! isset( $arguments[ $required_param ] ) ) {
					return new WP_Error(
						'wp_mcp_ai_missing_required_param',
						sprintf(
							/* translators: %1$s: parameter name, %2$s: function name */
							__( 'Required parameter "%1$s" missing for tool "%2$s".', 'mcp-ai-wpoos' ),
							$required_param,
							$function_name
						),
						array( 'parameter' => $required_param )
					);
				}
			}
		}

		// Validate parameter types if schema includes type definitions.
		if ( isset( $tool_schema['properties'] ) && is_array( $tool_schema['properties'] ) ) {
			foreach ( $arguments as $param_name => $param_value ) {
				if ( ! isset( $tool_schema['properties'][ $param_name ] ) ) {
					// Ignore extra parameters (non-strict mode).
					continue;
				}

				$param_schema = $tool_schema['properties'][ $param_name ];
				if ( ! isset( $param_schema['type'] ) ) {
					continue;
				}

				$expected_type = $param_schema['type'];
				$actual_type   = gettype( $param_value );

				// Map PHP types to JSON Schema types.
				$type_map = array(
					'boolean' => 'boolean',
					'integer' => 'number',
					'double'  => 'number',
					'string'  => 'string',
					'array'   => 'array',
					'object'  => 'object',
					'NULL'    => 'null',
				);

				$mapped_type = isset( $type_map[ $actual_type ] ) ? $type_map[ $actual_type ] : $actual_type;

				// Allow integer for number type.
				if ( 'number' === $expected_type && in_array( $mapped_type, array( 'number', 'integer' ), true ) ) {
					continue;
				}

				if ( $expected_type !== $mapped_type ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_param_type',
						sprintf(
							/* translators: %1$s: parameter name, %2$s: expected type, %3$s: actual type */
							__( 'Parameter "%1$s" expected type "%2$s" but got "%3$s".', 'mcp-ai-wpoos' ),
							$param_name,
							$expected_type,
							$mapped_type
						),
						array(
							'parameter'     => $param_name,
							'expected_type' => $expected_type,
							'actual_type'   => $mapped_type,
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Automatically trim tools based on context to reduce token usage.
	 *
	 * This is a simplified implementation that keeps tools relevant to the conversation.
	 *
	 * @since 1.0.0
	 *
	 * @param array $messages Message history.
	 * @param array $tools    Array of tool definitions.
	 * @param array $options  Request options.
	 * @return array Trimmed tools array.
	 */
	protected function auto_trim_tools( $messages, $tools, $options = array() ) {
		// Get the last user message to determine relevance.
		$last_user_message = '';
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
				$last_user_message = isset( $messages[ $i ]['content'] ) ? strtolower( (string) $messages[ $i ]['content'] ) : '';
				break;
			}
		}

		if ( empty( $last_user_message ) || empty( $tools ) ) {
			return $tools;
		}

		// Score each tool based on relevance.
		$scored_tools = array();
		foreach ( $tools as $tool ) {
			$score = 0;

			// Check name relevance.
			if ( isset( $tool['name'] ) ) {
				$tool_name  = strtolower( str_replace( array( '-', '_' ), ' ', $tool['name'] ) );
				$name_words = explode( ' ', $tool_name );
				foreach ( $name_words as $word ) {
					if ( ! empty( $word ) && false !== strpos( $last_user_message, $word ) ) {
						$score += 3; // Higher weight for name match.
					}
				}
			}

			// Check description relevance.
			if ( isset( $tool['description'] ) ) {
				$tool_desc  = strtolower( $tool['description'] );
				$desc_words = explode( ' ', $tool_desc );
				foreach ( $desc_words as $word ) {
					if ( strlen( $word ) > 3 && false !== strpos( $last_user_message, $word ) ) {
						$score += 1;
					}
				}
			}

			$scored_tools[] = array(
				'tool'  => $tool,
				'score' => $score,
			);
		}

		// Sort by score (descending).
		usort(
			$scored_tools,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		// Keep top tools (limit to max 10 tools to avoid token overflow).
		$max_tools     = isset( $options['maxTools'] ) ? absint( $options['maxTools'] ) : 10;
		$trimmed_tools = array();

		foreach ( array_slice( $scored_tools, 0, $max_tools ) as $scored ) {
			// Only include tools with a relevance score.
			if ( $scored['score'] > 0 || count( $trimmed_tools ) < 3 ) {
				// Always keep at least 3 tools even if score is 0.
				$trimmed_tools[] = $scored['tool'];
			}
		}

		// If no tools passed the relevance test, keep all original tools.
		if ( empty( $trimmed_tools ) ) {
			return $tools;
		}

		return $trimmed_tools;
	}

	/**
	 * Get the provider name for logging.
	 *
	 * Must be implemented by the client class.
	 *
	 * @return string Provider name (e.g., 'openai', 'gemini', 'anthropic').
	 */
	abstract protected function get_provider_name();

	/**
	 * Prepare tool definitions in provider-specific format.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $tools Array of tool definitions.
	 * @return array Provider-specific tool definitions.
	 */
	abstract protected function prepare_tool_definitions( array $tools );

	/**
	 * Extract tool calls from API response.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $response API response.
	 * @return array Array of tool calls.
	 */
	abstract protected function extract_tool_calls_from_response( array $response );

	/**
	 * Build assistant message with tool calls.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $response   API response.
	 * @param array $tool_calls Tool calls to include.
	 * @return array Assistant message.
	 */
	abstract protected function build_assistant_message_with_tool_calls( array $response, array $tool_calls );

	/**
	 * Get function name from tool call.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $tool_call Tool call data.
	 * @return string Function name.
	 */
	abstract protected function get_tool_call_function_name( array $tool_call );

	/**
	 * Get tool call ID from tool call.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $tool_call Tool call data.
	 * @return string Tool call ID.
	 */
	abstract protected function get_tool_call_id( array $tool_call );

	/**
	 * Parse arguments from tool call.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $tool_call Tool call data.
	 * @return array Parsed arguments.
	 */
	abstract protected function parse_tool_call_arguments( array $tool_call );

	/**
	 * Build tool response message.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param string $tool_call_id  Tool call ID.
	 * @param string $function_name Function name.
	 * @param string $content       Response content.
	 * @return array Tool response message.
	 */
	abstract protected function build_tool_response_message( $tool_call_id, $function_name, $content );

	/**
	 * Get tool definition name.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $tool_definition Tool definition.
	 * @return string Tool name.
	 */
	abstract protected function get_tool_definition_name( array $tool_definition );

	/**
	 * Get tool definition parameters.
	 *
	 * Must be implemented by the client class.
	 *
	 * @param array $tool_definition Tool definition.
	 * @return array|null Parameters schema or null.
	 */
	abstract protected function get_tool_definition_parameters( array $tool_definition );
}
