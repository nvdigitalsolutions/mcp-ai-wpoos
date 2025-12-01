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

		public function get_endpoint_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['ollama_endpoint_url'] ) ? $settings['ollama_endpoint_url'] : '';
		}

		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['ollama_model'] ) ? $settings['ollama_model'] : '';
		}

		public function test_connection() {
			$endpoint_url = $this->get_endpoint_url();
			if ( empty( $endpoint_url ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_endpoint', __( 'No Ollama endpoint URL configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/tags';

			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Ollama connection failed.', 'wp-mcp-ai' ), __( 'Ollama', 'wp-mcp-ai' ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'wp_mcp_ai_api_error', __( 'Ollama returned error.', 'wp-mcp-ai' ), array( 'status' => $code ) );
			}

			return array(
				'success' => true,
				'message' => __( 'Connected to Ollama.', 'wp-mcp-ai' ),
			);
		}

		public function list_models() {
			$endpoint_url = $this->get_endpoint_url();
			if ( empty( $endpoint_url ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_endpoint', __( 'No endpoint configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/tags';

			// Use a minimum of 30 seconds for listing models from local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Failed to list models.', 'wp-mcp-ai' ), __( 'Ollama', 'wp-mcp-ai' ) );
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON.', 'wp-mcp-ai' ) );
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

		public function create_chat_completion( array $messages, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();
			if ( empty( $endpoint_url ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_endpoint', __( 'No endpoint configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$model = $this->resolve_model( $options );
			if ( empty( $model ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_ollama_model', __( 'No model configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$payload = $this->build_payload( $messages, $options, $model );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/chat';

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
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Request failed.', 'wp-mcp-ai' ), __( 'Ollama', 'wp-mcp-ai' ) );
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON.', 'wp-mcp-ai' ) );
			}

			return $this->normalize_response( $decoded, $model );
		}

		protected function resolve_model( array $options ) {
			return ! empty( $options['model'] ) ? sanitize_text_field( $options['model'] ) : $this->get_model();
		}

		protected function build_payload( array $messages, array $options, $model ) {
			if ( empty( $messages ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_messages', __( 'No messages provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
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

			$payload = array(
				'model'    => $model,
				'messages' => $ollama_messages,
				'stream'   => false,
			);

			if ( ! isset( $payload['options'] ) ) {
				$payload['options'] = array();
			}

			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
				$payload['options']['temperature'] = (float) $options['temperature'];
			}

			// Apply resource-aware num_predict if not explicitly set.
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

				if ( $num_predict > 0 ) {
					$payload['options']['num_predict'] = $num_predict;
				}
			} elseif ( isset( $options['max_tokens'] ) ) {
				$payload['options']['num_predict'] = absint( $options['max_tokens'] );
			} elseif ( isset( $options['num_predict'] ) ) {
				$payload['options']['num_predict'] = absint( $options['num_predict'] );
			}

			if ( empty( $payload['options'] ) ) {
				unset( $payload['options'] );
			}

			if ( ! empty( $options['system_prompt'] ) ) {
				$payload['system'] = wp_kses_post( $options['system_prompt'] );
			}

			return $payload;
		}

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

		protected function normalize_response( array $response, $model ) {
			$message = array( 'role' => 'assistant' );
			$content = isset( $response['message']['content'] ) ? (string) $response['message']['content'] : '';

			if ( '' !== $content ) {
				$message['content'] = array(
					array(
						'type' => 'text',
						'text' => $content,
					),
				);
			}

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
					__( 'No Ollama endpoint URL configured.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_ollama_model',
					__( 'No Ollama model configured.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $prompt ) || ! is_string( $prompt ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_prompt',
					__( 'No prompt was provided for the completion request.', 'wp-mcp-ai' ),
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
					__( 'Ollama completion request failed.', 'wp-mcp-ai' ),
					__( 'Ollama', 'wp-mcp-ai' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON response from Ollama.', 'wp-mcp-ai' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from Ollama.', 'wp-mcp-ai' );

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
