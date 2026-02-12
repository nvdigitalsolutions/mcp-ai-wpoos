<?php
/**
 * Ollama API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
	/**
	 * Provides a wrapper around Ollama's native API endpoints.
	 */
	class WP_MCP_AI_Ollama_Client {

		/**
		 * Get the configured network interface for HTTP requests.
		 *
		 * @return string
		 */
		public function get_network_interface() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['ollama_network_interface'] ) ? sanitize_text_field( $settings['ollama_network_interface'] ) : '';
		}

		/**
		 * Get the Ollama endpoint URL.
		 *
		 * @return string Endpoint URL.
		 */
		public function get_endpoint_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['ollama_endpoint_url'] ) ? $settings['ollama_endpoint_url'] : '';
		}

		/**
		 * Get the Ollama model.
		 *
		 * @return string Model name.
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['ollama_model'] ) ? $settings['ollama_model'] : '';
		}

		/**
		 * Test the Ollama connection.
		 *
		 * @return array|WP_Error Connection test result or error.
		 */
		public function test_connection() {
			$endpoint_url = $this->get_endpoint_url();
			if ( empty( $endpoint_url ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_endpoint', __( 'No Ollama endpoint URL configured.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/tags';

			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Ollama connection failed.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'wp_mcp_ai_api_error', __( 'Ollama returned error.', 'mcp-ai-wpoos' ), array( 'status' => $code ) );
			}

			return array(
				'success' => true,
				'message' => __( 'Connected to Ollama.', 'mcp-ai-wpoos' ),
			);
		}

	/**
	 * List available models from the Ollama server.
	 *
	 * @param array $options Optional parameters (bypass_cache).
	 * @return array|WP_Error Array of models or WP_Error on failure.
	 */
	public function list_models( array $options = array() ) {
		$endpoint_url = $this->get_endpoint_url();
		if ( empty( $endpoint_url ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_ollama_endpoint', __( 'No endpoint configured.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Check if caching is enabled.
		$settings     = WP_MCP_AI_Admin_Settings::get_settings();
		$use_cache    = ! empty( $settings['enable_ollama_api_caching'] );
		$bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
			$use_cache = false;
		}

		/**
		 * Filter whether to cache Ollama model list requests.
		 *
		 * @param bool   $use_cache    Whether to use caching.
		 * @param string $endpoint_url Ollama endpoint URL.
		 * @param array  $options      Request options.
		 */
		$use_cache = apply_filters( 'wp_mcp_ai_cache_ollama_models', $use_cache, $endpoint_url, $options );

		if ( $use_cache && ! $bypass_cache ) {
			// Build cache key including endpoint URL (Ollama is self-hosted, different endpoints = different models).
			$cache_key = 'ollama_models_list_' . md5( $endpoint_url );

			// Get cache TTL from settings or use default (5 minutes for local servers).
			$cache_ttl = isset( $settings['ollama_model_list_cache_ttl'] ) ? absint( $settings['ollama_model_list_cache_ttl'] ) : 5 * MINUTE_IN_SECONDS;

			/**
			 * Filter the cache TTL for Ollama model lists.
			 *
			 * @param int    $cache_ttl    Cache TTL in seconds.
			 * @param string $endpoint_url Ollama endpoint URL.
			 * @param array  $options      Request options.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_ollama_model_list_ttl', $cache_ttl, $endpoint_url, $options );

			return WP_MCP_AI_Cache_Helper::remember(
				$cache_key,
				function () use ( $endpoint_url ) {
					return $this->fetch_models_from_api( $endpoint_url );
				},
				$cache_ttl
			);
		}

		return $this->fetch_models_from_api( $endpoint_url );
	}

	/**
	 * Fetch models from Ollama API (internal method).
	 *
	 * @param string $endpoint_url Ollama endpoint URL.
	 * @return array|WP_Error Array of models or WP_Error on failure.
	 */
	private function fetch_models_from_api( $endpoint_url ) {
		$url = untrailingslashit( $endpoint_url ) . '/api/tags';

		// Use a minimum of 30 seconds for listing models from local providers.
		// Local network connections may have higher latency than localhost.
		$timeout = max( 30, $this->resolve_timeout( array() ) );

		$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

		if ( is_wp_error( $response ) ) {
			return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Failed to list models.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON.', 'mcp-ai-wpoos' ) );
		}

		$models = array();
		if ( isset( $decoded['models'] ) && is_array( $decoded['models'] ) ) {
			foreach ( $decoded['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					$models[] = array(
						'name'   => $model['name'],
						'size'   => isset( $model['size'] ) ? $model['size'] : 0,
						'family' => isset( $model['details']['family'] ) ? $model['details']['family'] : '',
					);
				}
			}
		}
		return $models;
	}


		/**
		 * Create a chat completion.
		 *
		 * @param array $messages Messages to send.
		 * @param array $options  Optional configuration.
		 * @return array|WP_Error Chat completion result or error.
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();
			if ( empty( $endpoint_url ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_endpoint', __( 'No endpoint configured.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			$model = $this->resolve_model( $options );
			if ( empty( $model ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_model', __( 'No model configured.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			$payload = $this->build_payload( $messages, $options, $model );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/chat';

			// Check if streaming is enabled in the payload.
			$is_streaming = isset( $payload['stream'] ) && $payload['stream'];

			$http_args = array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				// Use higher minimum timeout for local AI models which need more time to generate responses.
				'timeout' => max( 120, $this->resolve_timeout( $options ) ),
			);

			// For streaming responses, we need to handle the response differently.
			// Ollama streams newline-delimited JSON objects when stream=true.
			if ( $is_streaming ) {
				// Set HTTP streaming to true so we can read the response in chunks.
				$http_args['stream'] = true;
			}

			$response = wp_remote_post( $url, $http_args );

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Request failed.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
			}

			// Handle streaming vs non-streaming responses differently.
			if ( $is_streaming ) {
				return $this->handle_streaming_response( $response, $model );
			}

			// Non-streaming response - decode and normalize as before.
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON.', 'mcp-ai-wpoos' ) );
			}

			return $this->normalize_response( $decoded, $model );
		}

		/**
		 * Handle streaming response from Ollama.
		 *
		 * Ollama streams newline-delimited JSON objects when stream=true.
		 * Each chunk is a JSON object with 'message' and 'done' fields.
		 * We accumulate the content and return the final normalized response.
		 *
		 * @param array|WP_Error $response HTTP response from wp_remote_post.
		 * @param string         $model    Model name.
		 * @return array|WP_Error Normalized response or error.
		 */
		protected function handle_streaming_response( $response, $model ) {
			$body = wp_remote_retrieve_body( $response );

			if ( empty( $body ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_streaming_response', __( 'Empty streaming response from Ollama.', 'mcp-ai-wpoos' ) );
			}

			// Split response by newlines to get individual JSON chunks.
			$lines = explode( "\n", $body );

			$accumulated_content = '';
			$final_chunk         = null;

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) ) {
					continue;
				}

				// Decode the JSON chunk.
				$chunk = json_decode( $line, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
					// Skip invalid JSON lines.
					continue;
				}

				// Accumulate content from message field.
				if ( isset( $chunk['message']['content'] ) ) {
					// Ollama sends incremental content deltas in each chunk.
					// We need to append (concatenate) each delta to build the full response.
					// See: https://docs.ollama.com/api/streaming.
					$accumulated_content .= (string) $chunk['message']['content'];
				}

				// Check if this is the final chunk.
				if ( isset( $chunk['done'] ) && $chunk['done'] ) {
					$final_chunk = $chunk;
					break; // Stop processing once we hit the done chunk.
				}
			}

			// If we didn't find a final chunk, return an error.
			if ( null === $final_chunk ) {
				return new WP_Error( 'wp_mcp_ai_incomplete_streaming_response', __( 'Ollama streaming response did not complete (no done=true chunk).', 'mcp-ai-wpoos' ) );
			}

			// Build a normalized response structure similar to non-streaming responses.
			// Use the final chunk for metadata (usage, done_reason, etc.).
			$normalized_response = array(
				'message' => array(
					'role'    => isset( $final_chunk['message']['role'] ) ? $final_chunk['message']['role'] : 'assistant',
					'content' => $accumulated_content,
				),
				'done'    => true,
			);

			// Copy over metadata fields from the final chunk if available.
			if ( isset( $final_chunk['done_reason'] ) ) {
				$normalized_response['done_reason'] = $final_chunk['done_reason'];
			}
			if ( isset( $final_chunk['prompt_eval_count'] ) ) {
				$normalized_response['prompt_eval_count'] = $final_chunk['prompt_eval_count'];
			}
			if ( isset( $final_chunk['eval_count'] ) ) {
				$normalized_response['eval_count'] = $final_chunk['eval_count'];
			}

			// Normalize the response to match the expected format.
			return $this->normalize_response( $normalized_response, $model );
		}

		/**
		 * Resolve the model to use.
		 *
		 * @param array $options Options array.
		 * @return string Model name.
		 */
		protected function resolve_model( array $options ) {
			return ! empty( $options['model'] ) ? sanitize_text_field( $options['model'] ) : $this->get_model();
		}

		/**
		 * Build the payload for the Ollama API.
		 *
		 * @param array  $messages Messages to send.
		 * @param array  $options  Optional configuration.
		 * @param string $model    Model to use.
		 * @return array|WP_Error Payload array or error.
		 */
		protected function build_payload( array $messages, array $options, $model ) {
			if ( empty( $messages ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_messages', __( 'No messages provided.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			$ollama_messages = array();
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

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

				if ( 'tool' === $role ) {
					$tool_name = isset( $message['name'] ) ? sanitize_text_field( $message['name'] ) : 'tool';
					$content   = sprintf( '[Tool %s]: %s', $tool_name, $content );
					$role      = 'user';
				}

				$ollama_messages[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}

			// Check if streaming is requested via options.
			// Default to false for backward compatibility and non-streaming use cases.
			$stream = isset( $options['stream'] ) && $options['stream'] ? true : false;

			$payload = array(
				'model'    => $model,
				'messages' => $ollama_messages,
				'stream'   => $stream,
			);

			if ( ! isset( $payload['options'] ) ) {
				$payload['options'] = array();
			}

			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
				$payload['options']['temperature'] = (float) $options['temperature'];
			}

			// Apply resource-aware num_predict if not explicitly set.
			// Priority order:
			// 1. options['max_tokens'] (if set, converted to num_predict for Ollama compatibility)
			// 2. options['num_predict'] (if set, Ollama native parameter)
			// 3. Resource manager tier-based limits (2000/8000/32000 based on workload tier).
			if ( ! isset( $options['max_tokens'] ) && ! isset( $options['num_predict'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$num_predict  = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens (num_predict) for Ollama requests.
				 *
				 * @param int   $num_predict The maximum tokens to use.
				 * @param array $options     Request options.
				 */
				$num_predict = apply_filters( 'wp_mcp_ai_ollama_num_predict', $num_predict, $options );

				// Enforce minimum value to prevent Ollama from using unlimited tokens.
				// If filter returns 0 or negative, use minimum of 512 tokens.
				$num_predict = max( 512, absint( $num_predict ) );

				$payload['options']['num_predict'] = $num_predict;
			} elseif ( isset( $options['max_tokens'] ) ) {
				// Use max_tokens with minimum enforcement.
				$payload['options']['num_predict'] = max( 512, absint( $options['max_tokens'] ) );
			} elseif ( isset( $options['num_predict'] ) ) {
				// Use num_predict with minimum enforcement.
				$payload['options']['num_predict'] = max( 512, absint( $options['num_predict'] ) );
			}

			if ( empty( $payload['options'] ) ) {
				unset( $payload['options'] );
			}

			if ( ! empty( $options['system_prompt'] ) ) {
				$payload['system'] = wp_kses_post( $options['system_prompt'] );

				// Log system prompt inclusion for Ollama requests.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'ollama_system_prompt_included',
						'Ollama: System prompt added to payload',
						array(
							'model'                => $model,
							'system_prompt_length' => strlen( $payload['system'] ),
							'system_preview'       => substr( $payload['system'], 0, 100 ) . '...',
						)
					);
				}
			} elseif ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				// Log warning if system prompt is missing.
				WP_MCP_AI_Logger::log_event(
					'ollama_system_prompt_missing',
					'Ollama: No system prompt in options',
					array(
						'model'            => $model,
						'has_options_key'  => isset( $options['system_prompt'] ),
						'options_is_empty' => empty( $options['system_prompt'] ),
					)
				);
			}

			return $payload;
		}

		/**
		 * Resolve the timeout value.
		 *
		 * @param array $options Options array.
		 * @return int Timeout in seconds.
		 */
		protected function resolve_timeout( array $options ) {
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for local AI providers since these are external.
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
		 * Normalize the Ollama response.
		 *
		 * @param array  $response Response from Ollama.
		 * @param string $model    Model used.
		 * @return array Normalized response.
		 */
		protected function normalize_response( array $response, $model ) {
			$message = array( 'role' => 'assistant' );
			$content = isset( $response['message']['content'] ) ? (string) $response['message']['content'] : '';

			// Determine finish_reason based on Ollama response.
			// Ollama provides a 'done_reason' field that indicates why generation stopped.
			// Possible values: 'stop' (natural completion), 'length' (max tokens), 'load' (loading model).
			// For non-streaming requests with stream=false, Ollama always sets done=true.
			$finish_reason = 'stop'; // Default to 'stop' for successful completions.

			if ( isset( $response['done_reason'] ) && '' !== $response['done_reason'] ) {
				// Use Ollama's done_reason if available (most reliable).
				$finish_reason = sanitize_key( $response['done_reason'] );
			} elseif ( isset( $response['done'] ) && ! $response['done'] ) {
				// If done=false explicitly, response was incomplete.
				$finish_reason = 'length';
			} elseif ( '' === trim( $content ) ) {
				// If we have no content at all, something went wrong.
				$finish_reason = 'length';
			}
			// Otherwise keep default 'stop' - we have content and done=true or missing (assumed complete).

			// Industry standard: When finish_reason is 'length' with no content, provide helpful error message.
			// This happens when the prompt/conversation consumes all available tokens (num_predict limit).
			// Following OpenAI API standard: message.content field should always be present.
			if ( 'length' === $finish_reason && '' === trim( $content ) ) {
				$content = __( 'The model could not generate a response because the conversation exceeded the available token limit. Try shortening your message, starting a new conversation, or increasing the token limit in Settings → NV oOS → Orchestration → Max Tokens.', 'mcp-ai-wpoos' );
			}

			// Always set message content field to maintain OpenAI API compatibility.
			// All providers (OpenAI, LM Studio, Gemini, Anthropic) include this field even when empty.
			$message['content'] = array(
				array(
					'type' => 'text',
					'text' => $content,
				),
			);

			$normalized = array(
				'choices'  => array(
					array(
						'index'         => 0,
						'message'       => $message,
						'finish_reason' => $finish_reason,
					),
				),
				'provider' => 'ollama',
				'model'    => $model,
			);

			if ( isset( $response['prompt_eval_count'] ) || isset( $response['eval_count'] ) ) {
				$prompt_tokens     = isset( $response['prompt_eval_count'] ) ? (int) $response['prompt_eval_count'] : 0;
				$completion_tokens = isset( $response['eval_count'] ) ? (int) $response['eval_count'] : 0;

				$normalized['usage'] = array(
					'prompt_tokens'     => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'total_tokens'      => $prompt_tokens + $completion_tokens,
				);
			}

			return $normalized;
		}

		/**
		 * Create a text completion request against Ollama.
		 * This uses the /api/generate endpoint which is useful for
		 * simple text completion tasks without the chat format overhead.
		 *
		 * @param string $prompt  The text prompt to complete.
		 * @param array  $options Additional options (model, temperature, timeout).
		 * @return array|WP_Error
		 */
		public function create_completion( $prompt, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_ollama_endpoint',
					__( 'No Ollama endpoint URL configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_ollama_model',
					__( 'No Ollama model configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $prompt ) || ! is_string( $prompt ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_prompt',
					__( 'No prompt was provided for the completion request.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$payload = array(
				'model'  => $model,
				'prompt' => wp_kses_post( (string) $prompt ),
				'stream' => false,
			);

			// Add temperature if specified.
			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$payload['options'] = array(
					'temperature' => (float) $options['temperature'],
				);
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/generate';

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
					// Use higher minimum timeout for local AI models which need more time to generate responses.
					'timeout' => max( 120, $this->resolve_timeout( $options ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Ollama completion request failed.', 'mcp-ai-wpoos' ),
					__( 'Ollama', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON response from Ollama.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from Ollama.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			// Ollama's generate endpoint returns: { "response": "text", "done": true, ... }
			// Normalize to OpenAI-style format for consistency.
			if ( isset( $decoded['response'] ) ) {
				$normalized = array(
					'id'      => 'ollama-gen-' . time(),
					'object'  => 'text_completion',
					'created' => time(),
					'model'   => $model,
					'choices' => array(
						array(
							'text'          => $decoded['response'],
							'index'         => 0,
							'finish_reason' => isset( $decoded['done'] ) && $decoded['done'] ? 'stop' : 'length',
						),
					),
				);

				return $normalized;
			}

			return $decoded;
		}
	}
}
