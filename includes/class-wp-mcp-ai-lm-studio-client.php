<?php
/**
 * LM Studio API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
	/**
	 * Provides a wrapper around LM Studio's OpenAI-compatible endpoints.
	 * LM Studio implements the OpenAI API format, so this is essentially
	 * a lightweight adapter that uses local endpoints instead of api.openai.com.
	 */
	class WP_MCP_AI_LM_Studio_Client {

		/**
		 * Get the configured network interface for HTTP requests.
		 *
		 * @return string
		 */
		public function get_network_interface() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['lm_studio_network_interface'] ) ? sanitize_text_field( $settings['lm_studio_network_interface'] ) : '';
		}

		/**
		 * Retrieve the configured LM Studio endpoint URL.
		 *
		 * @return string
		 */
		public function get_endpoint_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['lm_studio_endpoint_url'] ) ? $settings['lm_studio_endpoint_url'] : '';
		}

		/**
		 * Retrieve the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['lm_studio_model'] ) ? $settings['lm_studio_model'] : '';
		}

		/**
		 * Test the connection to the LM Studio instance.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$url = untrailingslashit( $endpoint_url ) . '/v1/models';

			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array( 'Accept' => 'application/json' ),
			);

			WP_MCP_AI_Logger::log_event(
				'lm_studio_test_connection',
				'Testing LM Studio connection.',
				array(
					'url'     => $url,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio connection test failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio connection test failed to complete.', 'wp-mcp-ai' ),
					__( 'LM Studio', 'wp-mcp-ai' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'LM Studio returned an error response.',
					array( 'code' => $code )
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'LM Studio returned an unexpected response.', 'wp-mcp-ai' ),
					array( 'status' => $code )
				);
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_test_connection', 'LM Studio connection successful.' );

			return array(
				'success' => true,
				'message' => __( 'Successfully connected to LM Studio instance.', 'wp-mcp-ai' ),
			);
		}

		/**
		 * List available models from the LM Studio instance.
		 *
		 * @return array|WP_Error
		 */
		public function list_models() {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$url = untrailingslashit( $endpoint_url ) . '/v1/models';

			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array( 'Accept' => 'application/json' ),
			);

			WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'Fetching models from LM Studio.', array( 'url' => $url ) );

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio model listing failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio model listing request failed to complete.', 'wp-mcp-ai' ),
					__( 'LM Studio', 'wp-mcp-ai' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio API returned malformed JSON.', 'wp-mcp-ai' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

				WP_MCP_AI_Logger::log_error(
					'LM Studio returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			$models = array();

			// LM Studio uses OpenAI format: { "data": [ { "id": "model-name", ... }, ... ] }.
			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				foreach ( $decoded['data'] as $model ) {
					if ( isset( $model['id'] ) ) {
						$model_info = array(
							'id'       => $model['id'],
							'owned_by' => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
							'created'  => isset( $model['created'] ) ? $model['created'] : 0,
						);

						// Capture context_length if available (critical for context window management).
						if ( isset( $model['context_length'] ) ) {
							$model_info['context_length'] = absint( $model['context_length'] );
						}

						$models[] = $model_info;
					}
				}
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'LM Studio models retrieved.', array( 'count' => count( $models ) ) );

			return $models;
		}

		/**
		 * Get the context window size for a specific model.
		 *
		 * This method queries the LM Studio API to retrieve the model's
		 * actual context_length parameter, which is critical for preventing
		 * context overflow errors.
		 *
		 * @param string $model Model identifier.
		 * @return int|WP_Error Context window size in tokens, or WP_Error on failure.
		 */
		public function get_model_context_window( $model ) {
			$model = sanitize_text_field( $model );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_model',
					__( 'No model specified for context window lookup.', 'wp-mcp-ai' )
				);
			}

			// Try to get from cache first (5 minute cache).
			$cache_key = 'lm_studio_context_' . md5( $model );
			$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_lm_studio' );

			if ( false !== $cached ) {
				return $cached;
			}

			// Fetch model list from LM Studio API.
			$models = $this->list_models();

			if ( is_wp_error( $models ) ) {
				// If we can't fetch models, return a conservative default.
				WP_MCP_AI_Logger::log_error(
					'Failed to fetch LM Studio models for context window lookup.',
					array(
						'model' => $model,
						'error' => $models->get_error_message(),
					)
				);

				// Return a conservative default (4096 tokens is common for smaller models).
				return 4096;
			}

			// Find the model in the list.
			foreach ( $models as $model_info ) {
				if ( isset( $model_info['id'] ) && $model_info['id'] === $model ) {
					if ( isset( $model_info['context_length'] ) && $model_info['context_length'] > 0 ) {
						$context_window = absint( $model_info['context_length'] );

						// Cache for 5 minutes.
						wp_cache_set( $cache_key, $context_window, 'wp_mcp_ai_lm_studio', 5 * MINUTE_IN_SECONDS );

						return $context_window;
					}
				}
			}

			// Model not found or context_length not available.
			// Return a conservative default.
			WP_MCP_AI_Logger::log_event(
				'lm_studio_context_window_unknown',
				'Context window not found for model, using conservative default.',
				array( 'model' => $model )
			);

			return 4096;
		}

		/**
		 * Validate that the message context fits within the model's context window.
		 *
		 * This prevents the "context overflow" error by checking token count
		 * before sending the request to LM Studio.
		 *
		 * @param array  $messages Message array.
		 * @param string $model    Model identifier.
		 * @param array  $options  Request options.
		 * @return bool|WP_Error True if validation passes, WP_Error otherwise.
		 */
		protected function validate_context_window( array $messages, $model, array $options = array() ) {
			// Get the model's context window.
			$context_window = $this->get_model_context_window( $model );

			// If we got an error, log it but don't block the request
			// (the error might be transient).
			if ( is_wp_error( $context_window ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to get context window for validation, proceeding with request.',
					array(
						'model' => $model,
						'error' => $context_window->get_error_message(),
					)
				);
				return true;
			}

			// Estimate token count for all messages.
			$estimated_tokens = 0;

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$content = isset( $message['content'] ) ? $message['content'] : '';

				// Handle array content (multimodal).
				if ( is_array( $content ) ) {
					$text_parts = array();
					foreach ( $content as $segment ) {
						if ( is_string( $segment ) ) {
							$text_parts[] = $segment;
						} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
							$text_parts[] = $segment['text'];
						}
					}
					$content = implode( "\n", $text_parts );
				}

				// Estimate tokens (rough heuristic: ~4 chars per token).
				if ( ! empty( $content ) && is_string( $content ) ) {
					$char_count        = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );
					$estimated_tokens += (int) ceil( $char_count / 4 );
				}

				// Add overhead for message structure (~10 tokens per message).
				$estimated_tokens += 10;
			}

			// Account for max_tokens setting (response tokens).
			$max_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 2048;

			// Add tool definitions if present (can be substantial).
			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				$tools_json        = wp_json_encode( $options['tools'] );
				$tools_char_count  = function_exists( 'mb_strlen' ) ? mb_strlen( $tools_json, 'UTF-8' ) : strlen( $tools_json );
				$estimated_tokens += (int) ceil( $tools_char_count / 4 );
			}

			$total_tokens = $estimated_tokens + $max_tokens;

			// Add 10% safety margin for tokenization differences.
			$total_tokens_with_margin = (int) ceil( $total_tokens * 1.1 );

			WP_MCP_AI_Logger::log_event(
				'lm_studio_context_validation',
				'Validating context window.',
				array(
					'model'                    => $model,
					'context_window'           => $context_window,
					'estimated_input_tokens'   => $estimated_tokens,
					'max_output_tokens'        => $max_tokens,
					'total_with_margin'        => $total_tokens_with_margin,
					'is_within_limit'          => $total_tokens_with_margin <= $context_window,
				)
			);

			// Check if we're within the context window.
			if ( $total_tokens_with_margin > $context_window ) {
				return new WP_Error(
					'wp_mcp_ai_context_overflow',
					sprintf(
						/* translators: 1: Estimated tokens, 2: Context window size, 3: Model name */
						__( 'The request requires approximately %1$d tokens, but the model (%3$s) only supports %2$d tokens. Please reduce the message history or use a model with a larger context window.', 'wp-mcp-ai' ),
						$total_tokens_with_margin,
						$context_window,
						$model
					),
					array(
						'status'               => 400,
						'estimated_tokens'     => $estimated_tokens,
						'max_output_tokens'    => $max_tokens,
						'total_tokens'         => $total_tokens_with_margin,
						'context_window'       => $context_window,
						'overflow_by'          => $total_tokens_with_margin - $context_window,
						'actions'              => array(
							'reduce_message_history'   => __( 'Reduce the number of messages in the conversation history.', 'wp-mcp-ai' ),
							'reduce_max_tokens'        => __( 'Reduce the max_tokens parameter to allow more input.', 'wp-mcp-ai' ),
							'use_larger_context_model' => __( 'Use a model with a larger context window (e.g., load the model with --ctx-size 8192 or higher).', 'wp-mcp-ai' ),
						),
					)
				);
			}

			return true;
		}

		/**
		 * Perform a chat completion request against LM Studio.
		 *
		 * @param array $messages Message payload to send to LM Studio.
		 * @param array $options  Additional options (model, temperature, tools, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_endpoint' => __( 'Add an LM Studio endpoint URL in the WP oOS settings.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_model',
					__( 'No LM Studio model has been configured.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_model' => __( 'Choose an LM Studio model in the WP oOS settings.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			// Validate context window before building payload to prevent overflow errors.
			$context_check = $this->validate_context_window( $messages, $model, $options );
			if ( is_wp_error( $context_check ) ) {
				return $context_check;
			}

			$payload = $this->build_payload( $messages, $options, $model );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$url = untrailingslashit( $endpoint_url ) . '/v1/chat/completions';

			$request_args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				// Use higher minimum timeout for local AI models which need more time to generate responses.
				'timeout' => max( 120, $this->resolve_timeout( $options ) ),
			);

			WP_MCP_AI_Logger::log_event( 'lm_studio_request', 'Sending request to LM Studio.', array( 'model' => $model ) );

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio API request failed to complete.', 'wp-mcp-ai' ),
					__( 'LM Studio', 'wp-mcp-ai' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio API returned malformed JSON.', 'wp-mcp-ai' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

				WP_MCP_AI_Logger::log_error(
					'LM Studio returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			// LM Studio returns OpenAI-compatible format, so we can use it directly.
			$normalized = $this->normalize_response( $decoded, $model );

			WP_MCP_AI_Logger::log_event( 'lm_studio_response', 'LM Studio request completed.' );

			return $normalized;
		}

		/**
		 * Resolve the model identifier for the request.
		 *
		 * Since LM Studio implements the OpenAI-compatible API, it follows
		 * the same model resolution pattern as OpenAI:
		 * 1. Use model from request options if provided
		 * 2. Fall back to LM Studio-specific model setting
		 * 3. Fall back to filtered fallback model (defaults to default_model setting)
		 *
		 * @param array $options Request options.
		 * @return string
		 */
		protected function resolve_model( array $options ) {
			if ( ! empty( $options['model'] ) ) {
				return sanitize_text_field( $options['model'] );
			}

			$model = $this->get_model();

			if ( ! empty( $model ) ) {
				return $model;
			}

			// Fall back to default_model for OpenAI-compatible behavior.
			$settings       = WP_MCP_AI_Admin_Settings::get_settings();
			$fallback_model = ! empty( $settings['default_model'] ) ? $settings['default_model'] : '';

			/**
			 * Filter the fallback model for LM Studio when no model is configured.
			 *
			 * Allows customization of which model LM Studio should use when neither
			 * the request options nor the lm_studio_model setting specify a model.
			 * Defaults to the default_model setting for OpenAI compatibility.
			 *
			 * @since 1.0.0
			 *
			 * @param string $fallback_model The fallback model identifier. Default is default_model setting.
			 * @param array  $options        Request options.
			 */
			$fallback_model = apply_filters( 'wp_mcp_ai_lm_studio_fallback_model', $fallback_model, $options );

			if ( ! empty( $fallback_model ) ) {
				return sanitize_text_field( $fallback_model );
			}

			return '';
		}

		/**
		 * Build the request payload sent to LM Studio.
		 * LM Studio uses OpenAI-compatible format.
		 *
		 * @param array  $messages Chat messages.
		 * @param array  $options  Request options.
		 * @param string $model    Model identifier.
		 * @return array|WP_Error
		 */
		protected function build_payload( array $messages, array $options, $model ) {
			if ( empty( $messages ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_messages',
					__( 'No chat messages were provided for the request.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'review_request_payload' => __( 'Provide at least one user or system message before calling the API.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			// LM Studio uses OpenAI format, so we can pass messages mostly as-is.
			// When tools are provided, preserve OpenAI-compatible message structure.
			$has_tools          = ! empty( $options['tools'] );
			$formatted_messages = array();

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				// When using tools, preserve assistant messages with tool_calls.
				if ( $has_tools && 'assistant' === $role ) {
					$formatted_message = array( 'role' => $role );

					// Convert content array to string if needed.
					if ( is_array( $content ) ) {
						$text_parts = array();
						foreach ( $content as $segment ) {
							if ( is_string( $segment ) ) {
								$text_parts[] = $segment;
							} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
								$text_parts[] = $segment['text'];
							}
						}
						$content = implode( "\n", $text_parts );
					}

					$formatted_message['content'] = wp_kses_post( (string) $content );

					// Preserve tool_calls if present.
					if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
						$formatted_message['tool_calls'] = $message['tool_calls'];
					}

					$formatted_messages[] = $formatted_message;
					continue;
				}

				// When using tools, preserve tool role messages with tool_call_id.
				if ( $has_tools && 'tool' === $role ) {
					$formatted_message = array( 'role' => $role );

					// Convert content array to string if needed.
					if ( is_array( $content ) ) {
						$text_parts = array();
						foreach ( $content as $segment ) {
							if ( is_string( $segment ) ) {
								$text_parts[] = $segment;
							} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
								$text_parts[] = $segment['text'];
							}
						}
						$content = implode( "\n", $text_parts );
					}

					$formatted_message['content'] = wp_kses_post( (string) $content );

					// Preserve tool_call_id if present.
					if ( isset( $message['tool_call_id'] ) ) {
						$formatted_message['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
					}

					// Preserve tool name if present.
					if ( isset( $message['name'] ) ) {
						$formatted_message['name'] = sanitize_text_field( $message['name'] );
					}

					$formatted_messages[] = $formatted_message;
					continue;
				}

				// For non-tool scenarios or other roles, use simplified format.
				// Convert content array to string if needed.
				if ( is_array( $content ) ) {
					$text_parts = array();
					foreach ( $content as $segment ) {
						if ( is_string( $segment ) ) {
							$text_parts[] = $segment;
						} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
							$text_parts[] = $segment['text'];
						}
					}
					$content = implode( "\n", $text_parts );
				}

				$content = wp_kses_post( (string) $content );

				if ( '' === trim( $content ) && 'tool' !== $role ) {
					continue;
				}

				// When tools are NOT provided, convert tool messages to user messages for backward compatibility.
				// This handles cases where conversation history contains tool responses but the current
				// request doesn't include the tools option (e.g., replaying saved conversations).
				if ( ! $has_tools && 'tool' === $role ) {
					$tool_name = isset( $message['name'] ) ? sanitize_text_field( $message['name'] ) : 'tool';
					$content   = sprintf( '[Tool %s]: %s', $tool_name, $content );
					$role      = 'user';
				}

				$formatted_messages[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}

			$payload = array(
				'model'    => $model,
				'messages' => $formatted_messages,
				'stream'   => false, // Explicitly disable streaming to prevent chunked responses.
			);

			// Add tools if provided (OpenAI-compatible function calling).
			if ( ! empty( $options['tools'] ) ) {
				$payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
			}

			// Add temperature if specified.
			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			// Apply resource-aware max_tokens if not explicitly set.
			if ( ! isset( $options['max_tokens'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_tokens   = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens for LM Studio requests.
				 *
				 * @param int   $max_tokens The maximum tokens to use.
				 * @param array $options    Request options.
				 */
				$max_tokens = apply_filters( 'wp_mcp_ai_lm_studio_max_tokens', $max_tokens, $options );

				if ( $max_tokens > 0 ) {
					$payload['max_tokens'] = $max_tokens;
				}
			} else {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			}

			return $payload;
		}

		/**
		 * Resolve the timeout for the request.
		 *
		 * @param array $options Request options.
		 * @return int
		 */
		protected function resolve_timeout( array $options ) {
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

			// Use ignore_execution_time=true for local AI providers since these are external
			// HTTP requests that don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );

			if ( isset( $options['timeout'] ) && $options['timeout'] ) {
				$timeout = max( 5, absint( $options['timeout'] ) );
			}

			$timeout = max( 5, $timeout );

		// Ensure PHP execution time is sufficient for the timeout.
		$resource_mgr->ensure_execution_time( $timeout + 10 );

		return $timeout;
		}

		/**
		 * Normalize LM Studio response to match our standard format.
		 * Since LM Studio uses OpenAI format, minimal transformation is needed.
		 *
		 * @param array  $response Decoded LM Studio response.
		 * @param string $model    Model identifier.
		 * @return array
		 */
		protected function normalize_response( array $response, $model ) {
			// LM Studio already returns OpenAI-compatible format.
			// Just ensure we have the provider and model set correctly.
			if ( ! isset( $response['choices'] ) ) {
				$response['choices'] = array();
			}

			// Normalize content to array format if it's a string.
			foreach ( $response['choices'] as $index => $choice ) {
				if ( isset( $choice['message']['content'] ) && is_string( $choice['message']['content'] ) ) {
					$response['choices'][ $index ]['message']['content'] = array(
						array(
							'type' => 'text',
							'text' => $choice['message']['content'],
						),
					);
				}
			}

			$response['provider'] = 'lm_studio';

			if ( ! isset( $response['model'] ) ) {
				$response['model'] = $model;
			}

			return $response;
		}

		/**
		 * Create a text completion request against LM Studio.
		 * This uses the legacy completions endpoint which is useful for
		 * simple text completion tasks without the chat format overhead.
		 *
		 * @param string $prompt  The text prompt to complete.
		 * @param array  $options Additional options (model, temperature, max_tokens, timeout).
		 * @return array|WP_Error
		 */
		public function create_completion( $prompt, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_endpoint' => __( 'Add an LM Studio endpoint URL in the WP oOS settings.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_model',
					__( 'No LM Studio model has been configured.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_model' => __( 'Choose an LM Studio model in the WP oOS settings.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			if ( empty( $prompt ) || ! is_string( $prompt ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_prompt',
					__( 'No prompt was provided for the completion request.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'review_request_payload' => __( 'Provide a text prompt before calling the API.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			// Validate context window for completion requests too.
			$context_window = $this->get_model_context_window( $model );
			if ( ! is_wp_error( $context_window ) ) {
				// Estimate prompt tokens.
				$char_count       = function_exists( 'mb_strlen' ) ? mb_strlen( $prompt, 'UTF-8' ) : strlen( $prompt );
				$prompt_tokens    = (int) ceil( $char_count / 4 );
				$max_tokens       = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 2048;
				$total_tokens     = (int) ceil( ( $prompt_tokens + $max_tokens ) * 1.1 ); // 10% safety margin.

				if ( $total_tokens > $context_window ) {
					return new WP_Error(
						'wp_mcp_ai_context_overflow',
						sprintf(
							/* translators: 1: Estimated tokens, 2: Context window size, 3: Model name */
							__( 'The completion request requires approximately %1$d tokens, but the model (%3$s) only supports %2$d tokens. Please reduce the prompt length or use a model with a larger context window.', 'wp-mcp-ai' ),
							$total_tokens,
							$context_window,
							$model
						),
						array(
							'status'            => 400,
							'prompt_tokens'     => $prompt_tokens,
							'max_output_tokens' => $max_tokens,
							'total_tokens'      => $total_tokens,
							'context_window'    => $context_window,
							'overflow_by'       => $total_tokens - $context_window,
							'actions'           => array(
								'reduce_prompt'            => __( 'Reduce the prompt length.', 'wp-mcp-ai' ),
								'reduce_max_tokens'        => __( 'Reduce the max_tokens parameter.', 'wp-mcp-ai' ),
								'use_larger_context_model' => __( 'Use a model with a larger context window.', 'wp-mcp-ai' ),
							),
						)
					);
				}
			}

			$payload = array(
				'model'  => $model,
				'prompt' => wp_kses_post( (string) $prompt ),
				'stream' => false, // Explicitly disable streaming to prevent chunked responses.
			);

			// Add temperature if specified.
			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			// Apply resource-aware max_tokens if not explicitly set.
			if ( ! isset( $options['max_tokens'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_tokens   = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens for LM Studio completion requests.
				 *
				 * @param int   $max_tokens The maximum tokens to use.
				 * @param array $options    Request options.
				 */
				$max_tokens = apply_filters( 'wp_mcp_ai_lm_studio_completion_max_tokens', $max_tokens, $options );

				if ( $max_tokens > 0 ) {
					$payload['max_tokens'] = $max_tokens;
				}
			} else {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			}

			$url = untrailingslashit( $endpoint_url ) . '/v1/completions';

			$request_args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				// Use higher minimum timeout for local AI models which need more time to generate responses.
				'timeout' => max( 120, $this->resolve_timeout( $options ) ),
			);

			WP_MCP_AI_Logger::log_event( 'lm_studio_completion_request', 'Sending completion request to LM Studio.', array( 'model' => $model ) );

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio completion request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio completion API request failed to complete.', 'wp-mcp-ai' ),
					__( 'LM Studio', 'wp-mcp-ai' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio completion response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio completion API returned malformed JSON.', 'wp-mcp-ai' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

				WP_MCP_AI_Logger::log_error(
					'LM Studio completion returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			// LM Studio returns OpenAI-compatible format.
			WP_MCP_AI_Logger::log_event( 'lm_studio_completion_response', 'LM Studio completion request completed.' );

			return $decoded;
		}

		/**
		 * Normalise tool definitions to satisfy the OpenAI payload schema.
		 *
		 * Follows the same pattern as OpenAI client to ensure compatibility
		 * with LM Studio's OpenAI-compatible API implementation.
		 *
		 * @param array $tools Tool definitions sourced from the REST layer.
		 * @return array
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
	}
}
