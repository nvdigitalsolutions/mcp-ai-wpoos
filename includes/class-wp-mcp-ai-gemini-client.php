<?php
/**
 * Gemini API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
	/**
	 * Provides a wrapper around Gemini's generateContent endpoint.
	 */
	class WP_MCP_AI_Gemini_Client {
		const API_ENDPOINT            = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
		const API_STREAM_ENDPOINT     = 'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent';
		const API_LIST_MODELS         = 'https://generativelanguage.googleapis.com/v1beta/models';
		const API_COUNT_TOKENS        = 'https://generativelanguage.googleapis.com/v1beta/models/%s:countTokens';
		const API_EMBED_CONTENT       = 'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent';
		const API_BATCH_EMBED_CONTENT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContent';

		/**
		 * Retrieve the configured API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
		}

		/**
		 * Perform a chat completion request against Gemini.
		 *
		 * @param array $messages Message payload to send to Gemini.
		 * @param array $options  Additional options (model, temperature, tools, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_model',
					__( 'No Gemini model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_model' => __( 'Choose a Gemini model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$payload = $this->build_payload( $messages, $options );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$endpoint = sprintf( self::API_ENDPOINT, rawurlencode( $model ) );
			$url = $endpoint;

			$request_args = array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $this->resolve_timeout( $options ),
			);

			WP_MCP_AI_Logger::log_event( 'gemini_request', 'Sending request to Gemini.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Gemini request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Gemini', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Gemini returned an error response.',
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

			WP_MCP_AI_Logger::log_event( 'gemini_response', 'Gemini request completed.', array( 'response' => $normalized ) );

			return $normalized;
		}

		/**
		 * Generate an image using Gemini's multimodal endpoint.
		 *
		 * @param string $prompt  Natural language prompt describing the desired image.
		 * @param array  $options Optional overrides (model, mime_type, aspect_ratio, timeout).
		 * @return array|WP_Error
		 */
		public function generate_image( $prompt, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$prompt = sanitize_textarea_field( $prompt );

			if ( '' === $prompt ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_prompt',
					__( 'A text prompt must be supplied to generate an image.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$default_model = isset( $settings['gemini_image_model'] ) && '' !== $settings['gemini_image_model'] ? sanitize_text_field( $settings['gemini_image_model'] ) : 'gemini-2.5-flash-image';
			$default_mime_type = isset( $settings['gemini_image_mime_type'] ) && '' !== $settings['gemini_image_mime_type'] ? $this->normalise_image_mime_type( $settings['gemini_image_mime_type'] ) : 'image/png';
			$default_aspect_ratio = isset( $settings['gemini_image_aspect_ratio'] ) && '' !== $settings['gemini_image_aspect_ratio'] ? $this->normalise_aspect_ratio( $settings['gemini_image_aspect_ratio'] ) : '4:3';

			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
			$mime_type = isset( $options['mime_type'] ) && '' !== $options['mime_type'] ? $this->normalise_image_mime_type( $options['mime_type'] ) : $default_mime_type;
			$aspect_ratio = isset( $options['aspect_ratio'] ) && '' !== $options['aspect_ratio'] ? $this->normalise_aspect_ratio( $options['aspect_ratio'] ) : $default_aspect_ratio;

			if ( '' === $mime_type ) {
				$mime_type = 'image/png';
			}

			if ( '' === $aspect_ratio ) {
				$aspect_ratio = '4:3';
			}

			$payload = array(
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array(
								'text' => $prompt,
							),
						),
					),
				),
			);

			$generation_config = array(
				'responseModalities' => array( 'IMAGE' ),
			);

			$image_config = array();

			// Only set aspectRatio if it's not "auto" (let AI decide).
			if ( '' !== $aspect_ratio && 'auto' !== $aspect_ratio ) {
				$image_config['aspectRatio'] = $aspect_ratio;
			}

			if ( ! empty( $image_config ) ) {
				$generation_config['imageConfig'] = $image_config;
			}

			if ( array_key_exists( 'temperature', $options ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$generation_config['temperature'] = (float) $options['temperature'];
			}

			if ( ! empty( $generation_config ) ) {
				$payload['generationConfig'] = $generation_config;
			}

			/**
			 * Allow third parties to filter the Gemini image payload prior to dispatch.
			 *
			 * @param array  $payload Prepared request payload.
			 * @param array  $options Original method options.
			 * @param string $prompt  Prompt text supplied by the caller.
			 */
			$payload = apply_filters( 'wp_mcp_ai_gemini_image_payload', $payload, $options, $prompt );

			$encoded_payload = wp_json_encode( $payload );

			if ( false === $encoded_payload ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Gemini request payload.', 'mcp-ai-wpoos' ) );
			}

			$endpoint = sprintf( self::API_ENDPOINT, rawurlencode( $model ) );
			$url = $endpoint;

			$request_args = array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'timeout' => $this->resolve_timeout( $options ),
				'body'    => $encoded_payload,
			);

			WP_MCP_AI_Logger::log_event(
				'gemini_image_request',
				'Sending image generation request to Gemini.',
				array(
					'model'        => $model,
					'mime_type'    => $mime_type,
					'aspect_ratio' => $aspect_ratio,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Gemini image request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Gemini', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini image response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Gemini returned an error response for image generation.',
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

			$image_payload = $this->extract_image_payload_from_response( $decoded, $options );

			if ( is_wp_error( $image_payload ) ) {
				return $image_payload;
			}

			$result = array(
				'image'          => $image_payload['data'],
				'mime_type'      => $image_payload['mime_type'],
				'format'         => $image_payload['format'],
				'model'          => $model,
				'prompt'         => $prompt,
				'aspect_ratio'   => $aspect_ratio,
				'created'        => time(),
				'revised_prompt' => $image_payload['revised_prompt'],
			);

			// Extract usage metadata from the response for token tracking.
			if ( isset( $decoded['usageMetadata'] ) && is_array( $decoded['usageMetadata'] ) ) {
				$usage = array();

				if ( isset( $decoded['usageMetadata']['promptTokenCount'] ) ) {
					$usage['prompt_tokens'] = (int) $decoded['usageMetadata']['promptTokenCount'];
				}

				if ( isset( $decoded['usageMetadata']['candidatesTokenCount'] ) ) {
					$usage['completion_tokens'] = (int) $decoded['usageMetadata']['candidatesTokenCount'];
				}

				if ( isset( $decoded['usageMetadata']['totalTokenCount'] ) ) {
					$usage['total_tokens'] = (int) $decoded['usageMetadata']['totalTokenCount'];
				}

				if ( ! empty( $usage ) ) {
					$result['usage'] = $usage;
				}
			}

			/**
			 * Allow third parties to filter the Gemini image result payload.
			 *
			 * @param array $result        Normalised image response payload.
			 * @param array $decoded       Raw decoded API response.
			 * @param array $options       Original method options.
			 */
			$result = apply_filters( 'wp_mcp_ai_gemini_image_result', $result, $decoded, $options );

			WP_MCP_AI_Logger::log_event(
				'gemini_image_response',
				'Gemini image generation completed.',
				array(
					'model'        => $model,
					'mime_type'    => $result['mime_type'],
					'aspect_ratio' => $aspect_ratio,
					'format'       => $result['format'],
				)
			);

			return $result;
		}

		/**
		 * Edit an image using Gemini's image editing capabilities (Nano Banana).
		 *
		 * @param string $prompt  Natural language prompt describing the desired edits.
		 * @param array  $options Optional overrides (model, source_image, mime_type, aspect_ratio, timeout).
		 * @return array|WP_Error Edited image data or error.
		 */
		public function edit_image( $prompt, array $options = array() ) {
			// Image editing uses the same endpoint as generation, but with a source image.
			// Gemini's Nano Banana processes both generation and editing.
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$prompt = sanitize_textarea_field( $prompt );

			if ( '' === $prompt ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_prompt',
					__( 'A text prompt must be supplied to edit an image.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Source image is required for editing.
			if ( empty( $options['source_image'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_source_image',
					__( 'A source image must be provided for editing.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$source_image = $options['source_image'];

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$default_model = isset( $settings['gemini_image_model'] ) && '' !== $settings['gemini_image_model'] ? sanitize_text_field( $settings['gemini_image_model'] ) : 'gemini-2.5-flash-image';
			$default_mime_type = isset( $settings['gemini_image_mime_type'] ) && '' !== $settings['gemini_image_mime_type'] ? $this->normalise_image_mime_type( $settings['gemini_image_mime_type'] ) : 'image/png';
			$default_aspect_ratio = isset( $settings['gemini_image_aspect_ratio'] ) && '' !== $settings['gemini_image_aspect_ratio'] ? $this->normalise_aspect_ratio( $settings['gemini_image_aspect_ratio'] ) : '4:3';

			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
			$mime_type = isset( $options['mime_type'] ) && '' !== $options['mime_type'] ? $this->normalise_image_mime_type( $options['mime_type'] ) : $default_mime_type;
			$aspect_ratio = isset( $options['aspect_ratio'] ) && '' !== $options['aspect_ratio'] ? $this->normalise_aspect_ratio( $options['aspect_ratio'] ) : $default_aspect_ratio;

			if ( '' === $mime_type ) {
				$mime_type = 'image/png';
			}

			if ( '' === $aspect_ratio ) {
				$aspect_ratio = '4:3';
			}

			// Build payload with source image and edit prompt.
			$payload = array(
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array(
								'text' => $prompt,
							),
							array(
								'inline_data' => array(
									'mime_type' => $source_image['mime_type'],
									'data'      => $source_image['data'],
								),
							),
						),
					),
				),
			);

			$generation_config = array(
				'responseModalities' => array( 'IMAGE' ),
			);

			$image_config = array();

			// Only set aspectRatio if it's not "auto" (let AI decide).
			if ( '' !== $aspect_ratio && 'auto' !== $aspect_ratio ) {
				$image_config['aspectRatio'] = $aspect_ratio;
			}

			if ( ! empty( $image_config ) ) {
				$generation_config['imageConfig'] = $image_config;
			}

			if ( array_key_exists( 'temperature', $options ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$generation_config['temperature'] = (float) $options['temperature'];
			}

			if ( ! empty( $generation_config ) ) {
				$payload['generationConfig'] = $generation_config;
			}

			/**
			 * Allow third parties to filter the Gemini image edit payload prior to dispatch.
			 *
			 * @param array  $payload Prepared request payload.
			 * @param array  $options Original method options.
			 * @param string $prompt  Prompt text supplied by the caller.
			 */
			$payload = apply_filters( 'wp_mcp_ai_gemini_image_edit_payload', $payload, $options, $prompt );

			$encoded_payload = wp_json_encode( $payload );

			if ( false === $encoded_payload ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Gemini request payload.', 'mcp-ai-wpoos' ) );
			}

			$endpoint = sprintf( self::API_ENDPOINT, rawurlencode( $model ) );
			$url = $endpoint;

			$request_args = array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'timeout' => $this->resolve_timeout( $options ),
				'body'    => $encoded_payload,
			);

			WP_MCP_AI_Logger::log_event(
				'gemini_image_edit_request',
				'Sending image edit request to Gemini.',
				array(
					'model'        => $model,
					'mime_type'    => $mime_type,
					'aspect_ratio' => $aspect_ratio,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Gemini image edit request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Gemini', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini image edit response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Gemini returned an error response for image editing.',
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

			$image_payload = $this->extract_image_payload_from_response( $decoded, $options );

			if ( is_wp_error( $image_payload ) ) {
				return $image_payload;
			}

			$result = array(
				'image'          => $image_payload['data'],
				'mime_type'      => $image_payload['mime_type'],
				'format'         => $image_payload['format'],
				'model'          => $model,
				'prompt'         => $prompt,
				'aspect_ratio'   => $aspect_ratio,
				'created'        => time(),
				'revised_prompt' => $image_payload['revised_prompt'],
			);

			// Extract usage metadata from the response for token tracking.
			if ( isset( $decoded['usageMetadata'] ) && is_array( $decoded['usageMetadata'] ) ) {
				$usage = array();

				if ( isset( $decoded['usageMetadata']['promptTokenCount'] ) ) {
					$usage['prompt_tokens'] = (int) $decoded['usageMetadata']['promptTokenCount'];
				}

				if ( isset( $decoded['usageMetadata']['candidatesTokenCount'] ) ) {
					$usage['completion_tokens'] = (int) $decoded['usageMetadata']['candidatesTokenCount'];
				}

				if ( isset( $decoded['usageMetadata']['totalTokenCount'] ) ) {
					$usage['total_tokens'] = (int) $decoded['usageMetadata']['totalTokenCount'];
				}

				if ( ! empty( $usage ) ) {
					$result['usage'] = $usage;
				}
			}

			/**
			 * Allow third parties to filter the Gemini image edit result payload.
			 *
			 * @param array $result        Normalised image response payload.
			 * @param array $decoded       Raw decoded API response.
			 * @param array $options       Original method options.
			 */
			$result = apply_filters( 'wp_mcp_ai_gemini_image_edit_result', $result, $decoded, $options );

			WP_MCP_AI_Logger::log_event(
				'gemini_image_edit_response',
				'Gemini image edit completed.',
				array(
					'model'        => $model,
					'mime_type'    => $result['mime_type'],
					'aspect_ratio' => $aspect_ratio,
					'format'       => $result['format'],
				)
			);

			return $result;
		}

	/**
	 * List available models from Gemini.
	 *
	 * @param array $options Optional parameters (page_size, page_token, timeout, bypass_cache).
	 * @return array|WP_Error Array containing models list or WP_Error on failure.
	 */
	public function list_models( array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		// Check if caching is enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$use_cache = ! empty( $settings['enable_gemini_api_caching'] );
		$bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
			$use_cache = false;
		}

		/**
		 * Filter whether to cache Gemini model list requests.
		 *
		 * @param bool  $use_cache Whether to use caching.
		 * @param array $options   Request options.
		 */
		$use_cache = apply_filters( 'wp_mcp_ai_cache_gemini_models', $use_cache, $options );

		if ( $use_cache && ! $bypass_cache ) {
			// For paginated results, include pagination params in cache key.
			$cache_key_parts = array( 'gemini_models_list' );
			if ( isset( $options['page_size'] ) ) {
				$cache_key_parts[] = 'ps_' . absint( $options['page_size'] );
			}
			if ( isset( $options['page_token'] ) ) {
				$cache_key_parts[] = 'pt_' . md5( sanitize_text_field( $options['page_token'] ) );
			}
			$cache_key = implode( '_', $cache_key_parts );

			// Get cache TTL from settings or use default (12 hours).
			$cache_ttl = isset( $settings['gemini_model_list_cache_ttl'] ) ? absint( $settings['gemini_model_list_cache_ttl'] ) : 12 * HOUR_IN_SECONDS;

			/**
			 * Filter the cache TTL for Gemini model list.
			 *
			 * @param int $cache_ttl Cache TTL in seconds.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_gemini_model_list_ttl', $cache_ttl );

			return WP_MCP_AI_Cache_Helper::remember(
				$cache_key,
				function () use ( $api_key, $options ) {
					return $this->fetch_models_from_api( $api_key, $options );
				},
				$cache_ttl
			);
		}

		return $this->fetch_models_from_api( $api_key, $options );
	}

	/**
	 * Fetch models from Gemini API (internal method).
	 *
	 * @param string $api_key Gemini API key.
	 * @param array  $options Optional parameters.
	 * @return array|WP_Error Array of models or WP_Error on failure.
	 */
	private function fetch_models_from_api( $api_key, $options ) {
		$url = self::API_LIST_MODELS;

		$query_args = array();

		if ( isset( $options['page_size'] ) ) {
			$query_args['pageSize'] = absint( $options['page_size'] );
		}

		if ( isset( $options['page_token'] ) ) {
			$query_args['pageToken'] = sanitize_text_field( $options['page_token'] );
		}

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		$request_args = array(
			'headers' => array(
				'x-goog-api-key' => $api_key,
			),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event( 'gemini_list_models', 'Requesting available Gemini models.' );

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Gemini list models request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
				__( 'Gemini', 'mcp-ai-wpoos' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini list models response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

			WP_MCP_AI_Logger::log_error(
				'Gemini returned an error response for list models.',
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

		WP_MCP_AI_Logger::log_event( 'gemini_list_models_response', 'Gemini models list retrieved successfully.' );

		return $decoded;
	}


	/**
	 * Count tokens for a given content payload.
	 *
	 * @param array $messages Message payload to count tokens for.
	 * @param array $options  Additional options (model, timeout, bypass_cache).
	 * @return array|WP_Error Token count data or WP_Error on failure.
	 */
	public function count_tokens( array $messages, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$model = $this->resolve_model( $options );

		if ( empty( $model ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_model',
				__( 'No Gemini model has been configured.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_gemini_model' => __( 'Choose a Gemini model in the NV oOS settings.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		// Check if caching is enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$use_cache = ! empty( $settings['enable_gemini_api_caching'] );
		$bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
			$use_cache = false;
		}

		/**
		 * Filter whether to cache Gemini token count requests.
		 *
		 * @param bool  $use_cache Whether to use caching.
		 * @param array $messages  Messages to count.
		 * @param array $options   Request options.
		 */
		$use_cache = apply_filters( 'wp_mcp_ai_cache_gemini_token_count', $use_cache, $messages, $options );

		if ( $use_cache && ! $bypass_cache ) {
			// Build cache key from messages and model.
			$cache_key_data = array(
				'messages' => $messages,
				'model'    => $model,
			);

			$cache_key = 'gemini_token_count_' . md5( wp_json_encode( $cache_key_data ) );

			// Get cache TTL from settings or use default (1 hour).
			$cache_ttl = isset( $settings['gemini_token_count_cache_ttl'] ) ? absint( $settings['gemini_token_count_cache_ttl'] ) : HOUR_IN_SECONDS;

			/**
			 * Filter the cache TTL for Gemini token count.
			 *
			 * @param int   $cache_ttl Cache TTL in seconds.
			 * @param array $options   Request options.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_gemini_token_count_ttl', $cache_ttl, $options );

			return WP_MCP_AI_Cache_Helper::remember(
				$cache_key,
				function () use ( $api_key, $messages, $options, $model ) {
					return $this->fetch_token_count_from_api( $api_key, $messages, $options, $model );
				},
				$cache_ttl
			);
		}

		return $this->fetch_token_count_from_api( $api_key, $messages, $options, $model );
	}

	/**
	 * Fetch token count from Gemini API (internal method).
	 *
	 * @param string $api_key Gemini API key.
	 * @param array  $messages Messages to count.
	 * @param array  $options Optional parameters.
	 * @param string $model   Model name.
	 * @return array|WP_Error Token count data or WP_Error on failure.
	 */
	private function fetch_token_count_from_api( $api_key, $messages, $options, $model ) {
		$payload = $this->build_payload( $messages, $options );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$endpoint = sprintf( self::API_COUNT_TOKENS, rawurlencode( $model ) );
		$url = $endpoint;

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event( 'gemini_count_tokens', 'Sending token count request to Gemini.' );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Gemini token count request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
				__( 'Gemini', 'mcp-ai-wpoos' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini token count response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

			WP_MCP_AI_Logger::log_error(
				'Gemini returned an error response for token count.',
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

		WP_MCP_AI_Logger::log_event( 'gemini_count_tokens_response', 'Gemini token count completed.' );

		return $decoded;
	}


	/**
	 * Create text embeddings for RAG/semantic search.
	 *
	 * @param string $text    Text content to embed.
	 * @param array  $options Additional options (model, task_type, title, timeout, bypass_cache).
	 * @return array|WP_Error Embedding data or WP_Error on failure.
	 */
	public function create_embedding( $text, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$text = sanitize_textarea_field( $text );

		if ( '' === $text ) {
			return new WP_Error(
				'wp_mcp_ai_missing_text',
				__( 'No text content was provided for embedding.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Check if caching is enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$use_cache = ! empty( $settings['enable_gemini_api_caching'] );
		$bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
			$use_cache = false;
		}

		/**
		 * Filter whether to cache Gemini embedding requests.
		 *
		 * @param bool   $use_cache Whether to use caching.
		 * @param string $text      Text content.
		 * @param array  $options   Request options.
		 */
		$use_cache = apply_filters( 'wp_mcp_ai_cache_gemini_embeddings', $use_cache, $text, $options );

		if ( $use_cache && ! $bypass_cache ) {
			// Build cache key from text and relevant options.
			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'text-embedding-004';
			$task_type = isset( $options['task_type'] ) && '' !== $options['task_type'] ? sanitize_text_field( $options['task_type'] ) : '';
			$title = isset( $options['title'] ) && '' !== $options['title'] ? sanitize_text_field( $options['title'] ) : '';

			$cache_key_data = array(
				'text'      => $text,
				'model'     => $model,
				'task_type' => $task_type,
				'title'     => $title,
			);

			$cache_key = 'gemini_embedding_' . md5( wp_json_encode( $cache_key_data ) );

			// Get cache TTL from settings or use default (24 hours).
			$cache_ttl = isset( $settings['gemini_embedding_cache_ttl'] ) ? absint( $settings['gemini_embedding_cache_ttl'] ) : 24 * HOUR_IN_SECONDS;

			/**
			 * Filter the cache TTL for Gemini embeddings.
			 *
			 * @param int   $cache_ttl Cache TTL in seconds.
			 * @param array $options   Request options.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_gemini_embedding_ttl', $cache_ttl, $options );

			return WP_MCP_AI_Cache_Helper::remember(
				$cache_key,
				function () use ( $api_key, $text, $options ) {
					return $this->fetch_embedding_from_api( $api_key, $text, $options );
				},
				$cache_ttl
			);
		}

		return $this->fetch_embedding_from_api( $api_key, $text, $options );
	}

	/**
	 * Fetch embedding from Gemini API (internal method).
	 *
	 * @param string $api_key Gemini API key.
	 * @param string $text    Text to embed.
	 * @param array  $options Optional parameters.
	 * @return array|WP_Error Embedding data or WP_Error on failure.
	 */
	private function fetch_embedding_from_api( $api_key, $text, $options ) {
		// Default to text-embedding-004 model for embeddings.
		$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'text-embedding-004';

		$payload = array(
			'content' => array(
				'parts' => array(
					array(
						'text' => $text,
					),
				),
			),
		);

		// Optional task type for optimized embeddings.
		if ( isset( $options['task_type'] ) && '' !== $options['task_type'] ) {
			$task_type = sanitize_text_field( $options['task_type'] );
			// Valid task types: RETRIEVAL_QUERY, RETRIEVAL_DOCUMENT, SEMANTIC_SIMILARITY, CLASSIFICATION, CLUSTERING.
			$allowed_task_types = array(
				'RETRIEVAL_QUERY',
				'RETRIEVAL_DOCUMENT',
				'SEMANTIC_SIMILARITY',
				'CLASSIFICATION',
				'CLUSTERING',
			);

			if ( in_array( $task_type, $allowed_task_types, true ) ) {
				$payload['taskType'] = $task_type;
			}
		}

		// Optional title for RETRIEVAL_DOCUMENT task type.
		if ( isset( $options['title'] ) && '' !== $options['title'] ) {
			$payload['title'] = sanitize_text_field( $options['title'] );
		}

		/**
		 * Allow third parties to filter the Gemini embedding payload prior to dispatch.
		 *
		 * @param array  $payload Prepared request payload.
		 * @param array  $options Original method options.
		 * @param string $text    Text content supplied by the caller.
		 */
		$payload = apply_filters( 'wp_mcp_ai_gemini_embedding_payload', $payload, $options, $text );

		$endpoint = sprintf( self::API_EMBED_CONTENT, rawurlencode( $model ) );
		$url = $endpoint;

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event( 'gemini_create_embedding', 'Sending embedding request to Gemini.', array( 'model' => $model ) );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Gemini embedding request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
				__( 'Gemini', 'mcp-ai-wpoos' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini embedding response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

			WP_MCP_AI_Logger::log_error(
				'Gemini returned an error response for embedding.',
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

		WP_MCP_AI_Logger::log_event( 'gemini_embedding_response', 'Gemini embedding completed.', array( 'model' => $model ) );

		return $decoded;
	}


	/**
	 * Create embeddings for multiple text inputs in batch.
	 *
	 * This method is more efficient than calling create_embedding() multiple times
	 * as it processes all texts in a single API request.
	 *
	 * @param array $texts   Array of text strings to embed.
	 * @param array $options Optional parameters:
	 *                       - model (string): Embedding model (default: 'text-embedding-004').
	 *                       - task_type (string): Task optimization type.
	 *                       - timeout (int): Request timeout in seconds.
	 *                       - bypass_cache (bool): Skip cache for this request.
	 * @return array|WP_Error Batch embedding results with 'embeddings' array or error.
	 */
	public function batch_embed_content( array $texts, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		if ( empty( $texts ) || ! is_array( $texts ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_texts',
				__( 'No text content was provided for batch embedding.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Check if caching is enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$use_cache = ! empty( $settings['enable_gemini_api_caching'] );
		$bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
			$use_cache = false;
		}

		/**
		 * Filter whether to cache Gemini batch embedding requests.
		 *
		 * @param bool  $use_cache Whether to use caching.
		 * @param array $texts     Texts to embed.
		 * @param array $options   Request options.
		 */
		$use_cache = apply_filters( 'wp_mcp_ai_cache_gemini_batch_embeddings', $use_cache, $texts, $options );

		if ( $use_cache && ! $bypass_cache ) {
			// Build cache key from texts and relevant options.
			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'text-embedding-004';
			$task_type = isset( $options['task_type'] ) && '' !== $options['task_type'] ? sanitize_text_field( $options['task_type'] ) : '';

			$cache_key_data = array(
				'texts'     => $texts,
				'model'     => $model,
				'task_type' => $task_type,
			);

			$cache_key = 'gemini_batch_embedding_' . md5( wp_json_encode( $cache_key_data ) );

			// Get cache TTL from settings or use default (24 hours - same as single embeddings).
			$cache_ttl = isset( $settings['gemini_embedding_cache_ttl'] ) ? absint( $settings['gemini_embedding_cache_ttl'] ) : 24 * HOUR_IN_SECONDS;

			/**
			 * Filter the cache TTL for Gemini batch embeddings.
			 *
			 * @param int   $cache_ttl Cache TTL in seconds.
			 * @param array $options   Request options.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_gemini_batch_embedding_ttl', $cache_ttl, $options );

			return WP_MCP_AI_Cache_Helper::remember(
				$cache_key,
				function () use ( $api_key, $texts, $options ) {
					return $this->fetch_batch_embeddings_from_api( $api_key, $texts, $options );
				},
				$cache_ttl
			);
		}

		return $this->fetch_batch_embeddings_from_api( $api_key, $texts, $options );
	}

	/**
	 * Fetch batch embeddings from Gemini API (internal method).
	 *
	 * @param string $api_key Gemini API key.
	 * @param array  $texts   Texts to embed.
	 * @param array  $options Optional parameters.
	 * @return array|WP_Error Batch embedding data or WP_Error on failure.
	 */
	private function fetch_batch_embeddings_from_api( $api_key, $texts, $options ) {
		// Default to text-embedding-004 model for embeddings.
		$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'text-embedding-004';

		// Build requests array.
		$requests = array();
		foreach ( $texts as $text ) {
			$sanitized_text = sanitize_textarea_field( $text );
			if ( '' === $sanitized_text ) {
				continue;
			}

			$request = array(
				'content' => array(
					'parts' => array(
						array( 'text' => $sanitized_text ),
					),
				),
			);

			// Optional task type for optimized embeddings.
			if ( isset( $options['task_type'] ) && '' !== $options['task_type'] ) {
				$task_type = sanitize_text_field( $options['task_type'] );
				// Valid task types: RETRIEVAL_QUERY, RETRIEVAL_DOCUMENT, SEMANTIC_SIMILARITY, CLASSIFICATION, CLUSTERING.
				$allowed_task_types = array(
					'RETRIEVAL_QUERY',
					'RETRIEVAL_DOCUMENT',
					'SEMANTIC_SIMILARITY',
					'CLASSIFICATION',
					'CLUSTERING',
				);

				if ( in_array( $task_type, $allowed_task_types, true ) ) {
					$request['taskType'] = $task_type;
				}
			}

			$requests[] = $request;
		}

		if ( empty( $requests ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_batch',
				__( 'No valid text content was provided for batch embedding after sanitization.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$payload = array( 'requests' => $requests );

		/**
		 * Allow third parties to filter the Gemini batch embedding payload prior to dispatch.
		 *
		 * @param array $payload Prepared request payload.
		 * @param array $options Original method options.
		 * @param array $texts   Text content supplied by the caller.
		 */
		$payload = apply_filters( 'wp_mcp_ai_gemini_batch_embedding_payload', $payload, $options, $texts );

		$endpoint = sprintf( self::API_BATCH_EMBED_CONTENT, rawurlencode( $model ) );
		$url = $endpoint;

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event(
			'gemini_batch_embed',
			'Sending batch embedding request to Gemini.',
			array(
				'model' => $model,
				'count' => count( $requests ),
			)
		);

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Gemini batch embedding request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
				__( 'Gemini', 'mcp-ai-wpoos' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini batch embedding response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

			WP_MCP_AI_Logger::log_error(
				'Gemini returned an error response for batch embedding.',
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

		WP_MCP_AI_Logger::log_event(
			'gemini_batch_embedding_response',
			'Gemini batch embedding completed.',
			array(
				'model' => $model,
				'count' => count( $requests ),
			)
		);

		return $decoded;
	}


		/**
		 * Perform a streaming chat completion request against Gemini.
		 *
		 * @param array    $messages Message payload to send to Gemini.
		 * @param array    $options  Additional options (model, temperature, tools, timeout).
		 * @param callable $callback Callback function to process each chunk of streaming data.
		 * @return array|WP_Error Final response summary or WP_Error on failure.
		 */
		public function stream_chat_completion( array $messages, array $options = array(), $callback = null ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_model',
					__( 'No Gemini model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_model' => __( 'Choose a Gemini model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$payload = $this->build_payload( $messages, $options );

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$endpoint = sprintf( self::API_STREAM_ENDPOINT, rawurlencode( $model ) );
			$url = add_query_arg( 'alt', 'sse', $endpoint );

			$request_args = array(
				'headers'  => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'     => wp_json_encode( $payload ),
				'timeout'  => $this->resolve_timeout( $options ),
				'stream'   => true,
				'blocking' => true,
			);

			WP_MCP_AI_Logger::log_event( 'gemini_stream_request', 'Sending streaming request to Gemini.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Gemini streaming request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Gemini API streaming request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Gemini', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				$decoded = json_decode( $body, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					$decoded = null;
				}

				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Gemini returned an error response for streaming.',
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

			// Process SSE stream response.
			$accumulated = array(
				'content'    => '',
				'tool_calls' => array(),
				'usage'      => array(),
			);

			$lines = explode( "\n", $body );

			foreach ( $lines as $line ) {
				$line = trim( $line );

				if ( '' === $line || 'data: [DONE]' === $line ) {
					continue;
				}

				if ( 0 === strpos( $line, 'data: ' ) ) {
					$json_str = substr( $line, 6 );
					$chunk = json_decode( $json_str, true );

					if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $chunk ) ) {
						continue;
					}

					// Process chunk.
					if ( isset( $chunk['candidates'] ) && is_array( $chunk['candidates'] ) ) {
						foreach ( $chunk['candidates'] as $candidate ) {
							if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
								foreach ( $candidate['content']['parts'] as $part ) {
									if ( isset( $part['text'] ) ) {
										$accumulated['content'] .= $part['text'];

										if ( is_callable( $callback ) ) {
											call_user_func( $callback, $part['text'], 'text' );
										}
									}

									if ( isset( $part['thought'] ) ) {
										// Gemini 2.0 Flash Thinking mode provides thinking text.

										if ( ! isset( $accumulated['thinking'] ) ) {
											$accumulated['thinking'] = '';
										}
										$accumulated['thinking'] .= $part['thought'];

										if ( is_callable( $callback ) ) {
											call_user_func( $callback, $part['thought'], 'thought' );
										}
									}

									if ( isset( $part['functionCall'] ) && is_array( $part['functionCall'] ) ) {
										$accumulated['tool_calls'][] = $part['functionCall'];

										if ( is_callable( $callback ) ) {
											call_user_func( $callback, $part['functionCall'], 'function_call' );
										}
									}
								}
							}
						}
					}

					// Accumulate usage metadata.
					if ( isset( $chunk['usageMetadata'] ) && is_array( $chunk['usageMetadata'] ) ) {
						$accumulated['usage'] = $chunk['usageMetadata'];
					}
				}
			}

			$normalized = array(
				'choices'  => array(
					array(
						'index'   => 0,
						'message' => array(
							'role'    => 'assistant',
							'content' => array(
								array(
									'type' => 'text',
									'text' => $accumulated['content'],
								),
							),
						),
					),
				),
				'provider' => 'gemini',
				'model'    => $model,
			);

			if ( ! empty( $accumulated['tool_calls'] ) ) {
				$normalized['choices'][0]['message']['tool_calls'] = $accumulated['tool_calls'];
			}

			if ( ! empty( $accumulated['usage'] ) ) {
				$usage = array();

				if ( isset( $accumulated['usage']['promptTokenCount'] ) ) {
					$usage['prompt_tokens'] = (int) $accumulated['usage']['promptTokenCount'];
				}

				if ( isset( $accumulated['usage']['candidatesTokenCount'] ) ) {
					$usage['completion_tokens'] = (int) $accumulated['usage']['candidatesTokenCount'];
				}

				if ( isset( $accumulated['usage']['totalTokenCount'] ) ) {
					$usage['total_tokens'] = (int) $accumulated['usage']['totalTokenCount'];
				}

				if ( ! empty( $usage ) ) {
					$normalized['usage'] = $usage;
				}
			}

			WP_MCP_AI_Logger::log_event( 'gemini_stream_response', 'Gemini streaming request completed.' );

			return $normalized;
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

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! empty( $settings['default_gemini_model'] ) ) {
				return $settings['default_gemini_model'];
			}

			return 'gemini-1.5-flash';
		}

		/**
		 * Create a geospatial query with Google Maps grounding.
		 *
		 * @param string $query   Natural language query about locations or places.
		 * @param array  $options Additional options (model, location, timeout).
		 * @return array|WP_Error Response with geospatial context token or error.
		 */
		public function create_geospatial_query( $query, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_api_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$query = sanitize_textarea_field( $query );

			if ( '' === $query ) {
				return new WP_Error(
					'wp_mcp_ai_missing_query',
					__( 'A query must be supplied for geospatial search.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$model = $this->resolve_model( $options );

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_model',
					__( 'No Gemini model has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_model' => __( 'Choose a Gemini model in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Build the payload with Google Maps grounding.
			$payload = array(
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array(
								'text' => $query,
							),
						),
					),
				),
				'tools'    => array(
					array(
						'google_search_retrieval' => array(
							'dynamic_retrieval_config' => array(
								'mode'              => 'MODE_DYNAMIC',
								'dynamic_threshold' => 0.3,
							),
						),
					),
					array(
						'google_maps' => array(
							'enabled' => true,
						),
					),
				),
			);

			// Add optional location context for better results.
			if ( isset( $options['location'] ) && is_array( $options['location'] ) ) {
				if ( isset( $options['location']['latitude'] ) && isset( $options['location']['longitude'] ) ) {
					$payload['location_context'] = array(
						'latitude'  => floatval( $options['location']['latitude'] ),
						'longitude' => floatval( $options['location']['longitude'] ),
					);
				}
			}

			// Add generation config if temperature is specified.
			if ( isset( $options['temperature'] ) ) {
				$payload['generationConfig'] = array(
					'temperature' => floatval( $options['temperature'] ),
				);
			}

			/**
			 * Allow third parties to filter the Gemini geospatial payload prior to dispatch.
			 *
			 * @param array  $payload Prepared request payload.
			 * @param array  $options Original method options.
			 * @param string $query   Query text supplied by the caller.
			 */
			$payload = apply_filters( 'wp_mcp_ai_gemini_geospatial_payload', $payload, $options, $query );

			$endpoint = sprintf( self::API_ENDPOINT, rawurlencode( $model ) );
			$url = $endpoint;

			$request_args = array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $this->resolve_timeout( $options ),
			);

			WP_MCP_AI_Logger::log_event(
				'gemini_geospatial_request',
				'Sending geospatial query request to Gemini with Google Maps grounding.',
				array(
					'query' => $query,
					'model' => $model,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Gemini geospatial request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Gemini API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Gemini', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini geospatial response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Gemini returned an error response for geospatial query.',
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

			// Extract Google Maps context token if available.
			if ( isset( $decoded['candidates'][0]['googleMapsWidgetContextToken'] ) ) {
				$normalized['google_maps_context_token'] = $decoded['candidates'][0]['googleMapsWidgetContextToken'];
			}

			if ( ! isset( $normalized['model'] ) && ! empty( $model ) ) {
				$normalized['model'] = $model;
			}

			WP_MCP_AI_Logger::log_event(
				'gemini_geospatial_response',
				'Gemini geospatial query completed.',
				array(
					'has_context_token' => isset( $normalized['google_maps_context_token'] ),
				)
			);

			return $normalized;
		}

		/**
		 * Build the request payload sent to Gemini.
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

			$contents = array();
			$system_fragments = array();
			$pending_tool_calls = array();
			$latest_assistant_index = null;

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : array();

				if ( 'system' === $role ) {
					$system_fragments = array_merge( $system_fragments, $this->normalize_segments_to_text( $content ) );
					continue;
				}

				$text_segments = $this->normalize_segments_to_text( $content );

				if ( empty( $text_segments ) ) {
					$text_segments = array();
				}

				$gemini_role = 'user';
				if ( 'assistant' === $role ) {
					$pending_tool_calls = array();
					$latest_assistant_index = null;
					$gemini_role = 'model';
				}

				if ( 'user' === $role ) {
					$pending_tool_calls = array();
					$latest_assistant_index = null;
				}

				$parts = array();
				$message_tool_call_ids = array();

				if ( 'assistant' === $role && ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					foreach ( $message['tool_calls'] as $tool_call ) {
						$function_call = $this->convert_tool_call_to_function_call( $tool_call );

						if ( $function_call ) {
							$parts[] = array( 'functionCall' => $function_call );

							if ( isset( $tool_call['id'] ) ) {
								$tool_call_id = sanitize_text_field( $tool_call['id'] );
								if ( '' !== $tool_call_id ) {
									$message_tool_call_ids[] = $tool_call_id;
								}
							}
						}
					}
				}

				if ( 'tool' === $role ) {
					$gemini_role = 'user';
					$tool_call_id = isset( $message['tool_call_id'] ) ? sanitize_text_field( $message['tool_call_id'] ) : '';

					if ( '' === $tool_call_id || ! isset( $pending_tool_calls[ $tool_call_id ] ) ) {
						continue;
					}

					$last_index = count( $contents ) - 1;
					$origin_index = $pending_tool_calls[ $tool_call_id ];

					if ( $last_index < 0 || null === $latest_assistant_index ) {
						unset( $pending_tool_calls[ $tool_call_id ] );
						continue;
					}

					if ( $origin_index !== $latest_assistant_index || $origin_index > $last_index ) {
						unset( $pending_tool_calls[ $tool_call_id ] );
						continue;
					}

					$function_response = $this->convert_tool_message_to_function_response( $message );

					if ( $function_response ) {
						$parts[]       = array( 'functionResponse' => $function_response );
						$text_segments = array();
						unset( $pending_tool_calls[ $tool_call_id ] );
					} else {
						unset( $pending_tool_calls[ $tool_call_id ] );
						continue;
					}
				}

				// Add text segments as text parts.
				foreach ( $text_segments as $segment_text ) {
					$parts[] = array( 'text' => $segment_text );
				}

				// Add file parts (e.g., videos uploaded to Gemini File API).
				if ( 'user' === $role || 'assistant' === $role ) {
					$file_parts = $this->extract_file_parts( $content );
					foreach ( $file_parts as $file_part ) {
						$parts[] = $file_part;
					}
				}

				if ( empty( $parts ) ) {
					continue;
				}

				if ( ! empty( $message_tool_call_ids ) ) {
					$next_index = count( $contents );

					foreach ( $message_tool_call_ids as $tool_call_id ) {
						$pending_tool_calls[ $tool_call_id ] = $next_index;
					}

					$latest_assistant_index = $next_index;
				} elseif ( 'assistant' === $role ) {
					$latest_assistant_index = null;
				}

				$contents[] = array(
					'role'  => $gemini_role,
					'parts' => $parts,
				);

				if ( 'assistant' === $role && null === $latest_assistant_index ) {
					$latest_assistant_index = count( $contents ) - 1;
				}
			}

			if ( ! empty( $options['system_prompt'] ) ) {
				$system_fragments[] = wp_kses_post( $options['system_prompt'] );
			}

			if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
				$system_fragments = array_merge( $system_fragments, $this->build_memory_fragments( $options['memory_documents'] ) );
			}

			$payload = array(
				'contents' => array_values( $contents ),
			);

			if ( ! empty( $system_fragments ) ) {
				$system_parts = array();
				foreach ( $system_fragments as $fragment ) {
					$fragment = trim( $fragment );
					if ( '' === $fragment ) {
						continue;
					}
					$system_parts[] = array( 'text' => $fragment );
				}

				if ( ! empty( $system_parts ) ) {
					$payload['system_instruction'] = array( 'parts' => $system_parts );
				}
			}

			if ( ! isset( $payload['generationConfig'] ) ) {
				$payload['generationConfig'] = array();
			}

			if ( array_key_exists( 'temperature', $options ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
				$payload['generationConfig']['temperature'] = (float) $options['temperature'];
			}

			// Apply resource-aware max_output_tokens if not explicitly set.
			if ( ! isset( $options['max_tokens'] ) && ! isset( $options['max_output_tokens'] ) ) {
				$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
				$max_output_tokens = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum output tokens for Gemini requests.
				 *
				 * @param int   $max_output_tokens The maximum output tokens to use.
				 * @param array $options           Request options.
				 */
				$max_output_tokens = apply_filters( 'wp_mcp_ai_gemini_max_output_tokens', $max_output_tokens, $options );

				if ( $max_output_tokens > 0 ) {
					$payload['generationConfig']['maxOutputTokens'] = $max_output_tokens;
				}
			} elseif ( isset( $options['max_tokens'] ) ) {
				$payload['generationConfig']['maxOutputTokens'] = absint( $options['max_tokens'] );
			} elseif ( isset( $options['max_output_tokens'] ) ) {
				$payload['generationConfig']['maxOutputTokens'] = absint( $options['max_output_tokens'] );
			}

			// Add support for JSON schema responses.
			if ( isset( $options['response_mime_type'] ) && '' !== $options['response_mime_type'] ) {
				$payload['generationConfig']['responseMimeType'] = sanitize_text_field( $options['response_mime_type'] );
			}

			if ( isset( $options['response_schema'] ) && is_array( $options['response_schema'] ) ) {
				$payload['generationConfig']['responseSchema'] = $options['response_schema'];
			}

			if ( isset( $options['response_json_schema'] ) && is_array( $options['response_json_schema'] ) ) {
				$payload['generationConfig']['responseJsonSchema'] = $options['response_json_schema'];
			}

			if ( empty( $payload['generationConfig'] ) ) {
				unset( $payload['generationConfig'] );
			}

			// Add support for safety settings configuration.
			if ( isset( $options['safety_settings'] ) && is_array( $options['safety_settings'] ) ) {
				$safety_settings = array();

				$allowed_categories = array(
					'HARM_CATEGORY_HARASSMENT',
					'HARM_CATEGORY_HATE_SPEECH',
					'HARM_CATEGORY_SEXUALLY_EXPLICIT',
					'HARM_CATEGORY_DANGEROUS_CONTENT',
				);

				$allowed_thresholds = array(
					'BLOCK_NONE',
					'BLOCK_ONLY_HIGH',
					'BLOCK_MEDIUM_AND_ABOVE',
					'BLOCK_LOW_AND_ABOVE',
					'HARM_BLOCK_THRESHOLD_UNSPECIFIED',
				);

				foreach ( $options['safety_settings'] as $category => $threshold ) {
					// Support both array format and direct category => threshold mapping.
					if ( is_array( $threshold ) ) {
						$cat_value = isset( $threshold['category'] ) ? sanitize_text_field( $threshold['category'] ) : $category;
						$threshold_value = isset( $threshold['threshold'] ) ? sanitize_text_field( $threshold['threshold'] ) : 'BLOCK_MEDIUM_AND_ABOVE';
					} else {
						$cat_value = sanitize_text_field( $category );
						$threshold_value = sanitize_text_field( $threshold );
					}

					if ( in_array( $cat_value, $allowed_categories, true ) && in_array( $threshold_value, $allowed_thresholds, true ) ) {
						$safety_settings[] = array(
							'category'  => $cat_value,
							'threshold' => $threshold_value,
						);
					}
				}

				if ( ! empty( $safety_settings ) ) {
					$payload['safetySettings'] = $safety_settings;
				}
			}

			if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
				$translated_tools = $this->translate_tools( $options['tools'] );
				if ( ! empty( $translated_tools ) ) {
					$payload['tools'] = $translated_tools;
				}
			}

			return $payload;
		}

		/**
		 * Normalise segments to text fragments.
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

					case 'input_image':
						$label = __( '[Image attachment]', 'mcp-ai-wpoos' );
						if ( isset( $segment['caption'] ) && '' !== $segment['caption'] ) {
							$label = '[Image: ' . sanitize_text_field( $segment['caption'] ) . ']';
						} elseif ( isset( $segment['image_url']['url'] ) && '' !== $segment['image_url']['url'] ) {
							$label = '[Image: ' . esc_url_raw( $segment['image_url']['url'] ) . ']';
						} elseif ( isset( $segment['url'] ) && '' !== $segment['url'] ) {
							$name = isset( $segment['name'] ) ? sanitize_text_field( $segment['name'] ) : 'Image';
							$label = '[' . $name . ': ' . esc_url_raw( $segment['url'] ) . ']';
						}
						$fragments[] = $label;
						break;

					case 'input_file':
						$label = __( '[File attachment]', 'mcp-ai-wpoos' );

						// Prefer display_name, fallback to name, or use 'File'.
						$name = 'File';
						if ( isset( $segment['display_name'] ) && '' !== $segment['display_name'] ) {
							$name = sanitize_text_field( $segment['display_name'] );
						} elseif ( isset( $segment['name'] ) && '' !== $segment['name'] ) {
							$name = sanitize_text_field( $segment['name'] );
						}

						// If URL is available, show it with the name.
						if ( isset( $segment['url'] ) && '' !== $segment['url'] ) {
							$label = '[' . $name . ': ' . esc_url_raw( $segment['url'] ) . ']';
						} else {
							$label = '[File: ' . $name . ']';
						}

						$fragments[] = $label;
						break;

					case 'tool_result':
						$result_text = $this->render_tool_result_text( $segment );
						if ( '' !== $result_text ) {
							$fragments[] = $result_text;
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
		 * Extract file parts from message content.
		 *
		 * Extracts file data (e.g., video files uploaded to Gemini File API)
		 * from message content segments and formats them for Gemini API.
		 *
		 * @param mixed $content Message content (string or array of segments).
		 * @return array Array of file parts suitable for Gemini API.
		 */
		protected function extract_file_parts( $content ) {
			if ( is_string( $content ) || is_numeric( $content ) ) {
				return array();
			}

			if ( ! is_array( $content ) ) {
				return array();
			}

			$file_parts = array();

			foreach ( $content as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'text';

				// Handle file segments.
				if ( 'file' === $type || 'input_file' === $type ) {
					$file_part = $this->format_file_part( $segment );
					if ( null !== $file_part ) {
						$file_parts[] = $file_part;
					}
				}
			}

			return $file_parts;
		}

		/**
		 * Format a file segment as a Gemini fileData part.
		 *
		 * @param array $segment File segment with fileUri and mimeType.
		 * @return array|null Gemini fileData part or null if invalid.
		 */
		protected function format_file_part( $segment ) {
			// Extract file URI (could be 'file_uri', 'fileUri', or 'uri').
			$file_uri = '';
			if ( isset( $segment['file_uri'] ) && '' !== $segment['file_uri'] ) {
				$file_uri = esc_url_raw( $segment['file_uri'] );
			} elseif ( isset( $segment['fileUri'] ) && '' !== $segment['fileUri'] ) {
				$file_uri = esc_url_raw( $segment['fileUri'] );
			} elseif ( isset( $segment['uri'] ) && '' !== $segment['uri'] ) {
				$file_uri = esc_url_raw( $segment['uri'] );
			}

			if ( empty( $file_uri ) ) {
				return null;
			}

			// Extract MIME type (could be 'mime_type' or 'mimeType').
			$mime_type = '';
			if ( isset( $segment['mime_type'] ) && '' !== $segment['mime_type'] ) {
				$mime_type = sanitize_mime_type( $segment['mime_type'] );
			} elseif ( isset( $segment['mimeType'] ) && '' !== $segment['mimeType'] ) {
				$mime_type = sanitize_mime_type( $segment['mimeType'] );
			}

			if ( empty( $mime_type ) ) {
				return null;
			}

			// Build fileData part for Gemini API.
			return array(
				'fileData' => array(
					'fileUri'  => $file_uri,
					'mimeType' => $mime_type,
				),
			);
		}

		/**
		 * Convert tool result segment into plain text.
		 *
		 * @param array $segment Tool result segment.
		 * @return string
		 */
		protected function render_tool_result_text( array $segment ) {
			if ( isset( $segment['output'] ) ) {
				if ( is_string( $segment['output'] ) ) {
					return $segment['output'];
				} elseif ( is_array( $segment['output'] ) ) {
					$parts = $this->normalize_segments_to_text( $segment['output'] );
					if ( ! empty( $parts ) ) {
						return implode( "\n\n", $parts );
					}
				} elseif ( is_object( $segment['output'] ) ) {
					return wp_json_encode( $segment['output'] );
				}
			}

			if ( isset( $segment['content'] ) ) {
				$parts = $this->normalize_segments_to_text( $segment['content'] );
				if ( ! empty( $parts ) ) {
					return implode( "\n\n", $parts );
				}
			}

			return '';
		}

		/**
		 * Convert an OpenAI-style tool call into a Gemini functionCall part.
		 *
		 * @param array $tool_call Tool call payload.
		 * @return array|null
		 */
		protected function convert_tool_call_to_function_call( array $tool_call ) {
			if ( empty( $tool_call ) || ! is_array( $tool_call ) ) {
				return null;
			}

			$type = isset( $tool_call['type'] ) ? sanitize_key( $tool_call['type'] ) : 'function';

			if ( 'function' !== $type ) {
				return null;
			}

			$function = array();
			if ( isset( $tool_call['function'] ) && is_array( $tool_call['function'] ) ) {
				$function = $tool_call['function'];
			}

			$name = isset( $function['name'] ) ? sanitize_text_field( $function['name'] ) : '';

			if ( '' === $name ) {
				return null;
			}

			$args = array();

			if ( isset( $function['arguments'] ) ) {
				$args = $this->normalise_tool_arguments( $function['arguments'] );
			} elseif ( isset( $tool_call['arguments'] ) ) {
				$args = $this->normalise_tool_arguments( $tool_call['arguments'] );
			}

			return array(
				'name' => $name,
				'args' => $args,
			);
		}

		/**
		 * Normalise tool arguments into an array suitable for Gemini.
		 *
		 * @param mixed $arguments Raw arguments payload.
		 * @return array
		 */
		protected function normalise_tool_arguments( $arguments ) {
			if ( is_array( $arguments ) ) {
				return $arguments;
			}

			if ( is_object( $arguments ) ) {
				return (array) $arguments;
			}

			if ( is_string( $arguments ) || is_numeric( $arguments ) ) {
				$decoded = json_decode( (string) $arguments, true );

				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					return $decoded;
				}

				$text = sanitize_textarea_field( (string) $arguments );

				if ( '' !== $text ) {
					return array( 'raw' => $text );
				}
			}

			return array();
		}

		/**
		 * Convert a tool result message into a Gemini functionResponse part.
		 *
		 * @param array $message Tool message payload.
		 * @return array|null
		 */
		protected function convert_tool_message_to_function_response( array $message ) {
			$name = isset( $message['name'] ) ? sanitize_text_field( $message['name'] ) : '';

			if ( '' === $name ) {
				return null;
			}

			$response = array();

			if ( isset( $message['content'] ) ) {
				$response = $this->normalise_tool_response_content( $message['content'] );
			}

			if ( empty( $response ) && isset( $message['output'] ) ) {
				$response = $this->normalise_tool_response_content( $message['output'] );
			}

			if ( ! is_array( $response ) ) {
				$response = array();
			}

			if ( isset( $message['tool_call_id'] ) ) {
				$response['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
			}

			return array(
				'name'     => $name,
				'response' => $response,
			);
		}

		/**
		 * Normalise tool response content into an array representation.
		 *
		 * @param mixed $content Tool content payload.
		 * @return array
		 */
		protected function normalise_tool_response_content( $content ) {
			if ( is_array( $content ) ) {
				if ( wp_is_numeric_array( $content ) ) {
					$text = implode( "\n\n", $this->normalize_segments_to_text( $content ) );

					return $this->decode_tool_text_to_response( $text );
				}

				return $content;
			}

			if ( is_object( $content ) ) {
				return (array) $content;
			}

			if ( is_string( $content ) || is_numeric( $content ) ) {
				return $this->decode_tool_text_to_response( (string) $content );
			}

			return array();
		}

		/**
		 * Attempt to decode tool text content into a structured response array.
		 *
		 * @param string $text Tool output as text.
		 * @return array
		 */
		protected function decode_tool_text_to_response( $text ) {
			$text = trim( (string) $text );

			if ( '' === $text ) {
				return array();
			}

			$decoded = json_decode( $text, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}

			return array( 'output' => sanitize_textarea_field( $text ) );
		}

		/**
		 * Translate OpenAI-style tool definitions into Gemini declarations.
		 *
		 * @param array $tools Tool definitions.
		 * @return array
		 */
		protected function translate_tools( array $tools ) {
			$declarations = array();

			foreach ( $tools as $tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}

				$type = isset( $tool['type'] ) ? sanitize_key( $tool['type'] ) : '';
				if ( 'function' !== $type ) {
					continue;
				}

				if ( empty( $tool['function'] ) || ! is_array( $tool['function'] ) ) {
					continue;
				}

				$function = $tool['function'];
				$name = isset( $function['name'] ) ? sanitize_text_field( $function['name'] ) : '';

				if ( '' === $name ) {
					continue;
				}

				$declaration = array( 'name' => $name );

				if ( ! empty( $function['description'] ) ) {
					$declaration['description'] = sanitize_text_field( $function['description'] );
				}

				if ( isset( $function['parameters'] ) && is_array( $function['parameters'] ) ) {
					// Sanitize parameters to remove fields not supported by Gemini API.
					$declaration['parameters'] = $this->sanitize_parameters_for_gemini( $function['parameters'] );
				}

				$declarations[] = $declaration;
			}

			if ( empty( $declarations ) ) {
				return array();
			}

			return array(
				array(
					'functionDeclarations' => $declarations,
				),
			);
		}

		/**
		 * Normalize malformed property values in a properties object.
		 *
		 * Ensures that all values in a 'properties' object are proper Schema objects
		 * (arrays with at least a 'type' field), not scalar values. This prevents
		 * Gemini API errors like: "Invalid value... expecting Schema object but got string".
		 *
		 * @since 1.0.0
		 * @param array $properties The properties object to normalize.
		 * @return array Normalized properties object.
		 */
		protected function normalize_property_schemas( array $properties ) {
			$normalized = array();

			foreach ( $properties as $prop_name => $prop_value ) {
				// If a property value is a scalar (string/number/bool) instead of a schema object,.

				// convert it to a proper schema object with that value as the type.
				if ( ! is_array( $prop_value ) && ! is_object( $prop_value ) ) {
					// Assume the scalar value is meant to be the type.
					$normalized[ $prop_name ] = array(
						'type' => is_string( $prop_value ) ? $prop_value : 'string',
					);

					WP_MCP_AI_Logger::log_event(
						'gemini_schema_fix',
						'Converted scalar property value to schema object',
						array(
							'property'       => $prop_name,
							'original_value' => $prop_value,
							'converted_to'   => $normalized[ $prop_name ],
						)
					);
				} else {
					$normalized[ $prop_name ] = $prop_value;
				}
			}

			return $normalized;
		}

		/**
		 * Sanitize JSON Schema parameters for Gemini API compatibility.
		 *
		 * Gemini API uses a restricted subset of OpenAPI 3.0 Schema Object and does NOT support:
		 * - additionalProperties: Not supported at any level
		 * - type as array: Union types like ['string', 'array'] must be converted to single type
		 * - default: Default values for parameters
		 * - examples: Example values array
		 * - const: Constant value constraints
		 * - nullable: Nullable type indicator (use type array instead, which we convert)
		 * - $ref, $schema, $id: JSON Schema meta-keywords
		 * - oneOf, anyOf, allOf: Schema composition keywords (we extract the first option)
		 * - format: Format validators (limited/no support in function declarations)
		 *
		 * This method recursively strips these unsupported schema keywords while preserving
		 * property names that may match keyword names (e.g., a parameter named 'format').
		 *
		 * For schema composition keywords (oneOf, anyOf, allOf), we extract the first schema
		 * option and merge it into the parent schema to preserve type information.
		 *
		 * @param array  $schema     JSON Schema object to sanitize.
		 * @param string $parent_key The parent key to determine context (internal use).
		 * @return array Sanitized schema compatible with Gemini API.
		 */
		protected function sanitize_parameters_for_gemini( array $schema, $parent_key = '' ) {
			$sanitized = array();

			// List of unsupported keywords to filter out.
			$unsupported_keywords = array(
				'additionalProperties',
				'default',
				'examples',
				'const',
				'nullable',
				'$ref',
				'$schema',
				'$id',
				'format',
			);

			// Handle oneOf, anyOf, allOf - schema composition keywords.
			// Extract the first schema option and merge it into the current schema.
			$composition_keywords = array( 'oneOf', 'anyOf', 'allOf' );
			foreach ( $composition_keywords as $comp_key ) {
				if ( isset( $schema[ $comp_key ] ) && is_array( $schema[ $comp_key ] ) && ! empty( $schema[ $comp_key ] ) ) {
					// Get the first schema from the composition array.
					$first_option = $schema[ $comp_key ][0];
					if ( is_array( $first_option ) ) {
						// Merge the first option into the current schema.
						// This allows properties from the first option to be used.
						foreach ( $first_option as $opt_key => $opt_value ) {
							// Don't override existing keys in the schema.
							if ( ! isset( $schema[ $opt_key ] ) ) {
								$schema[ $opt_key ] = $opt_value;
							}
						}

						// Log the composition keyword handling for debugging.
						WP_MCP_AI_Logger::log_event(
							'gemini_schema_composition',
							"Converted {$comp_key} to first option in schema",
							array(
								'composition_keyword' => $comp_key,
								'parent_key'          => $parent_key,
								'first_option_type'   => isset( $first_option['type'] ) ? $first_option['type'] : 'unknown',
								'options_count'       => count( $schema[ $comp_key ] ),
							)
						);
					}
					// Remove the composition keyword after extracting its first option.
					unset( $schema[ $comp_key ] );
				}
			}

			foreach ( $schema as $key => $value ) {
				// IMPORTANT: Only filter schema keywords, not property names.
				// When parent_key is 'properties', the keys are parameter names, not schema keywords.
				// Example: A parameter named 'format' should be preserved, but a 'format' keyword should be removed.
				if ( 'properties' !== $parent_key && in_array( $key, $unsupported_keywords, true ) ) {
					continue;
				}

				// Handle 'type' field - convert arrays to single type.
				if ( 'type' === $key && is_array( $value ) ) {
					// For union types, use the first type (most specific).
					// E.g., ['string', 'array'] becomes 'string'.
					$sanitized[ $key ] = is_string( $value[0] ) ? $value[0] : 'string';
					continue;
				}

				// Handle 'enum' field - ensure it's not recursively processed as a nested schema.
				// Enum values should be preserved as-is (array of scalars).
				if ( 'enum' === $key && is_array( $value ) ) {
					$sanitized[ $key ] = $value;
					continue;
				}

				// Handle 'required' field - preserve as-is (array of property names).
				if ( 'required' === $key && is_array( $value ) ) {
					$sanitized[ $key ] = $value;
					continue;
				}

				// Recursively sanitize nested objects and arrays.
				// Pass the current key as parent_key to track context.
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_parameters_for_gemini( $value, $key );
				} else {
					$sanitized[ $key ] = $value;
				}
			}

			// Critical fix: Normalize property values to ensure they are schema objects, not scalars.
			// This prevents Gemini API errors where scalars are sent instead of Schema objects.
			if ( isset( $sanitized['properties'] ) && is_array( $sanitized['properties'] ) ) {
				$sanitized['properties'] = $this->normalize_property_schemas( $sanitized['properties'] );
			}

			// Enhancement: Ensure property schemas have a 'type' field.
			// If we're processing a property definition (parent_key is a property name from 'properties'),.

			// or an items definition (parent_key is 'items'), and it lacks a 'type' field,.

			// infer an appropriate type based on schema structure.
			if ( 'properties' === $parent_key ) {
				foreach ( $sanitized as $prop_name => $prop_schema ) {
					if ( is_array( $prop_schema ) && ! isset( $prop_schema['type'] ) ) {
						// Determine the appropriate type based on schema structure.
						$inferred_type = 'string'; // Default fallback.
						$reason = 'default';

						if ( isset( $prop_schema['items'] ) ) {
							// If 'items' is present, this should be an array type.
							$inferred_type = 'array';
							$reason = 'has_items';
						} elseif ( isset( $prop_schema['properties'] ) ) {
							// If 'properties' is present, this should be an object type.
							$inferred_type = 'object';
							$reason = 'has_properties';
						}

						$sanitized[ $prop_name ]['type'] = $inferred_type;

						WP_MCP_AI_Logger::log_event(
							'gemini_schema_enhancement',
							'Added missing type field to property schema',
							array(
								'property'      => $prop_name,
								'inferred_type' => $inferred_type,
								'reason'        => $reason,
							)
						);
					}
				}
			} elseif ( 'items' === $parent_key && is_array( $sanitized ) && ! isset( $sanitized['type'] ) ) {
				// Handle the items schema itself when it's missing a type.
				$inferred_type = 'string'; // Default fallback.
				$reason = 'default';

				if ( isset( $sanitized['items'] ) ) {
					// If 'items' is present, this should be an array type.
					$inferred_type = 'array';
					$reason = 'has_items';
				} elseif ( isset( $sanitized['properties'] ) ) {
					// If 'properties' is present, this should be an object type.
					$inferred_type = 'object';
					$reason = 'has_properties';
				}

				$sanitized['type'] = $inferred_type;

				WP_MCP_AI_Logger::log_event(
					'gemini_schema_enhancement',
					'Added missing type field to items schema',
					array(
						'inferred_type' => $inferred_type,
						'reason'        => $reason,
					)
				);
			}

			return $sanitized;
		}

		/**
		 * Build memory document fragments used in the system instruction.
		 *
		 * @param array $documents Memory documents.
		 * @return array
		 */
		protected function build_memory_fragments( array $documents ) {
			$fragments = array();

			foreach ( $documents as $document ) {
				if ( empty( $document['chunks'] ) || ! is_array( $document['chunks'] ) ) {
					continue;
				}

				$title = isset( $document['title'] ) && '' !== $document['title'] ? sanitize_text_field( $document['title'] ) : __( 'Document', 'mcp-ai-wpoos' );

				$chunks = array_values( array_filter( array_map( 'strval', $document['chunks'] ) ) );

				$parts = count( $chunks );
				$part_index = 0;

				foreach ( $chunks as $chunk ) {
					++$part_index;

					$label = $title;
					if ( $parts > 1 ) {
						/* translators: %1$s: document title, %2$d: chunk number. */
						$label = sprintf( __( '%1$s (Part %2$d)', 'mcp-ai-wpoos' ), $title, $part_index );
					}

					/* translators: %1$s: document title, %2$s: extracted text snippet. */
					$fragments[] = sprintf( __( 'Reference document "%1$s": %2$s', 'mcp-ai-wpoos' ), $label, $chunk );
				}
			}

			return $fragments;
		}

		/**
		 * Extract the first inline or downloadable image from the Gemini response payload.
		 *
		 * @param array $response Decoded API response.
		 * @param array $options  Original request options.
		 * @return array|WP_Error
		 */
		protected function extract_image_payload_from_response( array $response, array $options ) {
			if ( empty( $response['candidates'] ) || ! is_array( $response['candidates'] ) ) {
				WP_MCP_AI_Logger::log_error( 'Gemini image response missing candidates.', array( 'response' => $response ) );

				return new WP_Error( 'wp_mcp_ai_image_empty', __( 'Gemini returned an empty image response.', 'mcp-ai-wpoos' ) );
			}

			foreach ( $response['candidates'] as $candidate ) {
				$revised_prompt = $this->extract_revised_prompt_from_candidate( $candidate, $response );

				if ( empty( $candidate['content']['parts'] ) || ! is_array( $candidate['content']['parts'] ) ) {
					continue;
				}

				foreach ( $candidate['content']['parts'] as $part ) {
					if ( isset( $part['inlineData'] ) && is_array( $part['inlineData'] ) ) {
						$inline = $part['inlineData'];
						$data = isset( $inline['data'] ) ? $inline['data'] : '';
						$mime_type = isset( $inline['mimeType'] ) ? $this->normalise_image_mime_type( $inline['mimeType'] ) : '';

						if ( '' === $data ) {
							continue;
						}

						$decoded_data = $this->decode_inline_image_data( $data );

						if ( is_wp_error( $decoded_data ) ) {
							return $decoded_data;
						}

						if ( '' === $mime_type ) {
							$mime_type = 'image/png';
						}

						return array(
							'data'           => $decoded_data,
							'mime_type'      => $mime_type,
							'format'         => $this->map_mime_type_to_format( $mime_type ),
							'revised_prompt' => $revised_prompt,
						);
					}

					if ( isset( $part['fileData'] ) && is_array( $part['fileData'] ) ) {
						$file_data = $part['fileData'];
						$file_uri = isset( $file_data['fileUri'] ) ? esc_url_raw( $file_data['fileUri'] ) : '';

						if ( '' === $file_uri ) {
							continue;
						}

						$download = $this->download_image_from_url( $file_uri, $options );

						if ( is_wp_error( $download ) ) {
							return $download;
						}

						$mime_type = isset( $file_data['mimeType'] ) ? $this->normalise_image_mime_type( $file_data['mimeType'] ) : '';

						if ( '' === $mime_type && ! empty( $download['content_type'] ) ) {
							$mime_type = $this->normalise_image_mime_type( $download['content_type'] );
						}

						if ( '' === $mime_type ) {
							$mime_type = 'image/png';
						}

						return array(
							'data'           => $download['body'],
							'mime_type'      => $mime_type,
							'format'         => $this->map_mime_type_to_format( $mime_type ),
							'revised_prompt' => $revised_prompt,
						);
					}
				}
			}

			WP_MCP_AI_Logger::log_error( 'Gemini image response missing supported payload keys.', array( 'response' => $response ) );

			return new WP_Error( 'wp_mcp_ai_image_empty', __( 'Gemini returned an empty image response.', 'mcp-ai-wpoos' ) );
		}

		/**
		 * Extract any revised prompt text that Gemini returned alongside an image payload.
		 *
		 * @param array $candidate Individual candidate payload from Gemini.
		 * @param array $response  Full decoded Gemini response payload.
		 * @return string
		 */
		protected function extract_revised_prompt_from_candidate( array $candidate, array $response ) {
			$fragments = array();

			if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
				foreach ( $candidate['content']['parts'] as $part ) {
					if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$text = sanitize_textarea_field( $part['text'] );

						if ( '' !== $text ) {
							$fragments[] = $text;
						}
					}
				}
			}

			if ( empty( $fragments ) && isset( $response['promptFeedback'] ) && is_array( $response['promptFeedback'] ) ) {
				if ( isset( $response['promptFeedback']['blockReasonMessage'] ) ) {
					$text = sanitize_textarea_field( $response['promptFeedback']['blockReasonMessage'] );

					if ( '' !== $text ) {
						$fragments[] = $text;
					}
				}

				if ( isset( $response['promptFeedback']['safetyFeedback'] ) && is_array( $response['promptFeedback']['safetyFeedback'] ) ) {
					foreach ( $response['promptFeedback']['safetyFeedback'] as $feedback ) {
						if ( isset( $feedback['description'] ) ) {
							$text = sanitize_textarea_field( $feedback['description'] );

							if ( '' !== $text ) {
								$fragments[] = $text;
							}
						}
					}
				}
			}

			$fragments = array_values( array_unique( array_filter( $fragments, 'strlen' ) ) );

			if ( empty( $fragments ) ) {
				return '';
			}

			return implode( "\n\n", $fragments );
		}

		/**
		 * Normalise supported image MIME types returned by Gemini.
		 *
		 * @param string $mime_type Raw MIME type value.
		 * @return string
		 */
		protected function normalise_image_mime_type( $mime_type ) {
			$mime_type = sanitize_mime_type( (string) $mime_type );

			$allowed = array( 'image/png', 'image/jpeg', 'image/jpg', 'image/webp' );

			if ( in_array( $mime_type, $allowed, true ) ) {
				if ( 'image/jpg' === $mime_type ) {
					return 'image/jpeg';
				}

				return $mime_type;
			}

			return '';
		}

		/**
		 * Normalise an aspect ratio string.
		 *
		 * @param string $aspect_ratio Raw aspect ratio input.
		 * @return string
		 */
		protected function normalise_aspect_ratio( $aspect_ratio ) {
			$aspect_ratio = strtolower( (string) $aspect_ratio );
			$aspect_ratio = str_replace( ' ', '', $aspect_ratio );

			// Special case: "auto" means let the AI decide (no aspectRatio sent to API).
			if ( 'auto' === $aspect_ratio ) {
				return 'auto';
			}

			$aspect_ratio = strtoupper( $aspect_ratio );

			if ( preg_match( '/^(\d+):(\d+)$/', $aspect_ratio, $matches ) ) {
				$left = ltrim( $matches[1], '0' );
				$right = ltrim( $matches[2], '0' );

				if ( '' === $left ) {
					$left = '0';
				}

				if ( '' === $right ) {
					$right = '0';
				}

				return $left . ':' . $right;
			}

			$allowed = array( '1:1', '2:3', '3:2', '3:4', '4:3', '9:16', '16:9', '21:9' );

			if ( in_array( $aspect_ratio, $allowed, true ) ) {
				return $aspect_ratio;
			}

			return '';
		}

		/**
		 * Map a MIME type to a common file extension identifier.
		 *
		 * @param string $mime_type MIME type string.
		 * @return string
		 */
		protected function map_mime_type_to_format( $mime_type ) {
			switch ( $mime_type ) {
				case 'image/jpeg':
					return 'jpeg';
				case 'image/webp':
					return 'webp';
				case 'image/png':
				default:
					return 'png';
			}
		}

		/**
		 * Download an image from a remote Gemini file URI.
		 *
		 * @param string $url     Remote file URL.
		 * @param array  $options Request options including timeout.
		 * @return array|WP_Error Array containing body and content_type.
		 */
		protected function download_image_from_url( $url, array $options ) {
			$timeout = $this->resolve_timeout( $options );

			$request_args = array(
				'timeout' => $timeout,
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to download image from Gemini file URI.',
					array(
						'url'   => $url,
						'error' => $response->get_error_message(),
					)
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Gemini image download request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Gemini', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'Gemini returned a non-200 status while downloading image.',
					array(
						'url'  => $url,
						'code' => $code,
					)
				);

				return new WP_Error( 'wp_mcp_ai_http_error', __( 'Gemini returned an unexpected status while downloading the image.', 'mcp-ai-wpoos' ), array( 'status' => $code ) );
			}

			$body = wp_remote_retrieve_body( $response );

			if ( '' === $body ) {
				WP_MCP_AI_Logger::log_error( 'Gemini image download returned an empty body.', array( 'url' => $url ) );

				return new WP_Error( 'wp_mcp_ai_image_empty', __( 'Gemini returned an empty image response.', 'mcp-ai-wpoos' ) );
			}

			return array(
				'body'         => $body,
				'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
			);
		}

		/**
		 * Decode an inline base64 image payload returned by Gemini.
		 *
		 * @param string $data Raw base64 or base64url encoded string.
		 * @return string|WP_Error
		 */
		protected function decode_inline_image_data( $data ) {
			$data = (string) $data;
			$decoded = base64_decode( $data, true );

			if ( false !== $decoded ) {
				return $decoded;
			}

			$normalised = $this->normalise_base64_string( $data );

			if ( '' !== $normalised ) {
				$decoded = base64_decode( $normalised, true );

				if ( false !== $decoded ) {
					return $decoded;
				}
			}

			$decoded = base64_decode( $data );

			if ( false !== $decoded ) {
				return $decoded;
			}

			WP_MCP_AI_Logger::log_error(
				'Gemini inline image data could not be decoded.',
				array(
					'length' => strlen( $data ),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_image_decode_error',
				__( 'Gemini returned an invalid inline image payload.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Normalise a base64 or base64url string for decoding.
		 *
		 * @param string $data Raw base64 input.
		 * @return string
		 */
		protected function normalise_base64_string( $data ) {
			$data = (string) $data;
			$data = str_replace( array( "\r", "\n" ), '', $data );
			$data = strtr( $data, '-_', '+/' );

			$remainder = strlen( $data ) % 4;

			if ( 0 !== $remainder ) {
				$data .= str_repeat( '=', 4 - $remainder );
			}

			return $data;
		}

		/**
		 * Resolve the timeout for the request.
		 *
		 * @param array $options Request options.
		 * @return int
		 */
		protected function resolve_timeout( array $options ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();

			if ( isset( $options['timeout'] ) && $options['timeout'] ) {
				$timeout = max( 5, absint( $options['timeout'] ) );
			}

			return max( 5, $timeout );
		}

		/**
		 * Convert a Gemini response to an OpenAI-style structure for downstream compatibility.
		 *
		 * @param array $response Decoded Gemini response.
		 * @return array
		 */
		protected function normalize_response( array $response ) {
			$choices = array();

			if ( isset( $response['candidates'] ) && is_array( $response['candidates'] ) ) {
				foreach ( $response['candidates'] as $index => $candidate ) {
					$message = array( 'role' => 'assistant' );
					$segments = array();
					$tool_calls = array();
					$thinking = '';

					if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
						foreach ( $candidate['content']['parts'] as $part ) {
							if ( isset( $part['text'] ) ) {
								$segments[] = array(
									'type' => 'text',
									'text' => (string) $part['text'],
								);
								continue;
							}

							if ( isset( $part['thought'] ) ) {
								// Gemini 2.0 Flash Thinking mode provides thinking text.

								$thinking .= (string) $part['thought'];
								continue;
							}

							if ( isset( $part['functionCall'] ) && is_array( $part['functionCall'] ) ) {
								$tool_call = $this->convert_function_call_to_tool_call( $part['functionCall'], $index );
								if ( $tool_call ) {
									$tool_calls[] = $tool_call;
								}
							}

							if ( isset( $part['functionResponse'] ) && is_array( $part['functionResponse'] ) ) {
								$segments[] = array(
									'type' => 'text',
									'text' => $this->render_function_response_text( $part['functionResponse'] ),
								);
							}
						}
					}

					if ( ! empty( $segments ) ) {
						$message['content'] = $segments;
					}

					if ( ! empty( $tool_calls ) ) {
						$message['tool_calls'] = $tool_calls;
					}

					if ( ! empty( $thinking ) ) {
						$message['thinking'] = $thinking;
					}

					$choice = array(
						'index'   => $index,
						'message' => $message,
					);

					if ( isset( $candidate['finishReason'] ) ) {
						$choice['finish_reason'] = $candidate['finishReason'];
					}

					$choices[] = $choice;
				}
			}

			$normalized = array(
				'choices'  => $choices,
				'provider' => 'gemini',
			);

			if ( isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ) {
				$usage = array();

				if ( isset( $response['usageMetadata']['promptTokenCount'] ) ) {
					$usage['prompt_tokens'] = (int) $response['usageMetadata']['promptTokenCount'];
				}

				if ( isset( $response['usageMetadata']['candidatesTokenCount'] ) ) {
					$usage['completion_tokens'] = (int) $response['usageMetadata']['candidatesTokenCount'];
				}

				if ( isset( $response['usageMetadata']['totalTokenCount'] ) ) {
					$usage['total_tokens'] = (int) $response['usageMetadata']['totalTokenCount'];
				}

				if ( ! empty( $usage ) ) {
					$normalized['usage'] = $usage;
				}
			}

			return $normalized;
		}

		/**
		 * Convert a Gemini function call into an OpenAI-style tool call payload.
		 *
		 * @param array $function_call Gemini function call payload.
		 * @param int   $index         Candidate index.
		 * @return array|null
		 */
		protected function convert_function_call_to_tool_call( array $function_call, $index ) {
			$name = isset( $function_call['name'] ) ? sanitize_text_field( $function_call['name'] ) : '';

			if ( '' === $name ) {
				return null;
			}

			$args = '{}';
			if ( isset( $function_call['args'] ) && is_array( $function_call['args'] ) ) {
				$args = wp_json_encode( $function_call['args'] );
			}

			return array(
				'id'       => sprintf( 'gemini-function-%d-%s', $index, uniqid() ),
				'type'     => 'function',
				'function' => array(
					'name'      => $name,
					'arguments' => $args,
				),
			);
		}

		/**
		 * Render a Gemini function response into text.
		 *
		 * @param array $function_response Gemini function response payload.
		 * @return string
		 */
		protected function render_function_response_text( array $function_response ) {
			if ( isset( $function_response['name'] ) && isset( $function_response['response'] ) ) {
				$rendered = sprintf( '%s: %s', sanitize_text_field( $function_response['name'] ), wp_json_encode( $function_response['response'] ) );

				return $rendered;
			}

			if ( isset( $function_response['text'] ) ) {
				return (string) $function_response['text'];
			}

			return '';
		}

		/**
		 * Remove large payloads from the logged request.
		 *
		 * @param array $payload Request payload.
		 * @return array
		 */
		protected function obfuscate_request_for_log( array $payload ) {
			if ( isset( $payload['contents'] ) ) {
				$payload['contents'] = '[redacted]';
			}

			if ( isset( $payload['system_instruction'] ) ) {
				$payload['system_instruction'] = '[redacted]';
			}

			return $payload;
		}

		/**
		 * Transcribe audio using Google Speech-to-Text API.
		 *
		 * @param string $file_path Path to the audio file.
		 * @param array  $options   Additional options (language, encoding, etc.).
		 * @return array|WP_Error Transcription result or error.
		 */
		public function transcribe_audio( $file_path, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini/Google API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_path = (string) $file_path;

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_transcription_missing_file',
					__( 'The audio file to transcribe could not be located.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			// Read and base64 encode the file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$file_data = file_get_contents( $file_path );

			if ( false === $file_data ) {
				return new WP_Error(
					'wp_mcp_ai_file_read_error',
					__( 'Could not read the audio file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$audio_content = base64_encode( $file_data );

			// Get language code (default to auto-detect).
			$language_code = isset( $options['language'] ) && '' !== $options['language'] ? sanitize_text_field( $options['language'] ) : 'en-US';

			// Build request payload for Google Speech-to-Text API.
			$payload = array(
				'config' => array(
					'encoding'                   => 'LINEAR16', // Will be overridden by auto-detection.
					'languageCode'               => $language_code,
					'enableAutomaticPunctuation' => true,
				),
				'audio'  => array(
					'content' => $audio_content,
				),
			);

			$url = 'https://speech.googleapis.com/v1/speech:recognize?key=' . rawurlencode( $api_key );

			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : 60;
			$timeout = max( 5, $timeout );

			$request_args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'google_transcribe_audio',
				'Sending audio transcription request to Google Speech-to-Text.',
				array(
					'language'  => $language_code,
					'file_size' => strlen( $file_data ),
					'timeout'   => $timeout,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Google Speech-to-Text transcription failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Google Speech-to-Text transcription request failed.', 'mcp-ai-wpoos' ),
					__( 'Google Speech-to-Text', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				$error_message = __( 'Google Speech-to-Text transcription returned an error.', 'mcp-ai-wpoos' );
				$decoded_body = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['error']['message'] ) ) {
					$error_message .= ' ' . sanitize_text_field( $decoded_body['error']['message'] );
				}

				WP_MCP_AI_Logger::log_error(
					'Google Speech-to-Text transcription error.',
					array(
						'status' => $code,
						'body'   => $body,
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
					__( 'Invalid JSON response from Google Speech-to-Text.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			// Google Speech-to-Text returns: {"results": [{"alternatives": [{"transcript": "text", "confidence": 0.95}]}]}.
			// Extract the transcript from the first result.
			$text = '';
			if ( isset( $decoded['results'] ) && is_array( $decoded['results'] ) ) {
				foreach ( $decoded['results'] as $result ) {
					if ( isset( $result['alternatives'][0]['transcript'] ) ) {
						$text .= $result['alternatives'][0]['transcript'] . ' ';
					}
				}
			}

			$text = trim( $text );

			WP_MCP_AI_Logger::log_event(
				'google_transcribe_audio_success',
				'Successfully transcribed audio with Google Speech-to-Text.',
				array(
					'language'     => $language_code,
					'text_length'  => strlen( $text ),
					'result_count' => isset( $decoded['results'] ) ? count( $decoded['results'] ) : 0,
				)
			);

			// Check if transcription is empty.
			if ( '' === $text ) {
				return new WP_Error(
					'wp_mcp_ai_empty_transcription',
					__( 'Google Speech-to-Text returned an empty transcription. The audio may not contain speech or may be in an unsupported format.', 'mcp-ai-wpoos' ),
					array( 'response' => $decoded )
				);
			}

			// Normalize to consistent format.
			return array(
				'text'     => $text,
				'model'    => 'google-speech-to-text',
				'format'   => 'json',
				'language' => $language_code,
				'raw'      => $decoded,
			);
		}

		/**
		 * Generate speech using Google Text-to-Speech API.
		 *
		 * @param string $text    Text to convert to speech.
		 * @param array  $options Additional options (voice, language, etc.).
		 * @return array|WP_Error Audio data or error.
		 */
		public function generate_speech( $text, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_gemini_api_key',
					__( 'No Gemini/Google API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_gemini_key' => __( 'Add a Gemini API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$text = sanitize_textarea_field( $text );

			if ( '' === $text ) {
				return new WP_Error(
					'wp_mcp_ai_missing_speech_input',
					__( 'A text prompt must be supplied to generate speech.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Get voice and language settings.
			$language_code = isset( $options['language'] ) && '' !== $options['language'] ? sanitize_text_field( $options['language'] ) : 'en-US';
			$voice_name = isset( $options['voice'] ) && '' !== $options['voice'] ? sanitize_text_field( $options['voice'] ) : 'en-US-Neural2-C';

			// Build request payload for Google Text-to-Speech API.
			$payload = array(
				'input'       => array(
					'text' => $text,
				),
				'voice'       => array(
					'languageCode' => $language_code,
					'name'         => $voice_name,
				),
				'audioConfig' => array(
					'audioEncoding' => 'MP3',
				),
			);

			// Add speaking rate if provided.
			if ( isset( $options['speed'] ) && '' !== $options['speed'] ) {
				$speed = floatval( $options['speed'] );
				$speed = max( 0.25, min( 4.0, $speed ) );
				$payload['audioConfig']['speakingRate'] = $speed;
			}

			$url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode( $api_key );

			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : 60;
			$timeout = max( 5, $timeout );

			$request_args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'google_generate_speech',
				'Sending text-to-speech request to Google TTS.',
				array(
					'language'    => $language_code,
					'voice'       => $voice_name,
					'text_length' => strlen( $text ),
					'timeout'     => $timeout,
				)
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Google TTS request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'Google Text-to-Speech request failed.', 'mcp-ai-wpoos' ),
					__( 'Google Text-to-Speech', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				$error_message = __( 'Google Text-to-Speech returned an error.', 'mcp-ai-wpoos' );
				$decoded_body = json_decode( $body, true );

				if ( is_array( $decoded_body ) && isset( $decoded_body['error']['message'] ) ) {
					$error_message .= ' ' . sanitize_text_field( $decoded_body['error']['message'] );
				}

				WP_MCP_AI_Logger::log_error(
					'Google TTS error.',
					array(
						'status' => $code,
						'body'   => $body,
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
					__( 'Invalid JSON response from Google TTS.', 'mcp-ai-wpoos' ),
					array( 'body' => $body )
				);
			}

			// Google TTS returns: {"audioContent": "base64-encoded-audio"}.
			if ( ! isset( $decoded['audioContent'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_audio',
					__( 'No audio content received from Google TTS.', 'mcp-ai-wpoos' ),
					array( 'response' => $decoded )
				);
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$audio_data = base64_decode( $decoded['audioContent'] );

			if ( false === $audio_data ) {
				return new WP_Error(
					'wp_mcp_ai_audio_decode_error',
					__( 'Could not decode audio content from Google TTS.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'google_generate_speech_success',
				'Successfully generated speech with Google TTS.',
				array(
					'language'   => $language_code,
					'voice'      => $voice_name,
					'audio_size' => strlen( $audio_data ),
				)
			);

			// Return audio data in format compatible with OpenAI response.
			return array(
				'audio_data' => $audio_data,
				'format'     => 'mp3',
				'raw'        => $decoded,
			);
		}

		/**
		 * Execute a chat completion with tools and recursive tool execution.
		 *
		 * This method provides automatic tool execution in a loop for Gemini.
		 * It will:
		 * 1. Send messages with tool definitions to Gemini
		 * 2. If tools are called, execute them and add results to conversation
		 * 3. Repeat until no more tools are needed or max recursion is reached
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
			$strict_validation = isset( $options['strictValidation'] ) ? (bool) $options['strictValidation'] : true;
			$max_recursive_runs = isset( $options['maxRecursiveToolRuns'] ) ? absint( $options['maxRecursiveToolRuns'] ) : 5;
			$stream_final_response = isset( $options['streamFinalResponse'] ) ? (bool) $options['streamFinalResponse'] : false;
			$verbose = isset( $options['verbose'] ) ? (bool) $options['verbose'] : false;
			$auto_trim_tools = isset( $options['autoTrimTools'] ) ? (bool) $options['autoTrimTools'] : false;

			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					'gemini_run_with_tools_start',
					'Starting Gemini embedded function calling.',
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
						'gemini_auto_trim_tools',
						'Automatically trimmed tools based on context.',
						array( 'remaining_tool_count' => count( $tools ) )
					);
				}
			}

			// Convert tools to Gemini format and create tool lookup.
			$tool_definitions = array();
			$tool_functions = array();

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
			$request_options = $options;
			$request_options['tools'] = $tool_definitions;

			// Execute recursive tool calling loop.
			$conversation_messages = $messages;
			$recursion_count = 0;

			while ( $recursion_count < $max_recursive_runs ) {
				++$recursion_count;

				if ( $verbose ) {
					WP_MCP_AI_Logger::log_event(
						'gemini_tool_run_iteration',
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
							'gemini_run_with_tools_complete',
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
					$tool_call_id = isset( $tool_call['id'] ) ? $tool_call['id'] : uniqid( 'tool-', true );

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
							'Gemini tool function not found.',
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
								'Gemini tool argument validation failed.',
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
								'gemini_tool_executed',
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
							'Gemini tool execution failed.',
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
					'gemini_max_recursion_reached',
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

			// Validate parameter types if schema includes type definitions.
			if ( isset( $tool_schema['properties'] ) && is_array( $tool_schema['properties'] ) ) {
				foreach ( $arguments as $param_name => $param_value ) {
					if ( ! isset( $tool_schema['properties'][ $param_name ] ) ) {
						// Ignore extra parameters (non-strict mode).
						continue;
					}

					$param_schema = $tool_schema['properties'][ $param_name ];
					if ( ! isset( $param_schema['type'] ) ) {
						continue;
					}

					$expected_type = $param_schema['type'];
					$actual_type = gettype( $param_value );

					// Map PHP types to JSON Schema types.
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

					// Allow integer for number type.
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
			// Get the last user message to determine relevance.
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

			// Score each tool based on relevance.
			$scored_tools = array();
			foreach ( $tools as $tool ) {
				$score = 0;

				// Check name relevance.
				if ( isset( $tool['name'] ) ) {
					$tool_name = strtolower( str_replace( array( '-', '_' ), ' ', $tool['name'] ) );
					$name_words = explode( ' ', $tool_name );
					foreach ( $name_words as $word ) {
						if ( ! empty( $word ) && false !== strpos( $last_user_message, $word ) ) {
							$score += 3; // Higher weight for name match.
						}
					}
				}

				// Check description relevance.
				if ( isset( $tool['description'] ) ) {
					$tool_desc = strtolower( $tool['description'] );
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

			// Sort by score (descending).
			usort(
				$scored_tools,
				function ( $a, $b ) {
					return $b['score'] - $a['score'];
				}
			);

			// Keep top tools (limit to maxTools option).
			$max_tools = isset( $options['maxTools'] ) ? absint( $options['maxTools'] ) : 10;
			$trimmed_tools = array();

			foreach ( array_slice( $scored_tools, 0, $max_tools ) as $scored ) {
				// Only include tools with a relevance score.
				if ( $scored['score'] > 0 || count( $trimmed_tools ) < 3 ) {
					// Always keep at least 3 tools even if score is 0.
					$trimmed_tools[] = $scored['tool'];
				}
			}

			// If no tools passed the relevance test, keep all original tools.
			if ( empty( $trimmed_tools ) ) {
				return $tools;
			}

			return $trimmed_tools;
		}
	}
}
