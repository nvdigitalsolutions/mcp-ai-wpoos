<?php
/**
 * LM Studio API client wrapper.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		 * Transient key used to cache per-model capability flags (5 min).
		 *
		 * @var string
		 */
		const CAPABILITIES_TRANSIENT = 'wp_mcp_ai_lm_studio_capabilities';

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
		 * Retrieve the optional LM Studio API key.
		 *
		 * LM Studio 0.3.6+ supports optional bearer-token authentication.
		 * When a key is set in Settings → NV oOS → Providers → LM Studio, it is
		 * sent as `Authorization: Bearer <key>` on every request.
		 *
		 * @return string Empty string when no key is configured.
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['lm_studio_api_key'] ) ? (string) $settings['lm_studio_api_key'] : '';
		}

		/**
		 * Return the API path prefix to use for chat/completion/model endpoints.
		 *
		 * When the `lm_studio_use_native_api` setting is enabled the native
		 * `/api/v0` surface is used, which returns richer metadata fields
		 * (`stats`, `model_info`, `capabilities`).  The default `/v1` prefix
		 * keeps full backwards compatibility.
		 *
		 * A per-request filter is available for callers that need to override
		 * the global setting:
		 *
		 *   add_filter( 'wp_mcp_ai_lm_studio_native_endpoint', '__return_true' );
		 *
		 * @param array $options Optional request options forwarded to the filter.
		 * @return string '/api/v0' or '/v1'.
		 */
		public function get_api_prefix( array $options = array() ) {
			$settings   = WP_MCP_AI_Admin_Settings::get_settings();
			$use_native = ! empty( $settings['lm_studio_use_native_api'] );

			/**
			 * Filter whether to use the LM Studio native `/api/v0` endpoint surface.
			 *
			 * @since 1.5.0
			 *
			 * @param bool  $use_native True to use /api/v0, false to use /v1 (default).
			 * @param array $options    Request options.
			 */
			$use_native = (bool) apply_filters( 'wp_mcp_ai_lm_studio_native_endpoint', $use_native, $options );

			return $use_native ? '/api/v0' : '/v1';
		}

		/**
		 * Build the Authorization header array when an API key is configured.
		 *
		 * @return array Empty array when no key is set, otherwise ['Authorization' => 'Bearer …'].
		 */
		protected function build_auth_headers() {
			$api_key = $this->get_api_key();
			if ( '' === $api_key ) {
				return array();
			}
			return array( 'Authorization' => 'Bearer ' . $api_key );
		}

		/**
		 * Test the connection to the LM Studio instance.
		 *
		 * Tries `/v1/models` first (OpenAI-compatible surface).  If that returns
		 * a 404 — which older LM Studio builds emit — the method transparently
		 * falls back to `/api/v0/models` (native REST surface).  The LM Studio
		 * version string is included in the success result when the server
		 * returns an `x-lm-studio-version` response header.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$headers = array_merge(
				array( 'Accept' => 'application/json' ),
				$this->build_auth_headers()
			);

			$request_args = array(
				'timeout' => $timeout,
				'headers' => $headers,
			);

			// Try the OpenAI-compatible endpoint first.
			$url = untrailingslashit( $endpoint_url ) . '/v1/models';

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
					__( 'The LM Studio connection test failed to complete.', 'mcp-ai-wpoos' ),
					__( 'LM Studio', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			// If /v1/models returned 404, fall back to the native /api/v0/models endpoint.
			if ( 404 === $code ) {
				$fallback_url = untrailingslashit( $endpoint_url ) . '/api/v0/models';

				WP_MCP_AI_Logger::log_event(
					'lm_studio_test_connection',
					'Falling back to native /api/v0/models endpoint.',
					array( 'url' => $fallback_url )
				);

				$response = wp_remote_get( $fallback_url, $request_args );

				if ( is_wp_error( $response ) ) {
					WP_MCP_AI_Logger::log_error( 'LM Studio fallback connection test failed.', array( 'error' => $response->get_error_message() ) );

					return WP_MCP_AI_HTTP::prepare_transport_error(
						$response,
						'wp_mcp_ai_http_error',
						__( 'The LM Studio connection test failed to complete.', 'mcp-ai-wpoos' ),
						__( 'LM Studio', 'mcp-ai-wpoos' )
					);
				}

				$code = wp_remote_retrieve_response_code( $response );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'LM Studio returned an error response.',
					array( 'code' => $code )
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'LM Studio returned an unexpected response.', 'mcp-ai-wpoos' ),
					array( 'status' => $code )
				);
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_test_connection', 'LM Studio connection successful.' );

			$result = array(
				'success' => true,
				'message' => __( 'Successfully connected to LM Studio instance.', 'mcp-ai-wpoos' ),
			);

			// Include the server version string when present.
			$version = wp_remote_retrieve_header( $response, 'x-lm-studio-version' );
			if ( ! empty( $version ) ) {
				$result['version'] = sanitize_text_field( $version );
				$result['message'] = sprintf(
					/* translators: %s: LM Studio version number */
					__( 'Successfully connected to LM Studio instance (version %s).', 'mcp-ai-wpoos' ),
					$result['version']
				);
			}

			return $result;
		}

		/**
		 * List available models from the LM Studio instance.
		 *
		 * When the native API is enabled (`lm_studio_use_native_api`) the
		 * `/api/v0/models` endpoint is used, which returns richer per-model
		 * metadata: architecture, quantization, loaded state, context window
		 * sizes, and a `capabilities` array.  The additional fields are passed
		 * through so the model picker can surface capability information (e.g.
		 * disabling non-tool-capable models when an assistant has tools enabled).
		 *
		 * @return array|WP_Error
		 */
		public function list_models() {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$prefix = $this->get_api_prefix();
			$url    = untrailingslashit( $endpoint_url ) . $prefix . '/models';

			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array_merge(
					array( 'Accept' => 'application/json' ),
					$this->build_auth_headers()
				),
			);

			WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'Fetching models from LM Studio.', array( 'url' => $url ) );

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio model listing failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio model listing request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'LM Studio', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from LM Studio.', 'mcp-ai-wpoos' );

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

			// LM Studio uses OpenAI-compatible format: a data array of objects each containing an id field.
			// The native /api/v0/models endpoint returns additional metadata fields.
			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				foreach ( $decoded['data'] as $model ) {
					if ( ! isset( $model['id'] ) ) {
						continue;
					}

					$entry = array(
						'id'       => $model['id'],
						'owned_by' => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
						'created'  => isset( $model['created'] ) ? $model['created'] : 0,
					);

					// Native /api/v0/models fields (present when lm_studio_use_native_api is on).
					if ( isset( $model['arch'] ) ) {
						$entry['arch'] = sanitize_text_field( $model['arch'] );
					}
					if ( isset( $model['quantization'] ) ) {
						$entry['quantization'] = sanitize_text_field( $model['quantization'] );
					}
					if ( isset( $model['state'] ) ) {
						// 'loaded' or 'not-loaded'.
						$entry['state'] = sanitize_text_field( $model['state'] );
					}
					if ( isset( $model['max_context_length'] ) ) {
						$entry['max_context_length'] = absint( $model['max_context_length'] );
					}
					if ( isset( $model['loaded_context_length'] ) ) {
						$entry['loaded_context_length'] = absint( $model['loaded_context_length'] );
					}
					if ( isset( $model['capabilities'] ) && is_array( $model['capabilities'] ) ) {
						$entry['capabilities'] = array_map( 'sanitize_text_field', $model['capabilities'] );
					}

					$models[] = $entry;
				}
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'LM Studio models retrieved.', array( 'count' => count( $models ) ) );

			// Cache capability information for the tools guard.
			$this->cache_capabilities_from_model_list( $models );

			return $models;
		}

		/**
		 * Perform a chat completion request against LM Studio.
		 *
		 * Supports both non-streaming and SSE streaming responses.  When
		 * `$options['stream']` is truthy the method sends the request with
		 * `stream: true` in the JSON payload and parses the Server-Sent Events
		 * response body, invoking `$options['stream_callback']` for each chunk.
		 * The final return value always uses the same OpenAI-compatible shape
		 * regardless of whether streaming was used.
		 *
		 * @param array $messages Message payload to send to LM Studio.
		 * @param array $options  Additional options (model, temperature, tools, timeout, stream, stream_callback).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_endpoint' => __( 'Add an LM Studio endpoint URL in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_model',
					__( 'No LM Studio model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_model' => __( 'Choose an LM Studio model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Phase 5: Capability guard — skip tool-calling payload for models
			// that don't advertise the tool_use capability (prevents LM Studio 400s).
			if ( ! empty( $options['tools'] ) ) {
				$capability_check = $this->check_tool_capability( $model );
				if ( is_wp_error( $capability_check ) ) {
					return $capability_check;
				}
			}

			// Filter orphaned tool messages before building the payload.
			// LM Studio's OpenAI-compatible API may reject requests where
			// tool messages lack a matching assistant tool_call.
			$messages = $this->filter_tool_messages_for_payload( $messages );

			$payload = $this->build_payload( $messages, $options, $model );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$prefix = $this->get_api_prefix( $options );
			$url    = untrailingslashit( $endpoint_url ) . $prefix . '/chat/completions';

			$is_streaming = ! empty( $payload['stream'] );

			// Real-time SSE: if a stream_callback is provided AND cURL is available,
			// bypass wp_remote_post entirely.  wp_remote_post always buffers the full
			// response body before returning, so its stream_callback fires only after
			// the complete download — not while the model is generating tokens.
			// CURLOPT_WRITEFUNCTION fires for every incoming network chunk, forwarding
			// each content/reasoning delta to the browser the moment it arrives.
			if ( $is_streaming && function_exists( 'curl_init' ) ) {
				$realtime_cb = isset( $options['stream_callback'] ) && is_callable( $options['stream_callback'] ) ? $options['stream_callback'] : null;
				if ( null !== $realtime_cb ) {
					WP_MCP_AI_Logger::log_event(
						'lm_studio_request',
						'Sending real-time streaming request to LM Studio via cURL.',
						array(
							'model'    => $model,
							'realtime' => true,
						)
					);
					return $this->do_realtime_curl_stream( $url, $payload, $model, $this->resolve_timeout( $options ), $realtime_cb );
				}
			}

			$request_args = array(
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$this->build_auth_headers()
				),
				'body'    => wp_json_encode( $payload ),
				// Use higher minimum timeout for local AI models which need more time to generate responses.
				'timeout' => max( 120, $this->resolve_timeout( $options ) ),
			);

			if ( $is_streaming ) {
				/**
				 * Filter the HTTP request arguments for LM Studio streaming requests.
				 *
				 * Allows tuning the timeout, headers, or other transport options for
				 * SSE streaming requests to LM Studio.
				 *
				 * @since 1.5.0
				 *
				 * @param array $request_args wp_remote_post() arguments.
				 * @param array $options      Original request options.
				 * @param array $payload      JSON payload being sent.
				 */
				$request_args = apply_filters( 'wp_mcp_ai_lm_studio_stream_request_args', $request_args, $options, $payload );
			}

			WP_MCP_AI_Logger::log_event(
				'lm_studio_request',
				'Sending request to LM Studio.',
				array(
					'model'     => $model,
					'streaming' => $is_streaming,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'LM Studio', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// For SSE streaming, delegate to the dedicated parser.
			if ( $is_streaming ) {
				$stream_callback = isset( $options['stream_callback'] ) && is_callable( $options['stream_callback'] ) ? $options['stream_callback'] : null;
				return $this->handle_sse_streaming_response( $body, $model, $code, $stream_callback );
			}

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, $decoded, $response );
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
			// Memory documents are additional context from uploaded files or vector stores.
			if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
				$memory_messages = $this->build_memory_messages_from_options( $options );
				if ( ! empty( $memory_messages ) ) {
					$system_messages = array_merge( $system_messages, $memory_messages );
				}
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
				// This handles cases where conversation history contains tool responses but the current.
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

			// Prepend system messages to the formatted messages.
			// This ensures assistant knowledge and instructions are passed to LM Studio.
			if ( ! empty( $system_messages ) ) {
				$formatted_messages = array_merge( $system_messages, $formatted_messages );
			}

			$payload = array(
				'model'    => $model,
				'messages' => $formatted_messages,
			);

			// Honour streaming flag — default false for backwards compatibility.
			$payload['stream'] = isset( $options['stream'] ) && $options['stream'];

			// JIT auto-unload: pass through ttl (seconds) when provided so LM Studio
			// automatically unloads the model after the specified idle period.
			if ( isset( $options['ttl'] ) && is_numeric( $options['ttl'] ) ) {
				$payload['ttl'] = absint( $options['ttl'] );
			}

			// Structured outputs: pass through response_format when it's a valid
			// json_schema descriptor (LM Studio supports stricter schemas than OpenAI).
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$fmt_type = isset( $options['response_format']['type'] ) ? $options['response_format']['type'] : '';
				if ( in_array( $fmt_type, array( 'json_schema', 'json_object', 'text' ), true ) ) {
					$payload['response_format'] = $options['response_format'];
				}
			}

			// Add tools if provided (OpenAI-compatible function calling).
			if ( ! empty( $options['tools'] ) ) {
				$payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );

				// tool_choice controls which tool the model calls ('auto', 'none', 'required', or a specific function).
				if ( isset( $options['tool_choice'] ) ) {
					if ( is_string( $options['tool_choice'] ) && in_array( $options['tool_choice'], array( 'auto', 'none', 'required' ), true ) ) {
						$payload['tool_choice'] = $options['tool_choice'];
					} elseif ( is_array( $options['tool_choice'] ) && isset( $options['tool_choice']['type'] ) && 'function' === $options['tool_choice']['type'] && isset( $options['tool_choice']['function']['name'] ) ) {
						$payload['tool_choice'] = array(
							'type'     => 'function',
							'function' => array(
								'name' => sanitize_text_field( $options['tool_choice']['function']['name'] ),
							),
						);
					}
				}

				// parallel_tool_calls controls whether the model can call multiple tools concurrently.
				if ( isset( $options['parallel_tool_calls'] ) ) {
					$payload['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
				}
			}

			// Add temperature if specified.
			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			// Additional OpenAI-compatible parameters supported by LM Studio.
			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['top_p'] = (float) $options['top_p'];
			}

			if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
				$payload['seed'] = (int) $options['seed'];
			}

			if ( isset( $options['presence_penalty'] ) && is_numeric( $options['presence_penalty'] ) ) {
				$payload['presence_penalty'] = (float) $options['presence_penalty'];
			}

			if ( isset( $options['frequency_penalty'] ) && is_numeric( $options['frequency_penalty'] ) ) {
				$payload['frequency_penalty'] = (float) $options['frequency_penalty'];
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
		 * Normalize LM Studio response to match our standard format.
		 *
		 * Handles:
		 * - OpenAI-compatible `/v1` responses (minimal transformation).
		 * - Native `/api/v0` responses which add `stats` and `model_info` fields.
		 * - Reasoning models (DeepSeek-R1, Qwen-QwQ) that emit `reasoning_content`
		 *   or wrap thinking in `<think>…</think>` tags inside `content`.
		 * - Malformed tool-call `arguments` JSON that LM Studio occasionally emits.
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

			// Phase 2: Extract native API telemetry stats when present.
			// The /api/v0 surface adds a top-level `stats` object with timing data.
			if ( isset( $response['stats'] ) && is_array( $response['stats'] ) ) {
				$stats       = $response['stats'];
				$usage_stats = array();

				if ( isset( $stats['tokens_per_second'] ) ) {
					$usage_stats['tokens_per_second'] = (float) $stats['tokens_per_second'];
				}
				if ( isset( $stats['time_to_first_token'] ) ) {
					$usage_stats['time_to_first_token_ms'] = (float) $stats['time_to_first_token'];
				}
				if ( isset( $stats['generation_time'] ) ) {
					$usage_stats['generation_time_ms'] = (float) $stats['generation_time'];
				}
				if ( isset( $stats['stop_reason'] ) ) {
					$usage_stats['stop_reason'] = sanitize_text_field( $stats['stop_reason'] );
				}

				if ( ! empty( $usage_stats ) ) {
					$response['usage_stats'] = $usage_stats;

					/**
					 * Fires after LM Studio native-API performance stats are parsed.
					 *
					 * Allows the cost/performance dashboard to record per-request
					 * telemetry without coupling to the response normalization path.
					 *
					 * @since 1.5.0
					 *
					 * @param array  $usage_stats Parsed stats array.
					 * @param string $model       Model identifier.
					 * @param array  $response    Full decoded response.
					 */
					do_action( 'wp_mcp_ai_lm_studio_provider_stats', $usage_stats, $model, $response );
				}
			}

			// Phase 5: Normalize choices — content, reasoning, and tool-call repairs.
			foreach ( $response['choices'] as $index => $choice ) {
				if ( ! isset( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
					continue;
				}

				$message = $choice['message'];

				// --- Reasoning content passthrough ---------------------------------
				// Some models (DeepSeek-R1, Qwen-QwQ) place extended thinking in a
				// dedicated `reasoning_content` field.  We preserve it so the REST
				// layer can stream it as a separate thinking block to the chat UI.
				if ( ! empty( $message['reasoning_content'] ) ) {
					$response['choices'][ $index ]['message']['reasoning_content'] = (string) $message['reasoning_content'];
				}

				// --- <think>…</think> extraction -----------------------------------
				// Some open-source reasoning models inline their thinking inside
				// `<think>…</think>` tags in the main content field.  Extract the
				// block into `reasoning_content` and remove it from `content` so the
				// agentic loop receives clean assistant text.
				$raw_content = isset( $message['content'] ) ? $message['content'] : '';
				if ( is_string( $raw_content ) && false !== strpos( $raw_content, '<think>' ) ) {
					$think_extracted = '';
					$cleaned_content = preg_replace_callback(
						'/<think>(.*?)<\/think>/s',
						function ( $m ) use ( &$think_extracted ) {
							$think_extracted .= $m[1];
							return '';
						},
						$raw_content
					);

					if ( '' !== $think_extracted ) {
						// Only overwrite reasoning_content when none was already set.
						if ( empty( $response['choices'][ $index ]['message']['reasoning_content'] ) ) {
							// Sanitize the extracted reasoning text: strip all HTML tags that
							// a model might embed inside its thinking block before it is
							// stored or forwarded to the chat UI.
							$response['choices'][ $index ]['message']['reasoning_content'] = wp_strip_all_tags( trim( $think_extracted ) );
						}
						$response['choices'][ $index ]['message']['content'] = trim( (string) $cleaned_content );
						// Re-read cleaned content for the array conversion below.
						$raw_content = $response['choices'][ $index ]['message']['content'];
					}
				}

				// --- Content array normalization -----------------------------------
				if ( is_string( $raw_content ) ) {
					$response['choices'][ $index ]['message']['content'] = array(
						array(
							'type' => 'text',
							'text' => $raw_content,
						),
					);
				}

				// --- Tool-call argument repair ------------------------------------
				// LM Studio occasionally emits tool-call `arguments` as a nested
				// object instead of a JSON-encoded string, or produces truncated
				// JSON strings.  Normalize to the expected string format.
				if ( ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					foreach ( $response['choices'][ $index ]['message']['tool_calls'] as $tc_idx => $tc ) {
						if ( ! isset( $tc['function']['arguments'] ) ) {
							continue;
						}

						$args = $tc['function']['arguments'];

						if ( is_array( $args ) || is_object( $args ) ) {
							// Already decoded — re-encode as string.
							$response['choices'][ $index ]['message']['tool_calls'][ $tc_idx ]['function']['arguments'] = wp_json_encode( $args );
						} elseif ( is_string( $args ) ) {
							// Validate and attempt to repair truncated JSON.
							// Note: this is a narrow repair for the most common LM Studio
							// failure mode — a trailing-truncated object (missing `}`).
							// Other malformations (missing quotes, trailing commas, nested
							// truncation) are not repaired and will be logged as-is.
							// If repair cannot be applied the original string is preserved
							// so the caller can decide how to handle the invalid JSON.
							json_decode( $args );
							if ( JSON_ERROR_NONE !== json_last_error() ) {
								// Append a closing brace as a minimal repair strategy for
								// truncated objects, which is the most common failure mode.
								$repaired = $args . '}';
								json_decode( $repaired );
								if ( JSON_ERROR_NONE === json_last_error() ) {
									$response['choices'][ $index ]['message']['tool_calls'][ $tc_idx ]['function']['arguments'] = $repaired;

									WP_MCP_AI_Logger::log_event(
										'lm_studio_tool_call_repair',
										'Repaired malformed tool-call arguments.',
										array( 'tool_call_id' => isset( $tc['id'] ) ? $tc['id'] : '' )
									);
								}
							}
						}
					}
				}
			}

			$response['provider'] = 'lm_studio';

			if ( ! isset( $response['model'] ) ) {
				$response['model'] = $model;
			}

			return $response;
		}

		/**
		 * Translate an LM Studio HTTP error into a WP_Error.
		 *
		 * Handle a non-2xx API response and return an appropriate WP_Error
		 * with structured error data and actionable guidance.
		 *
		 * @param int          $code     HTTP status code.
		 * @param array        $decoded  Decoded JSON response body.
		 * @param array|object $response Full WP HTTP response.
		 * @return WP_Error
		 */
		protected function handle_api_error( $code, array $decoded, $response ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'mcp-ai-wpoos' );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_lm_studio_api_error';

			if ( 401 === $code ) {
				$error_code            = 'wp_mcp_ai_lm_studio_auth_error';
				$error_data['actions'] = array(
					'auth_info' => __( 'Verify your LM Studio endpoint URL and API key in NV oOS → Providers → LM Studio.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The LM Studio API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'LM Studio returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);
			}

			return new WP_Error( $error_code, $error_message, $error_data );
		}

		/**
		 * Drop tool role messages that are not associated with the most recent
		 * assistant tool call.
		 *
		 * LM Studio's OpenAI-compatible API requires tool responses to
		 * immediately follow the assistant message that emitted the corresponding
		 * tool call. When intervening messages appear between those entries the
		 * request may be rejected. This normaliser filters out any tool messages
		 * that no longer have a matching pending call so the payload remains valid.
		 *
		 * Logic mirrors WP_MCP_AI_OpenAI_Client::filter_tool_messages_for_payload().
		 *
		 * @param array $messages Chat history supplied by the caller.
		 * @return array
		 */
		protected function filter_tool_messages_for_payload( array $messages ) {
			if ( empty( $messages ) ) {
				return $messages;
			}

			$filtered                = array();
			$pending_calls           = array();
			$awaiting_tool_responses = false;
			$incomplete_group_start  = null;

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

				if ( '' === $role ) {
					continue;
				}

				if ( in_array( $role, array( 'system', 'user' ), true ) ) {
					// If the previous assistant message had tool_calls that were never
					// fully answered, drop the entire incomplete group.
					if ( $awaiting_tool_responses && null !== $incomplete_group_start ) {
						$filtered = array_slice( $filtered, 0, $incomplete_group_start );
						if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
							WP_MCP_AI_Logger::log_event(
								'lm_studio_dropped_incomplete_tool_group',
								'Dropped assistant message with unresolved tool_calls before user/system message.',
								array(
									'pending_call_ids' => array_keys( $pending_calls ),
								)
							);
						}
					}

					$pending_calls           = array();
					$awaiting_tool_responses = false;
					$incomplete_group_start  = null;
					$filtered[]              = $message;
					continue;
				}

				if ( 'assistant' === $role ) {
					// If the PREVIOUS assistant had unresolved tool_calls, drop that group.
					if ( $awaiting_tool_responses && null !== $incomplete_group_start ) {
						$filtered = array_slice( $filtered, 0, $incomplete_group_start );
						if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
							WP_MCP_AI_Logger::log_event(
								'lm_studio_dropped_incomplete_tool_group',
								'Dropped assistant message with unresolved tool_calls before next assistant message.',
								array(
									'pending_call_ids' => array_keys( $pending_calls ),
								)
							);
						}
					}

					$pending_calls           = array();
					$awaiting_tool_responses = false;
					$incomplete_group_start  = null;

					if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
						foreach ( $message['tool_calls'] as $tool_call ) {
							if ( ! is_array( $tool_call ) ) {
								continue;
							}

							$call_id = isset( $tool_call['id'] ) ? sanitize_text_field( (string) $tool_call['id'] ) : '';

							if ( '' === $call_id ) {
								continue;
							}

							$pending_calls[ $call_id ] = true;
						}
					}

					if ( ! empty( $pending_calls ) ) {
						$awaiting_tool_responses = true;
						$incomplete_group_start  = count( $filtered );
					}

					$filtered[] = $message;
					continue;
				}

				if ( 'tool' === $role ) {
					$tool_call_id = isset( $message['tool_call_id'] ) ? sanitize_text_field( (string) $message['tool_call_id'] ) : '';

					if ( '' === $tool_call_id || ! $awaiting_tool_responses || ! isset( $pending_calls[ $tool_call_id ] ) ) {
						if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
							WP_MCP_AI_Logger::log_event(
								'lm_studio_dropped_orphan_tool_message',
								'Dropping tool message without matching tool call before LM Studio request.',
								array(
									'tool_call_id' => $tool_call_id,
									'reason'       => '' === $tool_call_id
										? 'missing_tool_call_id'
										: ( $awaiting_tool_responses ? 'tool_call_not_found' : 'no_pending_tool_calls' ),
								)
							);
						}

						continue;
					}

					unset( $pending_calls[ $tool_call_id ] );

					if ( empty( $pending_calls ) ) {
						$awaiting_tool_responses = false;
						$incomplete_group_start  = null;
					}

					$filtered[] = $message;
					continue;
				}

				$pending_calls           = array();
				$awaiting_tool_responses = false;
				$incomplete_group_start  = null;
				$filtered[]              = $message;
			}

			return array_values( $filtered );
		}

		/**
		 * Parse an SSE (Server-Sent Events) response body from LM Studio and
		 * assemble it into a single OpenAI-compatible chat completion object.
		 *
		 * LM Studio's OpenAI-compatible streaming endpoint emits lines of the
		 * form `data: {JSON}` followed by a terminal `data: [DONE]` line.
		 * Each JSON chunk carries a `choices[0].delta` with incremental
		 * `content` and/or `tool_calls` fragments.
		 *
		 * The `$stream_callback` receives each raw chunk array as it is parsed,
		 * which lets the SSE handler forward tokens to the browser immediately.
		 *
		 * @param string        $body            Raw SSE response body.
		 * @param string        $model           Model identifier.
		 * @param int           $http_code       HTTP status code from wp_remote_post.
		 * @param callable|null $stream_callback Optional per-chunk callback.
		 * @return array|WP_Error Assembled and normalized response, or WP_Error on failure.
		 */
		protected function handle_sse_streaming_response( $body, $model, $http_code, $stream_callback = null ) {
			if ( empty( $body ) ) {
				WP_MCP_AI_Logger::log_error( 'Empty SSE response from LM Studio.', array( 'model' => $model ) );
				return new WP_Error(
					'wp_mcp_ai_empty_streaming_response',
					__( 'Empty streaming response from LM Studio.', 'mcp-ai-wpoos' )
				);
			}

			// Surface HTTP-level errors before attempting to parse SSE.
			if ( $http_code >= 400 ) {
				// Body may be a plain JSON error object rather than SSE.
				$decoded = json_decode( $body, true );
				$msg     = ( is_array( $decoded ) && isset( $decoded['error']['message'] ) )
					? $decoded['error']['message']
					: __( 'LM Studio returned an error during streaming.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error( 'LM Studio streaming error response.', array( 'code' => $http_code ) );

				return new WP_Error(
					'wp_mcp_ai_stream_error',
					$msg,
					array( 'status' => $http_code )
				);
			}

			$lines = explode( "\n", $body );

			$accumulated_content   = '';
			$accumulated_reasoning = '';
			$tool_calls_by_index   = array(); // Maps SSE delta index → accumulated tool-call object.
			$response_id           = '';
			$finish_reason         = null;
			$usage                 = null;
			$found_done            = false;

			foreach ( $lines as $line ) {
				$line = trim( $line );

				if ( '' === $line ) {
					continue;
				}

				// SSE comment lines (keep-alive pings).
				if ( ':' === $line[0] ) {
					continue;
				}

				if ( 'data: [DONE]' === $line ) {
					$found_done = true;
					break;
				}

				if ( 0 !== strpos( $line, 'data: ' ) ) {
					continue;
				}

				$json  = substr( $line, 6 ); // Strip 'data: ' prefix.
				$chunk = json_decode( $json, true );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
					continue;
				}

				// Capture response ID from first chunk.
				if ( '' === $response_id && isset( $chunk['id'] ) ) {
					$response_id = (string) $chunk['id'];
				}

				// Invoke the per-chunk callback so the SSE handler can forward tokens.
				if ( null !== $stream_callback ) {
					call_user_func( $stream_callback, $chunk );
				}

				if ( empty( $chunk['choices'] ) || ! is_array( $chunk['choices'] ) ) {
					continue;
				}

				$choice = $chunk['choices'][0];
				$delta  = isset( $choice['delta'] ) ? $choice['delta'] : array();

				// Accumulate content text.
				if ( isset( $delta['content'] ) && is_string( $delta['content'] ) ) {
					$accumulated_content .= $delta['content'];
				}

				// Accumulate reasoning_content (reasoning models).
				if ( isset( $delta['reasoning_content'] ) && is_string( $delta['reasoning_content'] ) ) {
					$accumulated_reasoning .= $delta['reasoning_content'];
				}

				// Accumulate tool_calls deltas.
				if ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
					foreach ( $delta['tool_calls'] as $tc_delta ) {
						if ( ! is_array( $tc_delta ) || ! isset( $tc_delta['index'] ) ) {
							continue;
						}

						$tc_idx = (int) $tc_delta['index'];

						if ( ! isset( $tool_calls_by_index[ $tc_idx ] ) ) {
							$tool_calls_by_index[ $tc_idx ] = array(
								'index'    => $tc_idx,
								'id'       => '',
								'type'     => 'function',
								'function' => array(
									'name'      => '',
									'arguments' => '',
								),
							);
						}

						if ( isset( $tc_delta['id'] ) ) {
							$tool_calls_by_index[ $tc_idx ]['id'] = (string) $tc_delta['id'];
						}
						if ( isset( $tc_delta['type'] ) ) {
							$tool_calls_by_index[ $tc_idx ]['type'] = (string) $tc_delta['type'];
						}
						if ( isset( $tc_delta['function']['name'] ) ) {
							$tool_calls_by_index[ $tc_idx ]['function']['name'] .= (string) $tc_delta['function']['name'];
						}
						if ( isset( $tc_delta['function']['arguments'] ) ) {
							$tool_calls_by_index[ $tc_idx ]['function']['arguments'] .= (string) $tc_delta['function']['arguments'];
						}
					}
				}

				// Capture finish_reason and usage from the last data chunk.
				if ( isset( $choice['finish_reason'] ) && null !== $choice['finish_reason'] ) {
					$finish_reason = $choice['finish_reason'];
				}
				if ( isset( $chunk['usage'] ) && is_array( $chunk['usage'] ) ) {
					$usage = $chunk['usage'];
				}
			}

			if ( ! $found_done ) {
				WP_MCP_AI_Logger::log_event(
					'lm_studio_stream',
					'SSE stream ended without [DONE] sentinel (model may have been interrupted).',
					array( 'model' => $model )
				);
			}

			// Build the assembled message.
			$message = array(
				'role'    => 'assistant',
				'content' => $accumulated_content,
			);

			if ( '' !== $accumulated_reasoning ) {
				$message['reasoning_content'] = $accumulated_reasoning;
			}

			if ( ! empty( $tool_calls_by_index ) ) {
				ksort( $tool_calls_by_index );
				$message['tool_calls'] = array_values( $tool_calls_by_index );
			}

			// Assemble into a chat.completion-shaped response and normalize.
			$assembled = array(
				'id'      => $response_id,
				'object'  => 'chat.completion',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => $message,
						'finish_reason' => $finish_reason,
					),
				),
			);

			if ( null !== $usage ) {
				$assembled['usage'] = $usage;
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_stream', 'SSE streaming response assembled.', array( 'model' => $model ) );

			return $this->normalize_response( $assembled, $model );
		}

		/**
		 * Perform a real-time SSE stream request to LM Studio using direct cURL.
		 *
		 * Unlike the `wp_remote_post` path (which buffers the full response body
		 * before returning), this method uses `CURLOPT_WRITEFUNCTION` to process
		 * each network chunk as it arrives from LM Studio.  Every `delta.content`
		 * or `delta.reasoning_content` token is forwarded to `$stream_callback`
		 * immediately, so the browser SSE connection receives tokens in real time
		 * as the local model generates them.
		 *
		 * Tool-call argument deltas are accumulated silently (they do not contain
		 * visible content) and included in the assembled response at the end.
		 *
		 * @param string   $url             Full `chat/completions` endpoint URL.
		 * @param array    $payload         Request payload (`stream: true` already set).
		 * @param string   $model           Resolved model identifier (for normalization).
		 * @param int      $timeout         Request timeout in seconds.
		 * @param callable $stream_callback Invoked with each content/reasoning chunk array.
		 * @return array|WP_Error Normalized response on success, WP_Error on failure.
		 */
		protected function do_realtime_curl_stream( $url, array $payload, $model, $timeout, $stream_callback ) {
			// Build cURL-style header list: ['Authorization: Bearer ...', 'Content-Type: application/json'].
			// Use raw header values — cURL validates/rejects headers with unsafe characters internally,
			// and sanitize_text_field() would corrupt valid bearer token characters.
			$curl_headers = array( 'Content-Type: application/json' );
			foreach ( $this->build_auth_headers() as $header_name => $header_value ) {
				$curl_headers[] = $header_name . ': ' . $header_value;
			}

			// Mirror the SSL-bypass behaviour from WP_MCP_AI_HTTP_Helper for local endpoints.
			$parsed_url = wp_parse_url( $url );
			$host       = ! empty( $parsed_url['host'] ) ? $parsed_url['host'] : '';
			$settings   = WP_MCP_AI_Admin_Settings::get_settings();
			$bypass_ssl = isset( $settings['enable_loopback_ssl_bypass'] ) ? (bool) $settings['enable_loopback_ssl_bypass'] : true;
			$skip_ssl   = $bypass_ssl && class_exists( 'WP_MCP_AI_HTTP_Helper' ) && WP_MCP_AI_HTTP_Helper::is_loopback_address( $host );

			// Accumulator state shared between the CURLOPT_WRITEFUNCTION closure
			// and the assembly code that runs after curl_exec() completes.
			$sse_buffer          = '';
			$http_status         = 0;
			$accumulated_content = '';
			$accumulated_reason  = '';
			$tool_calls_by_idx   = array();
			$response_id         = '';
			$finish_reason       = null;
			$usage               = null;
			$found_done          = false;

			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_errno
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close

			/*
			 * Direct cURL is required here for real-time LM Studio streaming.
			 *
			 * wp_remote_post() buffers the entire response body in memory and
			 * only returns it after the connection closes. It cannot forward
			 * individual tokens to the browser as they arrive from the model.
			 *
			 * CURLOPT_WRITEFUNCTION with a streaming callback is the only way
			 * to deliver Server-Sent Events token-by-token in real time. For
			 * non-streaming requests, the standard wp_remote_post() fallback
			 * is used instead (see the is_streaming guard above).
			 *
			 * This path is also gated behind function_exists('curl_init') and
			 * is_callable($stream_callback) checks.
			 */
			$ch = curl_init();

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
					CURLOPT_HTTPHEADER     => $curl_headers,
					CURLOPT_TIMEOUT        => max( 120, $timeout ),
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_SSL_VERIFYPEER => ! $skip_ssl,
					CURLOPT_SSL_VERIFYHOST => $skip_ssl ? 0 : 2,

					// Capture the HTTP status code from the response header line.
					CURLOPT_HEADERFUNCTION => function ( $_curl_handle, $header ) use ( &$http_status ) {
						if ( preg_match( '/^HTTP\/[\d.]+ (\d+)/', $header, $matches ) ) {
							$http_status = (int) $matches[1];
						}
						return strlen( $header );
					},

					// Process SSE data as it arrives — this is what achieves real-time streaming.
					// The closure receives raw bytes from the LM Studio socket; it maintains a
					// line-oriented buffer and parses complete SSE events on the fly.
					CURLOPT_WRITEFUNCTION  => function ( $_curl_handle, $data ) use ( &$sse_buffer, &$accumulated_content, &$accumulated_reason, &$tool_calls_by_idx, &$response_id, &$finish_reason, &$usage, &$found_done, $stream_callback ) {
						$sse_buffer .= $data;

						// Walk through all complete lines in the accumulated buffer.
						while ( false !== ( $newline_pos = strpos( $sse_buffer, "\n" ) ) ) {
							$line       = trim( substr( $sse_buffer, 0, $newline_pos ) );
							$sse_buffer = substr( $sse_buffer, $newline_pos + 1 );

							if ( '' === $line || ':' === $line[0] ) {
								continue; // Blank separator lines and SSE keep-alive comment pings.
							}

							if ( 'data: [DONE]' === $line ) {
								$found_done = true;
								continue;
							}

							if ( 0 !== strpos( $line, 'data: ' ) ) {
								continue;
							}

							$chunk = json_decode( substr( $line, 6 ), true );
							if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
								continue;
							}

							// Capture response ID from first chunk.
							if ( '' === $response_id && isset( $chunk['id'] ) ) {
								$response_id = (string) $chunk['id'];
							}

							$choice = isset( $chunk['choices'][0] ) ? $chunk['choices'][0] : array();
							$delta  = isset( $choice['delta'] ) ? $choice['delta'] : array();

							// Forward content tokens to the browser immediately.
							if ( isset( $delta['content'] ) && is_string( $delta['content'] ) && '' !== $delta['content'] ) {
								$accumulated_content .= $delta['content'];
								call_user_func(
									$stream_callback,
									array(
										'choices' => array(
											array( 'delta' => array( 'content' => $delta['content'] ) ),
										),
									)
								);
							}

							// Forward reasoning tokens (DeepSeek-R1 / Qwen-QwQ style models).
							if ( isset( $delta['reasoning_content'] ) && is_string( $delta['reasoning_content'] ) && '' !== $delta['reasoning_content'] ) {
								$accumulated_reason .= $delta['reasoning_content'];
								call_user_func(
									$stream_callback,
									array(
										'choices' => array(
											array( 'delta' => array( 'reasoning_content' => $delta['reasoning_content'] ) ),
										),
									)
								);
							}

							// Accumulate tool-call argument deltas silently.
							if ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
								foreach ( $delta['tool_calls'] as $tc_delta ) {
									if ( ! is_array( $tc_delta ) || ! isset( $tc_delta['index'] ) ) {
										continue;
									}
									$idx = (int) $tc_delta['index'];
									if ( ! isset( $tool_calls_by_idx[ $idx ] ) ) {
										$tool_calls_by_idx[ $idx ] = array(
											'index'    => $idx,
											'id'       => '',
											'type'     => 'function',
											'function' => array(
												'name' => '',
												'arguments' => '',
											),
										);
									}
									if ( isset( $tc_delta['id'] ) ) {
										$tool_calls_by_idx[ $idx ]['id'] = (string) $tc_delta['id'];
									}
									if ( isset( $tc_delta['type'] ) ) {
										$tool_calls_by_idx[ $idx ]['type'] = (string) $tc_delta['type'];
									}
									if ( isset( $tc_delta['function']['name'] ) ) {
										$tool_calls_by_idx[ $idx ]['function']['name'] .= (string) $tc_delta['function']['name'];
									}
									if ( isset( $tc_delta['function']['arguments'] ) ) {
										$tool_calls_by_idx[ $idx ]['function']['arguments'] .= (string) $tc_delta['function']['arguments'];
									}
								}
							}

							if ( isset( $choice['finish_reason'] ) && null !== $choice['finish_reason'] ) {
								$finish_reason = $choice['finish_reason'];
							}
							if ( isset( $chunk['usage'] ) && is_array( $chunk['usage'] ) ) {
								$usage = $chunk['usage'];
							}
						}

						return strlen( $data ); // Must return consumed byte count to cURL.
					},
				)
			);

			curl_exec( $ch );
			$curl_errno = curl_errno( $ch );
			$curl_error = curl_error( $ch );
			curl_close( $ch );
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_exec
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_errno
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_error
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_close

			if ( $curl_errno ) {
				WP_MCP_AI_Logger::log_error(
					'LM Studio real-time streaming failed.',
					array(
						'error' => $curl_error,
						'errno' => $curl_errno,
					)
				);
				return new WP_Error(
					'wp_mcp_ai_http_error',
					$curl_error ? $curl_error : __( 'cURL streaming request failed.', 'mcp-ai-wpoos' )
				);
			}

			if ( $http_status >= 400 ) {
				WP_MCP_AI_Logger::log_error(
					'LM Studio real-time streaming returned HTTP error.',
					array( 'code' => $http_status )
				);
				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'LM Studio returned an error during streaming.', 'mcp-ai-wpoos' ),
					array( 'status' => $http_status )
				);
			}

			if ( ! $found_done ) {
				WP_MCP_AI_Logger::log_event(
					'lm_studio_realtime_stream',
					'Real-time SSE stream ended without [DONE] sentinel (model may have been interrupted).',
					array( 'model' => $model )
				);
			}

			// Assemble the chat.completion-shaped response from accumulated streaming data.
			$message = array(
				'role'    => 'assistant',
				'content' => $accumulated_content,
			);

			if ( '' !== $accumulated_reason ) {
				$message['reasoning_content'] = $accumulated_reason;
			}

			if ( ! empty( $tool_calls_by_idx ) ) {
				ksort( $tool_calls_by_idx );
				$message['tool_calls'] = array_values( $tool_calls_by_idx );
			}

			$assembled = array(
				'id'      => $response_id,
				'object'  => 'chat.completion',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => $message,
						'finish_reason' => $finish_reason,
					),
				),
			);

			if ( null !== $usage ) {
				$assembled['usage'] = $usage;
			}

			WP_MCP_AI_Logger::log_event( 'lm_studio_realtime_stream', 'Real-time streaming response assembled.', array( 'model' => $model ) );

			return $this->normalize_response( $assembled, $model );
		}

		/**
		 * Retrieve and cache per-model capability flags from the LM Studio
		 * native `/api/v0/models` endpoint.
		 *
		 * Results are stored in a 5-minute transient keyed by
		 * `wp_mcp_ai_lm_studio_capabilities` to avoid a round-trip on every
		 * chat request.  The cached value is a map of model ID → capabilities[].
		 *
		 * @return array Map of model_id => string[] capabilities.  Empty array on failure or
		 *               when native API is disabled.
		 */
		public function get_model_capabilities() {
			$cached = get_transient( self::CAPABILITIES_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			// Only the native /api/v0/models endpoint includes capabilities.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( empty( $settings['lm_studio_use_native_api'] ) ) {
				return array();
			}

			$models = $this->list_models();
			if ( is_wp_error( $models ) ) {
				return array();
			}

			$map = array();
			foreach ( $models as $model ) {
				if ( ! empty( $model['id'] ) && isset( $model['capabilities'] ) ) {
					$map[ $model['id'] ] = $model['capabilities'];
				}
			}

			set_transient( self::CAPABILITIES_TRANSIENT, $map, 5 * MINUTE_IN_SECONDS );

			return $map;
		}

		/**
		 * Warm the capabilities transient from a freshly-fetched model list.
		 *
		 * Called by list_models() so a single model-list fetch also populates
		 * the capability cache without a second HTTP request.
		 *
		 * @param array $models Normalised model list from list_models().
		 */
		protected function cache_capabilities_from_model_list( array $models ) {
			$map = array();
			foreach ( $models as $model ) {
				if ( ! empty( $model['id'] ) && isset( $model['capabilities'] ) ) {
					$map[ $model['id'] ] = (array) $model['capabilities'];
				}
			}
			if ( ! empty( $map ) ) {
				set_transient( self::CAPABILITIES_TRANSIENT, $map, 5 * MINUTE_IN_SECONDS );
			}
		}

		/**
		 * Guard function that checks whether the given model supports tool_use.
		 *
		 * Returns `null` when the check passes (either because the capability
		 * cache confirms support, or because capability data is unavailable and
		 * we give the model the benefit of the doubt).
		 *
		 * Returns `WP_Error( 'wp_mcp_ai_tools_unsupported_by_model' )` when the
		 * native API confirms the model does NOT list `tool_use` in its
		 * capabilities, preventing an avoidable 400 from LM Studio.
		 *
		 * @param string $model_id Model identifier.
		 * @return null|WP_Error
		 */
		protected function check_tool_capability( $model_id ) {
			$capabilities = $this->get_model_capabilities();

			if ( empty( $capabilities ) || ! isset( $capabilities[ $model_id ] ) ) {
				// No capability data available — give the model the benefit of the doubt.
				return null;
			}

			if ( in_array( 'tool_use', (array) $capabilities[ $model_id ], true ) ) {
				return null; // Model supports tools.
			}

			WP_MCP_AI_Logger::log_event(
				'lm_studio_capability_guard',
				'Skipping tools payload: model does not advertise tool_use capability.',
				array( 'model' => $model_id )
			);

			return new WP_Error(
				'wp_mcp_ai_tools_unsupported_by_model',
				sprintf(
					/* translators: %s: model identifier */
					__( 'LM Studio model "%s" does not support tool calling. Load a tool-capable model or disable the capability guard.', 'mcp-ai-wpoos' ),
					$model_id
				),
				array( 'status' => 400 )
			);
		}

		/**
		 * Generate embeddings for one or more text inputs using LM Studio's
		 * OpenAI-compatible `/v1/embeddings` endpoint.
		 *
		 * LM Studio requires an embedding-capable model to be loaded (e.g.
		 * `nomic-embed-text`).  The `model` option must match the loaded model
		 * identifier or LM Studio will return an error.
		 *
		 * @param string|array $input   A single string or an array of strings.
		 * @param array        $options Optional parameters: model, encoding_format, timeout.
		 * @return array|WP_Error OpenAI-compatible embeddings response or WP_Error.
		 */
		public function create_embedding( $input, array $options = array() ) {
			$endpoint_url = $this->get_endpoint_url();

			if ( empty( $endpoint_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_endpoint',
					__( 'No LM Studio endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $input ) || ( is_string( $input ) && '' === trim( $input ) ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_input',
					__( 'Input text must be provided for embeddings.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Resolve model: options override, then lm_studio_model, then empty string
			// (LM Studio will use the currently loaded embedding model).
			$model = $this->resolve_model( $options );

			$payload = array(
				'input' => $input,
			);

			if ( '' !== $model ) {
				$payload['model'] = $model;
			}

			if ( isset( $options['encoding_format'] ) && '' !== $options['encoding_format'] ) {
				$payload['encoding_format'] = sanitize_text_field( $options['encoding_format'] );
			}

			// Embeddings always use the OpenAI-compatible /v1 surface.
			$url = untrailingslashit( $endpoint_url ) . '/v1/embeddings';

			$request_args = array(
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$this->build_auth_headers()
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => max( 60, $this->resolve_timeout( $options ) ),
			);

			WP_MCP_AI_Logger::log_event(
				'lm_studio_embeddings',
				'Requesting embeddings from LM Studio.',
				array(
					'model'      => $model,
					'input_type' => is_array( $input ) ? 'array' : 'string',
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'LM Studio embeddings request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The LM Studio embeddings request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'LM Studio', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio embeddings response.', array( 'body' => $body ) );
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio embeddings API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio embeddings.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'LM Studio embeddings returned an error.',
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

			$decoded['provider'] = 'lm_studio';

			WP_MCP_AI_Logger::log_event( 'lm_studio_embeddings', 'LM Studio embeddings request completed.' );

			return $decoded;
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
					__( 'No LM Studio endpoint URL has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_endpoint' => __( 'Add an LM Studio endpoint URL in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_lm_studio_model',
					__( 'No LM Studio model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_lm_studio_model' => __( 'Choose an LM Studio model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $prompt ) || ! is_string( $prompt ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_prompt',
					__( 'No prompt was provided for the completion request.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'review_request_payload' => __( 'Provide a text prompt before calling the API.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$payload = array(
				'model'  => $model,
				'prompt' => wp_kses_post( (string) $prompt ),
				'stream' => false, // Completions endpoint: streaming not implemented; keep off.
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
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$this->build_auth_headers()
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
					__( 'The LM Studio completion API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'LM Studio', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio completion response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio completion API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'mcp-ai-wpoos' );

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
		 * Build additional system messages from memory documents.
		 *
		 * Memory documents provide additional context from assistant knowledge base
		 * (uploaded files, vector stores, etc.) that should be available to the model.
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

				// Sanitize the parameters schema to ensure OpenAI-compatible format.
				// LM Studio uses OpenAI-compatible function calling, so the same schema
				// constraints apply (e.g. root-level composition keywords are not allowed).
				if ( isset( $tool['function'] ) && is_array( $tool['function'] ) && isset( $tool['function']['parameters'] ) && is_array( $tool['function']['parameters'] ) ) {
					$tool['function']['parameters'] = $this->sanitize_parameters_for_openai( $tool['function']['parameters'] );
				} elseif ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
					$tool['parameters'] = $this->sanitize_parameters_for_openai( $tool['parameters'] );
				}

				$normalised[] = $tool;
			}

			return array_values( $normalised );
		}

		/**
		 * Sanitize a function parameter schema to meet LM Studio / OpenAI requirements.
		 *
		 * LM Studio uses the OpenAI-compatible function-calling API, so the same schema
		 * constraints apply: composition keywords (oneOf, anyOf, allOf, not) are not
		 * permitted at the root level of the parameters object, and the root type must
		 * be 'object'.
		 *
		 * Mirrors the sanitize_parameters_for_openai implementation in WP_MCP_AI_OpenAI_Client
		 * to ensure full parity when passing tool definitions to LM Studio.
		 *
		 * @param array  $schema     JSON Schema array to sanitize.
		 * @param string $parent_key Parent key (empty string signals root-level checks).
		 * @return array Sanitized schema.
		 */
		protected function sanitize_parameters_for_openai( array $schema, $parent_key = '' ) {
			$sanitized = array();

			// At the root level, remove composition keywords not allowed by OpenAI/LM Studio.
			if ( '' === $parent_key ) {
				$root_unsupported = array( 'oneOf', 'anyOf', 'allOf', 'not' );
				foreach ( $root_unsupported as $keyword ) {
					if ( isset( $schema[ $keyword ] ) ) {
						WP_MCP_AI_Logger::log_event(
							'lm_studio_schema_sanitization',
							"Removed unsupported top-level keyword: {$keyword}",
							array(
								'keyword' => $keyword,
								'context' => 'root_level',
							)
						);
						unset( $schema[ $keyword ] );
					}
				}

				// Ensure root type is 'object'.
				if ( ! isset( $schema['type'] ) ) {
					$schema['type'] = 'object';
				}
			}

			// Recursively process nested structures.
			foreach ( $schema as $key => $value ) {
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_parameters_for_openai( $value, $key );
				} else {
					$sanitized[ $key ] = $value;
				}
			}

			return $sanitized;
		}
	}
}
