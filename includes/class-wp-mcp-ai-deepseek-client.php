<?php
/**
 * DeepSeek API client wrapper.
 *
 * DeepSeek exposes an OpenAI-compatible REST API at https://api.deepseek.com.
 * This client handles chat completions, model listing, and connection testing
 * without vendoring any third-party SDK.
 *
 * @link    https://platform.deepseek.com/api-docs
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
	/**
	 * Provides a wrapper around the DeepSeek API (OpenAI-compatible).
	 *
	 * Supports chat completions, tool/function calling (deepseek-chat only),
	 * streaming (SSE identical to OpenAI), JSON mode, and live model listing.
	 *
	 * Note on embeddings: DeepSeek does not currently expose a public embeddings
	 * endpoint. No WP_MCP_AI_Embedding_Provider_DeepSeek is registered.
	 *
	 * Note on vision: DeepSeek has experimental VL models (deepseek-vl2) but
	 * this integration does not advertise vision support in v1 to avoid
	 * mis-routing. Enable via the filter {@see wp_mcp_ai_deepseek_supports_vision}.
	 */
	class WP_MCP_AI_DeepSeek_Client {

		/**
		 * Default base URL for the DeepSeek API (no trailing slash, no path).
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://api.deepseek.com';

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
		const USER_AGENT = 'WP-MCP-AI-DeepSeek-Client/1.0';

		/**
		 * Default chat model when none is configured.
		 *
		 * deepseek-chat is the general-purpose, tool-calling model (DeepSeek-V3).
		 *
		 * @var string
		 */
		const DEFAULT_MODEL = 'deepseek-chat';

		/**
		 * Models that do not support tool/function calling.
		 *
		 * deepseek-reasoner (DeepSeek-R1) is a chain-of-thought model that
		 * rejects the `tools` parameter. Tools are stripped automatically.
		 *
		 * @var array
		 */
		const MODELS_WITHOUT_TOOL_CALLING = array( 'deepseek-reasoner' );

		// -------------------------------------------------------------------------
		// Accessors.
		// -------------------------------------------------------------------------

		/**
		 * Retrieve the configured DeepSeek API key.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['deepseek_api_key'] ) ? $settings['deepseek_api_key'] : '';
		}

		/**
		 * Retrieve the configured default model.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['deepseek_model'] ) ? $settings['deepseek_model'] : '';
		}

		/**
		 * Retrieve the configured base URL.
		 *
		 * Supports custom proxies (Volcano Engine, Together AI, etc.) via the
		 * deepseek_base_url setting.  Falls back to {@see DEFAULT_BASE_URL}.
		 *
		 * @return string Base URL without trailing slash.
		 */
		public function get_base_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$base_url = isset( $settings['deepseek_base_url'] ) ? trim( $settings['deepseek_base_url'] ) : '';

			if ( '' === $base_url ) {
				$base_url = self::DEFAULT_BASE_URL;
			}

			return untrailingslashit( $base_url );
		}

		// -------------------------------------------------------------------------
		// HTTP helpers.
		// -------------------------------------------------------------------------

		/**
		 * Build standard HTTP request headers for DeepSeek API calls.
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
			 * Filter the DeepSeek request headers before sending.
			 *
			 * @since 2026.05
			 *
			 * @param array  $headers Associative array of HTTP headers.
			 * @param string $api_key The API key being used.
			 */
			return apply_filters( 'wp_mcp_ai_deepseek_request_headers', $headers, $api_key );
		}

		/**
		 * Resolve the request timeout in seconds.
		 *
		 * @param array $options Request options may carry a 'timeout' key.
		 * @return int
		 */
		protected function resolve_timeout( array $options ) {
			$default = 60;

			if ( ! empty( $options['timeout'] ) && is_numeric( $options['timeout'] ) ) {
				return max( 10, absint( $options['timeout'] ) );
			}

			return $default;
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

		/**
		 * Return true when the model does not support tool/function calling.
		 *
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

		// -------------------------------------------------------------------------
		// Core methods.
		// -------------------------------------------------------------------------

		/**
		 * Perform a chat completion request against the DeepSeek API.
		 *
		 * The DeepSeek API is OpenAI-compatible: messages, tools, response_format,
		 * and streaming options are passed through unchanged.
		 *
		 * @param array $messages Message payload (OpenAI-compatible format).
		 * @param array $options  Additional options:
		 *                        - model (string): Override the model.
		 *                        - temperature (float): Sampling temperature.
		 *                        - top_p (float): Nucleus sampling.
		 *                        - max_tokens (int): Maximum output tokens.
		 *                        - tools (array): OpenAI-compatible tool definitions.
		 *                        - tool_choice (string|array): Tool selection.
		 *                        - response_format (array): e.g. ['type' => 'json_object'].
		 *                        - stream (bool): Enable SSE streaming.
		 *                        - timeout (int): HTTP timeout in seconds.
		 *                        - system_prompt (string): System instruction.
		 * @return array|WP_Error Normalised completion response or WP_Error on failure.
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_deepseek_api_key',
					__( 'No DeepSeek API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_deepseek_api_key' => __( 'Add a DeepSeek API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_deepseek_model',
					__( 'No DeepSeek model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_deepseek_model' => __( 'Choose a DeepSeek model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$payload = $this->build_payload( $messages, $options, $model );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$url = $this->get_base_url() . self::API_ENDPOINT;

			$request_args = array(
				'headers' => $this->build_request_headers( $api_key ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => max( 60, $this->resolve_timeout( $options ) ),
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'deepseek_request',
					'Sending request to DeepSeek.',
					array(
						'model'         => $model,
						'message_count' => count( $messages ),
						'has_tools'     => ! empty( $payload['tools'] ),
					)
				);
			}

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'DeepSeek request failed.', array( 'error' => $response->get_error_message() ) );
				}

				if ( class_exists( 'WP_MCP_AI_HTTP' ) ) {
					return WP_MCP_AI_HTTP::prepare_transport_error(
						$response,
						'wp_mcp_ai_http_error',
						__( 'The DeepSeek API request failed to complete.', 'mcp-ai-wpoos' ),
						__( 'DeepSeek', 'mcp-ai-wpoos' )
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
					WP_MCP_AI_Logger::log_error( 'Failed to decode DeepSeek response.', array( 'body' => $body ) );
				}

				return new WP_Error( 'wp_mcp_ai_deepseek_invalid_response', __( 'The DeepSeek API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, $decoded, $response );
			}

			$normalized = $this->normalize_response( $decoded );

			if ( ! isset( $normalized['model'] ) && ! empty( $model ) ) {
				$normalized['model'] = $model;
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'deepseek_response', 'DeepSeek request completed.', array( 'model' => $model ) );
			}

			return $normalized;
		}

		/**
		 * List available models from the DeepSeek API.
		 *
		 * The /models endpoint returns an OpenAI-shaped JSON object with a `data`
		 * array of model objects each containing an `id` field.
		 *
		 * @return array|WP_Error Array of model objects or WP_Error on failure.
		 */
		public function list_models() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_deepseek_api_key',
					__( 'No DeepSeek API key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$url = $this->get_base_url() . self::API_MODELS;

			$request_args = array(
				'timeout' => 30,
				'headers' => $this->build_request_headers( $api_key ),
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'deepseek_list_models', 'Fetching models from DeepSeek.', array( 'url' => $url ) );
			}

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'DeepSeek model listing failed.', array( 'error' => $response->get_error_message() ) );
				}

				return $response;
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_deepseek_invalid_response', __( 'The DeepSeek API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected error from DeepSeek models endpoint.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_deepseek_api_error',
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
				WP_MCP_AI_Logger::log_event( 'deepseek_list_models', 'DeepSeek models retrieved.', array( 'count' => count( $models ) ) );
			}

			return $models;
		}

		/**
		 * Test the connection to the DeepSeek API.
		 *
		 * Sends a 1-token chat completion to verify API key and network access.
		 *
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_deepseek_api_key',
					__( 'No DeepSeek API key has been configured.', 'mcp-ai-wpoos' ),
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
				'message' => __( 'Successfully connected to DeepSeek.', 'mcp-ai-wpoos' ),
				'model'   => $test_model,
			);
		}

		/**
		 * Count tokens using a heuristic estimator.
		 *
		 * DeepSeek does not expose a public token-count endpoint.  The
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
		 * Build the JSON payload sent to DeepSeek.
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
					__( 'No chat messages were provided for the DeepSeek request.', 'mcp-ai-wpoos' ),
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

			if ( isset( $options['max_tokens'] ) && is_numeric( $options['max_tokens'] ) ) {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			}

			// JSON mode.
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			// Streaming flag.
			if ( ! empty( $options['stream'] ) ) {
				$payload['stream'] = true;
			}

			// Tool/function calling — only for models that support it.
			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				if ( $this->model_lacks_tool_calling( $model ) ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'deepseek_tools_skipped',
							sprintf(
								/* translators: %s: model name */
								'Tools stripped for DeepSeek model %s (does not support function calling).',
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

			/**
			 * Filter the DeepSeek request payload before it is sent.
			 *
			 * @since 2026.05
			 *
			 * @param array  $payload  Request payload.
			 * @param array  $messages Original messages.
			 * @param array  $options  Request options.
			 * @param string $model    Resolved model identifier.
			 */
			return apply_filters( 'wp_mcp_ai_deepseek_request_payload', $payload, $messages, $options, $model );
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
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from DeepSeek.', 'mcp-ai-wpoos' );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_deepseek_api_error';

			if ( 401 === $code ) {
				$error_code            = 'wp_mcp_ai_deepseek_auth_error';
				$error_data['actions'] = array(
					'auth_info' => __( 'Verify your DeepSeek API key in NV oOS → Providers → DeepSeek.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The DeepSeek API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'DeepSeek returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);
			}

			return new WP_Error( $error_code, $error_message, $error_data );
		}

		/**
		 * Normalise a DeepSeek response to the plugin's internal format.
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

			$normalized = array(
				'content'       => $content,
				'finish_reason' => isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : '',
				'model'         => isset( $decoded['model'] ) ? $decoded['model'] : '',
				'usage'         => isset( $decoded['usage'] ) ? $decoded['usage'] : array(),
				'raw'           => $decoded,
			);

			// Pass through tool_calls when present (function calling).
			if ( ! empty( $message['tool_calls'] ) ) {
				$normalized['tool_calls'] = $message['tool_calls'];
			}

			// Pass through reasoning_content for deepseek-reasoner models.
			if ( ! empty( $message['reasoning_content'] ) ) {
				$normalized['reasoning_content'] = $message['reasoning_content'];
			}

			return $normalized;
		}
	}
}
