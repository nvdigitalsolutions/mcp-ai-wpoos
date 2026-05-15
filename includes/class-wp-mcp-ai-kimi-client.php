<?php
/**
 * Kimi API client wrapper.
 *
 * Kimi exposes an OpenAI-compatible REST API at https://api.moonshot.cn/v1.
 * This client handles chat completions, model listing, and connection testing
 * without vendoring any third-party SDK.
 *
 * @link    https://platform.moonshot.cn/docs
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
	/**
	 * Provides a wrapper around the Kimi API (OpenAI-compatible).
	 *
	 * Supports chat completions, tool/function calling, streaming (SSE identical
	 * to OpenAI), JSON mode, and live model listing.
	 *
	 * Note on embeddings: Kimi does not currently expose a public embeddings
	 * endpoint. No WP_MCP_AI_Embedding_Provider_Kimi is registered.
	 *
	 * Note on vision: Kimi K2.5/K2.6 models support multimodal input including
	 * images and video via base64 encoding or file references.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Kimi_Client {

		/**
		 * Default base URL for the Kimi API (no trailing slash, no path).
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://api.moonshot.cn/v1';

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
		 * Token counting path relative to the base URL.
		 *
		 * @var string
		 */
		const API_TOKEN_COUNT = '/tokenizers/estimate-token-count';

		/**
		 * User-Agent string sent with every request.
		 *
		 * @var string
		 */
		const USER_AGENT = 'WP-MCP-AI-Kimi-Client/1.0';

		/**
		 * Default chat model when none is configured.
		 *
		 * Kimi-k2.6 is the latest multimodal model with 256K context window.
		 *
		 * @var string
		 */
		const DEFAULT_MODEL = 'kimi-k2.6';

		/**
		 * Models that support tool/function calling.
		 *
		 * @var array
		 */
		const MODELS_WITH_TOOL_CALLING = array(
			'kimi-k2.6',
			'kimi-k2.5',
			'kimi-k2',
		);

		/**
		 * Models that do not support tool calling.
		 *
		 * Kimi-k2-thinking is a chain-of-thought model that may reject tools.
		 *
		 * @var array
		 */
		const MODELS_WITHOUT_TOOL_CALLING = array(
			'kimi-k2-thinking',
		);

		/**
		 * Maximum context window sizes by model family.
		 *
		 * @var array
		 */
		const MODEL_CONTEXT_WINDOWS = array(
			'kimi-k2.6'        => 256000,
			'kimi-k2.5'        => 256000,
			'kimi-k2'          => 256000,
			'kimi-k2-thinking' => 256000,
			'moonshot-v1'      => 128000,
		);

		// -------------------------------------------------------------------------
		// Accessors.
		// -------------------------------------------------------------------------

		/**
		 * Retrieve the configured Kimi API key.
		 *
		 * @since 1.0.0
		 * @return string Empty string when not configured.
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['kimi_api_key'] ) ? $settings['kimi_api_key'] : '';
		}

		/**
		 * Retrieve the configured default model.
		 *
		 * @since 1.0.0
		 * @return string Empty string when not configured.
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['kimi_model'] ) ? $settings['kimi_model'] : '';
		}

		/**
		 * Retrieve the configured base URL.
		 *
		 * Supports custom proxies via the kimi_base_url setting.
		 * Falls back to {@see DEFAULT_BASE_URL}.
		 *
		 * @since 1.0.0
		 * @return string Base URL without trailing slash.
		 */
		public function get_base_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$base_url = isset( $settings['kimi_base_url'] ) ? trim( $settings['kimi_base_url'] ) : '';

			if ( '' === $base_url ) {
				$base_url = self::DEFAULT_BASE_URL;
			}

			return untrailingslashit( $base_url );
		}

		/**
		 * Get the context window size for a model.
		 *
		 * @since 1.0.0
		 * @param string $model Model identifier.
		 * @return int Context window size in tokens.
		 */
		public function get_context_window( $model ) {
			$model = sanitize_text_field( $model );

			// Check exact match first.
			if ( isset( self::MODEL_CONTEXT_WINDOWS[ $model ] ) ) {
				return self::MODEL_CONTEXT_WINDOWS[ $model ];
			}

			// Check prefix match for model families.
			foreach ( self::MODEL_CONTEXT_WINDOWS as $model_key => $window ) {
				if ( 0 === strpos( $model, $model_key ) ) {
					return $window;
				}
			}

			// Default to 256K for unknown Kimi models.
			return 256000;
		}

		/**
		 * Check if a model supports tool calling.
		 *
		 * @since 1.0.0
		 * @param string $model Model identifier.
		 * @return bool True if tools are supported.
		 */
		public function model_supports_tools( $model ) {
			$model = sanitize_text_field( $model );

			// Check explicit non-support first.
			if ( in_array( $model, self::MODELS_WITHOUT_TOOL_CALLING, true ) ) {
				return false;
			}

			// Check explicit support.
			if ( in_array( $model, self::MODELS_WITH_TOOL_CALLING, true ) ) {
				return true;
			}

			// Default to true for unknown models (most Kimi models support tools).
			return true;
		}

		// -------------------------------------------------------------------------
		// HTTP helpers.
		// -------------------------------------------------------------------------

		/**
		 * Build standard HTTP request headers for Kimi API calls.
		 *
		 * @since 1.0.0
		 * @param string $api_key API key to authorize the request.
		 * @return array Associative array of HTTP headers.
		 */
		protected function build_request_headers( $api_key ) {
			$headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
				'User-Agent'    => self::USER_AGENT,
			);

			/**
			 * Filter the Kimi request headers before sending.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $headers Associative array of HTTP headers.
			 * @param string $api_key The API key being used.
			 */
			return apply_filters( 'wp_mcp_ai_kimi_request_headers', $headers, $api_key );
		}

		/**
		 * Resolve timeout for Kimi requests.
		 *
		 * @since 1.0.0
		 * @return int Timeout in seconds.
		 */
		protected function resolve_timeout() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $settings['kimi_timeout'] ) ? absint( $settings['kimi_timeout'] ) : 0;

			if ( $timeout <= 0 ) {
				$timeout = 60; // Default 60 seconds.
			}

			/**
			 * Filter the Kimi request timeout.
			 *
			 * @since 1.0.0
			 *
			 * @param int $timeout Timeout in seconds.
			 */
			return apply_filters( 'wp_mcp_ai_kimi_timeout', $timeout );
		}

		/**
		 * Resolve the model to use for a request.
		 *
		 * @since 1.0.0
		 * @param string $model Optional model override.
		 * @return string Resolved model identifier.
		 */
		protected function resolve_model( $model = '' ) {
			$model = sanitize_text_field( $model );

			if ( '' === $model ) {
				$model = $this->get_model();
			}

			if ( '' === $model ) {
				$model = self::DEFAULT_MODEL;
			}

			/**
			 * Filter the resolved Kimi model.
			 *
			 * @since 1.0.0
			 *
			 * @param string $model The resolved model.
			 */
			return apply_filters( 'wp_mcp_ai_kimi_model', $model );
		}

		// -------------------------------------------------------------------------
		// API methods.
		// -------------------------------------------------------------------------

		/**
		 * Create a chat completion.
		 *
		 * Sends a chat completion request to the Kimi API. Supports streaming
		 * via the 'stream' option.
		 *
		 * @since 1.0.0
		 * @param array $messages Array of message arrays with 'role' and 'content'.
		 * @param array $options  Optional. Additional options:
		 *                        - model: string - Model to use.
		 *                        - temperature: float - Sampling temperature.
		 *                        - max_completion_tokens: int - Max tokens to generate.
		 *                        - stream: bool - Enable streaming.
		 *                        - tools: array - Tool definitions for function calling.
		 *                        - tool_choice: string|array - Tool selection mode.
		 *                        - response_format: array - JSON mode configuration.
		 *                        - stop: string|array - Stop sequences.
		 *                        - top_p: float - Nucleus sampling parameter.
		 *                        - prompt_cache_key: string - Cache key for similar requests.
		 *                        - thinking: array - Thinking mode configuration.
		 * @return array|WP_Error Response array or error.
		 */
		public function create_chat_completion( $messages, $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'kimi_api_key_missing',
					__( 'Kimi API key is not configured.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! is_array( $messages ) || empty( $messages ) ) {
				return new WP_Error(
					'kimi_invalid_messages',
					__( 'Messages must be a non-empty array.', 'mcp-ai-wpoos' )
				);
			}

			$model   = $this->resolve_model( $options['model'] ?? '' );
			$payload = $this->build_payload( $messages, $options, $model );

			$headers = $this->build_request_headers( $api_key );
			$url     = $this->get_base_url() . self::API_ENDPOINT;
			$timeout = $this->resolve_timeout();

			/**
			 * Filter the Kimi chat completion URL.
			 *
			 * @since 1.0.0
			 *
			 * @param string $url     The request URL.
			 * @param string $model   The model being used.
			 * @param array  $payload The request payload.
			 */
			$url = apply_filters( 'wp_mcp_ai_kimi_chat_url', $url, $model, $payload );

			$args = array(
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
				'method'  => 'POST',
			);

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$body          = wp_remote_retrieve_body( $response );

			if ( 200 !== $response_code ) {
				return $this->handle_api_error( $response_code, $body );
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'kimi_invalid_response',
					__( 'Invalid response from Kimi API.', 'mcp-ai-wpoos' )
				);
			}

			return $this->normalize_response( $data );
		}

		/**
		 * List available models from the Kimi API.
		 *
		 * @since 1.0.0
		 * @return array|WP_Error Array of model data or error.
		 */
		public function list_models() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'kimi_api_key_missing',
					__( 'Kimi API key is not configured.', 'mcp-ai-wpoos' )
				);
			}

			$headers = $this->build_request_headers( $api_key );
			$url     = $this->get_base_url() . self::API_MODELS;
			$timeout = $this->resolve_timeout();

			$args = array(
				'headers' => $headers,
				'timeout' => $timeout,
				'method'  => 'GET',
			);

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$body          = wp_remote_retrieve_body( $response );

			if ( 200 !== $response_code ) {
				return $this->handle_api_error( $response_code, $body );
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || ! isset( $data['data'] ) ) {
				return new WP_Error(
					'kimi_invalid_response',
					__( 'Invalid response from Kimi API.', 'mcp-ai-wpoos' )
				);
			}

			return $data['data'];
		}

		/**
		 * Test the connection to the Kimi API.
		 *
		 * Attempts to list models to verify the API key is valid.
		 *
		 * @since 1.0.0
		 * @return array|WP_Error Success array or error.
		 */
		public function test_connection() {
			$models = $this->list_models();

			if ( is_wp_error( $models ) ) {
				return $models;
			}

			$model_count = count( $models );

			return array(
				'success'     => true,
				'message'     => sprintf(
					/* translators: %d: number of models */
					__( 'Connection successful. Found %d models.', 'mcp-ai-wpoos' ),
					$model_count
				),
				'model_count' => $model_count,
				'models'      => array_slice( $models, 0, 5 ), // Return first 5 models.
			);
		}

		/**
		 * Estimate token count for messages.
		 *
		 * Uses the Kimi token estimation endpoint.
		 *
		 * @since 1.0.0
		 * @param array  $messages Array of messages.
		 * @param string $model    Optional model identifier.
		 * @return array|WP_Error Token count data or error.
		 */
		public function count_tokens( $messages, $model = null ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'kimi_api_key_missing',
					__( 'Kimi API key is not configured.', 'mcp-ai-wpoos' )
				);
			}

			$model = $this->resolve_model( $model );

			$headers = $this->build_request_headers( $api_key );
			$url     = $this->get_base_url() . self::API_TOKEN_COUNT;
			$timeout = $this->resolve_timeout();

			$payload = array(
				'model'    => $model,
				'messages' => $messages,
			);

			$args = array(
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
				'method'  => 'POST',
			);

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$body          = wp_remote_retrieve_body( $response );

			if ( 200 !== $response_code ) {
				return $this->handle_api_error( $response_code, $body );
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'kimi_invalid_response',
					__( 'Invalid response from Kimi API.', 'mcp-ai-wpoos' )
				);
			}

			return $data;
		}

		// -------------------------------------------------------------------------
		// Payload builders.
		// -------------------------------------------------------------------------

		/**
		 * Build the request payload for chat completions.
		 *
		 * @since 1.0.0
		 * @param array  $messages Array of messages.
		 * @param array  $options  Additional options.
		 * @param string $model    Resolved model.
		 * @return array Payload array.
		 */
		protected function build_payload( $messages, $options, $model ) {
			$payload = array(
				'model'    => $model,
				'messages' => $messages,
			);

			// Temperature (0-2).
			if ( isset( $options['temperature'] ) ) {
				$payload['temperature'] = max( 0, min( 2, floatval( $options['temperature'] ) ) );
			}

			// Max completion tokens.
			if ( isset( $options['max_completion_tokens'] ) ) {
				$payload['max_completion_tokens'] = absint( $options['max_completion_tokens'] );
			} elseif ( isset( $options['max_tokens'] ) ) {
				// Support legacy max_tokens parameter.
				$payload['max_completion_tokens'] = absint( $options['max_tokens'] );
			}

			// Top P sampling.
			if ( isset( $options['top_p'] ) ) {
				$payload['top_p'] = max( 0, min( 1, floatval( $options['top_p'] ) ) );
			}

			// Stop sequences.
			if ( isset( $options['stop'] ) ) {
				$payload['stop'] = $options['stop'];
			}

			// Response format (JSON mode).
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			// Streaming.
			if ( isset( $options['stream'] ) && $options['stream'] ) {
				$payload['stream'] = true;

				// Stream options.
				if ( isset( $options['stream_options'] ) && is_array( $options['stream_options'] ) ) {
					$payload['stream_options'] = $options['stream_options'];
				}
			}

			// Tools (function calling).
			if ( isset( $options['tools'] ) && is_array( $options['tools'] ) && $this->model_supports_tools( $model ) ) {
				$payload['tools'] = $options['tools'];

				if ( isset( $options['tool_choice'] ) ) {
					$payload['tool_choice'] = $options['tool_choice'];
				}
			}

			// Prompt cache key for optimization.
			if ( isset( $options['prompt_cache_key'] ) && ! empty( $options['prompt_cache_key'] ) ) {
				$payload['prompt_cache_key'] = sanitize_text_field( $options['prompt_cache_key'] );
			}

			// Safety identifier for usage policy.
			if ( isset( $options['safety_identifier'] ) && ! empty( $options['safety_identifier'] ) ) {
				$payload['safety_identifier'] = sanitize_text_field( $options['safety_identifier'] );
			}

			// Thinking mode configuration.
			if ( isset( $options['thinking'] ) && is_array( $options['thinking'] ) ) {
				$payload['thinking'] = $options['thinking'];
			}

			/**
			 * Filter the Kimi request payload before sending.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $payload The request payload.
			 * @param string $model   The model being used.
			 * @param array  $options The original options.
			 */
			return apply_filters( 'wp_mcp_ai_kimi_payload', $payload, $model, $options );
		}

		// -------------------------------------------------------------------------
		// Error handling.
		// -------------------------------------------------------------------------

		/**
		 * Handle API error responses.
		 *
		 * Parses Kimi API error responses and returns appropriate WP_Error.
		 *
		 * @since 1.0.0
		 * @param int    $response_code HTTP response code.
		 * @param string $body          Response body.
		 * @return WP_Error Error object.
		 */
		protected function handle_api_error( $response_code, $body ) {
			$data = json_decode( $body, true );
			$code = 'kimi_api_error';

			if ( is_array( $data ) && isset( $data['error'] ) ) {
				$error_info = $data['error'];
				$message    = isset( $error_info['message'] ) ? $error_info['message'] : __( 'Unknown error from Kimi API.', 'mcp-ai-wpoos' );

				if ( isset( $error_info['code'] ) ) {
					$code = 'kimi_' . sanitize_key( $error_info['code'] );
				}
			} else {
				$message = sprintf(
					/* translators: %d: HTTP response code */
					__( 'Kimi API returned HTTP %d.', 'mcp-ai-wpoos' ),
					$response_code
				);
			}

			$error = new WP_Error( $code, $message, array( 'status' => $response_code ) );

			/**
			 * Filter the Kimi API error.
			 *
			 * @since 1.0.0
			 *
			 * @param WP_Error $error         The error object.
			 * @param int      $response_code HTTP response code.
			 * @param string   $body          Response body.
			 */
			return apply_filters( 'wp_mcp_ai_kimi_api_error', $error, $response_code, $body );
		}

		// -------------------------------------------------------------------------
		// Response normalization.
		// -------------------------------------------------------------------------

		/**
		 * Normalize Kimi API response to standard format.
		 *
		 * Ensures consistent response structure across providers.
		 *
		 * @since 1.0.0
		 * @param array $api_response Raw API response.
		 * @return array Normalized response.
		 */
		protected function normalize_response( $api_response ) {
			$normalized = array(
				'id'      => isset( $api_response['id'] ) ? sanitize_text_field( $api_response['id'] ) : '',
				'object'  => isset( $api_response['object'] ) ? sanitize_text_field( $api_response['object'] ) : 'chat.completion',
				'created' => isset( $api_response['created'] ) ? absint( $api_response['created'] ) : time(),
				'model'   => isset( $api_response['model'] ) ? sanitize_text_field( $api_response['model'] ) : '',
				'choices' => array(),
				'usage'   => array(
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'total_tokens'      => 0,
				),
			);

			// Normalize choices.
			if ( isset( $api_response['choices'] ) && is_array( $api_response['choices'] ) ) {
				foreach ( $api_response['choices'] as $choice ) {
					$normalized_choice = array(
						'index'         => isset( $choice['index'] ) ? absint( $choice['index'] ) : 0,
						'message'       => array(
							'role'    => isset( $choice['message']['role'] ) ? sanitize_text_field( $choice['message']['role'] ) : 'assistant',
							'content' => isset( $choice['message']['content'] ) ? $choice['message']['content'] : '',
						),
						'finish_reason' => isset( $choice['finish_reason'] ) ? sanitize_text_field( $choice['finish_reason'] ) : null,
					);

					// Handle tool calls.
					if ( isset( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] ) ) {
						$normalized_choice['message']['tool_calls'] = $choice['message']['tool_calls'];
					}

					// Handle reasoning content (thinking models).
					if ( isset( $choice['message']['reasoning_content'] ) ) {
						$normalized_choice['message']['reasoning_content'] = $choice['message']['reasoning_content'];
					}

					$normalized['choices'][] = $normalized_choice;
				}
			}

			// Normalize usage.
			if ( isset( $api_response['usage'] ) && is_array( $api_response['usage'] ) ) {
				$normalized['usage'] = array(
					'prompt_tokens'     => isset( $api_response['usage']['prompt_tokens'] ) ? absint( $api_response['usage']['prompt_tokens'] ) : 0,
					'completion_tokens' => isset( $api_response['usage']['completion_tokens'] ) ? absint( $api_response['usage']['completion_tokens'] ) : 0,
					'total_tokens'      => isset( $api_response['usage']['total_tokens'] ) ? absint( $api_response['usage']['total_tokens'] ) : 0,
				);

				// Handle cached tokens if present.
				if ( isset( $api_response['usage']['cached_tokens'] ) ) {
					$normalized['usage']['cached_tokens'] = absint( $api_response['usage']['cached_tokens'] );
				}
			}

			/**
			 * Filter the normalized Kimi response.
			 *
			 * @since 1.0.0
			 *
			 * @param array $normalized   The normalized response.
			 * @param array $api_response The original API response.
			 */
			return apply_filters( 'wp_mcp_ai_kimi_normalized_response', $normalized, $api_response );
		}
	}
}
