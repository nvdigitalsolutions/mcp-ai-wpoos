<?php
/**
 * Ollama API client wrapper.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		 * Default minimum context window (num_ctx) used when callers do not
		 * provide one explicitly. Sized to comfortably hold a system prompt,
		 * tool definitions, recent chat history, and the requested generation
		 * budget for typical assistant workloads. Ollama itself defaults to
		 * only 2048 tokens which is too small in practice.
		 *
		 * @since 1.1.9
		 */
		const DEFAULT_NUM_CTX = 8192;

		/**
		 * Fallback assumption for num_predict when computing the dynamic
		 * num_ctx default and num_predict has not been resolved yet. Matches
		 * Ollama's own built-in num_ctx default so the math degrades to a
		 * safe minimum.
		 *
		 * @since 1.1.9
		 */
		const NUM_PREDICT_FALLBACK_FOR_CTX = 2048;

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

			WP_MCP_AI_Logger::log_event(
				'ollama_test_connection',
				'Testing Ollama connection.',
				array(
					'url'     => $url,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Ollama connection test failed.', array( 'error' => $response->get_error_message() ) );
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Ollama connection failed.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'Ollama connection test returned non-2xx status.',
					array( 'http_status' => $code )
				);
				return new WP_Error( 'wp_mcp_ai_api_error', __( 'Ollama returned error.', 'mcp-ai-wpoos' ), array( 'status' => $code ) );
			}

			WP_MCP_AI_Logger::log_event( 'ollama_test_connection', 'Ollama connection successful.' );

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

			WP_MCP_AI_Logger::log_event( 'ollama_list_models', 'Fetching models from Ollama.', array( 'url' => $url ) );

			$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Ollama model listing failed.', array( 'error' => $response->get_error_message() ) );
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Failed to list models.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Ollama model list response.', array( 'body' => wp_remote_retrieve_body( $response ) ) );
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON.', 'mcp-ai-wpoos' ) );
			}

			$models = array();
			if ( isset( $decoded['models'] ) && is_array( $decoded['models'] ) ) {
				foreach ( $decoded['models'] as $model ) {
					if ( isset( $model['name'] ) ) {
						$model_name = (string) $model['name'];
						$models[]   = array(
							'name'     => $model_name,
							'size'     => isset( $model['size'] ) ? $model['size'] : 0,
							'family'   => isset( $model['details']['family'] ) ? $model['details']['family'] : '',
							'is_cloud' => ( false !== strpos( $model_name, ':cloud' ) ),
						);
					}
				}
			}

			WP_MCP_AI_Logger::log_event( 'ollama_list_models', 'Ollama models retrieved.', array( 'count' => count( $models ) ) );

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

			// OpenAI-compatible loopback mode: route to /v1/chat/completions when enabled.
			// This lets every tool/skill/plugin work without any schema translation because
			// Ollama's compatibility layer already speaks the OpenAI function-calling protocol.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['ollama_use_openai_compatible_endpoint'] ) ) {
				return $this->create_openai_compat_completion( $messages, $options, $model );
			}

			$messages = $this->filter_tool_messages_for_payload( $messages );

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

			WP_MCP_AI_Logger::log_event( 'ollama_request', 'Sending request to Ollama.', array( 'model' => $model ) );

			$response = wp_remote_post( $url, $http_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Ollama request failed.', array( 'error' => $response->get_error_message() ) );
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Request failed.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
			}

			// Handle streaming vs non-streaming responses differently.
			if ( $is_streaming ) {
				return $this->handle_streaming_response( $response, $model );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$body    = wp_remote_retrieve_body( $response );
				$decoded = json_decode( $body, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					$decoded = array();
				}
				return $this->handle_api_error( $code, is_array( $decoded ) ? $decoded : array(), $response );
			}

			// Non-streaming response - decode and normalize as before.
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Ollama response.', array( 'body' => wp_remote_retrieve_body( $response ) ) );
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON.', 'mcp-ai-wpoos' ) );
			}

			WP_MCP_AI_Logger::log_event( 'ollama_response', 'Ollama request completed.' );

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
				WP_MCP_AI_Logger::log_error( 'Empty streaming response from Ollama.', array( 'model' => $model ) );
				return new WP_Error( 'wp_mcp_ai_empty_streaming_response', __( 'Empty streaming response from Ollama.', 'mcp-ai-wpoos' ) );
			}

			// Split response by newlines to get individual JSON chunks.
			$lines = explode( "\n", $body );

			$accumulated_content    = '';
			$accumulated_tool_calls = array(); // tool_calls accumulate across chunks.
			$accumulated_thinking   = '';      // reasoning/thinking channel (v0.7+).
			$final_chunk            = null;

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

				// Accumulate reasoning/thinking content if present.
				if ( isset( $chunk['message']['thinking'] ) ) {
					$accumulated_thinking .= (string) $chunk['message']['thinking'];
				}

				// Collect tool_calls from any chunk that carries them.
				// Ollama typically sends tool_calls as a complete array in a single chunk.
				if ( isset( $chunk['message']['tool_calls'] ) && is_array( $chunk['message']['tool_calls'] ) && ! empty( $chunk['message']['tool_calls'] ) ) {
					$accumulated_tool_calls = $chunk['message']['tool_calls'];
				}

				// Check if this is the final chunk.
				if ( isset( $chunk['done'] ) && $chunk['done'] ) {
					$final_chunk = $chunk;
					break; // Stop processing once we hit the done chunk.
				}
			}

			// If we didn't find a final chunk, return an error.
			if ( null === $final_chunk ) {
				WP_MCP_AI_Logger::log_error(
					'Ollama streaming response did not complete.',
					array(
						'model'       => $model,
						'accumulated' => strlen( $accumulated_content ),
					)
				);
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

			if ( ! empty( $accumulated_tool_calls ) ) {
				$normalized_response['message']['tool_calls'] = $accumulated_tool_calls;
			}

			if ( '' !== $accumulated_thinking ) {
				$normalized_response['message']['thinking'] = $accumulated_thinking;
			}

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

			// When tools are attached, preserve tool-related message fields intact so
			// the agentic loop's assistant→tool→assistant round-trip works correctly.
			$has_tools = ! empty( $options['tools'] );

			$ollama_messages = array();
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				// When using tools, preserve assistant messages with tool_calls intact.
				// The agentic loop stores tool_calls in OpenAI format (arguments as JSON
				// string) so we convert them back to Ollama's native format (object).
				if ( $has_tools && 'assistant' === $role ) {
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
					$msg = array(
						'role'    => $role,
						'content' => wp_kses_post( (string) $content ),
					);
					if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
						$msg['tool_calls'] = $this->convert_tool_calls_to_ollama( $message['tool_calls'] );
					}
					$ollama_messages[] = $msg;
					continue;
				}

				// When using tools, preserve tool-role messages with tool_call_id/name.
				if ( $has_tools && 'tool' === $role ) {
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
					$msg = array(
						'role'    => $role,
						'content' => wp_kses_post( (string) $content ),
					);
					if ( isset( $message['tool_call_id'] ) ) {
						$msg['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
					}
					if ( isset( $message['name'] ) ) {
						$msg['name'] = sanitize_text_field( $message['name'] );
					}
					$ollama_messages[] = $msg;
					continue;
				}

				// Base64-encoded images for Ollama vision models (e.g., llava).
				$images = array();

				if ( is_array( $content ) ) {
					$text_parts = array();
					foreach ( $content as $segment ) {
						if ( is_string( $segment ) ) {
							$text_parts[] = $segment;
						} elseif ( is_array( $segment ) ) {
							$seg_type = isset( $segment['type'] ) ? $segment['type'] : '';

							if ( isset( $segment['text'] ) ) {
								$text_parts[] = $segment['text'];
							} elseif ( 'input_image' === $seg_type || 'image_url' === $seg_type || 'image_file' === $seg_type ) {
								// Try to supply image data for Ollama vision models.
								$b64 = $this->get_image_base64_from_segment( $segment );
								if ( '' !== $b64 ) {
									$images[] = $b64;
								} else {
									// Fallback: include a URL reference in the text.
									$img_url = '';
									if ( ! empty( $segment['image_url']['url'] ) ) {
										$img_url = $segment['image_url']['url'];
									} elseif ( ! empty( $segment['url'] ) ) {
										$img_url = $segment['url'];
									}
									if ( '' !== $img_url ) {
										$img_name     = ! empty( $segment['file_name'] ) ? $segment['file_name'] : 'Image';
										$text_parts[] = '[' . $img_name . ': ' . esc_url_raw( $img_url ) . ']';
									}
								}
							} elseif ( 'input_file' === $seg_type || 'file' === $seg_type ) {
								// Include a reference to the file in the text.
								$file_name = '';
								if ( ! empty( $segment['display_name'] ) ) {
									$file_name = $segment['display_name'];
								} elseif ( ! empty( $segment['file_name'] ) ) {
									$file_name = $segment['file_name'];
								} elseif ( ! empty( $segment['name'] ) ) {
									$file_name = $segment['name'];
								}
								if ( '' !== $file_name ) {
									$file_name = sanitize_text_field( $file_name );
								} else {
									$file_name = 'File';
								}
								if ( ! empty( $segment['url'] ) ) {
									$text_parts[] = '[File: ' . $file_name . ' - ' . esc_url_raw( $segment['url'] ) . ']';
								} else {
									$text_parts[] = '[File: ' . $file_name . ']';
								}
							}
						}
					}
					$content = implode( "\n", $text_parts );
				}

				$content = wp_kses_post( (string) $content );
				if ( '' === trim( $content ) && 'tool' !== $role ) {
					continue;
				}

				// When tools are NOT present, convert tool messages to user messages for
				// backward compatibility (e.g., replaying saved conversations without tools).
				if ( 'tool' === $role ) {
					$tool_name = isset( $message['name'] ) ? sanitize_text_field( $message['name'] ) : 'tool';
					$content   = sprintf( '[Tool %s]: %s', $tool_name, $content );
					$role      = 'user';
				}

				$ollama_message = array(
					'role'    => $role,
					'content' => $content,
				);

				if ( ! empty( $images ) ) {
					$ollama_message['images'] = $images;
				}

				$ollama_messages[] = $ollama_message;
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

			// Additional Ollama sampling parameters (see https://github.com/ollama/ollama/blob/main/docs/modelfile.md).
			if ( isset( $options['top_k'] ) && is_numeric( $options['top_k'] ) ) {
				$payload['options']['top_k'] = (int) $options['top_k'];
			}

			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['options']['top_p'] = (float) $options['top_p'];
			}

			if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
				$payload['options']['seed'] = (int) $options['seed'];
			}

			if ( isset( $options['num_ctx'] ) && is_numeric( $options['num_ctx'] ) ) {
				$payload['options']['num_ctx'] = (int) $options['num_ctx'];
			}

			if ( isset( $options['repeat_penalty'] ) && is_numeric( $options['repeat_penalty'] ) ) {
				$payload['options']['repeat_penalty'] = (float) $options['repeat_penalty'];
			}

			if ( isset( $options['repeat_last_n'] ) && is_numeric( $options['repeat_last_n'] ) ) {
				$payload['options']['repeat_last_n'] = (int) $options['repeat_last_n'];
			}

			if ( isset( $options['tfs_z'] ) && is_numeric( $options['tfs_z'] ) ) {
				$payload['options']['tfs_z'] = (float) $options['tfs_z'];
			}

			if ( isset( $options['typical_p'] ) && is_numeric( $options['typical_p'] ) ) {
				$payload['options']['typical_p'] = (float) $options['typical_p'];
			}

			if ( isset( $options['mirostat'] ) && is_numeric( $options['mirostat'] ) ) {
				$payload['options']['mirostat'] = (int) $options['mirostat'];
			}

			if ( isset( $options['mirostat_eta'] ) && is_numeric( $options['mirostat_eta'] ) ) {
				$payload['options']['mirostat_eta'] = (float) $options['mirostat_eta'];
			}

			if ( isset( $options['mirostat_tau'] ) && is_numeric( $options['mirostat_tau'] ) ) {
				$payload['options']['mirostat_tau'] = (float) $options['mirostat_tau'];
			}

			if ( ! empty( $options['stop'] ) ) {
				$payload['options']['stop'] = is_array( $options['stop'] ) ? array_values( array_map( 'sanitize_text_field', $options['stop'] ) ) : array( sanitize_text_field( $options['stop'] ) );
			}

			if ( isset( $options['num_thread'] ) && is_numeric( $options['num_thread'] ) ) {
				$payload['options']['num_thread'] = (int) $options['num_thread'];
			}

			if ( isset( $options['num_gpu'] ) && is_numeric( $options['num_gpu'] ) ) {
				$payload['options']['num_gpu'] = (int) $options['num_gpu'];
			}

			if ( isset( $options['low_vram'] ) ) {
				$payload['options']['low_vram'] = (bool) $options['low_vram'];
			}

			// Apply resource-aware num_predict if not explicitly set.
					// Priority order:
					// 1. options['max_completion_tokens'] (if set, converted to num_predict for Ollama compatibility)
					// 2. options['max_tokens'] (if set, converted to num_predict for Ollama compatibility)
					// 3. options['num_predict'] (if set, Ollama native parameter)
					// 4. Resource manager tier-based limits (2000/8000/32000 based on workload tier).
					if ( ! isset( $options['max_tokens'] ) && ! isset( $options['num_predict'] ) && ! isset( $options['max_completion_tokens'] ) ) {
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
					} elseif ( isset( $options['max_completion_tokens'] ) ) {
						// Support max_completion_tokens (OpenAI-compatible naming).
						$payload['options']['num_predict'] = max( 512, absint( $options['max_completion_tokens'] ) );
					} elseif ( isset( $options['max_tokens'] ) ) {
						// Use max_tokens with minimum enforcement.
						$payload['options']['num_predict'] = max( 512, absint( $options['max_tokens'] ) );
					} elseif ( isset( $options['num_predict'] ) ) {
						// Use num_predict with minimum enforcement.
						$payload['options']['num_predict'] = max( 512, absint( $options['num_predict'] ) );
					}

			// Ensure num_ctx is large enough to hold the prompt plus the
			// generation budget (num_predict). Ollama defaults to num_ctx=2048
			// which is far too small for typical assistant requests with system
			// prompts and tool definitions; when the prompt exceeds the context
			// window, Ollama returns finish_reason=length with empty content,
			// surfacing as "exceeded the available token limit" in the UI.
			if ( ! isset( $payload['options']['num_ctx'] ) ) {
				$num_predict = isset( $payload['options']['num_predict'] ) ? (int) $payload['options']['num_predict'] : self::NUM_PREDICT_FALLBACK_FOR_CTX;

				// Headroom for prompt + system + tool definitions. The class
				// constant covers the vast majority of real-world chats; we
				// additionally make sure we have at least 2x the requested
				// generation budget.
				$default_num_ctx = max( self::DEFAULT_NUM_CTX, $num_predict * 2 );

				/**
				 * Filter the default Ollama num_ctx (context window size).
				 *
				 * Ollama's default is 2048 which causes prompts to overflow on
				 * almost any non-trivial chat. We default to 8192 (or 2x the
				 * generation budget, whichever is larger). Sites running large
				 * models (e.g. llama3.1 with 128K context) can raise this via
				 * the filter, while sites running on tiny VRAM may want to
				 * lower it.
				 *
				 * @since 1.1.9
				 *
				 * @param int   $default_num_ctx Default context window size.
				 * @param int   $num_predict     Resolved num_predict for this request.
				 * @param array $options         Original request options.
				 */
				$payload['options']['num_ctx'] = (int) apply_filters( 'wp_mcp_ai_ollama_default_num_ctx', $default_num_ctx, $num_predict, $options );
			}

			if ( empty( $payload['options'] ) ) {
				unset( $payload['options'] );
			}

			// Add tools if provided (Ollama native function calling, supported since v0.3).
			if ( ! empty( $options['tools'] ) ) {
				$payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );

				// tool_choice: 'auto', 'none', 'required', or a specific function object.
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

				// Ollama supports parallel_tool_calls in recent versions.
				if ( isset( $options['parallel_tool_calls'] ) ) {
					$payload['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
				}

				WP_MCP_AI_Logger::log_event(
					'ollama_tools_attached',
					'Ollama: tool definitions added to payload.',
					array(
						'model'       => $model,
						'tools_count' => count( $payload['tools'] ),
					)
				);
			}

			// Structured output: honor response_format.
			// 'json_object' → format: 'json'; 'json_schema' → format: <schema object>.
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$rf_type = isset( $options['response_format']['type'] ) ? $options['response_format']['type'] : '';
				if ( 'json_object' === $rf_type ) {
					$payload['format'] = 'json';
				} elseif ( 'json_schema' === $rf_type && isset( $options['response_format']['json_schema']['schema'] ) && is_array( $options['response_format']['json_schema']['schema'] ) ) {
					$payload['format'] = $options['response_format']['json_schema']['schema'];
				}
			} elseif ( isset( $options['format'] ) && '' !== $options['format'] ) {
				// Allow passing format directly (e.g. 'json' or a schema array).
				$payload['format'] = $options['format'];
			}

			// Think/reasoning channel (supported since Ollama v0.7 for reasoning models).
			if ( isset( $options['think'] ) ) {
				$payload['think'] = (bool) $options['think'];
			} elseif ( ! empty( $options['reasoning'] ) ) {
				$payload['think'] = true;
			}

			// keep_alive controls how long Ollama keeps the model loaded in GPU memory.
			// Use '-1' for permanent, '0' to unload immediately, or e.g. '5m' for 5 minutes.
			if ( isset( $options['keep_alive'] ) && '' !== $options['keep_alive'] ) {
				$payload['keep_alive'] = $options['keep_alive'];
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

			// Append memory documents (vector store chunks assigned to the assistant)
			// to the system string. Ollama's native API exposes a single top-level
			// `system` key, so we concatenate the chunks after the base system prompt.
			if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
				$memory_messages = $this->build_memory_messages_from_options( $options );
				if ( ! empty( $memory_messages ) ) {
					$memory_text = implode(
						"\n\n",
						array_column( $memory_messages, 'content' )
					);
					if ( ! empty( $payload['system'] ) ) {
							$payload['system'] .= "\n\n" . $memory_text;
						} else {
							$payload['system'] = $memory_text;
						}
					}
				}

				// Normalise and filter messages after system prompt injection.
				$payload['messages'] = $this->normalise_messages_for_payload( $payload['messages'] );
				$payload['messages'] = $this->filter_tool_messages_for_payload( $payload['messages'] );

				return $payload;
		}

		/**
		 * Prepare chat messages for the Ollama Chat payload.
		 *
		 * The REST layer represents text-only messages as arrays of segments so
		 * attachments and tool calls can be normalised consistently. Ollama
		 * models expect plain strings for the `content` field. To remain
		 * compatible we collapse text-only segment arrays back into strings
		 * while preserving multimodal payloads that rely on structured segments.
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
		 * Get base64-encoded image data from a message segment for Ollama vision models.
		 *
		 * Reads from the local WordPress attachment file when an attachment_id is
		 * available (fastest path), then falls back to downloading the image from
		 * the URL provided in the segment.
		 *
		 * @param array $segment {
		 *     Message segment of type input_image / image_url.
		 *
		 *     @type int    $attachment_id Optional. WordPress attachment post ID (fastest path).
		 *     @type array  $image_url     Optional. Array with 'url' key (e.g., from OpenAI-style content).
		 *     @type string $url           Optional. Direct image URL fallback.
		 *     @type string $file_name     Optional. Filename for logging context.
		 * }
		 * @return string Base64-encoded image data, or empty string on failure.
		 */
		protected function get_image_base64_from_segment( array $segment ) {
			// Prefer local file read via attachment_id (fastest, no HTTP overhead).
			if ( ! empty( $segment['attachment_id'] ) ) {
				$attachment_id = absint( $segment['attachment_id'] );
				$file_path     = get_attached_file( $attachment_id );
				if ( $file_path && file_exists( $file_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file; not a remote URL.
					$data = file_get_contents( $file_path );
					if ( false !== $data && '' !== $data ) {
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary image for Ollama API.
						return base64_encode( $data );
					}
				}
			}

			// Fall back to downloading from URL if available.
			$url = '';
			if ( ! empty( $segment['image_url']['url'] ) ) {
				$url = esc_url_raw( $segment['image_url']['url'] );
			} elseif ( ! empty( $segment['url'] ) ) {
				$url = esc_url_raw( $segment['url'] );
			}

			if ( '' === $url ) {
				return '';
			}

			$response = wp_remote_get(
				$url,
				array( 'timeout' => 30 )
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Ollama: failed to download image for vision model.',
					array(
						'url'   => $url,
						'error' => $response->get_error_message(),
					)
				);
				return '';
			}

			$body = wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				return '';
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary image for Ollama API.
			return base64_encode( $body );
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

			// Parse tool_calls from Ollama native format and convert to OpenAI format.
			// Ollama: [{function: {name: "...", arguments: {...}}}] (arguments is an object).
			// OpenAI: [{id: "...", type: "function", function: {name: "...", arguments: "..."}}] (arguments is a JSON string).
			$tool_calls = array();
			if ( isset( $response['message']['tool_calls'] ) && is_array( $response['message']['tool_calls'] ) && ! empty( $response['message']['tool_calls'] ) ) {
				foreach ( $response['message']['tool_calls'] as $index => $tc ) {
					if ( ! isset( $tc['function']['name'] ) ) {
						continue;
					}
					$arguments = isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : array();
					// Ollama returns arguments as an object; OpenAI expects a JSON-encoded string.
					if ( is_array( $arguments ) || is_object( $arguments ) ) {
						$arguments = wp_json_encode( $arguments );
					}
					if ( false === $arguments ) {
						$arguments = '{}';
					}
					$tool_calls[] = array(
						'id'       => 'call_' . $index . '_' . substr( md5( $tc['function']['name'] . $index ), 0, 8 ),
						'type'     => 'function',
						'function' => array(
							'name'      => sanitize_text_field( $tc['function']['name'] ),
							'arguments' => $arguments,
						),
					);
				}
			}

			if ( ! empty( $tool_calls ) ) {
				$message['tool_calls'] = $tool_calls;
				// Agentic loop expects finish_reason === 'tool_calls' to trigger execution.
				$finish_reason = 'tool_calls';
				// Empty content is normal when the model only calls tools.
				if ( '' === trim( $content ) ) {
					$content = '';
				}
			}

			// Industry standard: When finish_reason is 'length' with no content, provide helpful error message.
			// This happens when the prompt/conversation consumes all available tokens (num_predict limit).
			// Following OpenAI API standard: message.content field should always be present.
			if ( 'length' === $finish_reason && '' === trim( $content ) ) {
				$content = __( 'The model could not generate a response because the conversation exceeded the available token limit. Try shortening your message, starting a new conversation, or increasing the token limit in Settings → NV oOS → Orchestration → Max Tokens.', 'mcp-ai-wpoos' );
			}

			// Expose thinking/reasoning content when the model returns it (Ollama v0.7+ reasoning models).
			if ( isset( $response['message']['thinking'] ) && '' !== (string) $response['message']['thinking'] ) {
				$message['reasoning_content'] = (string) $response['message']['thinking'];
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

				// Extract cached tokens from prompt_tokens_details if available (OpenAI-compatible).
				if ( isset( $response['prompt_tokens_details']['cached_tokens'] ) ) {
					$normalized['usage']['cached_tokens'] = (int) $response['prompt_tokens_details']['cached_tokens'];
				}
			}

			return $normalized;
		}

		/**
		 * Handle a non-2xx API response and return an appropriate WP_Error.
		 *
		 * Ollama does not use API keys, so 401 authentication checks are
		 * omitted. The primary error of interest is 429 rate-limiting with
		 * an optional Retry-After header.
		 *
		 * @since 1.6.0
		 *
		 * @param int          $code     HTTP status code.
		 * @param array        $decoded  Decoded JSON response body.
		 * @param array|object $response Full WP HTTP response.
		 * @return WP_Error
		 */
		protected function handle_api_error( $code, array $decoded, $response ) {
			$error_message = isset( $decoded['error'] ) && is_string( $decoded['error'] )
				? $decoded['error']
				: __( 'Unexpected response from Ollama.', 'mcp-ai-wpoos' );
			$error_data    = array(
				'status' => $code,
				'body'   => $decoded,
			);

			$error_code = 'wp_mcp_ai_ollama_api_error';

			if ( 429 === $code ) {
				$error_code  = 'wp_mcp_ai_rate_limit_exceeded';
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after ) ) {
					$error_data['retry_after'] = absint( $retry_after );
				}
				$error_data['actions'] = array(
					'rate_limit_info' => __( 'The Ollama API rate limit has been exceeded. Try again in a few moments.', 'mcp-ai-wpoos' ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Ollama returned an error response.',
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
		 * Ollama's chat API requires tool responses to immediately follow the
		 * assistant message that emitted the corresponding tool call. When
		 * intervening messages appear between those entries the request may be
		 * rejected. This normaliser filters out any tool messages that no longer
		 * have a matching pending call so the payload remains valid.
		 *
		 * Logic mirrors WP_MCP_AI_OpenAI_Client::filter_tool_messages_for_payload().
		 *
		 * @since 1.6.0
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
								'ollama_dropped_incomplete_tool_group',
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
								'ollama_dropped_incomplete_tool_group',
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
								'ollama_dropped_orphan_tool_message',
								'Dropping tool message without matching tool call before Ollama request.',
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

			WP_MCP_AI_Logger::log_event( 'ollama_completion_request', 'Sending completion request to Ollama.', array( 'model' => $model ) );

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
				WP_MCP_AI_Logger::log_error( 'Ollama completion request failed.', array( 'error' => $response->get_error_message() ) );
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
				WP_MCP_AI_Logger::log_error( 'Failed to decode Ollama completion response.', array( 'body' => $body ) );
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON response from Ollama.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from Ollama.', 'mcp-ai-wpoos' );
				WP_MCP_AI_Logger::log_error(
					'Ollama completion request returned non-2xx status.',
					array(
						'http_status'  => $code,
						'error_detail' => $error_message,
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

				WP_MCP_AI_Logger::log_event( 'ollama_completion_response', 'Ollama completion request completed.' );

				return $normalized;
			}

			WP_MCP_AI_Logger::log_event( 'ollama_completion_response', 'Ollama completion request completed.' );

			return $decoded;
		}

		/**
		 * Convert tool_calls from OpenAI format to Ollama native format.
		 *
		 * The agentic loop stores tool_calls with arguments as a JSON-encoded string
		 * (OpenAI format). Ollama's native /api/chat expects arguments as a decoded
		 * PHP array/object. This method performs that conversion so the round-trip
		 * assistant→tool→assistant works correctly.
		 *
		 * @param array $tool_calls Tool calls in OpenAI format.
		 * @return array Tool calls in Ollama native format.
		 */
		protected function convert_tool_calls_to_ollama( array $tool_calls ) {
			$result = array();
			foreach ( $tool_calls as $tc ) {
				if ( ! is_array( $tc ) || ! isset( $tc['function']['name'] ) ) {
					continue;
				}
				$arguments = isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : array();
				// OpenAI stores arguments as a JSON string; decode to object for Ollama.
				if ( is_string( $arguments ) && '' !== $arguments ) {
					$decoded = json_decode( $arguments, true );
					if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
						$arguments = $decoded;
					} else {
						$arguments = array();
					}
				}
				$result[] = array(
					'function' => array(
						'name'      => sanitize_text_field( $tc['function']['name'] ),
						'arguments' => is_array( $arguments ) ? $arguments : array(),
					),
				);
			}
			return $result;
		}

		/**
		 * Build system messages for memory documents assigned to the assistant.
		 *
		 * Identical to the LM Studio / OpenAI client implementations — this helper
		 * is provider-agnostic and simply converts the pre-loaded `memory_documents`
		 * array (populated by the REST layer from the assistant's assigned vector
		 * store files) into an array of system-role messages, one per chunk.
		 *
		 * @since 1.2.0
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
		 * Normalize tool definitions for the Ollama /api/chat payload.
		 *
		 * Mirrors the normalise_tools_for_payload pattern used in the LM Studio client
		 * to ensure consistent tool schema handling across all local AI providers.
		 *
		 * @since 1.2.0
		 *
		 * @param mixed $tools Tool definitions from the REST layer.
		 * @return array Normalized tool array.
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

				// For type='function' tools, derive the top-level name from function.name
				// (overwriting any existing name to ensure consistency).
				// For all other types, fall through to the derivation block below.
				if ( 'function' === $type && isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
					$tool['name'] = (string) $tool['function']['name'];
				} elseif ( ! isset( $tool['name'] ) || '' === trim( (string) $tool['name'] ) ) {
					// No name yet: derive from the first available identifier.
					if ( isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
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

				// Sanitize parameter schemas to ensure Ollama-compatible format.
				if ( isset( $tool['function']['parameters'] ) && is_array( $tool['function']['parameters'] ) ) {
					$tool['function']['parameters'] = $this->sanitize_parameters_for_openai( $tool['function']['parameters'] );
				} elseif ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
					$tool['parameters'] = $this->sanitize_parameters_for_openai( $tool['parameters'] );
				}

				$normalised[] = $tool;
			}
			return array_values( $normalised );
		}

		/**
		 * Sanitize a function parameter schema to meet Ollama / OpenAI requirements.
		 *
		 * Removes composition keywords (oneOf, anyOf, allOf, not) from the root level
		 * and ensures the root type is 'object', matching the constraints applied by
		 * the OpenAI and LM Studio clients for full cross-provider parity.
		 *
		 * @param array  $schema     JSON Schema array to sanitize.
		 * @param string $parent_key Parent key (empty string signals root-level checks).
		 * @return array Sanitized schema.
		 */
		protected function sanitize_parameters_for_openai( array $schema, $parent_key = '' ) {
			if ( '' === $parent_key ) {
				$root_unsupported = array( 'oneOf', 'anyOf', 'allOf', 'not' );
				foreach ( $root_unsupported as $keyword ) {
					if ( isset( $schema[ $keyword ] ) ) {
						WP_MCP_AI_Logger::log_event(
							'ollama_schema_sanitization',
							"Removed unsupported top-level keyword: {$keyword}",
							array(
								'keyword' => $keyword,
								'context' => 'root_level',
							)
						);
						unset( $schema[ $keyword ] );
					}
				}
				if ( ! isset( $schema['type'] ) ) {
					$schema['type'] = 'object';
				}
			}

			$sanitized = array();
			foreach ( $schema as $key => $value ) {
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_parameters_for_openai( $value, $key );
				} else {
					$sanitized[ $key ] = $value;
				}
			}
			return $sanitized;
		}

		/**
		 * Fetch the capabilities of a specific model via /api/show.
		 *
		 * Ollama v0.5+ includes a 'capabilities' array in the model info response,
		 * e.g. ["completion", "tools", "vision", "thinking", "embedding"].
		 * Results are cached for 5 minutes to avoid hammering the server.
		 *
		 * @since 1.2.0
		 *
		 * @param string $model   Model name (e.g. 'llama3.1:8b').
		 * @param bool   $refresh Force a fresh API call, bypassing the cache.
		 * @return array Capabilities array (empty if unavailable or older Ollama version).
		 */
		public function get_model_capabilities( $model, $refresh = false ) {
			$endpoint_url = $this->get_endpoint_url();
			if ( empty( $endpoint_url ) || empty( $model ) ) {
				return array();
			}

			$model     = sanitize_text_field( $model );
			$cache_key = 'ollama_caps_' . md5( $endpoint_url . $model );

			if ( ! $refresh && class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
				$cached = WP_MCP_AI_Cache_Helper::get( $cache_key );
				if ( false !== $cached && is_array( $cached ) ) {
					return $cached;
				}
			}

			$url = untrailingslashit( $endpoint_url ) . '/api/show';

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'name' => $model ) ),
					'timeout' => max( 15, $this->resolve_timeout( array() ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return array();
			}

			$capabilities = isset( $decoded['capabilities'] ) && is_array( $decoded['capabilities'] ) ? $decoded['capabilities'] : array();

			if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
				WP_MCP_AI_Cache_Helper::set( $cache_key, $capabilities, 5 * MINUTE_IN_SECONDS );
			}

			return $capabilities;
		}

		/**
		 * Check whether the given model supports function/tool calling.
		 *
		 * @since 1.2.0
		 *
		 * @param string $model Model name.
		 * @return bool
		 */
		public function supports_tools( $model ) {
			return in_array( 'tools', $this->get_model_capabilities( $model ), true );
		}

		/**
		 * Check whether the given model supports vision (image inputs).
		 *
		 * @since 1.2.0
		 *
		 * @param string $model Model name.
		 * @return bool
		 */
		public function supports_vision( $model ) {
			return in_array( 'vision', $this->get_model_capabilities( $model ), true );
		}

		/**
		 * Check whether the given model supports thinking/reasoning output.
		 *
		 * @since 1.2.0
		 *
		 * @param string $model Model name.
		 * @return bool
		 */
		public function supports_thinking( $model ) {
			return in_array( 'thinking', $this->get_model_capabilities( $model ), true );
		}

		/**
		 * Create a chat completion via Ollama's OpenAI-compatible endpoint.
		 *
		 * When the 'ollama_use_openai_compatible_endpoint' setting is enabled this
		 * method is called instead of the native /api/chat path.  It sends the
		 * request to <endpoint>/v1/chat/completions using the OpenAI wire format,
		 * which means tool_calls, tool_choice, response_format, etc. all work out
		 * of the box without any schema translation.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $messages Chat messages.
		 * @param array  $options  Request options.
		 * @param string $model    Resolved model name.
		 * @return array|WP_Error
		 */
		protected function create_openai_compat_completion( array $messages, array $options, $model ) {
			$endpoint_url = $this->get_endpoint_url();
			$url          = untrailingslashit( $endpoint_url ) . '/v1/chat/completions';

			$payload = $this->build_openai_compat_payload( $messages, $options, $model );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$http_args = array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => max( 120, $this->resolve_timeout( $options ) ),
			);

			WP_MCP_AI_Logger::log_event( 'ollama_compat_request', 'Sending request to Ollama OpenAI-compatible endpoint.', array( 'model' => $model ) );

			$response = wp_remote_post( $url, $http_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Ollama OpenAI-compat request failed.', array( 'error' => $response->get_error_message() ) );
				return WP_MCP_AI_HTTP::prepare_transport_error( $response, 'wp_mcp_ai_http_error', __( 'Request failed.', 'mcp-ai-wpoos' ), __( 'Ollama', 'mcp-ai-wpoos' ) );
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid JSON from Ollama OpenAI-compatible endpoint.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_msg = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Ollama.', 'mcp-ai-wpoos' );
				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_msg,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event( 'ollama_compat_response', 'Ollama OpenAI-compatible request completed.' );

			return $this->normalize_openai_compat_response( $decoded, $model );
		}

		/**
		 * Build the payload for Ollama's OpenAI-compatible /v1/chat/completions endpoint.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $messages Chat messages.
		 * @param array  $options  Request options.
		 * @param string $model    Resolved model name.
		 * @return array|WP_Error
		 */
		protected function build_openai_compat_payload( array $messages, array $options, $model ) {
			if ( empty( $messages ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_messages', __( 'No messages provided.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			// OpenAI-compatible: system prompt is a system-role message, not a top-level key.
			$formatted = array();
			if ( ! empty( $options['system_prompt'] ) ) {
				$formatted[] = array(
					'role'    => 'system',
					'content' => wp_kses_post( (string) $options['system_prompt'] ),
				);
			}

			// Inject memory documents (vector store chunks assigned to the assistant)
			// immediately after the system-prompt message, mirroring the LM Studio client.
			if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
				$memory_messages = $this->build_memory_messages_from_options( $options );
				if ( ! empty( $memory_messages ) ) {
					$formatted = array_merge( $formatted, $memory_messages );
				}
			}

			// Messages are already in OpenAI format from the REST layer; pass them through.
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				// Flatten content arrays to strings for OpenAI compat.
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

				$msg = array(
					'role'    => $role,
					'content' => $content,
				);

				// Preserve tool_calls on assistant messages.
				if ( 'assistant' === $role && isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					$msg['tool_calls'] = $message['tool_calls'];
				}
				// Preserve tool_call_id on tool messages.
				if ( 'tool' === $role ) {
					if ( isset( $message['tool_call_id'] ) ) {
						$msg['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
					}
					if ( isset( $message['name'] ) ) {
						$msg['name'] = sanitize_text_field( $message['name'] );
					}
				}

				if ( '' === trim( $content ) && 'tool' !== $role && ! isset( $msg['tool_calls'] ) ) {
					continue;
				}

				$formatted[] = $msg;
			}

			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Support both max_tokens and max_completion_tokens naming conventions.
			if ( isset( $options['max_completion_tokens'] ) && is_numeric( $options['max_completion_tokens'] ) ) {
				$max_tokens = absint( $options['max_completion_tokens'] );
			} elseif ( isset( $options['max_tokens'] ) && is_numeric( $options['max_tokens'] ) ) {
				$max_tokens = absint( $options['max_tokens'] );
			} else {
				$max_tokens = $resource_mgr->get_max_tokens();
			}

			$is_streaming = isset( $options['stream'] ) && $options['stream'];

			$payload = array(
				'model'      => $model,
				'messages'   => $formatted,
				'stream'     => $is_streaming,
				'max_tokens' => max( 512, $max_tokens ),
			);

			// Include stream_options when streaming is enabled to receive usage data.
			if ( $is_streaming ) {
				$payload['stream_options'] = array( 'include_usage' => true );
			}

			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
				$payload['temperature'] = (float) $options['temperature'];
			}
			if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
				$payload['top_p'] = (float) $options['top_p'];
			}
			if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
				$payload['seed'] = (int) $options['seed'];
			}
			if ( isset( $options['stop'] ) && ! empty( $options['stop'] ) ) {
				$payload['stop'] = is_array( $options['stop'] ) ? array_values( array_map( 'sanitize_text_field', $options['stop'] ) ) : array( sanitize_text_field( $options['stop'] ) );
			}

			// Tools (OpenAI format — no translation needed).
			if ( ! empty( $options['tools'] ) ) {
				$payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
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
				if ( isset( $options['parallel_tool_calls'] ) ) {
					$payload['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
				}
			}

			// Structured output.
			if ( isset( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			return $payload;
		}

		/**
		 * Normalize the OpenAI-compatible endpoint response.
		 *
		 * Ollama's /v1/chat/completions response is already in OpenAI format.
		 * We only need to normalize the content field and set the provider slug.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $response Decoded API response.
		 * @param string $model    Model identifier.
		 * @return array
		 */
		protected function normalize_openai_compat_response( array $response, $model ) {
			if ( ! isset( $response['choices'] ) ) {
				$response['choices'] = array();
			}

			// Normalize content to array format if it's a plain string.
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

			$response['provider'] = 'ollama';
			if ( ! isset( $response['model'] ) ) {
				$response['model'] = $model;
			}
			return $response;
		}
	}
}
