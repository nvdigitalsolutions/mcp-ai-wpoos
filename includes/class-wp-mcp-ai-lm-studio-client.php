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
						$models[] = array(
							'id'       => $model['id'],
							'owned_by' => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
							'created'  => isset( $model['created'] ) ? $model['created'] : 0,
						);
					}
				}
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'LM Studio models retrieved.', array( 'count' => count( $models ) ) );

			return $models;
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
			$formatted_messages = array();

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

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

				// Convert tool messages to user messages.
				if ( 'tool' === $role ) {
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
	 * Perform a streaming chat completion request against LM Studio.
	 *
	 * This method enables Server-Sent Events (SSE) streaming for real-time token delivery.
	 * Similar to the Gemini client implementation, this buffers the complete SSE response
	 * from wp_remote_post() and then parses it chunk-by-chunk.
	 *
	 * @param array    $messages Message payload to send to LM Studio.
	 * @param array    $options  Additional options (model, temperature, tools, timeout).
	 * @param callable $callback Callback function to process each chunk of streaming data.
	 *                           Receives ($content, $type) where type is 'text' or 'tool_call'.
	 * @return array|WP_Error Final accumulated response or WP_Error on failure.
	 */
	public function stream_chat_completion( array $messages, array $options = array(), $callback = null ) {
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

		$payload = $this->build_payload( $messages, $options, $model );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Enable streaming for SSE response.
		$payload['stream'] = true;

		$url = untrailingslashit( $endpoint_url ) . '/v1/chat/completions';

		$request_args = array(
			'headers'  => array(
				'Content-Type' => 'application/json',
			),
			'body'     => wp_json_encode( $payload ),
			'timeout'  => max( 120, $this->resolve_timeout( $options ) ),
			'stream'   => true,
			'blocking' => true,
		);

		WP_MCP_AI_Logger::log_event( 'lm_studio_stream_request', 'Sending streaming request to LM Studio.', array( 'model' => $model ) );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LM Studio streaming request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The LM Studio API streaming request failed to complete.', 'wp-mcp-ai' ),
				__( 'LM Studio', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$decoded = null;
			}

			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'LM Studio returned an error response for streaming.',
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

		// Process SSE stream response (buffered by wp_remote_post).
		$accumulated = array(
			'content'    => '',
			'tool_calls' => array(),
			'role'       => 'assistant',
		);

		$lines = explode( "\n", $body );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || 'data: [DONE]' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, 'data: ' ) ) {
				$json_str = substr( $line, 6 );
				$chunk    = json_decode( $json_str, true );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
					continue;
				}

				// Process chunk (OpenAI-compatible format).
				if ( isset( $chunk['choices'] ) && is_array( $chunk['choices'] ) ) {
					foreach ( $chunk['choices'] as $choice ) {
						if ( isset( $choice['delta']['content'] ) && '' !== $choice['delta']['content'] ) {
							$accumulated['content'] .= $choice['delta']['content'];

							if ( is_callable( $callback ) ) {
								call_user_func( $callback, $choice['delta']['content'], 'text' );
							}
						}

						if ( isset( $choice['delta']['role'] ) ) {
							$accumulated['role'] = $choice['delta']['role'];
						}

						// Handle tool calls in streaming.
						if ( isset( $choice['delta']['tool_calls'] ) && is_array( $choice['delta']['tool_calls'] ) ) {
							foreach ( $choice['delta']['tool_calls'] as $tool_call_delta ) {
								$index = isset( $tool_call_delta['index'] ) ? $tool_call_delta['index'] : 0;

								if ( ! isset( $accumulated['tool_calls'][ $index ] ) ) {
									$accumulated['tool_calls'][ $index ] = array(
										'id'       => isset( $tool_call_delta['id'] ) ? $tool_call_delta['id'] : '',
										'type'     => isset( $tool_call_delta['type'] ) ? $tool_call_delta['type'] : 'function',
										'function' => array(
											'name'      => '',
											'arguments' => '',
										),
									);
								}

								if ( isset( $tool_call_delta['id'] ) && '' !== $tool_call_delta['id'] ) {
									$accumulated['tool_calls'][ $index ]['id'] = $tool_call_delta['id'];
								}

								if ( isset( $tool_call_delta['function']['name'] ) ) {
									$accumulated['tool_calls'][ $index ]['function']['name'] .= $tool_call_delta['function']['name'];
								}

								if ( isset( $tool_call_delta['function']['arguments'] ) ) {
									$accumulated['tool_calls'][ $index ]['function']['arguments'] .= $tool_call_delta['function']['arguments'];
								}

								if ( is_callable( $callback ) ) {
									call_user_func( $callback, $tool_call_delta, 'tool_call' );
								}
							}
						}
					}
				}
			}
		}

		// Build OpenAI-compatible response format.
		$normalized = array(
			'id'       => 'chatcmpl-' . uniqid(),
			'object'   => 'chat.completion',
			'created'  => time(),
			'model'    => $model,
			'provider' => 'lm_studio',
			'choices'  => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => $accumulated['role'],
						'content' => array(
							array(
								'type' => 'text',
								'text' => $accumulated['content'],
							),
						),
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Add tool calls to the response if present.
		if ( ! empty( $accumulated['tool_calls'] ) ) {
			$normalized['choices'][0]['message']['tool_calls'] = array_values( $accumulated['tool_calls'] );
		}

		WP_MCP_AI_Logger::log_event( 'lm_studio_stream_response', 'LM Studio streaming request completed.' );

		return $normalized;
	}

	/**
	 * Perform a streaming text completion request against LM Studio.
	 *
	 * This method enables Server-Sent Events (SSE) streaming for real-time token delivery.
	 * Similar to the Gemini client implementation, this buffers the complete SSE response
	 * from wp_remote_post() and then parses it chunk-by-chunk.
	 *
	 * @param string   $prompt   The text prompt to complete.
	 * @param array    $options  Additional options (model, temperature, max_tokens, timeout).
	 * @param callable $callback Callback function to process each chunk of streaming data.
	 *                           Receives ($content, $type) where type is 'text'.
	 * @return array|WP_Error Final accumulated response or WP_Error on failure.
	 */
	public function stream_completion( $prompt, array $options = array(), $callback = null ) {
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

		$payload = array(
			'model'  => $model,
			'prompt' => wp_kses_post( (string) $prompt ),
			'stream' => true, // Enable streaming for SSE response.
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
			 * Filter the maximum tokens for LM Studio streaming completion requests.
			 *
			 * @param int   $max_tokens The maximum tokens to use.
			 * @param array $options    Request options.
			 */
			$max_tokens = apply_filters( 'wp_mcp_ai_lm_studio_stream_completion_max_tokens', $max_tokens, $options );

			if ( $max_tokens > 0 ) {
				$payload['max_tokens'] = $max_tokens;
			}
		} else {
			$payload['max_tokens'] = absint( $options['max_tokens'] );
		}

		$url = untrailingslashit( $endpoint_url ) . '/v1/completions';

		$request_args = array(
			'headers'  => array(
				'Content-Type' => 'application/json',
			),
			'body'     => wp_json_encode( $payload ),
			'timeout'  => max( 120, $this->resolve_timeout( $options ) ),
			'stream'   => true,
			'blocking' => true,
		);

		WP_MCP_AI_Logger::log_event( 'lm_studio_stream_completion_request', 'Sending streaming completion request to LM Studio.', array( 'model' => $model ) );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LM Studio streaming completion request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The LM Studio completion API streaming request failed to complete.', 'wp-mcp-ai' ),
				__( 'LM Studio', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$decoded = null;
			}

			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'LM Studio completion returned an error response for streaming.',
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

		// Process SSE stream response (buffered by wp_remote_post).
		$accumulated_text = '';

		$lines = explode( "\n", $body );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || 'data: [DONE]' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, 'data: ' ) ) {
				$json_str = substr( $line, 6 );
				$chunk    = json_decode( $json_str, true );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
					continue;
				}

				// Process chunk (OpenAI-compatible format).
				if ( isset( $chunk['choices'] ) && is_array( $chunk['choices'] ) ) {
					foreach ( $chunk['choices'] as $choice ) {
						if ( isset( $choice['text'] ) && '' !== $choice['text'] ) {
							$accumulated_text .= $choice['text'];

							if ( is_callable( $callback ) ) {
								call_user_func( $callback, $choice['text'], 'text' );
							}
						}
					}
				}
			}
		}

		// Build OpenAI-compatible response format.
		$result = array(
			'id'      => 'cmpl-' . uniqid(),
			'object'  => 'text_completion',
			'created' => time(),
			'model'   => $model,
			'choices' => array(
				array(
					'text'          => $accumulated_text,
					'index'         => 0,
					'logprobs'      => null,
					'finish_reason' => 'stop',
				),
			),
		);

		WP_MCP_AI_Logger::log_event( 'lm_studio_stream_completion_response', 'LM Studio streaming completion request completed.' );

		return $result;
	}
}
}
