<?php
/**
 * Cloudflare Workers AI client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
	/**
	 * Provides a wrapper around Cloudflare Workers AI REST API endpoints.
	 */
	class WP_MCP_AI_Cloudflare_Client {

		/**
		 * Retrieve the configured API token.
		 *
		 * @return string
		 */
		public function get_api_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['cloudflare_api_token'] ) ? $settings['cloudflare_api_token'] : '';
		}

		/**
		 * Retrieve the configured account ID.
		 *
		 * @return string
		 */
		public function get_account_id() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['cloudflare_account_id'] ) ? $settings['cloudflare_account_id'] : '';
		}

		/**
		 * Retrieve the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '';
		}

		/**
		 * Test the connection to Cloudflare Workers AI.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Test with a simple API call to verify credentials.
			$url = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search',
				rawurlencode( $account_id )
			);

			$timeout = max( 30, $this->resolve_timeout( array() ) );

			$request_args = array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_test_connection',
				'Testing Cloudflare Workers AI connection.',
				array(
					'url'     => $url,
					'timeout' => $timeout,
				)
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Cloudflare Workers AI connection failed.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$body = wp_remote_retrieve_body( $response );
				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'Cloudflare Workers AI returned an error.', 'mcp-ai-wpoos' ),
					array(
						'status' => $code,
						'body'   => $body,
					)
				);
			}

			// Parse the response to get model count.
			$body        = wp_remote_retrieve_body( $response );
			$data        = json_decode( $body, true );
			$model_count = 0;

			if ( isset( $data['result'] ) && is_array( $data['result'] ) ) {
				$model_count = count( $data['result'] );
			}

			return array(
				'success'     => true,
				'message'     => __( 'Connected to Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
				'model_count' => $model_count,
			);
		}

		/**
		 * Perform a chat completion request against Cloudflare Workers AI.
		 *
		 * @param array $messages Message payload to send.
		 * @param array $options  Additional options (model, temperature, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_token  = $this->get_api_token();
			$account_id = $this->get_account_id();

			if ( empty( $api_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_api_token',
					__( 'No Cloudflare API token has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_api_token' => __( 'Add a Cloudflare API token in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $account_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_account_id',
					__( 'No Cloudflare account ID has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_account_id' => __( 'Add a Cloudflare account ID in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_cloudflare_model',
					__( 'No Cloudflare Workers AI model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_cloudflare_model' => __( 'Choose a Cloudflare Workers AI model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$payload = $this->build_payload( $messages, $options );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			// Cloudflare Workers AI expects model IDs like @cf/meta/llama-3.1-8b-instruct
			// to be part of the URL path with forward slashes intact, not URL-encoded.
			// Validate model ID format and escape properly for URL path.
			// Model IDs must start with @ and contain only alphanumeric, hyphens, dots, slashes, and underscores.
			if ( ! preg_match( '/^@[a-zA-Z0-9\/_.-]+$/', $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_model_id',
					__( 'Invalid Cloudflare model ID format.', 'mcp-ai-wpoos' ),
					array( 'model' => $model )
				);
			}

			// Only escape the @ symbol and spaces, preserve forward slashes for URL path.
			$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

			$url = sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
				rawurlencode( $account_id ),
				$escaped_model
			);

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $this->resolve_timeout( $options ),
			);

			WP_MCP_AI_Logger::log_event(
				'cloudflare_request',
				'Sending request to Cloudflare Workers AI.',
				array( 'payload' => $this->obfuscate_request_for_log( $payload ) )
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Cloudflare Workers AI request failed.', 'mcp-ai-wpoos' ),
					__( 'Cloudflare Workers AI', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				// Parse Cloudflare error response for better error messages.
				$error_message = __( 'Cloudflare Workers AI returned an error.', 'mcp-ai-wpoos' );
				$decoded_body  = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['errors'] ) && is_array( $decoded_body['errors'] ) ) {
					// Cloudflare returns errors in an array with code and message.
					foreach ( $decoded_body['errors'] as $error ) {
						if ( isset( $error['message'] ) ) {
							// Sanitize error message to prevent XSS.
							$sanitized_message = sanitize_text_field( $error['message'] );
							$error_message    .= ' ' . $sanitized_message;
							if ( isset( $error['code'] ) ) {
								// Ensure code is an integer.
								$error_code     = absint( $error['code'] );
								$error_message .= ' (Code: ' . $error_code . ')';
							}
							break; // Use the first error message.
						}
					}
				}

				WP_MCP_AI_Logger::log_error(
					'Cloudflare Workers AI returned an error.',
					array(
						'status'        => $code,
						'body'          => $body,
						'error_message' => $error_message,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $body,
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid JSON response from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			// Cloudflare Workers AI response format.
			if ( ! isset( $decoded['result'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Unexpected response format from Cloudflare Workers AI.', 'mcp-ai-wpoos' ),
					array( 'decoded' => $decoded )
				);
			}

			return $this->normalize_response( $decoded, $model );
		}

		/**
		 * Build the request payload.
		 *
		 * @param array $messages Messages array.
		 * @param array $options  Request options.
		 * @return array
		 */
		protected function build_payload( array $messages, array $options ) {
			// Normalize messages to ensure content is in the correct format.
			// Cloudflare Workers AI expects content to be a string for text-only messages.
			$normalized_messages = $this->normalize_messages( $messages );

			$payload = array(
				'messages' => $normalized_messages,
			);

			// Add optional parameters if provided.
			if ( isset( $options['temperature'] ) ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			if ( isset( $options['max_tokens'] ) ) {
				$payload['max_tokens'] = (int) $options['max_tokens'];
			}

			if ( isset( $options['stream'] ) ) {
				$payload['stream'] = (bool) $options['stream'];
			}

			return $payload;
		}

		/**
		 * Normalize messages to convert content arrays to strings.
		 *
		 * Cloudflare Workers AI expects message content to be a string for text-only messages.
		 * When content is provided as an array (OpenAI format for multimodal support),
		 * we need to convert it to a string by extracting and concatenating text parts.
		 *
		 * @param array $messages Messages array with potentially array-based content.
		 * @return array Normalized messages with string content.
		 */
		protected function normalize_messages( array $messages ) {
			$normalized = array();

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				// If content is an array, convert it to a string.
				if ( is_array( $content ) ) {
					$text_parts = array();

					foreach ( $content as $segment ) {
						// Handle simple string segments.
						if ( is_string( $segment ) ) {
							$text_parts[] = $segment;
						} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
							// Handle content part objects with 'text' property.
							$text_parts[] = $segment['text'];
						}
					}

					// Join text parts with newlines.
					$content = implode( "\n", $text_parts );
				}

				// Sanitize content and skip empty messages (except for assistant messages with tool_calls).
				$content = wp_kses_post( (string) $content );
				if ( '' === trim( $content ) && 'assistant' !== $role ) {
					continue;
				}

				// Build normalized message.
				$normalized_message = array(
					'role'    => $role,
					'content' => $content,
				);

				// Preserve tool_calls if present (for assistant messages).
				if ( isset( $message['tool_calls'] ) ) {
					$normalized_message['tool_calls'] = $message['tool_calls'];
				}

				// Preserve tool_call_id if present (for tool messages).
				if ( isset( $message['tool_call_id'] ) ) {
					$normalized_message['tool_call_id'] = $message['tool_call_id'];
				}

				// Preserve name if present (for tool messages).
				if ( isset( $message['name'] ) ) {
					$normalized_message['name'] = sanitize_text_field( $message['name'] );
				}

				$normalized[] = $normalized_message;
			}

			return $normalized;
		}

		/**
		 * Normalize the API response to a standard format.
		 *
		 * @param array  $decoded Decoded API response.
		 * @param string $model   Model name.
		 * @return array
		 */
		protected function normalize_response( array $decoded, $model ) {
			$result = isset( $decoded['result'] ) ? $decoded['result'] : array();

			// Extract content from response.
			$content = '';
			if ( isset( $result['response'] ) ) {
				$content = $result['response'];
			}

			// Extract usage data if available from Cloudflare API.
			// Cloudflare Workers AI may include usage in the response or result.
			$usage = array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
			);

			// Check if usage data is in the decoded response (top-level).
			if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
				$usage = $this->extract_usage_data( $decoded['usage'] );
			} elseif ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
				// Some endpoints may include usage within the result object.
				$usage = $this->extract_usage_data( $result['usage'] );
			}

			// If no usage data was provided by Cloudflare, estimate based on content length.
			if ( 0 === $usage['prompt_tokens'] && 0 === $usage['completion_tokens'] && 0 === $usage['total_tokens'] ) {
				$usage = $this->estimate_token_usage( $content );
			}

			// Add provider and model to usage for tracking.
			$usage['provider'] = 'cloudflare';
			$usage['model']    = $model;

			return array(
				'id'       => uniqid( 'cloudflare-', true ),
				'object'   => 'chat.completion',
				'created'  => time(),
				'model'    => $model,
				'provider' => 'cloudflare',
				'choices'  => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => $content,
						),
						'finish_reason' => 'stop',
					),
				),
				'usage'    => $usage,
			);
		}

		/**
		 * Extract usage data from Cloudflare API usage object.
		 *
		 * @param array $usage_data Raw usage data from API.
		 * @return array Normalized usage array.
		 */
		protected function extract_usage_data( array $usage_data ) {
			$prompt_tokens     = isset( $usage_data['prompt_tokens'] ) ? max( 0, (int) $usage_data['prompt_tokens'] ) : 0;
			$completion_tokens = isset( $usage_data['completion_tokens'] ) ? max( 0, (int) $usage_data['completion_tokens'] ) : 0;
			$total_tokens      = isset( $usage_data['total_tokens'] ) ? max( 0, (int) $usage_data['total_tokens'] ) : 0;

			// Calculate total if not provided.
			if ( 0 === $total_tokens && ( $prompt_tokens > 0 || $completion_tokens > 0 ) ) {
				$total_tokens = $prompt_tokens + $completion_tokens;
			}

			return array(
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
			);
		}

		/**
		 * Estimate token usage when not provided by the API.
		 *
		 * Uses a simple heuristic: ~4 characters per token (average for English text).
		 * This is an approximation and should be marked as estimated in tracking.
		 *
		 * @param string $content Response content.
		 * @return array Estimated usage array.
		 */
		protected function estimate_token_usage( $content ) {
			// Rough estimation: ~4 characters per token (standard approximation).
			$estimated_completion_tokens = max( 1, (int) ceil( strlen( $content ) / 4 ) );

			WP_MCP_AI_Logger::log_event(
				'cloudflare_estimated_usage',
				'Cloudflare response did not include usage data. Using estimation.',
				array(
					'estimated_completion_tokens' => $estimated_completion_tokens,
					'content_length'              => strlen( $content ),
				)
			);

			return array(
				'prompt_tokens'     => 0, // Cannot estimate prompt without request data.
				'completion_tokens' => $estimated_completion_tokens,
				'total_tokens'      => $estimated_completion_tokens,
			);
		}

		/**
		 * Resolve the model to use for the request.
		 *
		 * @param array $options Request options.
		 * @return string
		 */
		protected function resolve_model( array $options ) {
			if ( isset( $options['model'] ) && ! empty( $options['model'] ) ) {
				return sanitize_text_field( $options['model'] );
			}

			return $this->get_model();
		}

		/**
		 * Resolve the timeout to use for the request.
		 *
		 * @param array $options Request options.
		 * @return int
		 */
		protected function resolve_timeout( array $options ) {
			if ( isset( $options['timeout'] ) ) {
				return (int) $options['timeout'];
			}

			// Default timeout for Cloudflare Workers AI.
			return 60;
		}

		/**
		 * Obfuscate sensitive data from request for logging.
		 *
		 * @param array $payload Request payload.
		 * @return array
		 */
		protected function obfuscate_request_for_log( array $payload ) {
			$safe_payload = $payload;

			// Remove message content for privacy.
			if ( isset( $safe_payload['messages'] ) ) {
				$safe_payload['messages'] = array(
					'count' => count( $safe_payload['messages'] ),
				);
			}

			return $safe_payload;
		}
	}
}
