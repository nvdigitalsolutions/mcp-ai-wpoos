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
		const USER_AGENT   = 'WP-MCP-AI-Anthropic-Client/1.0';

		/**
		 * Maximum image size in bytes (10MB).
		 * Anthropic API recommends images under 5MB for optimal performance.
		 */
		const MAX_IMAGE_SIZE_BYTES = 10485760; // 10 * 1024 * 1024.

		/**
		 * Base64 encoding overhead multiplier.
		 * Base64 encoding increases size by ~33%, so 1.37 accounts for the overhead
		 * plus some buffer for the data URL prefix.
		 */
		const BASE64_OVERHEAD_MULTIPLIER = 1.37;

		/**
		 * Maximum data URL length (calculated from MAX_IMAGE_SIZE_BYTES).
		 */
		const MAX_DATA_URL_LENGTH = 14369951; // MAX_IMAGE_SIZE_BYTES * BASE64_OVERHEAD_MULTIPLIER.

		/**
		 * Allowed image media types for Anthropic vision API.
		 */
		const ALLOWED_IMAGE_TYPES = array( 'jpeg', 'jpg', 'png', 'gif', 'webp' );

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
					__( 'No Anthropic API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_anthropic_api_key' => __( 'Add an Anthropic API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_anthropic_model',
					__( 'No Anthropic model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_anthropic_model' => __( 'Choose an Anthropic model in the NV oOS settings.', 'mcp-ai-wpoos' ),
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
					__( 'The Anthropic API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Anthropic', 'mcp-ai-wpoos' )
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Anthropic response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Anthropic API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Anthropic.', 'mcp-ai-wpoos' );

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
					__( 'No Anthropic API key has been configured.', 'mcp-ai-wpoos' ),
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
				'message' => __( 'Successfully connected to Anthropic.', 'mcp-ai-wpoos' ),
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
					__( 'No chat messages were provided for the request.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'review_request_payload' => __( 'Provide at least one user or system message before calling the API.', 'mcp-ai-wpoos' ),
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
					__( 'No valid messages found for the Anthropic request.', 'mcp-ai-wpoos' ),
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

			// Add tools if specified (Anthropic format).
			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				$tools = $this->translate_tools_for_anthropic( $options['tools'] );
				if ( ! empty( $tools ) ) {
					$payload['tools'] = $tools;
				}
			}

			return $payload;
		}

		/**
		 * Translate OpenAI-style tools to Anthropic format.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tools Array of tool definitions in OpenAI format.
		 * @return array Array of tool definitions in Anthropic format.
		 */
		protected function translate_tools_for_anthropic( array $tools ) {
			$anthropic_tools = array();

			foreach ( $tools as $tool ) {
				if ( ! isset( $tool['function'] ) || ! is_array( $tool['function'] ) ) {
					continue;
				}

				$function = $tool['function'];
				if ( ! isset( $function['name'] ) ) {
					continue;
				}

				$anthropic_tool = array(
					'name'        => sanitize_text_field( $function['name'] ),
					'description' => isset( $function['description'] ) ? sanitize_text_field( $function['description'] ) : '',
				);

				// Add input schema if parameters are provided.
				if ( isset( $function['parameters'] ) && is_array( $function['parameters'] ) ) {
					$anthropic_tool['input_schema'] = $function['parameters'];
				}

				$anthropic_tools[] = $anthropic_tool;
			}

			return $anthropic_tools;
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
		 * @return array|string Array of content blocks if images present, string otherwise.
		 */
		protected function normalize_content_for_anthropic( $content ) {
			// If it's a simple string, return it as-is.
			if ( is_string( $content ) || is_numeric( $content ) ) {
				$text = trim( wp_kses_post( (string) $content ) );
				return '' !== $text ? $text : '';
			}

			// If it's an array, check for images and convert to Anthropic content blocks.
			if ( is_array( $content ) ) {
				$content_blocks = array();
				$has_images     = false;

				foreach ( $content as $segment ) {
					// Handle string segments.
					if ( is_string( $segment ) || is_numeric( $segment ) ) {
						$text = trim( wp_kses_post( (string) $segment ) );
						if ( '' !== $text ) {
							$content_blocks[] = array(
								'type' => 'text',
								'text' => $text,
							);
						}
						continue;
					}

					// Handle array segments.
					if ( ! is_array( $segment ) ) {
						continue;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'text';

					// Handle image types.
					if ( 'image' === $type || 'image_url' === $type ) {
						$image_block = $this->build_image_content_block( $segment );
						if ( null !== $image_block ) {
							$content_blocks[] = $image_block;
							$has_images       = true;
						}
						continue;
					}

					// Handle text types.
					if ( 'input_text' === $type || 'text' === $type ) {
						$text = '';
						if ( isset( $segment['text'] ) ) {
							$text = (string) $segment['text'];
						} elseif ( isset( $segment['content'] ) ) {
							$text = (string) $segment['content'];
						}
						$text = trim( wp_kses_post( $text ) );
						if ( '' !== $text ) {
							$content_blocks[] = array(
								'type' => 'text',
								'text' => $text,
							);
						}
						continue;
					}

					// Default: try to extract text.
					if ( isset( $segment['text'] ) && '' !== $segment['text'] ) {
						$text = trim( wp_kses_post( (string) $segment['text'] ) );
						if ( '' !== $text ) {
							$content_blocks[] = array(
								'type' => 'text',
								'text' => $text,
							);
						}
					}
				}

				// If we have images, return content blocks array.
				if ( $has_images && ! empty( $content_blocks ) ) {
					return $content_blocks;
				}

				// Otherwise, extract text and join (backward compatible).
				$text_parts = array();
				foreach ( $content_blocks as $block ) {
					if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
						$text_parts[] = $block['text'];
					}
				}

				if ( ! empty( $text_parts ) ) {
					return implode( "\n\n", $text_parts );
				}
			}

			return '';
		}

		/**
		 * Build an image content block in Anthropic format.
		 *
		 * @param array $segment Image segment with image data.
		 * @return array|null Anthropic image block or null if invalid.
		 */
		protected function build_image_content_block( $segment ) {
			if ( ! is_array( $segment ) ) {
				return null;
			}

			$image_url  = '';
			$image_data = '';

			// Handle OpenAI-style image_url format.
			if ( isset( $segment['image_url'] ) ) {
				if ( is_string( $segment['image_url'] ) ) {
					$image_url = $segment['image_url'];
				} elseif ( is_array( $segment['image_url'] ) && isset( $segment['image_url']['url'] ) ) {
					$image_url = $segment['image_url']['url'];
				}
			} elseif ( isset( $segment['url'] ) ) {
				$image_url = $segment['url'];
			} elseif ( isset( $segment['data'] ) ) {
				$image_data = $segment['data'];
			}

			// Handle data: URLs.
			if ( ! empty( $image_url ) && 0 === strpos( $image_url, 'data:' ) ) {
				// Validate URL length to prevent memory exhaustion.
				if ( strlen( $image_url ) > self::MAX_DATA_URL_LENGTH ) {
					WP_MCP_AI_Logger::log_error( 'Data URL too large for image.', array( 'length' => strlen( $image_url ) ) );
					return null;
				}

				// Extract base64 data from data URL.
				$matches = array();
				if ( preg_match( '/^data:image\/(\w+);base64,(.+)$/', $image_url, $matches ) ) {
					$media_type = $matches[1];
					$image_data = $matches[2];

					// Validate media type.
					if ( in_array( strtolower( $media_type ), self::ALLOWED_IMAGE_TYPES, true ) ) {
						$media_type = $this->normalize_image_media_type( $media_type );

						return array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => 'image/' . $media_type,
								'data'       => $image_data,
							),
						);
					}
				}

				WP_MCP_AI_Logger::log_error( 'Invalid data URL format for image.', array( 'url' => substr( $image_url, 0, 100 ) ) );
				return null;
			}

			// Handle remote URLs - fetch and convert to base64.
			if ( ! empty( $image_url ) && ( 0 === strpos( $image_url, 'http://' ) || 0 === strpos( $image_url, 'https://' ) ) ) {
				$response = wp_remote_get(
					$image_url,
					array(
						'timeout'    => 30,
						'user-agent' => self::USER_AGENT,
					)
				);

				if ( is_wp_error( $response ) ) {
					WP_MCP_AI_Logger::log_error(
						'Failed to fetch remote image.',
						array(
							'url'   => $image_url,
							'error' => $response->get_error_message(),
						)
					);
					return null;
				}

				// Validate content length before retrieving body.
				$content_length = wp_remote_retrieve_header( $response, 'content-length' );
				if ( ! empty( $content_length ) && absint( $content_length ) > self::MAX_IMAGE_SIZE_BYTES ) {
					WP_MCP_AI_Logger::log_error(
						'Remote image too large.',
						array(
							'url'            => $image_url,
							'content_length' => $content_length,
						)
					);
					return null;
				}

				$body         = wp_remote_retrieve_body( $response );
				$content_type = wp_remote_retrieve_header( $response, 'content-type' );

				if ( empty( $body ) ) {
					WP_MCP_AI_Logger::log_error( 'Empty response body when fetching image.', array( 'url' => $image_url ) );
					return null;
				}

				// Validate actual body size as fallback.
				if ( strlen( $body ) > self::MAX_IMAGE_SIZE_BYTES ) {
					WP_MCP_AI_Logger::log_error(
						'Remote image body too large.',
						array(
							'url'  => $image_url,
							'size' => strlen( $body ),
						)
					);
					return null;
				}

				// Determine media type.
				$media_type = '';
				if ( ! empty( $content_type ) ) {
					// Extract main type from content-type header.
					$content_type_parts = explode( ';', $content_type );
					$main_type          = trim( $content_type_parts[0] );

					if ( 0 === strpos( $main_type, 'image/' ) ) {
						$media_type = str_replace( 'image/', '', $main_type );
					}
				}

				// Validate media type.
				if ( empty( $media_type ) || ! in_array( strtolower( $media_type ), self::ALLOWED_IMAGE_TYPES, true ) ) {
					WP_MCP_AI_Logger::log_error(
						'Unsupported image media type.',
						array(
							'url'        => $image_url,
							'media_type' => $media_type,
						)
					);
					return null;
				}

				$media_type = $this->normalize_image_media_type( $media_type );

				// Validate that encoded size won't exceed limits before encoding.
				$estimated_encoded_size = strlen( $body ) * self::BASE64_OVERHEAD_MULTIPLIER;
				if ( $estimated_encoded_size > self::MAX_DATA_URL_LENGTH ) {
					WP_MCP_AI_Logger::log_error(
						'Image size exceeds maximum allowed size after base64 encoding.',
						array(
							'url'               => $image_url,
							'body_size'         => strlen( $body ),
							'estimated_encoded' => $estimated_encoded_size,
							'max_allowed'       => self::MAX_DATA_URL_LENGTH,
						)
					);
					return null;
				}

				// Convert to base64.
				$image_data = base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

				return array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => 'image/' . $media_type,
						'data'       => $image_data,
					),
				);
			}

			// Handle raw base64 data.
			if ( ! empty( $image_data ) ) {
				$media_type = 'jpeg';
				if ( isset( $segment['media_type'] ) ) {
					$provided_type = sanitize_text_field( $segment['media_type'] );
					if ( 0 === strpos( $provided_type, 'image/' ) ) {
						$media_type = str_replace( 'image/', '', $provided_type );
					} else {
						$media_type = $provided_type;
					}
				} else {
					// Log warning when media type is assumed.
					WP_MCP_AI_Logger::log_event( 'anthropic_image_media_type_assumed', 'Media type not specified for base64 image data, assuming JPEG.' );
				}

				$media_type = $this->normalize_image_media_type( $media_type );

				// Validate media type (jpg already normalized to jpeg above).
				// Filter out 'jpg' from allowed types since it's normalized to 'jpeg'.
				$allowed_types_normalized = array_diff( self::ALLOWED_IMAGE_TYPES, array( 'jpg' ) );
				if ( in_array( $media_type, $allowed_types_normalized, true ) ) {
					return array(
						'type'   => 'image',
						'source' => array(
							'type'       => 'base64',
							'media_type' => 'image/' . $media_type,
							'data'       => $image_data,
						),
					);
				}

				WP_MCP_AI_Logger::log_error( 'Unsupported image media type for raw data.', array( 'media_type' => $media_type ) );
				return null;
			}

			WP_MCP_AI_Logger::log_error(
				'No valid image data found in segment.',
				array(
					'segment_type'   => isset( $segment['type'] ) ? $segment['type'] : 'unknown',
					'has_image_url'  => isset( $segment['image_url'] ),
					'has_url'        => isset( $segment['url'] ),
					'has_data'       => isset( $segment['data'] ),
					'has_media_type' => isset( $segment['media_type'] ),
				)
			);
			return null;
		}

		/**
		 * Normalize image media type for Anthropic API.
		 *
		 * Converts 'jpg' to 'jpeg' to match Anthropic's expected format.
		 *
		 * @param string $media_type The media type to normalize (e.g., 'jpg', 'jpeg', 'png').
		 * @return string The normalized media type.
		 */
		protected function normalize_image_media_type( $media_type ) {
			if ( 'jpg' === strtolower( $media_type ) ) {
				return 'jpeg';
			}
			return strtolower( $media_type );
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

		/**
		 * Execute a chat completion with tools and recursive tool execution.
		 *
		 * This method provides automatic tool execution in a loop for Anthropic.
		 * Note: Anthropic uses "tool use" terminology instead of "function calling".
		 *
		 * @since 1.0.0
		 *
		 * @param array $messages Array of conversation messages.
		 * @param array $tools    Array of tool definitions with executable functions.
		 * @param array $options  Optional configuration:
		 *                        - strictValidation (bool): Validate arguments before execution. Default: true.
		 *                        - maxRecursiveToolRuns (int): Maximum recursion depth. Default: 5.
		 *                        - streamFinalResponse (bool): Enable streaming (not implemented for PHP). Default: false.
		 *                        - verbose (bool): Detailed logging. Default: false.
		 *                        - autoTrimTools (bool): Context-based tool selection. Default: false.
		 *                        - maxTools (int): Max tools when trimming. Default: 10.
		 *                        - model, temperature, timeout, etc.
		 * @return array|WP_Error Final response or error.
		 * @throws Exception If tool function is not callable.
		 */
		public function run_with_tools( array $messages, array $tools = array(), array $options = array() ) {
			// Configuration options with defaults.
			$strict_validation     = isset( $options['strictValidation'] ) ? (bool) $options['strictValidation'] : true;
			$max_recursive_runs    = isset( $options['maxRecursiveToolRuns'] ) ? absint( $options['maxRecursiveToolRuns'] ) : 5;
			$stream_final_response = isset( $options['streamFinalResponse'] ) ? (bool) $options['streamFinalResponse'] : false;
			$verbose               = isset( $options['verbose'] ) ? (bool) $options['verbose'] : false;
			$auto_trim_tools       = isset( $options['autoTrimTools'] ) ? (bool) $options['autoTrimTools'] : false;

			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					'anthropic_run_with_tools_start',
					'Starting Anthropic embedded function calling.',
					array(
						'message_count'      => count( $messages ),
						'tool_count'         => count( $tools ),
						'strict_validation'  => $strict_validation,
						'max_recursive_runs' => $max_recursive_runs,
						'auto_trim_tools'    => $auto_trim_tools,
					)
				);
			}

			// Validate tools array.
			if ( empty( $tools ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_tools',
					__( 'At least one tool must be provided for embedded function calling.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Auto-trim tools if enabled.
			if ( $auto_trim_tools ) {
				$tools = $this->auto_trim_tools( $messages, $tools, $options );
				if ( $verbose ) {
					WP_MCP_AI_Logger::log_event(
						'anthropic_auto_trim_tools',
						'Automatically trimmed tools based on context.',
						array( 'remaining_tool_count' => count( $tools ) )
					);
				}
			}

			// Convert tools to Anthropic format and create tool lookup.
			$tool_definitions = array();
			$tool_functions   = array();

			foreach ( $tools as $tool ) {
				if ( ! isset( $tool['name'] ) || ! isset( $tool['function'] ) ) {
					continue;
				}

				$tool_name = sanitize_text_field( $tool['name'] );

				// Build tool definition for API.
				$definition = array(
					'name'        => $tool_name,
					'description' => isset( $tool['description'] ) ? sanitize_text_field( $tool['description'] ) : '',
				);

				if ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
					$definition['parameters'] = $tool['parameters'];
				}

				$tool_definitions[] = array(
					'type'     => 'function',
					'function' => $definition,
				);

				// Store executable function.
				$tool_functions[ $tool_name ] = $tool['function'];
			}

			// Prepare options with tools.
			$request_options          = $options;
			$request_options['tools'] = $tool_definitions;

			// Execute recursive tool calling loop.
			$conversation_messages = $messages;
			$recursion_count       = 0;

			while ( $recursion_count < $max_recursive_runs ) {
				++$recursion_count;

				if ( $verbose ) {
					WP_MCP_AI_Logger::log_event(
						'anthropic_tool_run_iteration',
						sprintf( 'Tool execution iteration %d/%d', $recursion_count, $max_recursive_runs ),
						array( 'message_count' => count( $conversation_messages ) )
					);
				}

				// Make API request.
				$response = $this->create_chat_completion( $conversation_messages, $request_options );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// Check if model wants to call any tools.
				$tool_calls = array();
				if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
					$tool_calls = $response['choices'][0]['message']['tool_calls'];
				}

				// If no tool calls, we're done.
				if ( empty( $tool_calls ) ) {
					if ( $verbose ) {
						WP_MCP_AI_Logger::log_event(
							'anthropic_run_with_tools_complete',
							'Completed without tool calls.',
							array( 'iterations' => $recursion_count )
						);
					}

					// Return final response.
					return $response;
				}

				// Add assistant's tool call message to conversation.
				$conversation_messages[] = $response['choices'][0]['message'];

				// Execute each tool call.
				foreach ( $tool_calls as $tool_call ) {
					if ( ! isset( $tool_call['function']['name'] ) ) {
						continue;
					}

					$function_name = $tool_call['function']['name'];
					$tool_call_id  = isset( $tool_call['id'] ) ? $tool_call['id'] : uniqid( 'tool-', true );

					// Check if function exists.
					if ( ! isset( $tool_functions[ $function_name ] ) ) {
						$error_message = sprintf(
							/* translators: %s: function name */
							__( 'Tool function "%s" not found.', 'mcp-ai-wpoos' ),
							$function_name
						);

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => wp_json_encode( array( 'error' => $error_message ) ),
						);

						WP_MCP_AI_Logger::log_error(
							'Anthropic tool function not found.',
							array(
								'function_name' => $function_name,
								'tool_call_id'  => $tool_call_id,
							)
						);
						continue;
					}

					// Parse arguments.
					$arguments = array();
					if ( isset( $tool_call['function']['arguments'] ) ) {
						$args_json = $tool_call['function']['arguments'];
						if ( is_string( $args_json ) ) {
							$arguments = json_decode( $args_json, true );
							if ( JSON_ERROR_NONE !== json_last_error() ) {
								$arguments = array();
							}
						} elseif ( is_array( $args_json ) ) {
							$arguments = $args_json;
						}
					}

					// Validate arguments if strict validation is enabled.
					if ( $strict_validation ) {
						$validation_error = $this->validate_tool_arguments( $function_name, $arguments, $tool_definitions );
						if ( is_wp_error( $validation_error ) ) {
							$conversation_messages[] = array(
								'role'         => 'tool',
								'tool_call_id' => $tool_call_id,
								'name'         => $function_name,
								'content'      => wp_json_encode( array( 'error' => $validation_error->get_error_message() ) ),
							);

							WP_MCP_AI_Logger::log_error(
								'Anthropic tool argument validation failed.',
								array(
									'function_name' => $function_name,
									'error'         => $validation_error->get_error_message(),
								)
							);
							continue;
						}
					}

					// Execute the tool function.
					try {
						$function_callable = $tool_functions[ $function_name ];

						if ( ! is_callable( $function_callable ) ) {
							throw new Exception( 'Tool function is not callable.' );
						}

						$result = call_user_func( $function_callable, $arguments );

						// Convert result to JSON string.
						$result_content = is_string( $result ) ? $result : wp_json_encode( $result );

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => $result_content,
						);

						if ( $verbose ) {
							WP_MCP_AI_Logger::log_event(
								'anthropic_tool_executed',
								sprintf( 'Executed tool: %s', $function_name ),
								array(
									'function_name' => $function_name,
									'tool_call_id'  => $tool_call_id,
									'result_length' => strlen( $result_content ),
								)
							);
						}
					} catch ( Exception $e ) {
						$error_message = $e->getMessage();

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => wp_json_encode( array( 'error' => $error_message ) ),
						);

						WP_MCP_AI_Logger::log_error(
							'Anthropic tool execution failed.',
							array(
								'function_name' => $function_name,
								'error'         => $error_message,
							)
						);
					}
				}
			}

			// Max recursion reached.
			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					'anthropic_max_recursion_reached',
					'Maximum recursive tool runs reached.',
					array( 'max_runs' => $max_recursive_runs )
				);
			}

			return new WP_Error(
				'wp_mcp_ai_max_tool_recursion',
				__( 'Maximum recursive tool runs reached without completion.', 'mcp-ai-wpoos' ),
				array(
					'status'         => 500,
					'max_runs'       => $max_recursive_runs,
					'final_messages' => $conversation_messages,
				)
			);
		}

		/**
		 * Validate tool arguments against the tool definition schema.
		 *
		 * @since 1.0.0
		 *
		 * @param string $function_name    Name of the function being called.
		 * @param array  $arguments        Arguments provided by the model.
		 * @param array  $tool_definitions Array of tool definitions.
		 * @return true|WP_Error True if valid, WP_Error otherwise.
		 */
		protected function validate_tool_arguments( $function_name, $arguments, $tool_definitions ) {
			// Find the tool definition.
			$tool_schema = null;
			foreach ( $tool_definitions as $tool_def ) {
				if ( isset( $tool_def['function']['name'] ) && $tool_def['function']['name'] === $function_name ) {
					$tool_schema = isset( $tool_def['function']['parameters'] ) ? $tool_def['function']['parameters'] : null;
					break;
				}
			}

			if ( null === $tool_schema ) {
				return true; // No schema to validate against.
			}

			// Check required parameters.
			if ( isset( $tool_schema['required'] ) && is_array( $tool_schema['required'] ) ) {
				foreach ( $tool_schema['required'] as $required_param ) {
					if ( ! isset( $arguments[ $required_param ] ) ) {
						return new WP_Error(
							'wp_mcp_ai_missing_required_param',
							sprintf(
								/* translators: %1$s: parameter name, %2$s: function name */
								__( 'Required parameter "%1$s" missing for tool "%2$s".', 'mcp-ai-wpoos' ),
								$required_param,
								$function_name
							),
							array( 'parameter' => $required_param )
						);
					}
				}
			}

			// Validate parameter types.
			if ( isset( $tool_schema['properties'] ) && is_array( $tool_schema['properties'] ) ) {
				foreach ( $arguments as $param_name => $param_value ) {
					if ( ! isset( $tool_schema['properties'][ $param_name ] ) ) {
						continue;
					}

					$param_schema = $tool_schema['properties'][ $param_name ];
					if ( ! isset( $param_schema['type'] ) ) {
						continue;
					}

					$expected_type = $param_schema['type'];
					$actual_type   = gettype( $param_value );

					$type_map = array(
						'boolean' => 'boolean',
						'integer' => 'number',
						'double'  => 'number',
						'string'  => 'string',
						'array'   => 'array',
						'object'  => 'object',
						'NULL'    => 'null',
					);

					$mapped_type = isset( $type_map[ $actual_type ] ) ? $type_map[ $actual_type ] : $actual_type;

					if ( 'number' === $expected_type && in_array( $mapped_type, array( 'number', 'integer' ), true ) ) {
						continue;
					}

					if ( $expected_type !== $mapped_type ) {
						return new WP_Error(
							'wp_mcp_ai_invalid_param_type',
							sprintf(
								/* translators: %1$s: parameter name, %2$s: expected type, %3$s: actual type */
								__( 'Parameter "%1$s" expected type "%2$s" but got "%3$s".', 'mcp-ai-wpoos' ),
								$param_name,
								$expected_type,
								$mapped_type
							),
							array(
								'parameter'     => $param_name,
								'expected_type' => $expected_type,
								'actual_type'   => $mapped_type,
							)
						);
					}
				}
			}

			return true;
		}

		/**
		 * Automatically trim tools based on context to reduce token usage.
		 *
		 * @since 1.0.0
		 *
		 * @param array $messages Message history.
		 * @param array $tools    Array of tool definitions.
		 * @param array $options  Request options.
		 * @return array Trimmed tools array.
		 */
		protected function auto_trim_tools( $messages, $tools, $options = array() ) {
			// Get the last user message.
			$last_user_message = '';
			for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
				if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
					$last_user_message = isset( $messages[ $i ]['content'] ) ? strtolower( (string) $messages[ $i ]['content'] ) : '';
					break;
				}
			}

			if ( empty( $last_user_message ) || empty( $tools ) ) {
				return $tools;
			}

			// Score each tool.
			$scored_tools = array();
			foreach ( $tools as $tool ) {
				$score = 0;

				if ( isset( $tool['name'] ) ) {
					$tool_name  = strtolower( str_replace( array( '-', '_' ), ' ', $tool['name'] ) );
					$name_words = explode( ' ', $tool_name );
					foreach ( $name_words as $word ) {
						if ( ! empty( $word ) && false !== strpos( $last_user_message, $word ) ) {
							$score += 3;
						}
					}
				}

				if ( isset( $tool['description'] ) ) {
					$tool_desc  = strtolower( $tool['description'] );
					$desc_words = explode( ' ', $tool_desc );
					foreach ( $desc_words as $word ) {
						if ( strlen( $word ) > 3 && false !== strpos( $last_user_message, $word ) ) {
							++$score;
						}
					}
				}

				$scored_tools[] = array(
					'tool'  => $tool,
					'score' => $score,
				);
			}

			usort(
				$scored_tools,
				function ( $a, $b ) {
					return $b['score'] - $a['score'];
				}
			);

			$max_tools     = isset( $options['maxTools'] ) ? absint( $options['maxTools'] ) : 10;
			$trimmed_tools = array();

			foreach ( array_slice( $scored_tools, 0, $max_tools ) as $scored ) {
				if ( $scored['score'] > 0 || count( $trimmed_tools ) < 3 ) {
					$trimmed_tools[] = $scored['tool'];
				}
			}

			if ( empty( $trimmed_tools ) ) {
				return $tools;
			}

			return $trimmed_tools;
		}
	}
}
