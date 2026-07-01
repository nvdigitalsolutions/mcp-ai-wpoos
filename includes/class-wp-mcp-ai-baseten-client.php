<?php
/**
 * Baseten API client wrapper.
 *
 * Baseten Model APIs expose an OpenAI-compatible REST API at
 * https://inference.baseten.co/v1. This client handles chat completions,
 * model listing, and connection testing without vendoring any third-party SDK.
 *
 * @link    https://docs.baseten.co/reference/inference-api/overview
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Baseten_Client' ) ) {
	/**
	 * Provides a wrapper around the Baseten Model APIs (OpenAI-compatible).
	 *
	 * Supports chat completions, tool/function calling, streaming (SSE
	 * identical to OpenAI), JSON mode, and live model listing.
	 *
	 * Baseten Model APIs offer managed access to DeepSeek, GLM, Kimi, and
	 * other open-source LLMs. All supported models implement tool calling,
	 * and most support structured outputs.
	 */
	class WP_MCP_AI_Baseten_Client {

		/**
		 * Default base URL for the Baseten inference API (no trailing slash).
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://inference.baseten.co/v1';

		/**
		 * Chat completions path relative to the base URL.
		 *
		 * @var string
		 */
		const API_ENDPOINT = '/chat/completions';

		/**
		 * Model listing path relative to the base URL.
		 *
		 * @var string
		 */
		const API_MODELS = '/models';

		/**
		 * User-Agent string sent with every request.
		 *
		 * @var string
		 */
		const USER_AGENT = 'WP-MCP-AI-Baseten-Client/1.0';

		/**
		 * Default chat model when none is configured.
		 *
		 * @var string
		 */
		const DEFAULT_MODEL = 'deepseek-ai/DeepSeek-V3';

		/**
		 * Models that do not support tool/function calling.
		 *
		 * Some GLM and other models served via Baseten may not support tools.
		 * Tools are stripped automatically when these models are selected.
		 *
		 * @var array
		 */
		const MODELS_WITHOUT_TOOL_CALLING = array();

		/**
		 * Maximum context window sizes by model family prefix.
		 *
		 * @var array
		 */
		const MODEL_CONTEXT_WINDOWS = array(
			'zai-org/GLM-4'           => 128000,
			'deepseek-ai/DeepSeek-V3' => 128000,
			'deepseek-ai/DeepSeek-R1' => 128000,
			'moonshotai/Kimi-K2'      => 256000,
		);

		// -------------------------------------------------------------------------
		// Accessors.
		// -------------------------------------------------------------------------

		/**
		 * Retrieve the configured Baseten API key.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_api_key() {
			// If a transient API key was set via set_api_key(), use it instead
			// of the persisted setting. This prevents TOCTOU race conditions
			// when testing a key before saving it.
			if ( isset( $this->api_key_override ) && is_string( $this->api_key_override ) ) {
				return $this->api_key_override;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$key      = isset( $settings['enable_baseten'] ) && ! empty( $settings['baseten_api_key'] )
				? $settings['baseten_api_key']
				: '';

			if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
				$key = WP_MCP_AI_Credential_Resolver::get_api_key( 'baseten' ) ?? '';
			}

			return $key;
		}

		/**
		 * Override the API key for the lifetime of this instance only.
		 *
		 * Use this when testing a key before persisting it, instead of
		 * temporarily writing it to wp_options (which creates a TOCTOU
		 * race condition).
		 *
		 * @since 2026.07
		 * @param string $api_key The API key to use for this instance.
		 */
		public function set_api_key( $api_key ) {
			$this->api_key_override = $api_key;
		}

		/**
		 * In-memory API key override. Set via set_api_key().
		 *
		 * @since 2026.07
		 * @var string|null
		 */
		private $api_key_override = null;

		/**
		 * Retrieve the configured default model.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['baseten_model'] ) ? $settings['baseten_model'] : '';
		}

		/**
		 * Retrieve the configured base URL.
		 *
		 * Supports custom proxies via the `baseten_base_url` setting.
		 * Falls back to {@see DEFAULT_BASE_URL}.
		 *
		 * @return string Base URL without trailing slash.
		 */
		public function get_base_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$base_url = isset( $settings['baseten_base_url'] ) ? trim( $settings['baseten_base_url'] ) : '';

			if ( '' === $base_url ) {
				$base_url = self::DEFAULT_BASE_URL;
			}

			return untrailingslashit( $base_url );
		}

		/**
		 * Return true when the model does not support tool/function calling.
		 *
		 * @since 2026.07
		 * @param string $model Model identifier.
		 * @return bool
		 */
		protected function model_lacks_tool_calling( $model ) {
			foreach ( self::MODELS_WITHOUT_TOOL_CALLING as $no_tools ) {
				if ( $model === $no_tools || 0 === strpos( $model, $no_tools ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get the context window size for a given model.
		 *
		 * @since 2026.07
		 * @param string $model Model identifier.
		 * @return int Context window size in tokens.
		 */
		public function get_context_window( $model ) {
			$model = sanitize_text_field( $model );

			// Exact match first.
			if ( isset( self::MODEL_CONTEXT_WINDOWS[ $model ] ) ) {
				return self::MODEL_CONTEXT_WINDOWS[ $model ];
			}

			// Prefix match for model families.
			foreach ( self::MODEL_CONTEXT_WINDOWS as $prefix => $window ) {
				if ( 0 === strpos( $model, $prefix ) ) {
					return $window;
				}
			}

			// Default to 128K for unknown Baseten models.
			return 128000;
		}

		// -------------------------------------------------------------------------
		// HTTP helpers.
		// -------------------------------------------------------------------------

		/**
		 * Build standard HTTP request headers for Baseten API calls.
		 *
		 * @param string $api_key API key to authorise the request.
		 * @return array Associative array of HTTP headers.
		 */
		protected function build_request_headers( $api_key ) {
			$headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
				'User-Agent'    => self::USER_AGENT,
			);

			/**
			 * Filter the Baseten request headers before sending.
			 *
			 * @since 2026.05
			 *
			 * @param array  $headers Associative array of HTTP headers.
			 * @param string $api_key The API key being used.
			 */
			return apply_filters( 'wp_mcp_ai_baseten_request_headers', $headers, $api_key );
		}

		/**
		 * Resolve the request timeout in seconds.
		 *
		 * Reads the global 'request_timeout' admin setting (Settings → NV oOS → General →
		 * Behavior), falling back to the Resource Manager's workload-tier recommendation
		 * when no explicit timeout has been saved.  A per-request `timeout` option still
		 * takes precedence when provided by the caller.
		 *
		 * @param array $options Request options may carry a 'timeout' key.
		 * @return int
		 */
		protected function resolve_timeout( array $options ) {
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();

			if ( ! empty( $options['timeout'] ) && is_numeric( $options['timeout'] ) ) {
				$timeout = max( 10, absint( $options['timeout'] ) );
			}

			return max( 10, $timeout );
		}

		/**
		 * Resolve the model from $options, falling back to the configured default.
		 *
		 * @param array $options Request options.
		 * @return string
		 */
		protected function resolve_model( array $options ) {
			if ( ! empty( $options['model'] ) ) {
				return sanitize_text_field( $options['model'] );
			}

			$model = $this->get_model();

			return ! empty( $model ) ? $model : self::DEFAULT_MODEL;
		}

		// -------------------------------------------------------------------------
		// Core methods.
		// -------------------------------------------------------------------------

		/**
		 * Perform a chat completion request against the Baseten API.
		 *
		 * The Baseten Model APIs endpoint is OpenAI-compatible: messages,
		 * tools, response_format, and streaming options are passed through
		 * unchanged.
		 *
		 * @param array $messages Message payload (OpenAI-compatible format).
		 * @param array $options  Additional options:
		 *                        - model (string): Override the model.
		 *                        - temperature (float): Sampling temperature.
		 *                        - top_p (float): Nucleus sampling.
		 *                        - max_tokens (int): Maximum output tokens.
		 *                        - tools (array): OpenAI-compatible tool definitions.
		 *                        - tool_choice (string|array): Tool selection.
		 *                        - response_format (array): JSON-mode hint.
		 *                        - stream (bool): Enable SSE streaming.
		 *                        - timeout (int): HTTP timeout in seconds.
		 *                        - system_prompt (string): System instruction.
		 * @return array|WP_Error Normalised completion response or WP_Error on failure.
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_baseten_api_key',
					__( 'No Baseten API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_baseten_api_key' => __( 'Add a Baseten API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_baseten_model',
					__( 'No Baseten model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_baseten_model' => __( 'Choose a Baseten model in the NV oOS settings.', 'mcp-ai-wpoos' ),
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
				$preflight = WP_MCP_AI_Token_Budget_Manager::validate_context_window( $payload, $model, 'baseten', $options, $messages );
				if ( is_wp_error( $preflight ) ) {
					return $preflight;
				}
			}

			$url     = $this->get_base_url() . self::API_ENDPOINT;
			$timeout = $this->resolve_timeout( $options );

			$request_args = array(
				'headers' => $this->build_request_headers( $api_key ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => max( 60, $timeout ),
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'baseten_request',
					'Sending request to Baseten.',
					array(
						'model'         => $model,
						'message_count' => count( $messages ),
						'has_tools'     => ! empty( $payload['tools'] ),
					)
				);
			}

			// Real-time SSE: bypass wp_remote_post when streaming with a callback.
			$is_streaming = ! empty( $payload['stream'] );
			if ( $is_streaming && function_exists( 'curl_init' ) ) {
				$realtime_cb = isset( $options['stream_callback'] ) && is_callable( $options['stream_callback'] ) ? $options['stream_callback'] : null;
				if ( null !== $realtime_cb ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'baseten_request',
							'Sending real-time streaming request to Baseten via cURL.',
							array(
								'model'    => $model,
								'realtime' => true,
							)
						);
					}
					return $this->do_realtime_curl_stream( $url, $payload, $model, $timeout, $realtime_cb );
				}
			}

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'Baseten request failed.', array( 'error' => $response->get_error_message() ) );
				}

				if ( class_exists( 'WP_MCP_AI_HTTP' ) ) {
					return WP_MCP_AI_HTTP::prepare_transport_error(
						$response,
						'wp_mcp_ai_http_error',
						__( 'The Baseten API request failed to complete.', 'mcp-ai-wpoos' ),
						__( 'Baseten', 'mcp-ai-wpoos' )
					);
				}

				return $response;
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'Failed to decode Baseten response.', array( 'body' => $body ) );
				}

				return new WP_Error( 'wp_mcp_ai_baseten_invalid_response', __( 'The Baseten API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, $decoded, $response );
			}

			$normalized = $this->normalize_response( $decoded );

			if ( ! isset( $normalized['model'] ) && ! empty( $model ) ) {
				$normalized['model'] = $model;
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'baseten_response', 'Baseten request completed.', array( 'model' => $model ) );
			}

			return $normalized;
		}

		/**
		 * Perform a real-time SSE stream request to Baseten using direct cURL.
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
							if ( isset( $delta['content'] ) && is_string( $delta['content'] ) && '' !== $delta['content'] ) {
								$accumulated_content .= $delta['content'];
								call_user_func( $stream_callback, array( 'choices' => array( array( 'delta' => array( 'content' => $delta['content'] ) ) ) ) );
							}
							if ( isset( $delta['reasoning_content'] ) && is_string( $delta['reasoning_content'] ) && '' !== $delta['reasoning_content'] ) {
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
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Baseten real-time streaming failed.',
						array(
							'error' => $curl_error,
							'errno' => $curl_errno,
						)
					);
				}
				return new WP_Error( 'wp_mcp_ai_http_error', $curl_error ? $curl_error : __( 'cURL streaming request failed.', 'mcp-ai-wpoos' ) );
			}
			if ( $http_status >= 400 ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Baseten real-time streaming returned HTTP error.',
						array( 'code' => $http_status )
					);
				}
				return new WP_Error( 'wp_mcp_ai_api_error', __( 'Baseten returned an error during streaming.', 'mcp-ai-wpoos' ), array( 'status' => $http_status ) );
			}

			if ( ! $found_done ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'baseten_realtime_stream',
						'Real-time SSE stream ended without [DONE] sentinel (model may have been interrupted).',
						array( 'model' => $model )
					);
				}
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

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'baseten_realtime_stream', 'Real-time streaming response assembled.', array( 'model' => $model ) );
			}

			return $this->normalize_response( $assembled );
		}


		/**
		 * List available models from the Baseten API.
		 *
		 * The /models endpoint returns an OpenAI-shaped JSON object with a
		 * `data` array of model objects each containing an `id` field.
		 *
		 * @return array|WP_Error Array of model objects or WP_Error on failure.
		 */
		public function list_models() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_baseten_api_key',
					__( 'No Baseten API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$url = $this->get_base_url() . self::API_MODELS;

			$request_args = array(
				'timeout' => 30,
				'headers' => $this->build_request_headers( $api_key ),
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'baseten_list_models', 'Fetching models from Baseten.', array( 'url' => $url ) );
			}

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'Baseten model listing failed.', array( 'error' => $response->get_error_message() ) );
				}

				return $response;
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_baseten_invalid_response', __( 'The Baseten API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected error from Baseten models endpoint.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_baseten_api_error',
					$error_message,
					array( 'status' => $code )
				);
			}

			$models = array();

			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				foreach ( $decoded['data'] as $model ) {
					if ( isset( $model['id'] ) ) {
						$models[] = array(
							'id'       => sanitize_text_field( $model['id'] ),
							'owned_by' => isset( $model['owned_by'] ) ? sanitize_text_field( $model['owned_by'] ) : '',
							'created'  => isset( $model['created'] ) ? absint( $model['created'] ) : 0,
						);
					}
				}
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'baseten_list_models', 'Baseten models retrieved.', array( 'count' => count( $models ) ) );
			}

			return $models;
		}

		/**
		 * Test the connection to the Baseten API.
		 *
		 * Sends a 1-token chat completion to verify API key and network access.
		 *
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_baseten_api_key',
					__( 'No Baseten API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$test_model = $this->get_model() ? $this->get_model() : self::DEFAULT_MODEL;

			$result = $this->create_chat_completion(
				array(
					array(
						'role'    => 'user',
						'content' => 'Hi',
					),
				),
				array(
					'model'      => $test_model,
					'max_tokens' => 5,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success' => true,
				'message' => __( 'Successfully connected to Baseten.', 'mcp-ai-wpoos' ),
				'model'   => $test_model,
			);
		}

		/**
		 * Count tokens using a heuristic estimator.
		 *
		 * Baseten does not expose a public token-count endpoint. The
		 * WP_MCP_AI_Tool_Token_Limits heuristic (chars / 4) is used instead —
		 * the same pattern used by other non-OpenAI providers.
		 *
		 * @param array $messages Chat messages in OpenAI-compatible format.
		 * @param array $options  Optional parameters (model, system_prompt, timeout).
		 * @return int Estimated input token count.
		 */
		public function count_tokens( array $messages, array $options = array() ) {
			$char_count = 0;

			foreach ( $messages as $message ) {
				if ( is_array( $message ) && isset( $message['content'] ) ) {
					$char_count += strlen( (string) $message['content'] );
				}
			}

			if ( ! empty( $options['system_prompt'] ) ) {
				$char_count += strlen( (string) $options['system_prompt'] );
			}

			// Heuristic: ~4 chars per token (same approximation used across the codebase).
			return max( 1, (int) ceil( $char_count / 4 ) );
		}

		// -------------------------------------------------------------------------
		// Private helpers.
		// -------------------------------------------------------------------------

		/**
		 * Build the JSON payload sent to Baseten.
		 *
		 * @param array  $messages Chat messages.
		 * @param array  $options  Request options.
		 * @param string $model    Resolved model identifier.
		 * @return array|WP_Error
		 */
		protected function build_payload( array $messages, array $options, $model ) {
			if ( empty( $messages ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_messages',
					__( 'No chat messages were provided for the Baseten request.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$payload = array(
				'model'    => $model,
				'messages' => array(),
			);

			// Inject system prompt when provided as a top-level option.
			if ( ! empty( $options['system_prompt'] ) ) {
				$payload['messages'][] = array(
					'role'    => 'system',
					'content' => wp_kses_post( (string) $options['system_prompt'] ),
				);
			}

			// Filter orphaned tool messages before sending to Baseten.
			// Baseten's API is OpenAI-compatible and will reject or silently
			// mishandle messages where tool messages lack a matching assistant
			// tool_call. This mirrors the same filtering performed by the
			// OpenAI, DeepSeek, and Anthropic clients.
			$messages = $this->filter_tool_messages_for_payload( $messages );

			// Normalise content arrays into strings for compatibility.
			// The REST layer represents text-only messages as arrays of
			// segments; collapse them back to strings that Baseten expects.
			$messages = $this->normalise_messages_for_payload( $messages );

			// Pass through messages unchanged (OpenAI-compatible format).
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$payload['messages'][] = $message;
			}

			// Optional parameters.
			if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['top_p'] = (float) $options['top_p'];
			}

			// Max tokens — support both naming conventions.
			if ( isset( $options['max_completion_tokens'] ) && is_numeric( $options['max_completion_tokens'] ) ) {
				$payload['max_completion_tokens'] = absint( $options['max_completion_tokens'] );
			} elseif ( isset( $options['max_tokens'] ) && is_numeric( $options['max_tokens'] ) ) {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			}

			// JSON mode.
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			// Streaming flag.
			if ( ! empty( $options['stream'] ) ) {
				$payload['stream'] = true;

				// Include stream_options so the final chunk carries usage data.
				// Use caller-provided options when present; otherwise default
				// to include_usage=true for cost/usage badge display.
				$payload['stream_options'] = isset( $options['stream_options'] ) && is_array( $options['stream_options'] )
					? $options['stream_options']
					: array( 'include_usage' => true );
			}

			// Tool/function calling — only for models that support it.
			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				if ( $this->model_lacks_tool_calling( $model ) ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'baseten_tools_skipped',
							sprintf(
								/* translators: %s: model name */
								'Tools stripped for Baseten model %s (does not support function calling).',
								$model
							),
							array( 'model' => $model )
						);
					}
				} else {
					$payload['tools'] = $options['tools'];

					if ( isset( $options['tool_choice'] ) ) {
						$payload['tool_choice'] = $options['tool_choice'];
					}
				}
			}

			// Baseten prompt caching: route similar requests to the same server for disk KV cache hits.
			if ( ! empty( $options['prompt_cache_key'] ) ) {
				$payload['prompt_cache_key'] = sanitize_text_field( $options['prompt_cache_key'] );
			}

			/**
			 * Filter the Baseten request payload before it is sent.
			 *
			 * @since 2026.05
			 *
			 * @param array  $payload  Request payload.
			 * @param array  $messages Original messages.
			 * @param array  $options  Request options.
			 * @param string $model    Resolved model identifier.
			 */
			return apply_filters( 'wp_mcp_ai_baseten_request_payload', $payload, $messages, $options, $model );
		}

		/**
		 * Drop tool role messages that are not associated with the most recent
		 * assistant tool call.
		 *
		 * Baseten's API is OpenAI-compatible and requires tool responses to
		 * immediately follow the assistant message that emitted the corresponding
		 * tool call. This normaliser filters out any tool messages that no longer
		 * have a matching pending call so the payload remains valid.
		 *
		 * Logic mirrors WP_MCP_AI_DeepSeek_Client::filter_tool_messages_for_payload().
		 *
		 * @since 2026.07
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
					if ( $awaiting_tool_responses && null !== $incomplete_group_start ) {
						$filtered = array_slice( $filtered, 0, $incomplete_group_start );
						if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
							WP_MCP_AI_Logger::log_event(
								'baseten_dropped_incomplete_tool_group',
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
					if ( $awaiting_tool_responses && null !== $incomplete_group_start ) {
						$filtered = array_slice( $filtered, 0, $incomplete_group_start );
						if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
							WP_MCP_AI_Logger::log_event(
								'baseten_dropped_incomplete_tool_group',
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
								'baseten_dropped_orphan_tool_message',
								'Dropping tool message without matching tool call before Baseten request.',
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
		 * Prepare chat messages for the Baseten Chat Completions payload.
		 *
		 * The REST layer represents text-only messages as arrays of segments so
		 * attachments and tool calls can be normalised consistently. Some Baseten
		 * models may only accept plain strings for the `content` field.
		 * To remain compatible we collapse text-only segment arrays back into
		 * strings while preserving multimodal payloads that rely on structured
		 * segments.
		 *
		 * Logic mirrors WP_MCP_AI_DeepSeek_Client::normalise_messages_for_payload().
		 *
		 * @since 2026.07
		 *
		 * @param array $messages Sanitised chat messages.
		 * @return array
		 */
		protected function normalise_messages_for_payload( array $messages ) {
			$normalised = array();

			foreach ( $messages as $message ) {
				if ( ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
					$normalised[] = $message;
					continue;
				}

				$segments = array_values( $message['content'] );

				if ( empty( $segments ) ) {
					$message['content'] = '';
					$normalised[]       = $message;
					continue;
				}

				$all_text   = true;
				$text_parts = array();

				foreach ( $segments as $segment ) {
					if ( ! is_array( $segment ) ) {
						$all_text = false;
						break;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

					if ( 'text' !== $type ) {
						$all_text = false;
						break;
					}

					$text_parts[] = isset( $segment['text'] ) ? (string) $segment['text'] : '';
				}

				if ( $all_text ) {
					$text_parts         = array_filter(
						$text_parts,
						static function ( $part ) {
							return '' !== trim( $part );
						}
					);
					$message['content'] = implode( "\n\n", $text_parts );
				} else {
					$message['content'] = $segments;
				}

				$normalised[] = $message;
			}

			return $normalised;
		}

		/**
		 * Handle a non-2xx API response and return an appropriate WP_Error.
		 *
		 * @param int          $code     HTTP status code.
		 * @param array        $decoded  Decoded JSON response body.
		 * @param array|object $response Full WP HTTP response.
		 * @return WP_Error
		 */
		protected function handle_api_error( $code, array $decoded, $response ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Baseten.', 'mcp-ai-wpoos' );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_baseten_api_error';

			if ( 401 === $code || 403 === $code ) {
				$error_code            = 'wp_mcp_ai_baseten_auth_error';
				$error_data['actions'] = array(
					'auth_info' => __( 'Verify your Baseten API key in NV oOS → Providers → Baseten.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 402 === $code ) {
				$error_code            = 'wp_mcp_ai_baseten_insufficient_credits';
				$error_data['actions'] = array(
					'credits_info' => __( 'Your Baseten account has insufficient credits. Top up at https://app.baseten.co.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The Baseten API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Baseten returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);
			}

			return new WP_Error( $error_code, $error_message, $error_data );
		}

		/**
		 * Normalise a Baseten response to the plugin's internal format.
		 *
		 * The response format is OpenAI-compatible, so only light normalisation
		 * is required to satisfy the internal contract expected by the REST layer.
		 *
		 * @param array $decoded Decoded JSON response.
		 * @return array Normalised response.
		 */
		protected function normalize_response( array $decoded ) {
			// Extract the primary choice.
			$choice  = isset( $decoded['choices'][0] ) ? $decoded['choices'][0] : array();
			$message = isset( $choice['message'] ) ? $choice['message'] : array();
			$content = isset( $message['content'] ) ? $message['content'] : '';

			$raw_usage = isset( $decoded['usage'] ) ? $decoded['usage'] : array();
			// Extract prompt cache metrics when available.
			if ( isset( $raw_usage['prompt_cache_hit_tokens'] ) ) {
				$raw_usage['cached_tokens'] = (int) $raw_usage['prompt_cache_hit_tokens'];
			} elseif ( isset( $raw_usage['prompt_tokens_details']['cached_tokens'] ) ) {
				$raw_usage['cached_tokens'] = (int) $raw_usage['prompt_tokens_details']['cached_tokens'];
			}

			$normalized = array(
				'choices'       => array(
					array(
						'message'       => $message,
						'finish_reason' => isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : '',
					),
				),
				'content'       => $content,
				'finish_reason' => isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : '',
				'model'         => isset( $decoded['model'] ) ? $decoded['model'] : '',
				'provider'      => 'baseten',
				'usage'         => $raw_usage,
				'raw'           => $decoded,
			);

			// Pass through tool_calls when present (function calling).
			if ( ! empty( $message['tool_calls'] ) ) {
				$normalized['tool_calls'] = $message['tool_calls'];
			}

			// Pass through reasoning_content for reasoning models.
			if ( ! empty( $message['reasoning_content'] ) ) {
				$normalized['reasoning_content'] = $message['reasoning_content'];
			}

			return $normalized;
		}
	}
}
