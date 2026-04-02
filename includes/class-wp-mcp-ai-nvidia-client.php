<?php
/**
 * NVIDIA NIM API client wrapper.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Nvidia_Client' ) ) {
	/**
	 * Provides a wrapper around NVIDIA NIM API endpoints.
	 * Supports the OpenAI-compatible chat completions format.
	 */
	class WP_MCP_AI_Nvidia_Client {

		/**
		 * Default base URL for the NVIDIA NIM API.
		 *
		 * @var string
		 */
		const DEFAULT_ENDPOINT_URL = 'https://integrate.api.nvidia.com/v1';

		/**
		 * Retrieve the configured API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['nvidia_api_key'] ) ? $settings['nvidia_api_key'] : '';
		}

		/**
		 * Retrieve the configured NVIDIA NIM endpoint URL.
		 *
		 * @return string
		 */
		public function get_endpoint_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['nvidia_endpoint_url'] ) && '' !== $settings['nvidia_endpoint_url']
				? $settings['nvidia_endpoint_url']
				: self::DEFAULT_ENDPOINT_URL;
		}

		/**
		 * Retrieve the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['nvidia_model'] ) ? $settings['nvidia_model'] : '';
		}

		/**
		 * Test the connection to the NVIDIA NIM API.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nvidia_api_key',
					__( 'No NVIDIA NIM API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$endpoint_url = $this->get_endpoint_url();

			// Test connection by attempting to list available models.
			$url = untrailingslashit( $endpoint_url ) . '/models';

			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			);

			WP_MCP_AI_Logger::log_event(
				'nvidia_test_connection',
				'Testing NVIDIA NIM connection.',
				array(
					'url'     => $url,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'NVIDIA NIM connection test failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The NVIDIA NIM connection test failed to complete.', 'mcp-ai-wpoos' ),
					__( 'NVIDIA NIM', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'NVIDIA NIM returned an error response.',
					array( 'code' => $code )
				);

				return new WP_Error(
					'wp_mcp_ai_nvidia_api_error',
					__( 'NVIDIA NIM returned an unexpected response.', 'mcp-ai-wpoos' ),
					array( 'status' => $code )
				);
			}

			WP_MCP_AI_Logger::log_event( 'nvidia_test_connection', 'NVIDIA NIM connection successful.' );

			return array(
				'success' => true,
				'message' => __( 'Successfully connected to NVIDIA NIM API.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * List available models from the NVIDIA NIM API.
		 *
		 * @return array|WP_Error
		 */
		public function list_models() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nvidia_api_key',
					__( 'No NVIDIA NIM API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$endpoint_url = $this->get_endpoint_url();

			$url = untrailingslashit( $endpoint_url ) . '/models';

			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			);

			WP_MCP_AI_Logger::log_event( 'nvidia_list_models', 'Fetching models from NVIDIA NIM.', array( 'url' => $url ) );

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'NVIDIA NIM model listing failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The NVIDIA NIM model listing request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'NVIDIA NIM', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode NVIDIA NIM response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_nvidia_invalid_response', __( 'The NVIDIA NIM API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from NVIDIA NIM.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'NVIDIA NIM returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_nvidia_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			$models = array();

			// NVIDIA NIM uses OpenAI-compatible format: a data array of objects each containing an id field.
			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				foreach ( $decoded['data'] as $model ) {
					if ( isset( $model['id'] ) ) {
						$models[] = array(
							'id'       => $model['id'],
							'owned_by' => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
							'created'  => isset( $model['created'] ) ? $model['created'] : 0,
						);
					}
				}
			}

			WP_MCP_AI_Logger::log_event( 'nvidia_list_models', 'NVIDIA NIM models retrieved.', array( 'count' => count( $models ) ) );

			return $models;
		}

		/**
		 * Perform a chat completion request against NVIDIA NIM.
		 *
		 * @param array $messages Message payload to send to NVIDIA NIM.
		 * @param array $options  Additional options (model, temperature, tools, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nvidia_api_key',
					__( 'No NVIDIA NIM API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_nvidia_api_key' => __( 'Add an NVIDIA NIM API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$endpoint_url = $this->get_endpoint_url();

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nvidia_model',
					__( 'No NVIDIA NIM model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_nvidia_model' => __( 'Choose an NVIDIA NIM model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$payload = $this->build_payload( $messages, $options, $model );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$url = untrailingslashit( $endpoint_url ) . '/chat/completions';

			$request_args = array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => max( 60, $this->resolve_timeout( $options ) ),
			);

			WP_MCP_AI_Logger::log_event( 'nvidia_request', 'Sending request to NVIDIA NIM.', array( 'model' => $model ) );

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'NVIDIA NIM request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The NVIDIA NIM API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'NVIDIA NIM', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode NVIDIA NIM response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_nvidia_invalid_response', __( 'The NVIDIA NIM API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from NVIDIA NIM.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'NVIDIA NIM returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_nvidia_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			// NVIDIA NIM returns OpenAI-compatible format, so we can use it directly.
			$normalized = $this->normalize_response( $decoded, $model );

			WP_MCP_AI_Logger::log_event( 'nvidia_response', 'NVIDIA NIM request completed.' );

			return $normalized;
		}

		/**
		 * Resolve the model identifier for the request.
		 *
		 * Since NVIDIA NIM implements the OpenAI-compatible API, it follows
		 * the same model resolution pattern:
		 * 1. Use model from request options if provided
		 * 2. Fall back to NVIDIA-specific model setting
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
			 * Filter the fallback model for NVIDIA NIM when no model is configured.
			 *
			 * Allows customization of which model NVIDIA NIM should use when neither
			 * the request options nor the nvidia_model setting specify a model.
			 * Defaults to the default_model setting for OpenAI compatibility.
			 *
			 * @since 1.0.0
			 *
			 * @param string $fallback_model The fallback model identifier. Default is default_model setting.
			 * @param array  $options        Request options.
			 */
			$fallback_model = apply_filters( 'wp_mcp_ai_nvidia_fallback_model', $fallback_model, $options );

			if ( ! empty( $fallback_model ) ) {
				return sanitize_text_field( $fallback_model );
			}

			return '';
		}

		/**
		 * Build the request payload sent to NVIDIA NIM.
		 * NVIDIA NIM uses OpenAI-compatible format.
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
					__( 'No chat messages were provided for the request.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'review_request_payload' => __( 'Provide at least one user or system message before calling the API.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Prepare system messages array (will be prepended to messages).
			$system_messages = array();

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

			// NVIDIA NIM uses OpenAI format, so we can pass messages mostly as-is.
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

			// Prepend system messages to the formatted messages.
			if ( ! empty( $system_messages ) ) {
				$formatted_messages = array_merge( $system_messages, $formatted_messages );
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

			// Additional sampling parameters supported by NVIDIA NIM API.
			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['top_p'] = (float) $options['top_p'];
			}

			if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
				$payload['seed'] = (int) $options['seed'];
			}

			if ( ! empty( $options['stop'] ) ) {
				$payload['stop'] = is_array( $options['stop'] ) ? array_values( array_map( 'sanitize_text_field', $options['stop'] ) ) : array( sanitize_text_field( $options['stop'] ) );
			}

			if ( isset( $options['frequency_penalty'] ) && is_numeric( $options['frequency_penalty'] ) ) {
				$payload['frequency_penalty'] = (float) $options['frequency_penalty'];
			}

			if ( isset( $options['presence_penalty'] ) && is_numeric( $options['presence_penalty'] ) ) {
				$payload['presence_penalty'] = (float) $options['presence_penalty'];
			}

			// Reasoning effort for reasoning-capable models.
			$allowed_efforts = array( 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' );
			if ( isset( $options['reasoning_effort'] ) && in_array( $options['reasoning_effort'], $allowed_efforts, true ) ) {
				$payload['reasoning_effort'] = $options['reasoning_effort'];
			}

			// Structured output / JSON mode (OpenAI-compatible format).
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) && ! empty( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			// Tool choice to control which tool is invoked.
			if ( isset( $options['tool_choice'] ) ) {
				$payload['tool_choice'] = $options['tool_choice'];
			}

			// Apply resource-aware max_tokens if not explicitly set.
			// NVIDIA NIM uses the standard max_tokens parameter.
			if ( ! isset( $options['max_tokens'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_tokens   = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens for NVIDIA NIM requests.
				 *
				 * @param int   $max_tokens The maximum tokens to use.
				 * @param array $options    Request options.
				 */
				$max_tokens = apply_filters( 'wp_mcp_ai_nvidia_max_tokens', $max_tokens, $options );

				// Get model-specific limit from model config.
				$model_config = WP_MCP_AI_Model_Config::get_model_config( $model );
				if ( $model_config && isset( $model_config['max_completion_tokens'] ) ) {
					$model_limit = absint( $model_config['max_completion_tokens'] );
					// Respect model limit.
					$max_tokens = min( $max_tokens, $model_limit );
				}

				if ( $max_tokens > 0 ) {
					$payload['max_tokens'] = $max_tokens;
				}
			} else {
				$max_tokens = absint( $options['max_tokens'] );

				// Get model-specific limit from model config.
				$model_config = WP_MCP_AI_Model_Config::get_model_config( $model );
				if ( $model_config && isset( $model_config['max_completion_tokens'] ) ) {
					$model_limit = absint( $model_config['max_completion_tokens'] );
					// Respect model limit.
					$max_tokens = min( $max_tokens, $model_limit );
				}

				$payload['max_tokens'] = $max_tokens;
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

			// Use ignore_execution_time=false for cloud API providers.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( false );

			if ( isset( $options['timeout'] ) && $options['timeout'] ) {
				$timeout = max( 5, absint( $options['timeout'] ) );
			}

			$timeout = max( 5, $timeout );

			// Ensure PHP execution time is sufficient for the timeout.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

			return $timeout;
		}

		/**
		 * Normalize NVIDIA NIM response to match our standard format.
		 * Since NVIDIA NIM uses OpenAI format, minimal transformation is needed.
		 *
		 * @param array  $response Decoded NVIDIA NIM response.
		 * @param string $model    Model identifier.
		 * @return array
		 */
		protected function normalize_response( array $response, $model ) {
			// NVIDIA NIM already returns OpenAI-compatible format.
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

			$response['provider'] = 'nvidia';

			if ( ! isset( $response['model'] ) ) {
				$response['model'] = $model;
			}

			// Ensure usage data is present and includes provider/model information.
			// NVIDIA NIM returns OpenAI-compatible usage with prompt_tokens, completion_tokens, total_tokens.
			if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
				// Add provider and model to usage for frontend display.
				$response['usage']['provider'] = 'nvidia';
				$response['usage']['model']    = $model;
			} elseif ( ! isset( $response['usage'] ) ) {
				// If usage is missing, create a minimal structure.
				// This should not happen with proper NVIDIA NIM responses, but provides fallback.
				$response['usage'] = array(
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'total_tokens'      => 0,
					'provider'          => 'nvidia',
					'model'             => $model,
				);

				WP_MCP_AI_Logger::log_event(
					'nvidia_missing_usage',
					'NVIDIA NIM response missing usage data.',
					array( 'model' => $model )
				);
			}

			return $response;
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
		 * Normalise tool definitions to satisfy the OpenAI payload schema.
		 *
		 * Follows the same pattern as OpenAI client to ensure compatibility
		 * with NVIDIA NIM's OpenAI-compatible API implementation.
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
