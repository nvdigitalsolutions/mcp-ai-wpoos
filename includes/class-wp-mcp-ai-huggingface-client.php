<?php
/**
 * Hugging Face Inference API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
	/**
	 * Provides a wrapper around Hugging Face's Inference API endpoints.
	 * Supports the OpenAI-compatible chat completions format.
	 */
	class WP_MCP_AI_Huggingface_Client {

		/**
		 * Retrieve the configured API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['huggingface_api_key'] ) ? $settings['huggingface_api_key'] : '';
		}

		/**
		 * Retrieve the configured Hugging Face endpoint URL.
		 *
		 * @return string
		 */
		public function get_endpoint_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['huggingface_endpoint_url'] ) ? $settings['huggingface_endpoint_url'] : '';
		}

		/**
		 * Retrieve the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['huggingface_model'] ) ? $settings['huggingface_model'] : '';
		}

		/**
		 * Test the connection to the Hugging Face Inference API.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_api_key',
					__( 'No Hugging Face API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_endpoint',
					__( 'No Hugging Face endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

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
				'huggingface_test_connection',
				'Testing Hugging Face connection.',
				array(
					'url'     => $url,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Hugging Face connection test failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Hugging Face connection test failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Hugging Face', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'Hugging Face returned an error response.',
					array( 'code' => $code )
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'Hugging Face returned an unexpected response.', 'mcp-ai-wpoos' ),
					array( 'status' => $code )
				);
			}

			WP_MCP_AI_Logger::log_event( 'huggingface_test_connection', 'Hugging Face connection successful.' );

			return array(
				'success' => true,
				'message' => __( 'Successfully connected to Hugging Face Inference API.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * List available models from the Hugging Face Inference API.
		 *
		 * @return array|WP_Error
		 */
		public function list_models() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_api_key',
					__( 'No Hugging Face API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_endpoint',
					__( 'No endpoint configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$url = untrailingslashit( $endpoint_url ) . '/models';

			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			);

			WP_MCP_AI_Logger::log_event( 'huggingface_list_models', 'Fetching models from Hugging Face.', array( 'url' => $url ) );

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Hugging Face model listing failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Hugging Face model listing request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Hugging Face', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Hugging Face response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Hugging Face API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from Hugging Face.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Hugging Face returned an error response.',
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

			// Hugging Face uses OpenAI format: { "data": [ { "id": "model-name", ... }, ... ] }.
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

			WP_MCP_AI_Logger::log_event( 'huggingface_list_models', 'Hugging Face models retrieved.', array( 'count' => count( $models ) ) );

			return $models;
		}

		/**
		 * Perform a chat completion request against Hugging Face.
		 *
		 * @param array $messages Message payload to send to Hugging Face.
		 * @param array $options  Additional options (model, temperature, tools, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_api_key',
					__( 'No Hugging Face API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_huggingface_api_key' => __( 'Add a Hugging Face API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_endpoint',
					__( 'No Hugging Face endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_huggingface_endpoint' => __( 'Add a Hugging Face endpoint URL in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_model',
					__( 'No Hugging Face model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_huggingface_model' => __( 'Choose a Hugging Face model in the NV oOS settings.', 'mcp-ai-wpoos' ),
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

			WP_MCP_AI_Logger::log_event( 'huggingface_request', 'Sending request to Hugging Face.', array( 'model' => $model ) );

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Hugging Face request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Hugging Face API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Hugging Face', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Hugging Face response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Hugging Face API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Hugging Face.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Hugging Face returned an error response.',
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

			// Hugging Face returns OpenAI-compatible format, so we can use it directly.
			$normalized = $this->normalize_response( $decoded, $model );

			WP_MCP_AI_Logger::log_event( 'huggingface_response', 'Hugging Face request completed.' );

			return $normalized;
		}

		/**
		 * Resolve the model identifier for the request.
		 *
		 * Since Hugging Face implements the OpenAI-compatible API, it follows
		 * the same model resolution pattern:
		 * 1. Use model from request options if provided
		 * 2. Fall back to Hugging Face-specific model setting
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
			 * Filter the fallback model for Hugging Face when no model is configured.
			 *
			 * Allows customization of which model Hugging Face should use when neither
			 * the request options nor the huggingface_model setting specify a model.
			 * Defaults to the default_model setting for OpenAI compatibility.
			 *
			 * @since 1.0.0
			 *
			 * @param string $fallback_model The fallback model identifier. Default is default_model setting.
			 * @param array  $options        Request options.
			 */
			$fallback_model = apply_filters( 'wp_mcp_ai_huggingface_fallback_model', $fallback_model, $options );

			if ( ! empty( $fallback_model ) ) {
				return sanitize_text_field( $fallback_model );
			}

			return '';
		}

		/**
		 * Build the request payload sent to Hugging Face.
		 * Hugging Face uses OpenAI-compatible format.
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

			// Hugging Face uses OpenAI format, so we can pass messages mostly as-is.
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

			// Apply resource-aware max_tokens if not explicitly set.
			if ( ! isset( $options['max_tokens'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_tokens   = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens for Hugging Face requests.
				 *
				 * @param int   $max_tokens The maximum tokens to use.
				 * @param array $options    Request options.
				 */
				$max_tokens = apply_filters( 'wp_mcp_ai_huggingface_max_tokens', $max_tokens, $options );

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
		 * Normalize Hugging Face response to match our standard format.
		 * Since Hugging Face uses OpenAI format, minimal transformation is needed.
		 *
		 * @param array  $response Decoded Hugging Face response.
		 * @param string $model    Model identifier.
		 * @return array
		 */
		protected function normalize_response( array $response, $model ) {
			// Hugging Face already returns OpenAI-compatible format.
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

			$response['provider'] = 'huggingface';

			if ( ! isset( $response['model'] ) ) {
				$response['model'] = $model;
			}

			// Ensure usage data is present and includes provider/model information.
			// Hugging Face returns OpenAI-compatible usage with prompt_tokens, completion_tokens, total_tokens.
			if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
				// Add provider and model to usage for frontend display.
				$response['usage']['provider'] = 'huggingface';
				$response['usage']['model']    = $model;
			} elseif ( ! isset( $response['usage'] ) ) {
				// If usage is missing, create a minimal structure.
				// This should not happen with proper Hugging Face responses, but provides fallback.
				$response['usage'] = array(
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'total_tokens'      => 0,
					'provider'          => 'huggingface',
					'model'             => $model,
				);

				WP_MCP_AI_Logger::log_event(
					'huggingface_missing_usage',
					'Hugging Face response missing usage data.',
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
		 * with Hugging Face's OpenAI-compatible API implementation.
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

		/**
		 * Transcribe audio using Hugging Face Inference API (OpenAI-compatible).
		 *
		 * @param string $file_path Path to the audio file.
		 * @param array  $options   Additional options (model, language, etc.).
		 * @return array|WP_Error Transcription result or error.
		 */
		public function transcribe_audio( $file_path, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_api_key',
					__( 'No Hugging Face API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_huggingface_key' => __( 'Add a Hugging Face API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
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

			// Use Whisper model from Hugging Face or a custom endpoint.
			// Default to openai/whisper-large-v3 which is a popular Whisper model.
			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'openai/whisper-large-v3';

			// Get endpoint URL or use default Inference API.
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				// Use Hugging Face Inference API endpoint for the model.
				// Note: api-inference.huggingface.co is the correct endpoint for hosted models.
				// For dedicated Inference Endpoints, use custom endpoint_url setting.
				$url = sprintf( 'https://api-inference.huggingface.co/models/%s', rawurlencode( $model ) );
			} else {
				// Use custom endpoint with /audio/transcriptions path (OpenAI-compatible).
				// This is for dedicated Hugging Face Inference Endpoints with format:
				// https://<endpoint-name>.endpoints.huggingface.cloud/v1/audio/transcriptions
				$url = untrailingslashit( $endpoint_url ) . '/audio/transcriptions';
			}

			// Read file content.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$file_data = file_get_contents( $file_path );

			if ( false === $file_data ) {
				return new WP_Error(
					'wp_mcp_ai_file_read_error',
					__( 'Could not read the audio file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : 60;
			$timeout = max( 5, $timeout );

			// Hugging Face Inference API accepts raw audio data.
			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/octet-stream',
				),
				'body'    => $file_data,
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'huggingface_transcribe_audio',
				'Sending audio transcription request to Hugging Face.',
				array(
					'model'     => $model,
					'file_size' => strlen( $file_data ),
					'timeout'   => $timeout,
					'url'       => $url,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Hugging Face audio transcription failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Hugging Face audio transcription request failed.', 'mcp-ai-wpoos' ),
					__( 'Hugging Face', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				$error_message = __( 'Hugging Face audio transcription returned an error.', 'mcp-ai-wpoos' );
				$decoded_body  = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['error'] ) ) {
					$error_message .= ' ' . sanitize_text_field( $decoded_body['error'] );
					
					// Provide helpful context for common errors.
					if ( 404 === $code || false !== strpos( strtolower( $decoded_body['error'] ), 'no route' ) || false !== strpos( strtolower( $decoded_body['error'] ), 'not found' ) ) {
						$error_message .= ' ' . __( 'The Whisper model may not exist or be accessible. Verify the model name (e.g., openai/whisper-large-v3) is correct. For private models, ensure your API key has access. For dedicated endpoints, configure the huggingface_endpoint_url setting.', 'mcp-ai-wpoos' );
					}
				}

				WP_MCP_AI_Logger::log_error(
					'Hugging Face audio transcription error.',
					array(
						'status'      => $code,
						'body'        => $body,
						'model'       => $model,
						'endpoint'    => $url,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status'  => $code,
						'body'    => $body,
						'actions' => array(
							'verify_model_name'      => __( 'Check that the Whisper model name is correct (e.g., openai/whisper-large-v3).', 'mcp-ai-wpoos' ),
							'check_api_key'          => __( 'Verify your Hugging Face API key has access to the model.', 'mcp-ai-wpoos' ),
							'use_custom_endpoint'    => __( 'For dedicated Inference Endpoints, configure huggingface_endpoint_url in settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid JSON response from Hugging Face.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'huggingface_transcribe_audio_success',
				'Successfully transcribed audio with Hugging Face.',
				array(
					'model'         => $model,
					'has_text'      => isset( $decoded['text'] ),
					'response_keys' => is_array( $decoded ) ? array_keys( $decoded ) : array(),
				)
			);

			// Hugging Face Inference API returns: {"text": "transcription"}.
			// Normalize to consistent format.
			if ( isset( $decoded['text'] ) ) {
				$text = trim( $decoded['text'] );
				if ( '' === $text ) {
					return new WP_Error(
						'wp_mcp_ai_empty_transcription',
						__( 'Hugging Face returned an empty transcription.', 'mcp-ai-wpoos' ),
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
				__( 'Unexpected response format from Hugging Face.', 'mcp-ai-wpoos' ),
				array( 'response' => $decoded )
			);
		}

		/**
		 * Generate speech audio from text using Hugging Face Inference API TTS models.
		 *
		 * Supports models like:
		 * - facebook/fastspeech2-en-ljspeech (Fast, English)
		 * - facebook/mms-tts-eng (Multi-lingual Massively Multilingual Speech)
		 * - microsoft/speecht5_tts (High quality, multi-speaker)
		 * - Any text-to-speech model on Hugging Face Hub
		 *
		 * @param string $text    Text to convert to speech.
		 * @param array  $options Optional configuration (model, timeout).
		 * @return array|WP_Error Array with 'audio', 'format', 'model' on success, WP_Error on failure.
		 */
		public function generate_speech( $text, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_huggingface_api_key',
					__( 'No Hugging Face API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_huggingface_api_key' => __( 'Add a Hugging Face API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$text = sanitize_textarea_field( $text );

			if ( '' === $text ) {
				return new WP_Error(
					'wp_mcp_ai_missing_speech_input',
					__( 'A text prompt must be supplied to generate speech.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Get settings for defaults.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Default to facebook/mms-tts-eng (good quality, widely available).
			$default_model = isset( $settings['huggingface_speech_model'] ) && '' !== $settings['huggingface_speech_model']
				? sanitize_text_field( $settings['huggingface_speech_model'] )
				: 'facebook/mms-tts-eng';

			// Extract options.
			$model   = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : 30;
			$timeout = max( 5, $timeout );

			// Build payload - Hugging Face expects {"inputs": "text to speak"}.
			$payload = array(
				'inputs' => $text,
			);

			// Some models support additional parameters.
			if ( isset( $options['speaker'] ) && '' !== $options['speaker'] ) {
				$payload['parameters'] = array(
					'speaker' => sanitize_text_field( $options['speaker'] ),
				);
			}

			/**
			 * Filter the Hugging Face TTS payload before sending.
			 *
			 * @param array  $payload Prepared request payload.
			 * @param string $text    Original text input.
			 * @param string $model   Model identifier.
			 * @param array  $options Original options.
			 */
			$payload = apply_filters( 'wp_mcp_ai_huggingface_speech_payload', $payload, $text, $model, $options );

			$encoded_payload = wp_json_encode( $payload );
			if ( false === $encoded_payload ) {
				return new WP_Error(
					'wp_mcp_ai_encoding_error',
					__( 'Failed to encode the Hugging Face TTS request payload.', 'mcp-ai-wpoos' )
				);
			}

			// Build API endpoint for the specific model.
			// Note: api-inference.huggingface.co is the correct endpoint for hosted models.
			// For dedicated Inference Endpoints, configure huggingface_endpoint_url in settings.
			$url = sprintf(
				'https://api-inference.huggingface.co/models/%s',
				rawurlencode( $model )
			);

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => $timeout,
				'body'    => $encoded_payload,
			);

			WP_MCP_AI_Logger::log_event(
				'huggingface_tts_request',
				'Sending text-to-speech request to Hugging Face Inference API.',
				array(
					'model'   => $model,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Hugging Face text-to-speech request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Hugging Face Inference API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Hugging Face', 'mcp-ai-wpoos' )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				$decoded = json_decode( $body, true );
				$error   = json_last_error();

				if ( JSON_ERROR_NONE === $error && isset( $decoded['error'] ) ) {
					$message = is_string( $decoded['error'] ) ? $decoded['error'] : wp_json_encode( $decoded['error'] );
				} else {
					$message = __( 'Unexpected response from Hugging Face Inference API.', 'mcp-ai-wpoos' );
				}

				WP_MCP_AI_Logger::log_error(
					'Hugging Face text-to-speech request returned an error.',
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
					'wp_mcp_ai_empty_audio',
					__( 'Hugging Face Inference API returned an empty audio response.', 'mcp-ai-wpoos' )
				);
			}

			// Hugging Face TTS models typically return binary audio data (WAV or FLAC).
			// The content type header tells us the format.
			$headers      = wp_remote_retrieve_headers( $response );
			$content_type = isset( $headers['content-type'] ) ? sanitize_text_field( $headers['content-type'] ) : '';

			// Determine format from content type.
			$format = 'wav'; // Default to WAV.
			if ( false !== strpos( $content_type, 'audio/flac' ) ) {
				$format = 'flac';
			} elseif ( false !== strpos( $content_type, 'audio/mpeg' ) || false !== strpos( $content_type, 'audio/mp3' ) ) {
				$format = 'mp3';
			} elseif ( false !== strpos( $content_type, 'audio/wav' ) || false !== strpos( $content_type, 'audio/wave' ) ) {
				$format = 'wav';
			}

			WP_MCP_AI_Logger::log_event(
				'huggingface_tts_success',
				'Successfully generated speech with Hugging Face Inference API.',
				array(
					'model'        => $model,
					'format'       => $format,
					'content_type' => $content_type,
					'body_length'  => strlen( $body ),
				)
			);

			return array(
				'audio'        => $body,
				'format'       => $format,
				'model'        => $model,
				'content_type' => $content_type,
			);
		}
	}
}
