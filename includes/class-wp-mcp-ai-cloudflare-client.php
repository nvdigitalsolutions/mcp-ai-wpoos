<?php
/**
 * Cloudflare Workers AI client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
	/**
	 * Provides a wrapper around Cloudflare Workers AI REST API endpoints.
	 */
	class WP_MCP_AI_Cloudflare_Client {

		/**
		 * Retrieve the configured API token.
		 *
		 * @return string
		 */
		public function get_api_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['cloudflare_api_token'] ) ? $settings['cloudflare_api_token'] : '';
		}

		/**
		 * Retrieve the configured account ID.
		 *
		 * @return string
		 */
		public function get_account_id() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['cloudflare_account_id'] ) ? $settings['cloudflare_account_id'] : '';
		}

		/**
		 * Retrieve the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '';
		}

		/**
		 * Test the connection to Cloudflare Workers AI.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Test with a simple API call to verify credentials.
			$url = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search',
				rawurlencode( $account_id )
			);

			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_test_connection',
				'Testing Cloudflare Workers AI connection.',
				array(
					'url'     => $url,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Cloudflare Workers AI connection failed.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$body = wp_remote_retrieve_body( $response );
				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'Cloudflare Workers AI returned an error.', 'mcp-ai-wpoos' ),
					array(
						'status' => $code,
						'body'   => $body,
					)
				);
			}

			// Parse the response to get model count.
			$body        = wp_remote_retrieve_body( $response );
			$data        = json_decode( $body, true );
			$model_count = 0;

			if ( isset( $data['result'] ) && is_array( $data['result'] ) ) {
				$model_count = count( $data['result'] );
			}

			return array(
				'success'     => true,
				'message'     => __( 'Connected to Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
				'model_count' => $model_count,
			);
		}

		/**
		 * Perform a chat completion request against Cloudflare Workers AI.
		 *
		 * For Cloudflare Workers AI, this method intelligently routes to the best approach:
		 * - If tools are provided: Uses embedded function calling (run_with_tools) for better reliability
		 * - If no tools: Uses traditional single request/response
		 *
		 * This routing is necessary because Cloudflare models may return XML-formatted tool calls
		 * in the content, which requires the embedded approach to properly execute and continue.
		 *
		 * @param array $messages Message payload to send.
		 * @param array $options  Additional options (model, temperature, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			// Check if tools are provided and we should use embedded function calling.
			// Cloudflare Workers AI works better with embedded function calling because:
			// 1. Some models return XML tool calls instead of proper JSON
			// 2. The embedded approach handles the complete loop automatically
			// 3. It ensures tools are actually executed and results are returned.
			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				// Check if tools have executable functions (needed for embedded calling).
				$has_executables = $this->tools_have_executables( $options['tools'] );

				if ( $has_executables ) {
					// Use embedded function calling for better reliability.
					WP_MCP_AI_Logger::log_event(
						'cloudflare_routing_to_embedded',
						'Routing to embedded function calling (run_with_tools) because tools with executables are provided',
						array(
							'tool_count' => count( $options['tools'] ),
							'reason'     => 'Cloudflare models work better with embedded approach for tool execution',
						)
					);

					return $this->run_with_tools( $messages, $options['tools'], $options );
				} else {
					// Tools provided but no executables - this is the traditional flow
					// where the chat service will handle execution externally.
					WP_MCP_AI_Logger::log_event(
						'cloudflare_using_traditional',
						'Using traditional function calling because tools lack executable functions',
						array(
							'tool_count' => count( $options['tools'] ),
							'reason'     => 'Chat service will handle tool execution externally',
						)
					);
				}
			}

			// Continue with traditional single request/response approach.
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_api_token' => __( 'Add a Cloudflare API token in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_account_id' => __( 'Add a Cloudflare account ID in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_model',
					__( 'No Cloudflare Workers AI model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_model' => __( 'Choose a Cloudflare Workers AI model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Prepare system messages array (will be prepended to messages).
			$system_messages = array();

			// Log options to debug system_prompt issue.
			WP_MCP_AI_Logger::log_event(
				'cloudflare_system_prompt_check',
				'Checking system_prompt in options',
				array(
					'has_system_prompt'    => isset( $options['system_prompt'] ),
					'is_empty'             => empty( $options['system_prompt'] ),
					'system_prompt_length' => isset( $options['system_prompt'] ) ? strlen( (string) $options['system_prompt'] ) : 0,
					'options_keys'         => array_keys( $options ),
				)
			);

			// Add system_prompt if provided (assistant knowledge and instructions).
			if ( ! empty( $options['system_prompt'] ) ) {
				$system_messages[] = array(
					'role'    => 'system',
					'content' => wp_kses_post( (string) $options['system_prompt'] ),
				);
			}

			// Add memory documents if provided (assistant knowledge base).
			if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
				$memory_messages = $this->build_memory_messages_from_options( $options );
				if ( ! empty( $memory_messages ) ) {
					$system_messages = array_merge( $system_messages, $memory_messages );
				}
			}

			// Prepend system messages to conversation messages.
			if ( ! empty( $system_messages ) ) {
				$messages = array_merge( $system_messages, $messages );

				WP_MCP_AI_Logger::log_event(
					'cloudflare_system_messages_added',
					'System messages prepended to conversation',
					array(
						'system_message_count' => count( $system_messages ),
						'total_message_count'  => count( $messages ),
						'first_message_role'   => isset( $messages[0]['role'] ) ? $messages[0]['role'] : 'unknown',
					)
				);
			}

			$payload = $this->build_payload( $messages, $options );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			// Cloudflare Workers AI expects model IDs like @cf/meta/llama-3.1-8b-instruct
			// to be part of the URL path with forward slashes intact, not URL-encoded.
			// Validate model ID format and escape properly for URL path.
			// Model IDs must start with @ and contain only alphanumeric, hyphens, dots, slashes, and underscores.
			if ( ! preg_match( '/^@[a-zA-Z0-9\/_.-]+$/', $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_model_id',
					__( 'Invalid Cloudflare model ID format.', 'mcp-ai-wpoos' ),
					array( 'model' => $model )
				);
			}

			// Only escape the @ symbol and spaces, preserve forward slashes for URL path.
			$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

			$url = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
				rawurlencode( $account_id ),
				$escaped_model
			);

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $this->resolve_timeout( $options ),
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_request',
				'Sending request to Cloudflare Workers AI.',
				array(
					'payload'              => $this->obfuscate_request_for_log( $payload ),
					'has_system_field'     => isset( $payload['system'] ),
					'system_field_length'  => isset( $payload['system'] ) ? strlen( $payload['system'] ) : 0,
					'system_field_preview' => isset( $payload['system'] ) ? substr( $payload['system'], 0, 200 ) : '',
					'message_count'        => isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? count( $payload['messages'] ) : 0,
					'has_tools'            => isset( $payload['tools'] ) && ! empty( $payload['tools'] ),
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Cloudflare Workers AI request failed.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				// Parse Cloudflare error response for better error messages.
				$error_message = __( 'Cloudflare Workers AI returned an error.', 'mcp-ai-wpoos' );
				$decoded_body  = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['errors'] ) && is_array( $decoded_body['errors'] ) ) {
					// Cloudflare returns errors in an array with code and message.
					foreach ( $decoded_body['errors'] as $error ) {
						if ( isset( $error['message'] ) ) {
							// Sanitize error message to prevent XSS.
							$sanitized_message = sanitize_text_field( $error['message'] );
							$error_message    .= ' ' . $sanitized_message;
							if ( isset( $error['code'] ) ) {
								// Ensure code is an integer.
								$error_code     = absint( $error['code'] );
								$error_message .= ' (Code: ' . $error_code . ')';
							}
							break; // Use the first error message.
						}
					}
				}

				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI returned an error.',
					array(
						'status'        => $code,
						'body'          => $body,
						'error_message' => $error_message,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $body,
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid JSON response from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			// Cloudflare Workers AI response format.
			if ( ! isset( $decoded['result'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Unexpected response format from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
					array( 'decoded' => $decoded )
				);
			}

			// Log response structure for debugging tool_calls issues.
			WP_MCP_AI_Logger::log_event(
				'cloudflare_response_structure',
				'Cloudflare API response received',
				array(
					'has_result'       => isset( $decoded['result'] ),
					'has_tool_calls'   => isset( $decoded['result']['tool_calls'] ),
					'tool_calls_type'  => isset( $decoded['result']['tool_calls'] ) ? gettype( $decoded['result']['tool_calls'] ) : 'N/A',
					'tool_calls_empty' => isset( $decoded['result']['tool_calls'] ) ? empty( $decoded['result']['tool_calls'] ) : 'N/A',
					'response_keys'    => isset( $decoded['result'] ) ? array_keys( $decoded['result'] ) : array(),
					'model'            => $model,
				)
			);

			return $this->normalize_response( $decoded, $model );
		}

		/**
		 * Build the request payload.
		 *
		 * @param array $messages Messages array.
		 * @param array $options  Request options.
		 * @return array
		 */
		protected function build_payload( array $messages, array $options ) {
			// Normalize messages to ensure content is in the correct format.
			// Cloudflare Workers AI expects content to be a string for text-only messages.
			$normalized_messages = $this->normalize_messages( $messages );

			// CRITICAL: Cloudflare Workers AI follows OpenAI's chat completions format.
			// System messages should be kept in the messages array with role: "system",
			// NOT extracted to a separate "system" field (that's for Ollama only).
			//
			// According to Cloudflare documentation and testing:
			// - The API expects: {"messages": [{"role": "system", "content": "..."}]}
			// - System messages should be the FIRST messages in the array
			// - Multiple system messages are allowed and will be processed in order
			//
			// Previous implementation incorrectly used a separate "system" field which caused
			// the model to ignore system instructions entirely.
			//
			// See: https://developers.cloudflare.com/workers-ai/models/.

			WP_MCP_AI_Logger::log_event(
				'cloudflare_payload_build',
				'Building payload for Cloudflare API (OpenAI format)',
				array(
					'input_message_count'      => count( $messages ),
					'normalized_message_count' => count( $normalized_messages ),
					'first_message_role'       => isset( $normalized_messages[0]['role'] ) ? $normalized_messages[0]['role'] : 'none',
				)
			);

			// Build payload with messages array (OpenAI format).
			// System messages are included in the messages array, not extracted to a separate field.
			$payload = array(
				'messages' => $normalized_messages,
			);

			// Add optional parameters.
			if ( isset( $options['temperature'] ) ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			// Additional sampling parameters supported by Cloudflare Workers AI.
			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['top_p'] = (float) $options['top_p'];
			}

			if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
				$payload['seed'] = (int) $options['seed'];
			}

			if ( isset( $options['repetition_penalty'] ) && is_numeric( $options['repetition_penalty'] ) ) {
				$payload['repetition_penalty'] = (float) $options['repetition_penalty'];
			}

			if ( isset( $options['frequency_penalty'] ) && is_numeric( $options['frequency_penalty'] ) ) {
				$payload['frequency_penalty'] = (float) $options['frequency_penalty'];
			}

			if ( isset( $options['presence_penalty'] ) && is_numeric( $options['presence_penalty'] ) ) {
				$payload['presence_penalty'] = (float) $options['presence_penalty'];
			}

			// LoRA adapter: specifies a fine-tuned LoRA model variant to use on top of the base model.
			if ( ! empty( $options['lora'] ) ) {
				$payload['lora'] = sanitize_text_field( $options['lora'] );
			}

			// Set max_tokens using Resource Manager if not explicitly provided.
			// Cloudflare defaults to only 256 tokens which is extremely low.
			// This follows the same pattern as Ollama, Gemini, LM Studio, etc.
			if ( ! isset( $options['max_tokens'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_tokens   = $resource_mgr->get_max_tokens();

				WP_MCP_AI_Logger::log_event(
					'cloudflare_default_max_tokens',
					'Using Resource Manager max_tokens for Cloudflare',
					array(
						'max_tokens' => $max_tokens,
						'tier'       => $resource_mgr->get_workload_tier(),
						'reason'     => 'Cloudflare defaults to only 256 tokens which is too low',
					)
				);

				$payload['max_tokens'] = $max_tokens;
			} else {
				$payload['max_tokens'] = (int) $options['max_tokens'];
			}

			if ( isset( $options['stream'] ) ) {
				$payload['stream'] = (bool) $options['stream'];
			}

			// Add response_format for JSON mode (OpenAI-compatible).
			// Supported values: {"type": "json_object"} or {"type": "json_schema", "json_schema": {...}}.
			// Note: JSON mode is only supported on specific Cloudflare Workers AI models (e.g., Llama 3.1+, DeepSeek).
			// Auto-JSON mode for tool calling has been removed because:
			// 1. Not all Cloudflare models support JSON mode (added Feb 25, 2025)
			// 2. Tool calling works without explicit JSON mode on supported models
			// 3. Using json_object on unsupported models causes "unknown variant" errors.
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				// User explicitly set response_format, use it.
				// Only include if it's not empty to avoid sending empty arrays.
				if ( ! empty( $options['response_format'] ) ) {
					$payload['response_format'] = $options['response_format'];
				}
			}

			// Add tools LAST, after system and messages.
			// Tools are passed from the REST controller in OpenAI format with type='function' and function definition.
			// Respect tool_choice parameter to control when tools are included and how they're used.
			$tool_choice = isset( $options['tool_choice'] ) ? $options['tool_choice'] : 'auto';

			// If tool_choice is "none", don't include tools in the payload.
			// This prevents the model from auto-triggering tools when they shouldn't be used.
			if ( 'none' !== $tool_choice && ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				$payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );

				// Add tool_choice to payload if it's not "auto" (which is the default behavior).
				// Supported values: "auto" (default), "none", "any"/"required", or specific tool.
				if ( 'auto' !== $tool_choice ) {
					$payload['tool_choice'] = $tool_choice;
				}
			}

			return $payload;
		}

		/**
		 * Normalize tools for the request payload.
		 *
		 * Ensures tools have the correct format with proper name field.
		 * Converts tool slugs/IDs to names as needed.
		 *
		 * @param array $tools Tools array to normalize.
		 * @return array Normalized tools array.
		 */
		protected function normalise_tools_for_payload( $tools ) {
			if ( $tools instanceof \Traversable ) {
				$tools = iterator_to_array( $tools );
			}

			if ( is_object( $tools ) ) {
				$tools = (array) $tools;
			}

			if ( ! is_array( $tools ) ) {
				return array();
			}

			$normalised = array();

			foreach ( $tools as $tool ) {
				if ( $tool instanceof \Traversable ) {
					$tool = iterator_to_array( $tool );
				}

				if ( is_object( $tool ) ) {
					$tool = (array) $tool;
				}

				if ( ! is_array( $tool ) || empty( $tool ) ) {
					continue;
				}

				$type = isset( $tool['type'] ) ? sanitize_key( $tool['type'] ) : '';

				if ( 'function' === $type ) {
					if ( isset( $tool['function'] ) && is_array( $tool['function'] ) ) {
						if ( isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
							$tool['name'] = (string) $tool['function']['name'];
						}
					}
				}

				if ( ! isset( $tool['name'] ) || '' === $tool['name'] ) {
					if ( isset( $tool['function'] ) && is_array( $tool['function'] ) && isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
						$tool['name'] = (string) $tool['function']['name'];
					} elseif ( isset( $tool['slug'] ) && '' !== $tool['slug'] ) {
						$tool['name'] = (string) $tool['slug'];
					} elseif ( isset( $tool['id'] ) && '' !== $tool['id'] ) {
						$tool['name'] = (string) $tool['id'];
					}
				}

				if ( ! isset( $tool['name'] ) || '' === trim( (string) $tool['name'] ) ) {
					continue;
				}

				$tool['name'] = (string) $tool['name'];

				$normalised[] = $tool;
			}

			return array_values( $normalised );
		}

		/**
		 * Normalize messages to convert content arrays to strings.
		 *
		 * Cloudflare Workers AI expects message content to be a string for text-only messages.
		 * When content is provided as an array (OpenAI format for multimodal support),
		 * we need to convert it to a string by extracting and concatenating text parts.
		 *
		 * @param array $messages Messages array with potentially array-based content.
		 * @return array Normalized messages with string content.
		 */
		protected function normalize_messages( array $messages ) {
			$normalized = array();

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				// If content is an array, convert it to a string.
				if ( is_array( $content ) ) {
					$text_parts = array();

					foreach ( $content as $segment ) {
						// Handle simple string segments.
						if ( is_string( $segment ) ) {
							$text_parts[] = $segment;
						} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
							// Handle content part objects with 'text' property.
							$text_parts[] = $segment['text'];
						}
					}

					// Join text parts with newlines.
					$content = implode( "\n", $text_parts );
				}

				// Sanitize content and skip empty messages (except for assistant messages with tool_calls).
				$content = wp_kses_post( (string) $content );
				if ( '' === trim( $content ) && 'assistant' !== $role ) {
					continue;
				}

				// Build normalized message.
				$normalized_message = array(
					'role'    => $role,
					'content' => $content,
				);

				// Preserve tool_calls if present (for assistant messages).
				if ( isset( $message['tool_calls'] ) ) {
					$normalized_message['tool_calls'] = $message['tool_calls'];
				}

				// Preserve tool_call_id if present (for tool messages).
				if ( isset( $message['tool_call_id'] ) ) {
					$normalized_message['tool_call_id'] = $message['tool_call_id'];
				}

				// Preserve name if present (for tool messages).
				if ( isset( $message['name'] ) ) {
					$normalized_message['name'] = sanitize_text_field( $message['name'] );
				}

				$normalized[] = $normalized_message;
			}

			return $normalized;
		}

		/**
		 * Normalize the API response to a standard format.
		 *
		 * @param array  $decoded Decoded API response.
		 * @param string $model   Model name.
		 * @return array
		 */
		protected function normalize_response( array $decoded, $model ) {
			$result = isset( $decoded['result'] ) ? $decoded['result'] : array();

			// Extract content from response.
			$content = '';
			if ( isset( $result['response'] ) ) {
				$content = $result['response'];
			}

			// Build the assistant message.
			$message = array(
				'role'    => 'assistant',
				'content' => $content,
			);

			// Check for tool_calls in the result (OpenAI format).
			// Cloudflare may return tool_calls when the model decides to use a tool/function.
			// We need to validate that tool_calls is not just present but also properly formatted.
			$tool_calls_found = false;

			if ( isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) && ! empty( $result['tool_calls'] ) ) {
				$tool_calls_found = true;
			}

			// Some Cloudflare models (e.g., qwen2.5-coder) output tool calls as XML text instead of using
			// the proper tool_calls array. Detect and parse this format.
			// Pattern: <name>tool_name</name><arguments>{...}</arguments>.
			if ( ! $tool_calls_found && ! empty( $content ) && $this->contains_xml_tool_call( $content ) ) {
				$parsed_tool_calls = $this->parse_xml_tool_calls( $content );

				if ( ! empty( $parsed_tool_calls ) ) {
					// Use the parsed tool calls as if they came from the API properly formatted.
					$result['tool_calls'] = $parsed_tool_calls;
					$tool_calls_found     = true;

					// Remove XML from content since it's now converted to tool_calls.
					$message['content'] = '';

					WP_MCP_AI_Logger::log_event(
						'cloudflare_xml_tool_calls_parsed',
						'Detected and parsed XML-formatted tool calls from Cloudflare model response',
						array(
							'model'            => $model,
							'tool_call_count'  => count( $parsed_tool_calls ),
							'tool_names'       => array_map(
								function ( $tc ) {
									return isset( $tc['function']['name'] ) ? $tc['function']['name'] : 'unknown';
								},
								$parsed_tool_calls
							),
							'original_content' => substr( $content, 0, 500 ),
						)
					);
				}
			}

			// Some Cloudflare models output tool calls as plain JSON text in the content
			// instead of using the proper tool_calls array or XML format.
			// Pattern: {"type": "function", "name": "tool_name", "parameters": {...}}.
			if ( ! $tool_calls_found && ! empty( $content ) && $this->contains_json_tool_call( $content ) ) {
				$parsed_tool_calls = $this->parse_json_tool_calls( $content );

				if ( ! empty( $parsed_tool_calls ) ) {
					// Use the parsed tool calls as if they came from the API properly formatted.
					$result['tool_calls'] = $parsed_tool_calls;
					$tool_calls_found     = true;

					// Remove JSON from content since it's now converted to tool_calls.
					$message['content'] = '';

					WP_MCP_AI_Logger::log_event(
						'cloudflare_json_tool_calls_parsed',
						'Detected and parsed JSON-formatted tool calls from Cloudflare model response',
						array(
							'model'            => $model,
							'tool_call_count'  => count( $parsed_tool_calls ),
							'tool_names'       => array_map(
								function ( $tc ) {
									return isset( $tc['function']['name'] ) ? $tc['function']['name'] : 'unknown';
								},
								$parsed_tool_calls
							),
							'original_content' => substr( $content, 0, 500 ),
						)
					);
				}
			}

			if ( $tool_calls_found ) {
				// Validate and normalize each tool_call to OpenAI format.
				// Cloudflare may return tool_calls in two formats:
				// 1. OpenAI format: {"function": {"name": "tool_name", "arguments": {...}}}
				// 2. Simpler format: {"name": "tool_name", "arguments": {...}}.
				$valid_tool_calls = array();
				foreach ( $result['tool_calls'] as $index => $tool_call ) {
					if ( ! is_array( $tool_call ) ) {
						continue;
					}

					$normalized_tool_call = null;

					// Check for OpenAI format first (function.name).
					if ( isset( $tool_call['function'] ) &&
						is_array( $tool_call['function'] ) &&
						isset( $tool_call['function']['name'] ) &&
						! empty( $tool_call['function']['name'] ) ) {
						// Already in OpenAI format, use as-is.
						$normalized_tool_call = $tool_call;
					} elseif ( isset( $tool_call['name'] ) && ! empty( $tool_call['name'] ) ) {
						// Cloudflare simpler format - normalize to OpenAI format.
						// The arguments field needs to be a JSON string in OpenAI format.
						$arguments = isset( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();
						if ( is_array( $arguments ) || is_object( $arguments ) ) {
							$arguments = wp_json_encode( $arguments );
						}

						$normalized_tool_call = array(
							'id'       => isset( $tool_call['id'] ) ? $tool_call['id'] : 'call_' . uniqid(),
							'type'     => 'function',
							'function' => array(
								'name'      => $tool_call['name'],
								'arguments' => $arguments,
							),
						);

						WP_MCP_AI_Logger::log_event(
							'cloudflare_tool_call_normalized',
							'Normalized Cloudflare simpler format to OpenAI format',
							array(
								'original_format' => $tool_call,
								'normalized'      => $normalized_tool_call,
							)
						);
					}

					if ( $normalized_tool_call ) {
						$valid_tool_calls[] = $normalized_tool_call;
					} else {
						WP_MCP_AI_Logger::log_event(
							'cloudflare_invalid_tool_call',
							'Cloudflare returned malformed tool_call',
							array(
								'tool_call_structure' => $tool_call,
								'missing_function'    => ! isset( $tool_call['function'] ),
								'missing_name'        => ! isset( $tool_call['name'] ),
								'index'               => $index,
							)
						);
					}
				}

				// Only add tool_calls to message if we have valid ones.
				if ( ! empty( $valid_tool_calls ) ) {
					$message['tool_calls'] = $valid_tool_calls;

					WP_MCP_AI_Logger::log_event(
						'cloudflare_tool_calls_detected',
						'Cloudflare response contains valid tool_calls',
						array(
							'tool_call_count' => count( $valid_tool_calls ),
							'tool_names'      => array_map(
								function ( $tc ) {
									return isset( $tc['function']['name'] ) ? $tc['function']['name'] : 'unknown';
								},
								$valid_tool_calls
							),
							'has_content'     => ! empty( $content ),
							'content_preview' => substr( $content, 0, 100 ),
						)
					);
				} else {
					WP_MCP_AI_Logger::log_event(
						'cloudflare_tool_calls_filtered',
						'Cloudflare tool_calls array was present but contained no valid tool calls',
						array(
							'original_count' => count( $result['tool_calls'] ),
							'valid_count'    => 0,
						)
					);
				}
			}

			$has_tool_calls = isset( $message['tool_calls'] ) && ! empty( $message['tool_calls'] );

			// Extract usage data if available from Cloudflare API.
			// Cloudflare Workers AI may include usage in the response or result.
			$usage = array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
			);

			// Check if usage data is in the decoded response (top-level).
			if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
				$usage = $this->extract_usage_data( $decoded['usage'] );
			} elseif ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
				// Some endpoints may include usage within the result object.
				$usage = $this->extract_usage_data( $result['usage'] );
			}

			// If no usage data was provided by Cloudflare, estimate based on content length.
			if ( 0 === $usage['prompt_tokens'] && 0 === $usage['completion_tokens'] && 0 === $usage['total_tokens'] ) {
				$usage = $this->estimate_token_usage( $content );
			}

			// Add provider and model to usage for tracking.
			$usage['provider'] = 'cloudflare';
			$usage['model']    = $model;

			// Determine finish_reason based on whether tool_calls are present.
			// Note: Cloudflare may return finish_reason as 'stop' even with tool_calls,
			// but we normalize this to match OpenAI's behavior for compatibility.
			$finish_reason = $has_tool_calls ? 'tool_calls' : 'stop';

			return array(
				'id'       => uniqid( 'cloudflare-', true ),
				'object'   => 'chat.completion',
				'created'  => time(),
				'model'    => $model,
				'provider' => 'cloudflare',
				'choices'  => array(
					array(
						'index'         => 0,
						'message'       => $message,
						'finish_reason' => $finish_reason,
					),
				),
				'usage'    => $usage,
			);
		}

		/**
		 * Extract usage data from Cloudflare API usage object.
		 *
		 * @param array $usage_data Raw usage data from API.
		 * @return array Normalized usage array.
		 */
		protected function extract_usage_data( array $usage_data ) {
			$prompt_tokens     = isset( $usage_data['prompt_tokens'] ) ? max( 0, (int) $usage_data['prompt_tokens'] ) : 0;
			$completion_tokens = isset( $usage_data['completion_tokens'] ) ? max( 0, (int) $usage_data['completion_tokens'] ) : 0;
			$total_tokens      = isset( $usage_data['total_tokens'] ) ? max( 0, (int) $usage_data['total_tokens'] ) : 0;

			// Calculate total if not provided.
			if ( 0 === $total_tokens && ( $prompt_tokens > 0 || $completion_tokens > 0 ) ) {
				$total_tokens = $prompt_tokens + $completion_tokens;
			}

			return array(
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
			);
		}

		/**
		 * Estimate token usage when not provided by the API.
		 *
		 * Uses a simple heuristic: ~4 characters per token (average for English text).
		 * This is an approximation and should be marked as estimated in tracking.
		 *
		 * @param string $content Response content.
		 * @return array Estimated usage array.
		 */
		protected function estimate_token_usage( $content ) {
			// Rough estimation: ~4 characters per token (standard approximation).
			$estimated_completion_tokens = max( 1, (int) ceil( strlen( $content ) / 4 ) );

			WP_MCP_AI_Logger::log_event(
				'cloudflare_estimated_usage',
				'Cloudflare response did not include usage data. Using estimation.',
				array(
					'estimated_completion_tokens' => $estimated_completion_tokens,
					'content_length'              => strlen( $content ),
				)
			);

			return array(
				'prompt_tokens'     => 0, // Cannot estimate prompt without request data.
				'completion_tokens' => $estimated_completion_tokens,
				'total_tokens'      => $estimated_completion_tokens,
			);
		}

		/**
		 * Resolve the model to use for the request.
		 *
		 * @param array $options Request options.
		 * @return string
		 */
		protected function resolve_model( array $options ) {
			if ( isset( $options['model'] ) && ! empty( $options['model'] ) ) {
				return sanitize_text_field( $options['model'] );
			}

			return $this->get_model();
		}

		/**
		 * Resolve the timeout to use for the request.
		 *
		 * @param array $options Request options.
		 * @return int
		 */
		protected function resolve_timeout( array $options ) {
			if ( isset( $options['timeout'] ) ) {
				return (int) $options['timeout'];
			}

			// Default timeout for Cloudflare Workers AI.
			return 60;
		}

		/**
		 * Obfuscate sensitive data from request for logging.
		 *
		 * @param array $payload Request payload.
		 * @return array
		 */
		protected function obfuscate_request_for_log( array $payload ) {
			$safe_payload = $payload;

			// Remove message content for privacy.
			if ( isset( $safe_payload['messages'] ) ) {
				$safe_payload['messages'] = array(
					'count' => count( $safe_payload['messages'] ),
				);
			}

			return $safe_payload;
		}

		/**
		 * Build additional system messages from memory documents.
		 *
		 * @param array $options Chat request options containing memory_documents.
		 * @return array Array of system messages for memory documents.
		 */
		protected function build_memory_messages_from_options( array $options ) {
			if ( empty( $options['memory_documents'] ) || ! is_array( $options['memory_documents'] ) ) {
				return array();
			}

			$messages = array();

			foreach ( $options['memory_documents'] as $document ) {
				if ( empty( $document['chunks'] ) || ! is_array( $document['chunks'] ) ) {
					continue;
				}

				$title      = isset( $document['title'] ) && '' !== $document['title'] ? sanitize_text_field( $document['title'] ) : __( 'Document', 'mcp-ai-wpoos' );
				$chunks     = array_values( array_filter( array_map( 'strval', $document['chunks'] ) ) );
				$parts      = count( $chunks );
				$part_index = 0;

				foreach ( $chunks as $chunk ) {
					++$part_index;

					$label = $title;

					if ( $parts > 1 ) {
						/* translators: %1$s: document title, %2$d: chunk number. */
						$label = sprintf( __( '%1$s (Part %2$d)', 'mcp-ai-wpoos' ), $title, $part_index );
					}

					$messages[] = array(
						'role'    => 'system',
						/* translators: %1$s: document title, %2$s: extracted text snippet. */
						'content' => sprintf( __( 'Reference document "%1$s": %2$s', 'mcp-ai-wpoos' ), $label, wp_kses_post( $chunk ) ),
					);
				}
			}

			return $messages;
		}

		/**
		 * Generate an image using Cloudflare Workers AI.
		 *
		 * @param string $prompt  Text prompt for image generation.
		 * @param array  $options Optional parameters (model, width, height, num_steps, guidance, seed, timeout).
		 * @return array|WP_Error Image data array or error.
		 */
		public function generate_image( $prompt, array $options = array() ) {
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_api_token' => __( 'Add a Cloudflare API token in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_account_id' => __( 'Add a Cloudflare account ID in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $this->get_model();

			if ( empty( $model ) ) {
				// Default to stable-diffusion-xl-base-1.0 if no model is configured.
				$model = '@cf/stabilityai/stable-diffusion-xl-base-1.0';
			}

			// Validate model ID format.
			if ( ! preg_match( '/^@[a-zA-Z0-9\/_.-]+$/', $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_model_id',
					__( 'Invalid Cloudflare model ID format.', 'mcp-ai-wpoos' ),
					array( 'model' => $model )
				);
			}

			// Build request payload.
			$payload = array(
				'prompt' => sanitize_textarea_field( $prompt ),
			);

			// Add optional parameters if provided.
			if ( isset( $options['width'] ) && is_numeric( $options['width'] ) ) {
				$payload['width'] = max( 256, min( 2048, absint( $options['width'] ) ) );
			}

			if ( isset( $options['height'] ) && is_numeric( $options['height'] ) ) {
				$payload['height'] = max( 256, min( 2048, absint( $options['height'] ) ) );
			}

			if ( isset( $options['num_steps'] ) && is_numeric( $options['num_steps'] ) ) {
				$payload['num_steps'] = max( 1, min( 20, absint( $options['num_steps'] ) ) );
			}

			if ( isset( $options['guidance'] ) && is_numeric( $options['guidance'] ) ) {
				$payload['guidance'] = (float) $options['guidance'];
			}

			if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
				$payload['seed'] = absint( $options['seed'] );
			}

			// Escape model for URL path (preserve forward slashes).
			$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

			$url = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
				rawurlencode( $account_id ),
				$escaped_model
			);

			$timeout = isset( $options['timeout'] ) && $options['timeout'] > 0 ? absint( $options['timeout'] ) : 60;

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_image_request',
				'Sending image generation request to Cloudflare Workers AI.',
				array(
					'model'  => $model,
					'width'  => isset( $payload['width'] ) ? $payload['width'] : 'default',
					'height' => isset( $payload['height'] ) ? $payload['height'] : 'default',
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'cloudflare_image_error',
					'Cloudflare Workers AI image generation failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Cloudflare Workers AI image generation request failed.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				// Parse Cloudflare error response.
				$error_message = __( 'Cloudflare Workers AI returned an error.', 'mcp-ai-wpoos' );
				$decoded_body  = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['errors'] ) && is_array( $decoded_body['errors'] ) ) {
					foreach ( $decoded_body['errors'] as $error ) {
						if ( isset( $error['message'] ) ) {
							$error_message .= ' ' . sanitize_text_field( $error['message'] );
							if ( isset( $error['code'] ) ) {
								$error_message .= ' (Code: ' . absint( $error['code'] ) . ')';
							}
							break;
						}
					}
				}

				WP_MCP_AI_Logger::log_error(
					'cloudflare_image_error',
					'Cloudflare Workers AI returned an error.',
					array(
						'status' => $code,
						'body'   => $body,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $body,
					)
				);
			}

			// Cloudflare can return either binary image data or JSON with base64 encoded image.
			// Check content type to determine format.
			$content_type = wp_remote_retrieve_header( $response, 'content-type' );

			if ( false !== strpos( $content_type, 'application/json' ) ) {
				// JSON response with base64 encoded image.
				$decoded = json_decode( $body, true );

				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_response',
						__( 'Invalid JSON response from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
						array( 'body' => $body )
					);
				}

				if ( ! isset( $decoded['result'] ) || ! isset( $decoded['result']['image'] ) ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_response',
						__( 'Unexpected response format from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
						array( 'decoded' => $decoded )
					);
				}

				// Base64 encoded image data.
				$image_data = base64_decode( $decoded['result']['image'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary image data from Cloudflare Workers AI API response.

				if ( false === $image_data || '' === $image_data ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_image',
						__( 'Failed to decode base64 image data from Cloudflare Workers AI.', 'mcp-ai-wpoos' )
					);
				}

				return array(
					'image'     => $image_data,
					'format'    => 'png', // Most Cloudflare models return PNG.
					'mime_type' => 'image/png',
					'model'     => $model,
					'created'   => time(),
					'bytes'     => strlen( $image_data ),
					'width'     => isset( $payload['width'] ) ? $payload['width'] : null,
					'height'    => isset( $payload['height'] ) ? $payload['height'] : null,
					'num_steps' => isset( $payload['num_steps'] ) ? $payload['num_steps'] : null,
					'provider'  => 'cloudflare',
				);
			} else {
				// Binary image data (PNG, JPEG, etc.).
				$image_data = $body;

				if ( '' === $image_data ) {
					return new WP_Error(
						'wp_mcp_ai_empty_image',
						__( 'Cloudflare Workers AI returned an empty image.', 'mcp-ai-wpoos' )
					);
				}

				// Detect image format from binary data.
				$format    = 'png'; // Default.
				$mime_type = 'image/png';

				// Check for PNG signature.
				if ( 0 === strpos( $image_data, "\x89PNG" ) ) {
					$format    = 'png';
					$mime_type = 'image/png';
				} elseif ( 0 === strpos( $image_data, "\xFF\xD8\xFF" ) ) {
					// Check for JPEG signature.
					$format    = 'jpeg';
					$mime_type = 'image/jpeg';
				} elseif ( 0 === strpos( $image_data, 'RIFF' ) && false !== strpos( substr( $image_data, 0, 12 ), 'WEBP' ) ) {
					// Check for WebP signature.
					$format    = 'webp';
					$mime_type = 'image/webp';
				}

				return array(
					'image'     => $image_data,
					'format'    => $format,
					'mime_type' => $mime_type,
					'model'     => $model,
					'created'   => time(),
					'bytes'     => strlen( $image_data ),
					'width'     => isset( $payload['width'] ) ? $payload['width'] : null,
					'height'    => isset( $payload['height'] ) ? $payload['height'] : null,
					'num_steps' => isset( $payload['num_steps'] ) ? $payload['num_steps'] : null,
					'provider'  => 'cloudflare',
				);
			}
		}

		/**
		 * Run chat completion with embedded function calling support (ai-utils style).
		 *
		 * This method provides a PHP equivalent to the @cloudflare/ai-utils runWithTools() utility,
		 * enabling embedded function calling with Cloudflare Workers AI models.
		 *
		 * @since 1.0.0
		 *
		 * @param array $messages  Message payload to send.
		 * @param array $tools     Array of tool definitions with executable functions.
		 * @param array $options   Additional options:
		 *                         - model: Model to use (default: configured model)
		 *                         - temperature: Temperature setting (0-1)
		 *                         - max_tokens: Maximum tokens to generate
		 *                         - tool_choice: Control tool usage - "auto" (default), "none", "required", "any", or specific tool
		 *                         - response_format: JSON mode config - {type: "json_object"} or {type: "json_schema", json_schema: {...}}
		 *                           Note: Only supported on specific models (Llama 3.1+, DeepSeek, etc.). Not auto-enabled.
		 *                         - strictValidation: Validate tool arguments before execution (default: true)
		 *                         - maxRecursiveToolRuns: Maximum recursive tool call depth (default: 5)
		 *                         - streamFinalResponse: Return streaming response (default: false).
		 *                         - verbose: Enable verbose logging (default: false)
		 *                         - autoTrimTools: Automatically trim tools based on context (default: false)
		 *                         - maxTools: Maximum tools when auto-trimming (default: 10)
		 *                         - timeout: Request timeout in seconds.
		 * @return array|WP_Error Response array or error.
		 * @throws Exception If tool function is not callable.
		 */
		public function run_with_tools( array $messages, array $tools = array(), array $options = array() ) {
			// Configuration options with defaults.
			$strict_validation     = isset( $options['strictValidation'] ) ? (bool) $options['strictValidation'] : true;
			$max_recursive_runs    = isset( $options['maxRecursiveToolRuns'] ) ? absint( $options['maxRecursiveToolRuns'] ) : 5;
			$stream_final_response = isset( $options['streamFinalResponse'] ) ? (bool) $options['streamFinalResponse'] : false;
			$verbose               = isset( $options['verbose'] ) ? (bool) $options['verbose'] : false;
			$auto_trim_tools       = isset( $options['autoTrimTools'] ) ? (bool) $options['autoTrimTools'] : false;

			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					'cloudflare_run_with_tools_start',
					'Starting Cloudflare Workers AI embedded function calling.',
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
						'cloudflare_auto_trim_tools',
						'Automatically trimmed tools based on context.',
						array( 'remaining_tool_count' => count( $tools ) )
					);
				}
			}

			// Convert tools to Cloudflare format and create tool lookup.
			$tool_definitions = array();
			$tool_functions   = array();

			foreach ( $tools as $tool ) {
				if ( ! isset( $tool['name'] ) || ! isset( $tool['function'] ) ) {
					continue;
				}

				$tool_name = sanitize_text_field( $tool['name'] );

				// Build tool definition for API.
				$definition = array(
					'name'        => $tool_name,
					'description' => isset( $tool['description'] ) ? sanitize_text_field( $tool['description'] ) : '',
				);

				if ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
					$definition['parameters'] = $tool['parameters'];
				}

				$tool_definitions[] = array(
					'type'     => 'function',
					'function' => $definition,
				);

				// Store executable function.
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
						'cloudflare_tool_run_iteration',
						sprintf( 'Tool execution iteration %d/%d', $recursion_count, $max_recursive_runs ),
						array( 'message_count' => count( $conversation_messages ) )
					);
				}

				// Make API request.
				$response = $this->create_chat_completion( $conversation_messages, $request_options );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// Check if model wants to call any tools.
				$tool_calls = array();
				if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
					$tool_calls = $response['choices'][0]['message']['tool_calls'];
				}

				// If no tool calls, we're done.
				if ( empty( $tool_calls ) ) {
					if ( $verbose ) {
						WP_MCP_AI_Logger::log_event(
							'cloudflare_run_with_tools_complete',
							'Completed without tool calls.',
							array( 'iterations' => $recursion_count )
						);
					}

					// Return final response (optionally as stream).
					if ( $stream_final_response ) {
						// For PHP, we can't actually stream, so just return the response.
						// In a real Workers environment, this would use ReadableStream.
						return $response;
					}

					return $response;
				}

				// Add assistant's tool call message to conversation.
				$conversation_messages[] = $response['choices'][0]['message'];

				// Execute each tool call.
				foreach ( $tool_calls as $tool_call ) {
					if ( ! isset( $tool_call['function']['name'] ) ) {
						continue;
					}

					$function_name = $tool_call['function']['name'];
					$tool_call_id  = isset( $tool_call['id'] ) ? $tool_call['id'] : uniqid( 'tool-', true );

					// Check if function exists.
					if ( ! isset( $tool_functions[ $function_name ] ) ) {
						$error_message = sprintf(
							/* translators: %s: function name */
							__( 'Tool function "%s" not found.', 'mcp-ai-wpoos' ),
							$function_name
						);

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => wp_json_encode( array( 'error' => $error_message ) ),
						);

						WP_MCP_AI_Logger::log_error(
							'Cloudflare tool function not found.',
							array(
								'function_name' => $function_name,
								'tool_call_id'  => $tool_call_id,
							)
						);
						continue;
					}

					// Parse arguments.
					$arguments = array();
					if ( isset( $tool_call['function']['arguments'] ) ) {
						$args_json = $tool_call['function']['arguments'];
						if ( is_string( $args_json ) ) {
							$arguments = json_decode( $args_json, true );
							if ( JSON_ERROR_NONE !== json_last_error() ) {
								$arguments = array();
							}
						} elseif ( is_array( $args_json ) ) {
							$arguments = $args_json;
						}
					}

					// Validate arguments if strict validation is enabled.
					if ( $strict_validation ) {
						$validation_error = $this->validate_tool_arguments( $function_name, $arguments, $tool_definitions );
						if ( is_wp_error( $validation_error ) ) {
							$conversation_messages[] = array(
								'role'         => 'tool',
								'tool_call_id' => $tool_call_id,
								'name'         => $function_name,
								'content'      => wp_json_encode( array( 'error' => $validation_error->get_error_message() ) ),
							);

							WP_MCP_AI_Logger::log_error(
								'Cloudflare tool argument validation failed.',
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

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => $result_content,
						);

						if ( $verbose ) {
							WP_MCP_AI_Logger::log_event(
								'cloudflare_tool_executed',
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

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => wp_json_encode( array( 'error' => $error_message ) ),
						);

						WP_MCP_AI_Logger::log_error(
							'Cloudflare tool execution failed.',
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
					'cloudflare_max_recursion_reached',
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
				if ( isset( $tool_def['function']['name'] ) && $tool_def['function']['name'] === $function_name ) {
					$tool_schema = isset( $tool_def['function']['parameters'] ) ? $tool_def['function']['parameters'] : null;
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
		 * In a production environment, this could use more sophisticated NLP or embedding-based
		 * similarity matching.
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
							++$score;
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
		 * Check if content contains XML-formatted tool calls.
		 *
		 * Some Cloudflare models (especially code-focused models like qwen2.5-coder)
		 * output tool calls as XML text instead of using the proper tool_calls array.
		 *
		 * Pattern examples:
		 * - <name>tool_name</name><arguments>{...}</arguments>
		 * - ```xml\n<name>tool_name</name>\n<arguments>{...}</arguments>\n```
		 *
		 * @since 1.0.0
		 *
		 * @param string $content Response content to check.
		 * @return bool True if content contains XML tool call pattern.
		 */
		protected function contains_xml_tool_call( $content ) {
			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				return false;
			}

			// Check for XML tool call pattern: <name>...</name> followed by <arguments>...</arguments>
			// Allow for optional whitespace, newlines, and code block markers.
			$pattern = '/<name>\s*([^<]+)\s*<\/name>\s*<arguments>\s*(\{[^}]*\}|\[[^\]]*\])\s*<\/arguments>/is';

			return (bool) preg_match( $pattern, $content );
		}

		/**
		 * Parse XML-formatted tool calls from content.
		 *
		 * Extracts tool name and arguments from XML text and converts to OpenAI format.
		 *
		 * @since 1.0.0
		 *
		 * @param string $content Response content containing XML tool calls.
		 * @return array Array of tool calls in OpenAI format, or empty array if parsing fails.
		 */
		protected function parse_xml_tool_calls( $content ) {
			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				return array();
			}

			$tool_calls = array();

			// Pattern to match XML tool calls.
			// Captures: <name>tool_name</name><arguments>{...}</arguments>.
			$pattern = '/<name>\s*([^<]+)\s*<\/name>\s*<arguments>\s*(\{[^}]*\}|\[[^\]]*\])\s*<\/arguments>/is';

			$matches = array();
			if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$tool_name      = trim( $match[1] );
					$arguments_json = trim( $match[2] );

					// Validate tool name.
					if ( empty( $tool_name ) ) {
						WP_MCP_AI_Logger::log_event(
							'cloudflare_xml_tool_call_parse_error',
							'Found XML tool call but tool name is empty',
							array(
								'raw_match' => $match[0],
							)
						);
						continue;
					}

					// Validate and parse JSON arguments.
					$arguments_array = json_decode( $arguments_json, true );
					if ( JSON_ERROR_NONE !== json_last_error() ) {
						WP_MCP_AI_Logger::log_event(
							'cloudflare_xml_tool_call_parse_error',
							'Found XML tool call but arguments JSON is invalid',
							array(
								'tool_name'      => $tool_name,
								'arguments_json' => $arguments_json,
								'json_error'     => json_last_error_msg(),
							)
						);
						continue;
					}

					// Convert to OpenAI format.
					$tool_calls[] = array(
						'id'       => 'call_xml_' . uniqid(),
						'type'     => 'function',
						'function' => array(
							'name'      => sanitize_text_field( $tool_name ),
							'arguments' => wp_json_encode( $arguments_array ),
						),
					);

					WP_MCP_AI_Logger::log_event(
						'cloudflare_xml_tool_call_parsed',
						'Successfully parsed XML tool call',
						array(
							'tool_name' => $tool_name,
							'arguments' => $arguments_array,
						)
					);
				}
			}

			return $tool_calls;
		}

		/**
		 * Check if content contains JSON-formatted tool calls.
		 *
		 * Some Cloudflare models output tool calls as plain JSON text in the content
		 * instead of using the proper tool_calls array format.
		 *
		 * Pattern examples:
		 * - {"type": "function", "name": "tool_name", "parameters": {...}}
		 * - {"type": "function", "name": "tool_name", "arguments": {...}}
		 *
		 * @since 1.0.0
		 *
		 * @param string $content Response content to check.
		 * @return bool True if content contains JSON tool call pattern.
		 */
		protected function contains_json_tool_call( $content ) {
			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				return false;
			}

			// Try to decode as JSON first.
			$decoded = json_decode( trim( $content ), true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return false;
			}

			// Check if it matches tool call structure.
			if ( ! is_array( $decoded ) ) {
				return false;
			}

			// Check for function call pattern with type and name fields.
			// Accept both "parameters" and "arguments" field names.
			if ( isset( $decoded['type'] ) && 'function' === $decoded['type'] &&
				isset( $decoded['name'] ) && is_string( $decoded['name'] ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Parse JSON-formatted tool calls from content.
		 *
		 * Extracts tool name and arguments/parameters from JSON text and converts to OpenAI format.
		 *
		 * @since 1.0.0
		 *
		 * @param string $content Response content containing JSON tool calls.
		 * @return array Array of tool calls in OpenAI format, or empty array if parsing fails.
		 */
		protected function parse_json_tool_calls( $content ) {
			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				return array();
			}

			$tool_calls = array();

			// Try to decode the content as JSON.
			$decoded = json_decode( trim( $content ), true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_event(
					'cloudflare_json_tool_call_parse_error',
					'Failed to decode JSON tool call',
					array(
						'content'    => substr( $content, 0, 500 ),
						'json_error' => json_last_error_msg(),
					)
				);
				return array();
			}

			if ( ! is_array( $decoded ) ) {
				return array();
			}

			// Extract tool name.
			$tool_name = isset( $decoded['name'] ) ? trim( $decoded['name'] ) : '';

			if ( empty( $tool_name ) ) {
				WP_MCP_AI_Logger::log_event(
					'cloudflare_json_tool_call_parse_error',
					'Found JSON tool call but tool name is empty',
					array(
						'decoded' => $decoded,
					)
				);
				return array();
			}

			// Extract arguments - accept both "parameters" and "arguments" field names.
			$arguments = null;
			if ( isset( $decoded['parameters'] ) ) {
				$arguments = $decoded['parameters'];
			} elseif ( isset( $decoded['arguments'] ) ) {
				$arguments = $decoded['arguments'];
			}

			// Ensure arguments is an array or object.
			if ( ! is_array( $arguments ) && ! is_object( $arguments ) ) {
				$arguments = array();
			}

			// Convert to OpenAI format.
			$tool_calls[] = array(
				'id'       => 'call_json_' . uniqid(),
				'type'     => 'function',
				'function' => array(
					'name'      => sanitize_text_field( $tool_name ),
					'arguments' => wp_json_encode( $arguments ),
				),
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_json_tool_call_parsed',
				'Successfully parsed JSON tool call',
				array(
					'tool_name' => $tool_name,
					'arguments' => $arguments,
				)
			);

			return $tool_calls;
		}

		/**
		 * Check if tools array contains executable functions.
		 *
		 * For embedded function calling (run_with_tools), each tool must have
		 * a 'function' key with a callable value. This method checks if the
		 * provided tools array contains executables for embedded calling.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tools Array of tool definitions.
		 * @return bool True if tools have executable functions, false otherwise.
		 */
		protected function tools_have_executables( $tools ) {
			if ( empty( $tools ) || ! is_array( $tools ) ) {
				return false;
			}

			foreach ( $tools as $tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}

				// Check if tool has executable function.
				if ( isset( $tool['function'] ) && is_callable( $tool['function'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Transcribe audio using Cloudflare Workers AI Whisper model.
		 *
		 * @param string $file_path Path to the audio file.
		 * @param array  $options   Additional options (model, language, etc.).
		 * @return array|WP_Error Transcription result or error.
		 */
		public function transcribe_audio( $file_path, array $options = array() ) {
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_token' => __( 'Add a Cloudflare API token in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_account' => __( 'Add a Cloudflare account ID in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_path = (string) $file_path;

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_transcription_missing_file',
					__( 'The audio file to transcribe could not be located.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			// Cloudflare Workers AI Whisper model.
			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : '@cf/openai/whisper';

			// Build the URL with proper encoding for @ symbol.
			$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );
			$url           = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
				rawurlencode( $account_id ),
				$escaped_model
			);

			// Read file content.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin or temp file; WP_Filesystem is not available in this REST/cron/tool execution context.
			$file_data = file_get_contents( $file_path );

			if ( false === $file_data ) {
				return new WP_Error(
					'wp_mcp_ai_file_read_error',
					__( 'Could not read the audio file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			// Get timeout.
			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : 60;
			$timeout = max( 5, $timeout );

			// Cloudflare Workers AI expects the audio file as binary data in the request body.
			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/octet-stream',
				),
				'body'    => $file_data,
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_transcribe_audio',
				'Sending audio transcription request to Cloudflare Workers AI.',
				array(
					'model'     => $model,
					'file_size' => strlen( $file_data ),
					'timeout'   => $timeout,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI audio transcription failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Cloudflare Workers AI audio transcription request failed.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				$error_message = __( 'Cloudflare Workers AI audio transcription returned an error.', 'mcp-ai-wpoos' );
				$decoded_body  = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['errors'] ) && is_array( $decoded_body['errors'] ) ) {
					foreach ( $decoded_body['errors'] as $error ) {
						if ( isset( $error['message'] ) ) {
							$error_message .= ' ' . sanitize_text_field( $error['message'] );

							// Provide helpful context for common errors.
							if ( 404 === $code || false !== strpos( strtolower( $error['message'] ), 'no route' ) ) {
								$error_message .= ' ' . __( 'The Whisper model may not be available for your Cloudflare account. Verify the model name (@cf/openai/whisper or @cf/openai/whisper-large-v3-turbo) is correct and enabled.', 'mcp-ai-wpoos' );
							}
							break;
						}
					}
				}

				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI audio transcription error.',
					array(
						'status'      => $code,
						'body'        => $body,
						'model'       => $model,
						'account_id'  => $account_id,
						'request_url' => $url,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status'  => $code,
						'body'    => $body,
						'actions' => array(
							'verify_cloudflare_model' => __( 'Check that the Whisper model is available in your Cloudflare Workers AI account.', 'mcp-ai-wpoos' ),
							'check_account_limits'    => __( 'Verify your Cloudflare account has access to audio transcription features.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid JSON response from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'cloudflare_transcribe_audio_success',
				'Successfully transcribed audio with Cloudflare Workers AI.',
				array(
					'model'         => $model,
					'has_text'      => isset( $decoded['result']['text'] ),
					'response_keys' => is_array( $decoded ) ? array_keys( $decoded ) : array(),
				)
			);

			// Cloudflare Workers AI Whisper returns: {"result": {"text": "transcription", "vtt": "..."}}.
			// Normalize to OpenAI format for consistency.
			if ( isset( $decoded['result']['text'] ) ) {
				$text = trim( $decoded['result']['text'] );
				if ( '' === $text ) {
					return new WP_Error(
						'wp_mcp_ai_empty_transcription',
						__( 'Cloudflare Workers AI returned an empty transcription.', 'mcp-ai-wpoos' ),
						array( 'response' => $decoded )
					);
				}
				return array(
					'text'   => $text,
					'model'  => $model,
					'format' => 'json',
					'raw'    => $decoded,
				);
			}

			// Unexpected response format.
			return new WP_Error(
				'wp_mcp_ai_unexpected_response',
				__( 'Unexpected response format from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
				array( 'response' => $decoded )
			);
		}

		/**
		 * Generate speech audio from text using Cloudflare Workers AI TTS models.
		 *
		 * Note: Cloudflare Workers AI does not currently support Text-to-Speech (TTS) models.
		 * This method will return an error indicating that TTS is not available.
		 * Consider using OpenAI, Google Gemini, or Hugging Face providers for TTS functionality.
		 *
		 * @param string $text    Text to convert to speech.
		 * @param array  $options Optional configuration (model, voice, format, speed, timeout).
		 * @return WP_Error Always returns error as TTS is not supported by Cloudflare Workers AI.
		 */
		public function generate_speech( $text, array $options = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for future TTS implementation.
			// Cloudflare Workers AI does not currently support TTS models.
			// Models like @cf/deepgram/aura-2-en and @cf/myshell-ai/melotts do not exist in the catalog.
			return new WP_Error(
				'wp_mcp_ai_cloudflare_tts_unsupported',
				__( 'Text-to-speech (TTS) is not currently supported by Cloudflare Workers AI. Please use OpenAI, Google Gemini, or Hugging Face providers for speech generation features.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 501, // Not Implemented.
					'actions' => array(
						'switch_provider'    => __( 'Switch your assistant to OpenAI, Gemini, or Hugging Face provider.', 'mcp-ai-wpoos' ),
						'configure_openai'   => __( 'Configure an OpenAI API key as a fallback for TTS.', 'mcp-ai-wpoos' ),
						'cloudflare_catalog' => __( 'Check Cloudflare Workers AI model catalog for updates.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		/**
		 * Detect voice activity and turn completion using Cloudflare Workers AI.
		 *
		 * Uses @cf/pipecat-ai/smart-turn-v2 model for Voice Activity Detection (VAD).
		 * This model detects when a speaker has finished their conversational turn,
		 * enabling more natural voice interactions.
		 *
		 * @param string $file_path Path to audio file (PCM format recommended).
		 * @param array  $options   Optional configuration (timeout).
		 * @return array|WP_Error Array with 'is_complete' (bool) and 'probability' (float) on success.
		 */
		public function detect_turn_completion( $file_path, array $options = array() ) {
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_token' => __( 'Add a Cloudflare API token in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_account' => __( 'Add a Cloudflare account ID in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_path = (string) $file_path;

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'Audio file not found.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Smart Turn v2 model for VAD.
			$model = '@cf/pipecat-ai/smart-turn-v2';

			// Build the URL.
			$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );
			$url           = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
				rawurlencode( $account_id ),
				$escaped_model
			);

			// Read file content.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin or temp file; WP_Filesystem is not available in this REST/cron/tool execution context.
			$file_data = file_get_contents( $file_path );

			if ( false === $file_data ) {
				return new WP_Error(
					'wp_mcp_ai_file_read_error',
					__( 'Could not read the audio file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			// Get timeout.
			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : 30;
			$timeout = max( 5, $timeout );

			// Cloudflare Workers AI expects the audio file as binary data (PCM format preferred).
			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/octet-stream',
				),
				'body'    => $file_data,
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_vad_request',
				'Sending VAD turn detection request to Cloudflare Workers AI.',
				array(
					'model'     => $model,
					'file_size' => strlen( $file_data ),
					'timeout'   => $timeout,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI VAD request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Cloudflare Workers AI VAD request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				$decoded = json_decode( $body, true );
				$error   = json_last_error();

				if ( JSON_ERROR_NONE === $error && isset( $decoded['errors'][0]['message'] ) ) {
					$message = $decoded['errors'][0]['message'];
				} else {
					$message = __( 'Unexpected response from Cloudflare Workers AI.', 'mcp-ai-wpoos' );
				}

				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI VAD request returned an error.',
					array(
						'status'   => $status_code,
						'response' => JSON_ERROR_NONE === $error ? $decoded : $body,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$message,
					array( 'status' => $status_code )
				);
			}

			if ( '' === $body ) {
				return new WP_Error(
					'wp_mcp_ai_empty_response',
					__( 'Cloudflare Workers AI returned an empty VAD response.', 'mcp-ai-wpoos' )
				);
			}

			// Parse JSON response.
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid JSON response from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'cloudflare_vad_success',
				'Successfully detected turn completion with Cloudflare Workers AI.',
				array(
					'model'         => $model,
					'is_complete'   => isset( $decoded['result']['is_complete'] ) ? $decoded['result']['is_complete'] : null,
					'probability'   => isset( $decoded['result']['probability'] ) ? $decoded['result']['probability'] : null,
					'response_keys' => is_array( $decoded ) ? array_keys( $decoded ) : array(),
				)
			);

			// Smart Turn v2 response has a result object with is_complete (bool) and probability (float 0–1) fields.
			if ( isset( $decoded['result']['is_complete'] ) ) {
				return array(
					'is_complete' => (bool) $decoded['result']['is_complete'],
					'probability' => isset( $decoded['result']['probability'] ) ? (float) $decoded['result']['probability'] : 0.0,
					'raw'         => $decoded,
				);
			}

			// Unexpected response format.
			return new WP_Error(
				'wp_mcp_ai_unexpected_response',
				__( 'Unexpected response format from Cloudflare Workers AI VAD.', 'mcp-ai-wpoos' ),
				array( 'response' => $decoded )
			);
		}
	}
}
