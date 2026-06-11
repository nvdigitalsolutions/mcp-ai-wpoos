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
		 * Build the standard HTTP request headers for the NVIDIA NIM API.
		 *
		 * @param string $api_key      NVIDIA NIM API key.
		 * @param string $content_type Optional content type (default: application/json).
		 * @return array Associative array of HTTP headers.
		 */
		public function build_request_headers( $api_key, $content_type = 'application/json' ) {
			$headers = array(
				'Content-Type'  => $content_type,
				'Authorization' => 'Bearer ' . $api_key,
			);

			/**
			 * Filter the NVIDIA NIM request headers before sending.
			 *
			 * Allows third-party plugins to inject or modify headers for all
			 * NVIDIA NIM API requests.
			 *
			 * @since 2.7.0
			 *
			 * @param array  $headers  Associative array of HTTP headers.
			 * @param string $api_key  The API key being used.
			 */
			return apply_filters( 'wp_mcp_ai_nvidia_request_headers', $headers, $api_key );
		}

		/**
		 * Extract a human-readable error message from an NVIDIA NIM error response.
		 *
		 * NVIDIA NIM may return errors in multiple formats:
		 * - OpenAI-compatible: {"error": {"message": "...", "type": "...", "code": "..."}}
		 * - NVIDIA native:     {"status": 404, "title": "Not Found", "detail": "..."}
		 * - String error:      {"error": "some error string"}
		 *
		 * @param array  $decoded  Decoded JSON response body.
		 * @param string $fallback Fallback message when no message can be extracted.
		 * @return string
		 */
		protected function extract_error_message( array $decoded, $fallback = '' ) {
			// OpenAI-compatible format with nested error.message field.
			if ( isset( $decoded['error']['message'] ) && '' !== $decoded['error']['message'] ) {
				return (string) $decoded['error']['message'];
			}

			// NVIDIA native format with a detail field.
			if ( isset( $decoded['detail'] ) && '' !== $decoded['detail'] ) {
				$detail = (string) $decoded['detail'];

				// Prepend the title if available for additional context.
				if ( isset( $decoded['title'] ) && '' !== $decoded['title'] ) {
					$detail = (string) $decoded['title'] . ': ' . $detail;
				}

				return $detail;
			}

			// Flat error string (error key is a plain string).
			if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && '' !== $decoded['error'] ) {
				return $decoded['error'];
			}

			// NVIDIA native format with only title.
			if ( isset( $decoded['title'] ) && '' !== $decoded['title'] ) {
				return (string) $decoded['title'];
			}

			if ( '' !== $fallback ) {
				return $fallback;
			}

			return __( 'Unexpected response from NVIDIA NIM.', 'mcp-ai-wpoos' );
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
				$body    = wp_remote_retrieve_body( $response );
				$decoded = json_decode( $body, true );

				$error_detail = is_array( $decoded )
					? $this->extract_error_message( $decoded )
					: trim( $body );

				WP_MCP_AI_Logger::log_error(
					'NVIDIA NIM returned an error response.',
					array(
						'code' => $code,
						'url'  => $url,
						'body' => is_array( $decoded ) ? $decoded : $body,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_nvidia_api_error',
					/* translators: %1$d: HTTP status code, %2$s: error detail. */
					sprintf( __( 'NVIDIA NIM returned HTTP %1$d: %2$s', 'mcp-ai-wpoos' ), $code, $error_detail ),
					array( 'status' => $code )
				);
			}

			// Parse the response body to extract the model count.
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			$api_model_count = 0;
			if ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				$api_model_count = count( $decoded['data'] );
			}

			// Also count configured models from the model config registry.
			$configured_count = 0;
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$nvidia_models    = WP_MCP_AI_Model_Config::get_models_by_provider( 'nvidia' );
				$configured_count = count( $nvidia_models );
			}

			WP_MCP_AI_Logger::log_event(
				'nvidia_test_connection',
				'NVIDIA NIM connection successful.',
				array(
					'api_models'        => $api_model_count,
					'configured_models' => $configured_count,
				)
			);

			return array(
				'success'          => true,
				'message'          => __( 'Successfully connected to NVIDIA NIM API.', 'mcp-ai-wpoos' ),
				'model_count'      => $api_model_count,
				'configured_count' => $configured_count,
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
				WP_MCP_AI_Logger::log_error(
					'Failed to decode NVIDIA NIM response.',
					array(
						'url'  => $url,
						'code' => $code,
						'body' => $body,
					)
				);

				// Non-JSON responses (e.g. "404 page not found") indicate the endpoint URL is likely incorrect.
				if ( $code >= 400 ) {
					return new WP_Error(
						'wp_mcp_ai_nvidia_invalid_response',
						/* translators: %1$d: HTTP status code, %2$s: endpoint URL. */
						sprintf( __( 'NVIDIA NIM endpoint returned HTTP %1$d with a non-JSON response. Verify the endpoint URL is correct: %2$s', 'mcp-ai-wpoos' ), $code, $url ),
						array(
							'status' => $code,
							'body'   => $body,
						)
					);
				}

				return new WP_Error( 'wp_mcp_ai_nvidia_invalid_response', __( 'The NVIDIA NIM API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = $this->extract_error_message( $decoded );

				WP_MCP_AI_Logger::log_error(
					'NVIDIA NIM returned an error response.',
					array(
						'code' => $code,
						'url'  => $url,
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

			// Pre-flight context-window validation (shared with all providers).
			if ( class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
				$preflight = WP_MCP_AI_Token_Budget_Manager::validate_context_window( $payload, $model, 'nvidia', $options, $messages );
				if ( is_wp_error( $preflight ) ) {
					return $preflight;
				}
			}

			$url     = untrailingslashit( $endpoint_url ) . '/chat/completions';
			$timeout = max( 60, $this->resolve_timeout( $options ) );

			$request_args = array(
				'headers' => $this->build_request_headers( $api_key ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'nvidia_request',
				'Sending request to NVIDIA NIM.',
				array(
					'url'   => $url,
					'model' => $model,
				)
			);

			// Real-time SSE: bypass wp_remote_post when streaming with a callback.
			$is_streaming = ! empty( $payload['stream'] );
			if ( $is_streaming && function_exists( 'curl_init' ) ) {
				$realtime_cb = isset( $options['stream_callback'] ) && is_callable( $options['stream_callback'] ) ? $options['stream_callback'] : null;
				if ( null !== $realtime_cb ) {
					return $this->do_realtime_curl_stream( $url, $payload, $model, $timeout, $realtime_cb );
				}
			}

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'NVIDIA NIM request failed.',
					array(
						'url'   => $url,
						'model' => $model,
						'error' => $response->get_error_message(),
					)
				);

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
				WP_MCP_AI_Logger::log_error(
					'Failed to decode NVIDIA NIM response.',
					array(
						'url'   => $url,
						'model' => $model,
						'code'  => $code,
						'body'  => $body,
					)
				);

				// Non-JSON responses (e.g. "404 page not found") indicate the endpoint URL is likely incorrect.
				if ( $code >= 400 ) {
					return new WP_Error(
						'wp_mcp_ai_nvidia_invalid_response',
						/* translators: %1$d: HTTP status code, %2$s: model identifier. */
						sprintf( __( 'NVIDIA NIM returned HTTP %1$d with a non-JSON response for model "%2$s". The endpoint URL or model may be incorrect.', 'mcp-ai-wpoos' ), $code, $model ),
						array(
							'status' => $code,
							'body'   => $body,
						)
					);
				}

				return new WP_Error( 'wp_mcp_ai_nvidia_invalid_response', __( 'The NVIDIA NIM API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, $decoded, $response, $url, $model );
			}

			// NVIDIA NIM returns OpenAI-compatible format, so we can use it directly.
			$normalized = $this->normalize_response( $decoded, $model );

			WP_MCP_AI_Logger::log_event( 'nvidia_response', 'NVIDIA NIM request completed.' );

			return $normalized;
		}

		/**
		 * Perform a real-time SSE stream request to NVIDIA using direct cURL.
		 *
		 * Uses CURLOPT_WRITEFUNCTION to process each network chunk as it arrives.
		 *
		 * @param string   $url      Full endpoint URL.
		 * @param array    $payload  Request payload (stream: true already set).
		 * @param string   $model    Resolved model identifier.
		 * @param int      $timeout  Request timeout in seconds.
		 * @param callable $stream_callback Invoked with each content chunk array.
		 * @return array|WP_Error
		 */
		protected function do_realtime_curl_stream( $url, array $payload, $model, $timeout, $stream_callback ) {
			$api_key      = $this->get_api_key();
			$raw_headers  = $this->build_request_headers( $api_key );
			$curl_headers = array();
			foreach ( $raw_headers as $header_name => $header_value ) {
				$curl_headers[] = $header_name . ': ' . $header_value;
			}

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
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_HEADERFUNCTION => function ( $_ch, $header ) use ( &$http_status ) {
						if ( preg_match( '/^HTTP\/[\d.]+ (\d+)/', $header, $m ) ) {
							$http_status = (int) $m[1];
						}
						return strlen( $header );
					},
					CURLOPT_WRITEFUNCTION  => function ( $_ch, $data ) use ( &$sse_buffer, &$accumulated_content, &$accumulated_reason, &$tool_calls_by_idx, &$response_id, &$finish_reason, &$usage, &$found_done, $stream_callback ) {
						$sse_buffer .= $data;
						while ( false !== ( $pos = strpos(
							$sse_buffer,
							'
'
						) ) ) {
							$line = trim( substr( $sse_buffer, 0, $pos ) );
							$sse_buffer = substr( $sse_buffer, $pos + 1 );
							if ( '' === $line || ':' === $line[0] ) {
								continue;
							}
							if ( 'data: [DONE]' === $line ) {
								$found_done = true;
								continue; }
							if ( 0 !== strpos( $line, 'data: ' ) ) {
								continue;
							}
							$chunk = json_decode( substr( $line, 6 ), true );
							if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
								continue;
							}
							if ( '' === $response_id && isset( $chunk['id'] ) ) {
								$response_id = (string) $chunk['id'];
							}
							$choice = isset( $chunk['choices'][0] ) ? $chunk['choices'][0] : array();
							$delta = isset( $choice['delta'] ) ? $choice['delta'] : array();
							if ( ! empty( $delta['content'] ) ) {
								$accumulated_content .= $delta['content'];
								call_user_func( $stream_callback, array( 'choices' => array( array( 'delta' => array( 'content' => $delta['content'] ) ) ) ) );
							}

							// Handle reasoning/thinking content from reasoning models (e.g. DeepSeek-R1, Qwen3).
							if ( ! empty( $delta['reasoning_content'] ) ) {
								$accumulated_reason .= $delta['reasoning_content'];
								call_user_func( $stream_callback, array( 'choices' => array( array( 'delta' => array( 'reasoning_content' => $delta['reasoning_content'] ) ) ) ) );
							}
							if ( ! empty( $delta['tool_calls'] ) ) {
								foreach ( $delta['tool_calls'] as $tc ) {
									if ( ! isset( $tc['index'] ) ) {
										continue;
									}
									$idx = (int) $tc['index'];
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
									if ( isset( $tc['id'] ) ) {
										$tool_calls_by_idx[ $idx ]['id'] .= $tc['id'];
									}
									if ( isset( $tc['function']['name'] ) ) {
										$tool_calls_by_idx[ $idx ]['function']['name'] .= $tc['function']['name'];
									}
									if ( isset( $tc['function']['arguments'] ) ) {
										$tool_calls_by_idx[ $idx ]['function']['arguments'] .= $tc['function']['arguments'];
									}
								}
							}
							if ( isset( $choice['finish_reason'] ) && null !== $choice['finish_reason'] ) {
								$finish_reason = $choice['finish_reason'];
							}
							if ( ! empty( $chunk['usage'] ) ) {
								$usage = $chunk['usage'];
							}
						}
						return strlen( $data );
					},
				)
			);
			curl_exec( $ch );
			$curl_errno = curl_errno( $ch );
			$curl_error = curl_error( $ch );
			curl_close( $ch );
			// phpcs:enable

			if ( $curl_errno ) {
				return new WP_Error( 'wp_mcp_ai_http_error', $curl_error ? $curl_error : __( 'cURL streaming request failed.', 'mcp-ai-wpoos' ) );
			}
			if ( $http_status >= 400 ) {
				return new WP_Error( 'wp_mcp_ai_api_error', __( 'NVIDIA returned an error during streaming.', 'mcp-ai-wpoos' ), array( 'status' => $http_status ) );
			}

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

			if ( ! empty( $model ) ) {
				$assembled['model'] = $model;
			}

			return $this->normalize_response( $assembled, $model );
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

			// Check if the model supports function calling before including tools.
			// Many NVIDIA NIM models (Gemma, Phi-3, Granite, etc.) reject the tools parameter.
			$model_config = WP_MCP_AI_Model_Config::get_model_config( $model );
			if ( ! empty( $options['tools'] ) && $model_config && isset( $model_config['supports_function_calling'] ) && false === $model_config['supports_function_calling'] ) {
				WP_MCP_AI_Logger::log_event(
					'nvidia_tools_stripped',
					'Tools removed from payload because model does not support function calling.',
					array(
						'model'      => $model,
						'tool_count' => is_countable( $options['tools'] ) ? count( $options['tools'] ) : 0,
					)
				);
				unset( $options['tools'], $options['tool_choice'] );
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
				'stream'   => isset( $options['stream'] ) ? (bool) $options['stream'] : false, // Explicitly disable streaming to prevent chunked responses.
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
			// Reuse $model_config fetched earlier for tool support check.
			if ( ! $model_config ) {
				$model_config = WP_MCP_AI_Model_Config::get_model_config( $model );
			}

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
		 * Build a structured WP_Error from an HTTP error response.
		 *
		 * Leverages the existing {@see extract_error_message()} to parse
		 * NVIDIA's multi-format error responses (OpenAI-compatible, NVIDIA
		 * native, flat string) and adds action-level guidance for auth and
		 * rate-limit errors.
		 *
		 * @param int    $code     HTTP status code.
		 * @param array  $decoded  Decoded JSON response body.
		 * @param array  $response Raw WP HTTP response array.
		 * @param string $url      The endpoint URL that was called (for logging context).
		 * @param string $model    The model identifier (for logging context).
		 * @return WP_Error
		 */
		protected function handle_api_error( $code, array $decoded, $response, $url = '', $model = '' ) {
			$error_message = $this->extract_error_message( $decoded, __( 'Unexpected response from NVIDIA NIM.', 'mcp-ai-wpoos' ) );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_nvidia_api_error';

			if ( 401 === $code ) {
				$error_code            = 'wp_mcp_ai_nvidia_auth_error';
				$error_data['actions'] = array(
					'auth_info' => __( 'Verify your NVIDIA NIM API key in NV oOS → Providers → NVIDIA.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The NVIDIA NIM API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 404 === $code ) {
				$error_code            = 'wp_mcp_ai_nvidia_not_found';
				$error_data['actions'] = array(
					'endpoint_info' => __( 'The NVIDIA NIM endpoint or model was not found. Verify the endpoint URL and model identifier in the NV oOS settings.', 'mcp-ai-wpoos' ),
				);
			}

			WP_MCP_AI_Logger::log_error(
				'NVIDIA NIM returned an error response.',
				array(
					'code'  => $code,
					'url'   => $url,
					'model' => $model,
					'body'  => $decoded,
				)
			);

			return new WP_Error( $error_code, $error_message, $error_data );
		}

		/**
		 * Drop tool role messages that are not associated with the most recent
		 * assistant tool call.
		 *
		 * The NVIDIA NIM API requires tool responses to immediately follow the
		 * assistant message that emitted the corresponding tool call. When
		 * intervening messages appear between those entries the request may be
		 * rejected. This normaliser filters out any tool messages that no longer
		 * have a matching pending call so the payload remains valid.
		 *
		 * Copied from the OpenAI / DeepSeek client pattern.
		 *
		 * @param array $messages Messages to sanitize.
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
						WP_MCP_AI_Logger::log_event(
							'nvidia_dropped_incomplete_tool_group',
							'Dropped assistant message with unresolved tool_calls before user/system message.',
							array(
								'pending_call_ids' => array_keys( $pending_calls ),
							)
						);
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
						WP_MCP_AI_Logger::log_event(
							'nvidia_dropped_incomplete_tool_group',
							'Dropped assistant message with unresolved tool_calls before next assistant message.',
							array(
								'pending_call_ids' => array_keys( $pending_calls ),
							)
						);
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
						WP_MCP_AI_Logger::log_event(
							'nvidia_dropped_orphan_tool_message',
							'Dropping tool message without matching tool call before NVIDIA NIM request.',
							array(
								'tool_call_id' => $tool_call_id,
								'reason'       => '' === $tool_call_id
									? 'missing_tool_call_id'
									: ( $awaiting_tool_responses ? 'tool_call_not_found' : 'no_pending_tool_calls' ),
							)
						);

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
