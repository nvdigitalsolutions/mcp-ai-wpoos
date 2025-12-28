<?php
/**
 * Anthropic API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
	/**
	 * Provides a wrapper around Anthropic's Messages API endpoint.
	 */
	class WP_MCP_AI_Anthropic_Client {
		const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
		const API_VERSION  = '2023-06-01';

		/**
		 * Retrieve the configured API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['anthropic_api_key'] ) ? $settings['anthropic_api_key'] : '';
		}

		/**
		 * Retrieve the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : '';
		}

		/**
		 * Perform a chat completion request against Anthropic.
		 *
		 * @param array $messages Message payload to send to Anthropic.
		 * @param array $options  Additional options (model, temperature, max_tokens, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_anthropic_api_key',
					__( 'No Anthropic API key has been configured.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_anthropic_api_key' => __( 'Add an Anthropic API key in the NV oOS settings.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_anthropic_model',
					__( 'No Anthropic model has been configured.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_anthropic_model' => __( 'Choose an Anthropic model in the NV oOS settings.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			$payload = $this->build_payload( $messages, $options );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$payload['model'] = $model;

			$request_args = array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $this->resolve_timeout( $options ),
			);

			WP_MCP_AI_Logger::log_event( 'anthropic_request', 'Sending request to Anthropic.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

			$response = wp_remote_post( self::API_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Anthropic request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Anthropic API request failed to complete.', 'wp-mcp-ai' ),
					__( 'Anthropic', 'wp-mcp-ai' )
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Anthropic response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Anthropic API returned malformed JSON.', 'wp-mcp-ai' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Anthropic.', 'wp-mcp-ai' );

				WP_MCP_AI_Logger::log_error(
					'Anthropic returned an error response.',
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

			$normalized = $this->normalize_response( $decoded );

			if ( ! isset( $normalized['model'] ) && ! empty( $model ) ) {
				$normalized['model'] = $model;
			}

			WP_MCP_AI_Logger::log_event( 'anthropic_response', 'Anthropic request completed.', array( 'response' => $normalized ) );

			return $normalized;
		}

		/**
		 * Test the connection to Anthropic API.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_anthropic_api_key',
					__( 'No Anthropic API key has been configured.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			// Send a minimal test request.
			$test_messages = array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			);

			$result = $this->create_chat_completion(
				$test_messages,
				array(
					'model'      => $this->get_model() ? $this->get_model() : 'claude-3-haiku-20240307',
					'max_tokens' => 10,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success' => true,
				'message' => __( 'Successfully connected to Anthropic.', 'wp-mcp-ai' ),
			);
		}

		/**
		 * Resolve the model identifier for the request.
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

			return 'claude-3-5-sonnet-20241022';
		}

		/**
		 * Build the request payload sent to Anthropic.
		 *
		 * @param array $messages Chat messages.
		 * @param array $options  Request options.
		 * @return array|WP_Error
		 */
		protected function build_payload( array $messages, array $options ) {
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

			$anthropic_messages = array();
			$system_parts       = array();

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				// System messages go into a separate field.
				if ( 'system' === $role ) {
					$text_parts = $this->normalize_segments_to_text( $content );
					if ( ! empty( $text_parts ) ) {
						$system_parts = array_merge( $system_parts, $text_parts );
					}
					continue;
				}

				// Skip tool messages for now (Anthropic has different tool calling format).
				if ( 'tool' === $role ) {
					continue;
				}

				// Normalize content to Anthropic format.
				$normalized_content = $this->normalize_content_for_anthropic( $content );

				if ( empty( $normalized_content ) ) {
					continue;
				}

				$anthropic_messages[] = array(
					'role'    => $role,
					'content' => $normalized_content,
				);
			}

			if ( empty( $anthropic_messages ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_valid_messages',
					__( 'No valid messages found for the Anthropic request.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$payload = array(
				'messages' => $anthropic_messages,
			);

			// Add system message if present.
			if ( ! empty( $system_parts ) ) {
				$payload['system'] = implode( "\n\n", $system_parts );
			}

			// Add system prompt from options if provided.
			if ( ! empty( $options['system_prompt'] ) ) {
				$system_prompt = wp_kses_post( $options['system_prompt'] );
				if ( ! empty( $payload['system'] ) ) {
					$payload['system'] .= "\n\n" . $system_prompt;
				} else {
					$payload['system'] = $system_prompt;
				}
			}

			// Set max_tokens (required by Anthropic).
			if ( isset( $options['max_tokens'] ) && $options['max_tokens'] > 0 ) {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			} else {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_tokens   = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens for Anthropic requests.
				 *
				 * @param int   $max_tokens The maximum tokens to use.
				 * @param array $options    Request options.
				 */
				$max_tokens = apply_filters( 'wp_mcp_ai_anthropic_max_tokens', $max_tokens, $options );

				$payload['max_tokens'] = max( 1, absint( $max_tokens ) );
			}

			// Add temperature if specified.
			if ( array_key_exists( 'temperature', $options ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$payload['temperature'] = (float) $options['temperature'];
			}

			return $payload;
		}

		/**
		 * Normalize content segments to text fragments.
		 *
		 * @param mixed $segments Message segments.
		 * @return array
		 */
		protected function normalize_segments_to_text( $segments ) {
			if ( is_string( $segments ) || is_numeric( $segments ) ) {
				$text = trim( wp_kses_post( (string) $segments ) );

				return '' === $text ? array() : array( $text );
			}

			if ( ! is_array( $segments ) ) {
				return array();
			}

			$fragments = array();

			foreach ( $segments as $segment ) {
				if ( is_string( $segment ) || is_numeric( $segment ) ) {
					$text = trim( wp_kses_post( (string) $segment ) );
					if ( '' !== $text ) {
						$fragments[] = $text;
					}
					continue;
				}

				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'text';

				switch ( $type ) {
					case 'input_text':
					case 'text':
						$text = '';
						if ( isset( $segment['text'] ) ) {
							$text = (string) $segment['text'];
						} elseif ( isset( $segment['content'] ) ) {
							$text = (string) $segment['content'];
						}
						$text = trim( wp_kses_post( $text ) );
						if ( '' !== $text ) {
							$fragments[] = $text;
						}
						break;

					default:
						if ( isset( $segment['text'] ) && '' !== $segment['text'] ) {
							$fragments[] = trim( wp_kses_post( (string) $segment['text'] ) );
						}
						break;
				}
			}

			return $fragments;
		}

		/**
		 * Normalize content for Anthropic's content format.
		 *
		 * @param mixed $content Message content.
		 * @return array|string
		 */
		protected function normalize_content_for_anthropic( $content ) {
			// If it's a simple string, return it as-is.
			if ( is_string( $content ) || is_numeric( $content ) ) {
				$text = trim( wp_kses_post( (string) $content ) );
				return '' !== $text ? $text : '';
			}

			// If it's an array, convert to Anthropic content blocks.
			if ( is_array( $content ) ) {
				$text_parts = $this->normalize_segments_to_text( $content );

				// If we have text parts, join them.
				if ( ! empty( $text_parts ) ) {
					return implode( "\n\n", $text_parts );
				}
			}

			return '';
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

			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();

			if ( isset( $options['timeout'] ) && $options['timeout'] ) {
				$timeout = max( 5, absint( $options['timeout'] ) );
			}

			return max( 5, $timeout );
		}

		/**
		 * Convert an Anthropic response to an OpenAI-style structure for downstream compatibility.
		 *
		 * @param array $response Decoded Anthropic response.
		 * @return array
		 */
		protected function normalize_response( array $response ) {
			$message    = array( 'role' => 'assistant' );
			$segments   = array();
			$tool_calls = array();

			// Extract content from Anthropic's response.
			if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
				foreach ( $response['content'] as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}

					$type = isset( $block['type'] ) ? sanitize_key( $block['type'] ) : '';

					if ( 'text' === $type && isset( $block['text'] ) ) {
						$segments[] = array(
							'type' => 'text',
							'text' => (string) $block['text'],
						);
					}

					// Handle tool use blocks if present.
					if ( 'tool_use' === $type ) {
						$tool_call = $this->convert_anthropic_tool_use_to_tool_call( $block );
						if ( $tool_call ) {
							$tool_calls[] = $tool_call;
						}
					}
				}
			}

			if ( ! empty( $segments ) ) {
				$message['content'] = $segments;
			}

			if ( ! empty( $tool_calls ) ) {
				$message['tool_calls'] = $tool_calls;
			}

			$normalized = array(
				'choices'  => array(
					array(
						'index'   => 0,
						'message' => $message,
					),
				),
				'provider' => 'anthropic',
			);

			// Add finish reason if present.
			if ( isset( $response['stop_reason'] ) ) {
				$normalized['choices'][0]['finish_reason'] = $this->normalize_stop_reason( $response['stop_reason'] );
			}

			// Add usage metadata if present.
			if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
				$usage = array();

				if ( isset( $response['usage']['input_tokens'] ) ) {
					$usage['prompt_tokens'] = (int) $response['usage']['input_tokens'];
				}

				if ( isset( $response['usage']['output_tokens'] ) ) {
					$usage['completion_tokens'] = (int) $response['usage']['output_tokens'];
				}

				if ( isset( $usage['prompt_tokens'] ) && isset( $usage['completion_tokens'] ) ) {
					$usage['total_tokens'] = $usage['prompt_tokens'] + $usage['completion_tokens'];
				}

				if ( ! empty( $usage ) ) {
					$normalized['usage'] = $usage;
				}
			}

			// Add model if present.
			if ( isset( $response['model'] ) ) {
				$normalized['model'] = sanitize_text_field( $response['model'] );
			}

			return $normalized;
		}

		/**
		 * Convert Anthropic tool_use block to OpenAI-style tool call.
		 *
		 * @param array $tool_use Anthropic tool_use block.
		 * @return array|null
		 */
		protected function convert_anthropic_tool_use_to_tool_call( array $tool_use ) {
			if ( ! isset( $tool_use['name'] ) ) {
				return null;
			}

			$name = sanitize_text_field( $tool_use['name'] );
			$id   = isset( $tool_use['id'] ) ? sanitize_text_field( $tool_use['id'] ) : 'anthropic-' . uniqid();

			$args = isset( $tool_use['input'] ) && is_array( $tool_use['input'] ) ? $tool_use['input'] : array();

			return array(
				'id'       => $id,
				'type'     => 'function',
				'function' => array(
					'name'      => $name,
					'arguments' => wp_json_encode( $args ),
				),
			);
		}

		/**
		 * Normalize Anthropic stop_reason to OpenAI finish_reason.
		 *
		 * @param string $stop_reason Anthropic stop reason.
		 * @return string
		 */
		protected function normalize_stop_reason( $stop_reason ) {
			$stop_reason = sanitize_key( $stop_reason );

			switch ( $stop_reason ) {
				case 'end_turn':
					return 'stop';
				case 'max_tokens':
					return 'length';
				case 'stop_sequence':
					return 'stop';
				case 'tool_use':
					return 'tool_calls';
				default:
					return 'stop';
			}
		}

		/**
		 * Remove large payloads from the logged request.
		 *
		 * @param array $payload Request payload.
		 * @return array
		 */
		protected function obfuscate_request_for_log( array $payload ) {
			if ( isset( $payload['messages'] ) ) {
				$payload['messages'] = '[redacted]';
			}

			if ( isset( $payload['system'] ) ) {
				$payload['system'] = '[redacted]';
			}

			return $payload;
		}
	}
}
