<?php
/**
 * DigitalOcean Serverless Inference API client wrapper.
 *
 * DigitalOcean Serverless Inference (SI) exposes an OpenAI-compatible REST
 * API at https://inference.do-ai.run/v1.  It is authenticated by a
 * "model access key" (a `Bearer` token issued from
 * Gradient Platform → Serverless Inference → Model access keys), and accepts
 * messages, tools, response_format, and SSE streaming options identical to
 * the upstream OpenAI Chat Completions API.
 *
 * Unlike DigitalOcean's Agent endpoints (`*.agents.do-ai.run/api/v1`), the
 * serverless inference surface does *not* require the `?agent=true` flag,
 * does not have per-agent URLs, and supports a unified model catalogue. The
 * Agent endpoints are intentionally out of scope for this client.
 *
 * @link    https://docs.digitalocean.com/products/gradient-ai-platform/how-to/use-serverless-inference/
 * @link    https://docs.digitalocean.com/reference/api/gradient-ai-platform-api/
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_DigitalOcean_Client' ) ) {
	/**
	 * Provides a wrapper around the DigitalOcean Serverless Inference API
	 * (OpenAI-compatible).
	 *
	 * Supports chat completions, tool/function calling, JSON mode, streaming
	 * (SSE identical to OpenAI), embeddings, and live model listing.
	 */
	class WP_MCP_AI_DigitalOcean_Client {

		/**
		 * Default base URL for the DigitalOcean Serverless Inference API
		 * (no trailing slash). The base URL already includes `/v1` so the
		 * endpoint constants below are appended directly.
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://inference.do-ai.run/v1';

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
		 * Embeddings path relative to the base URL.
		 *
		 * @var string
		 */
		const API_EMBEDDINGS = '/embeddings';

		/**
		 * User-Agent string sent with every request.
		 *
		 * @var string
		 */
		const USER_AGENT = 'WP-MCP-AI-DigitalOcean-Client/1.0';

		/**
		 * Default chat model when none is configured.
		 *
		 * Llama 3.3 70B Instruct is broadly available on DigitalOcean
		 * Serverless Inference and supports tool calling.
		 *
		 * @var string
		 */
		const DEFAULT_MODEL = 'llama3.3-70b-instruct';

		/**
		 * Default embedding model when none is configured.
		 *
		 * @var string
		 */
		const DEFAULT_EMBEDDING_MODEL = 'gte-large-en-v1.5';

		// -------------------------------------------------------------------------
		// Accessors.
		// -------------------------------------------------------------------------

		/**
		 * Retrieve the configured DigitalOcean model access key.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['digitalocean_api_key'] ) ? $settings['digitalocean_api_key'] : '';
		}

		/**
		 * Retrieve the configured default model.
		 *
		 * @return string Empty string when not configured.
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['digitalocean_model'] ) ? $settings['digitalocean_model'] : '';
		}

		/**
		 * Retrieve the configured base URL.
		 *
		 * Supports custom proxies via the `digitalocean_base_url` setting.
		 * Falls back to {@see DEFAULT_BASE_URL}.
		 *
		 * @return string Base URL without trailing slash.
		 */
		public function get_base_url() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$base_url = isset( $settings['digitalocean_base_url'] ) ? trim( $settings['digitalocean_base_url'] ) : '';

			if ( '' === $base_url ) {
				$base_url = self::DEFAULT_BASE_URL;
			}

			return untrailingslashit( $base_url );
		}

		// -------------------------------------------------------------------------
		// HTTP helpers.
		// -------------------------------------------------------------------------

		/**
		 * Build standard HTTP request headers for DigitalOcean API calls.
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
			 * Filter the DigitalOcean request headers before sending.
			 *
			 * @since 1.1.16
			 *
			 * @param array  $headers Associative array of HTTP headers.
			 * @param string $api_key The API key being used.
			 */
			return apply_filters( 'wp_mcp_ai_digitalocean_request_headers', $headers, $api_key );
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

		// -------------------------------------------------------------------------
		// Core methods.
		// -------------------------------------------------------------------------

		/**
		 * Perform a chat completion request against the DigitalOcean
		 * Serverless Inference API.
		 *
		 * The API is OpenAI-compatible: messages, tools, response_format,
		 * and streaming options are passed through unchanged.
		 *
		 * @param array $messages Message payload (OpenAI-compatible format).
		 * @param array $options  Additional options:
		 *                        - model (string): Override the model.
		 *                        - temperature (float): Sampling temperature.
		 *                        - top_p (float): Nucleus sampling.
		 *                        - max_tokens (int): Maximum output tokens.
		 *                        - tools (array): OpenAI-compatible tool defs.
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
					'wp_mcp_ai_missing_digitalocean_api_key',
					__( 'No DigitalOcean model access key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_digitalocean_api_key' => __( 'Add a DigitalOcean model access key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_digitalocean_model',
					__( 'No DigitalOcean model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_digitalocean_model' => __( 'Choose a DigitalOcean model in the NV oOS settings.', 'mcp-ai-wpoos' ),
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
				$preflight = WP_MCP_AI_Token_Budget_Manager::validate_context_window( $payload, $model, 'digitalocean', $options, $messages );
				if ( is_wp_error( $preflight ) ) {
					return $preflight;
				}
			}

			$url = $this->get_base_url() . self::API_ENDPOINT;

			$request_args = array(
				'headers'   => $this->build_request_headers( $api_key ),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => max( 60, $this->resolve_timeout( $options ) ),
				'sslverify' => true,
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'digitalocean_request',
					'Sending request to DigitalOcean Serverless Inference.',
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
					return $this->do_realtime_curl_stream( $url, $payload, $model, $timeout, $realtime_cb );
				}
			}

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'DigitalOcean request failed.', array( 'error' => $response->get_error_message() ) );
				}

				if ( class_exists( 'WP_MCP_AI_HTTP' ) ) {
					return WP_MCP_AI_HTTP::prepare_transport_error(
						$response,
						'wp_mcp_ai_http_error',
						__( 'The DigitalOcean API request failed to complete.', 'mcp-ai-wpoos' ),
						__( 'DigitalOcean', 'mcp-ai-wpoos' )
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
					WP_MCP_AI_Logger::log_error( 'Failed to decode DigitalOcean response.', array( 'body' => $body ) );
				}

				return new WP_Error( 'wp_mcp_ai_digitalocean_invalid_response', __( 'The DigitalOcean API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, is_array( $decoded ) ? $decoded : array(), $response );
			}

			$normalized = $this->normalize_response( is_array( $decoded ) ? $decoded : array() );

			if ( ! isset( $normalized['model'] ) && ! empty( $model ) ) {
				$normalized['model'] = $model;
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'digitalocean_response', 'DigitalOcean request completed.', array( 'model' => $model ) );
			}

			return $normalized;
		}

		/**
		 * Perform a real-time SSE stream request to DigitalOcean using direct cURL.
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
					CURLOPT_WRITEFUNCTION  => function ( $_ch, $data ) use ( &$sse_buffer, &$accumulated_content, &$tool_calls_by_idx, &$response_id, &$finish_reason, &$usage, &$found_done, $stream_callback ) {
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
				return new WP_Error( 'wp_mcp_ai_api_error', __( 'DigitalOcean returned an error during streaming.', 'mcp-ai-wpoos' ), array( 'status' => $http_status ) );
			}

			$message = array(
				'role'    => 'assistant',
				'content' => $accumulated_content,
			);
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
			return $assembled;
		}


		/**
		 * List available models from the DigitalOcean Serverless Inference API.
		 *
		 * The /models endpoint returns an OpenAI-shaped JSON object with a
		 * `data` array of model objects.  Each entry includes the model `id`,
		 * and (where DO publishes it) context length and pricing metadata.
		 *
		 * @return array|WP_Error Array of model objects or WP_Error on failure.
		 */
		public function list_models() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_digitalocean_api_key',
					__( 'No DigitalOcean model access key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$url = $this->get_base_url() . self::API_MODELS;

			$request_args = array(
				'timeout'   => 30,
				'headers'   => $this->build_request_headers( $api_key ),
				'sslverify' => true,
			);

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'digitalocean_list_models', 'Fetching models from DigitalOcean.', array( 'url' => $url ) );
			}

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error( 'DigitalOcean model listing failed.', array( 'error' => $response->get_error_message() ) );
				}

				return $response;
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_digitalocean_invalid_response', __( 'The DigitalOcean API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected error from DigitalOcean models endpoint.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_digitalocean_api_error',
					$error_message,
					array( 'status' => $code )
				);
			}

			$models = array();

			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				foreach ( $decoded['data'] as $model ) {
					if ( ! is_array( $model ) || empty( $model['id'] ) ) {
						continue;
					}

					$entry = array(
						'id'             => sanitize_text_field( $model['id'] ),
						'name'           => isset( $model['name'] ) ? sanitize_text_field( $model['name'] ) : '',
						'context_length' => isset( $model['context_length'] ) ? absint( $model['context_length'] ) : 0,
					);

					if ( isset( $model['pricing'] ) && is_array( $model['pricing'] ) ) {
						$entry['pricing'] = array(
							'prompt'     => isset( $model['pricing']['prompt'] ) ? (string) $model['pricing']['prompt'] : '',
							'completion' => isset( $model['pricing']['completion'] ) ? (string) $model['pricing']['completion'] : '',
						);
					}

					$models[] = $entry;
				}
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event( 'digitalocean_list_models', 'DigitalOcean models retrieved.', array( 'count' => count( $models ) ) );
			}

			return $models;
		}

		/**
		 * Create an embedding for the supplied input.
		 *
		 * Unlike OpenRouter, DigitalOcean Serverless Inference ships a native
		 * /embeddings endpoint compatible with OpenAI's request/response shape.
		 *
		 * @param array $options Options:
		 *                       - input (string|array): Text to embed.
		 *                       - model (string): Embedding model id (optional).
		 *                       - timeout (int): HTTP timeout in seconds.
		 * @return array|WP_Error Decoded API response or WP_Error on failure.
		 */
		public function create_embedding( array $options ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_digitalocean_api_key',
					__( 'No DigitalOcean model access key has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $options['input'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_input',
					__( 'No input text was provided for the DigitalOcean embedding request.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$model = ! empty( $options['model'] ) ? sanitize_text_field( $options['model'] ) : self::DEFAULT_EMBEDDING_MODEL;

			$payload = array(
				'model' => $model,
				'input' => $options['input'],
			);

			/**
			 * Filter the DigitalOcean embedding request payload.
			 *
			 * @since 1.1.16
			 *
			 * @param array $payload Request payload.
			 * @param array $options Request options.
			 */
			$payload = apply_filters( 'wp_mcp_ai_digitalocean_embedding_payload', $payload, $options );

			$url = $this->get_base_url() . self::API_EMBEDDINGS;

			$request_args = array(
				'headers'   => $this->build_request_headers( $api_key ),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => max( 30, $this->resolve_timeout( $options ) ),
				'sslverify' => true,
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_digitalocean_invalid_response', __( 'The DigitalOcean embeddings endpoint returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->handle_api_error( $code, is_array( $decoded ) ? $decoded : array(), $response );
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Test the connection to the DigitalOcean Serverless Inference API.
		 *
		 * Sends a 5-token chat completion to verify API key and network access.
		 *
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_digitalocean_api_key',
					__( 'No DigitalOcean model access key has been configured.', 'mcp-ai-wpoos' ),
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
				'message' => __( 'Successfully connected to DigitalOcean Serverless Inference.', 'mcp-ai-wpoos' ),
				'model'   => $test_model,
			);
		}

		/**
		 * Count tokens using a heuristic estimator.
		 *
		 * DigitalOcean does not expose a public token-count endpoint and the
		 * accurate tokenizer varies per upstream model. The same chars/4
		 * heuristic used by other non-OpenAI providers is applied here.
		 *
		 * @param array $messages Chat messages in OpenAI-compatible format.
		 * @param array $options  Optional parameters (system_prompt).
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

			return max( 1, (int) ceil( $char_count / 4 ) );
		}

		// -------------------------------------------------------------------------
		// Private helpers.
		// -------------------------------------------------------------------------

		/**
		 * Build the JSON payload sent to DigitalOcean.
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
					__( 'No chat messages were provided for the DigitalOcean request.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$payload = array(
				'model'    => $model,
				'messages' => array(),
			);

			if ( ! empty( $options['system_prompt'] ) ) {
				$payload['messages'][] = array(
					'role'    => 'system',
					'content' => wp_kses_post( (string) $options['system_prompt'] ),
				);
			}

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$payload['messages'][] = $message;
			}

			if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['top_p'] = (float) $options['top_p'];
			}

			if ( isset( $options['max_tokens'] ) && is_numeric( $options['max_tokens'] ) ) {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			}

			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			if ( ! empty( $options['stream'] ) ) {
				$payload['stream'] = true;
			}

			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				$payload['tools'] = $options['tools'];

				if ( isset( $options['tool_choice'] ) ) {
					$payload['tool_choice'] = $options['tool_choice'];
				}
			}

			/**
			 * Filter the DigitalOcean request payload before it is sent.
			 *
			 * @since 1.1.16
			 *
			 * @param array  $payload  Request payload.
			 * @param array  $messages Original messages.
			 * @param array  $options  Request options.
			 * @param string $model    Resolved model identifier.
			 */
			return apply_filters( 'wp_mcp_ai_digitalocean_request_payload', $payload, $messages, $options, $model );
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
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from DigitalOcean.', 'mcp-ai-wpoos' );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_digitalocean_api_error';

			if ( 401 === $code || 403 === $code ) {
				$error_code            = 'wp_mcp_ai_digitalocean_auth_error';
				$error_data['actions'] = array(
					'auth_info' => __( 'Verify your DigitalOcean model access key in NV oOS → Providers → DigitalOcean.', 'mcp-ai-wpoos' ),
				);
			} elseif ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The DigitalOcean API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'DigitalOcean returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);
			}

			return new WP_Error( $error_code, $error_message, $error_data );
		}

		/**
		 * Normalise a DigitalOcean response to the plugin's internal format.
		 *
		 * The response format is OpenAI-compatible, so only light
		 * normalisation is required to satisfy the internal contract expected
		 * by the REST layer.
		 *
		 * @param array $decoded Decoded JSON response.
		 * @return array Normalised response.
		 */
		protected function normalize_response( array $decoded ) {
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

			if ( ! empty( $message['tool_calls'] ) ) {
				$normalized['tool_calls'] = $message['tool_calls'];
			}

			// DigitalOcean passes reasoning through `reasoning_content` for
			// reasoning-capable models (e.g. DeepSeek-R1 distills) — keep parity
			// with the DeepSeek client mapping.
			if ( ! empty( $message['reasoning_content'] ) ) {
				$normalized['reasoning_content'] = $message['reasoning_content'];
			} elseif ( ! empty( $message['reasoning'] ) ) {
				$normalized['reasoning_content'] = $message['reasoning'];
			}

			return $normalized;
		}
	}
}
