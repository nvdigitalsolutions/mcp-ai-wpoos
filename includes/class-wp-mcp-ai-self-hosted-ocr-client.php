<?php
/**
 * Self-Hosted OCR Client — Unified client for Unlimited-OCR and DeepSeek-OCR.
 *
 * Both models share the same infrastructure: vLLM server, OpenAI-compatible
 * REST API, NGramPerReqLogitsProcessor, and <image> prompt prefix. This
 * client uses a model_type discriminator to route to the correct endpoint
 * and apply model-specific defaults.
 *
 * @package WP_MCP_AI
 * @since   1.5.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-Hosted OCR Client class.
 *
 * Supports Unlimited-OCR (Baidu, 3B, MIT) and DeepSeek-OCR (DeepSeek, ~3B, MIT).
 * Both models require a vLLM server with the NGramPerReqLogitsProcessor registered.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Self_Hosted_OCR_Client {

	/**
	 * Valid model types.
	 *
	 * @since 1.5.0
	 * @var string[]
	 */
	const VALID_MODEL_TYPES = array( 'unlimited_ocr', 'deepseek_ocr' );

	/**
	 * Default parameters per model type.
	 *
	 * @since 1.5.0
	 * @var array
	 */
	const MODEL_DEFAULTS = array(
		'unlimited_ocr' => array(
			'ngram_size'        => 35,
			'window_size'       => 128,
			'window_size_multi' => 1024,
			'image_mode'        => 'gundam',
			'multi_image_mode'  => 'base',
			'prompt_template'   => '<image>document parsing.',
			'has_det_markers'   => true,
			'setting_key'       => 'unlimited_ocr_endpoint_url',
			'model_name'        => 'Unlimited-OCR',
		),
		'deepseek_ocr'  => array(
			'ngram_size'          => 30,
			'window_size'         => 90,
			'window_size_multi'   => 1024,
			'image_mode'          => 'gundam',
			'multi_image_mode'    => 'base',
			'prompt_template'     => '<image>' . "\n" . 'Free OCR.',
			'has_det_markers'     => false,
			'setting_key'         => 'deepseek_ocr_endpoint_url',
			'model_name'          => 'DeepSeek-OCR',
			'whitelist_token_ids' => array( 128821, 128822 ), // <td>, </td>
		),
	);

	/**
	 * Supported image MIME types for base64 encoding.
	 *
	 * @since 1.5.0
	 * @var array
	 */
	const SUPPORTED_MIME_TYPES = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
		'bmp'  => 'image/bmp',
		'tiff' => 'image/tiff',
	);

	/**
	 * Get the endpoint URL for a model type.
	 *
	 * @since 1.5.0
	 *
	 * @param string $model_type Model type ('unlimited_ocr' or 'deepseek_ocr').
	 * @return string Endpoint URL or empty string if not configured.
	 */
	public function get_endpoint_url( $model_type ) {
		$defaults = $this->get_model_defaults( $model_type );
		if ( ! $defaults ) {
			return '';
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return isset( $settings[ $defaults['setting_key'] ] )
			? untrailingslashit( (string) $settings[ $defaults['setting_key'] ] )
			: '';
	}

	/**
	 * Get default parameters for a model type.
	 *
	 * @since 1.5.0
	 *
	 * @param string $model_type Model type.
	 * @return array|null Model defaults or null if invalid.
	 */
	public function get_model_defaults( $model_type ) {
		if ( ! in_array( $model_type, self::VALID_MODEL_TYPES, true ) ) {
			return null;
		}

		return self::MODEL_DEFAULTS[ $model_type ];
	}

	/**
	 * Validate a model type.
	 *
	 * @since 1.5.0
	 *
	 * @param string $model_type Model type to validate.
	 * @return bool True if valid.
	 */
	public function is_valid_model_type( $model_type ) {
		return in_array( $model_type, self::VALID_MODEL_TYPES, true );
	}

	/**
	 * Test connection to a self-hosted OCR vLLM server.
	 *
	 * @since 1.5.0
	 *
	 * @param string $model_type Model type.
	 * @return array|WP_Error Connection test result or error.
	 */
	public function test_connection( $model_type ) {
		if ( ! $this->is_valid_model_type( $model_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_model_type',
				sprintf(
					/* translators: %s: model type */
					__( 'Invalid model type: %s.', 'mcp-ai-wpoos' ),
					esc_html( $model_type )
				),
				array( 'status' => 400 )
			);
		}

		$endpoint_url = $this->get_endpoint_url( $model_type );
		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ocr_endpoint',
				sprintf(
					/* translators: %s: model type label */
					__( 'No endpoint URL configured for %s.', 'mcp-ai-wpoos' ),
					esc_html( $this->get_model_label( $model_type ) )
				),
				array( 'status' => 400 )
			);
		}

		$url     = $endpoint_url . '/v1/models';
		$timeout = 30;

		$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_ocr_connection_failed',
				sprintf(
					/* translators: 1: model type label, 2: error message */
					__( '%1$s connection failed: %2$s.', 'mcp-ai-wpoos' ),
					esc_html( $this->get_model_label( $model_type ) ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_ocr_connection_error',
				sprintf(
					/* translators: 1: model type label, 2: HTTP status code */
					__( '%1$s returned HTTP %2$d.', 'mcp-ai-wpoos' ),
					esc_html( $this->get_model_label( $model_type ) ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: model type label */
				__( 'Connected to %s.', 'mcp-ai-wpoos' ),
				$this->get_model_label( $model_type )
			),
			'model_type' => $model_type,
			'models'     => isset( $data['data'] ) ? wp_list_pluck( $data['data'], 'id' ) : array(),
		);
	}

	/**
	 * Get human-readable model label.
	 *
	 * @since 1.5.0
	 *
	 * @param string $model_type Model type.
	 * @return string Label.
	 */
	public function get_model_label( $model_type ) {
		$defaults = $this->get_model_defaults( $model_type );
		if ( ! $defaults ) {
			return ucfirst( str_replace( '_', ' ', $model_type ) );
		}

		return $defaults['model_name'];
	}

	/**
	 * Perform OCR on a single image.
	 *
	 * @since 1.5.0
	 *
	 * @param string $image_data Base64-encoded image data (without data: URI prefix).
	 * @param string $prompt     OCR prompt. If empty, uses model default.
	 * @param string $model_type Model type ('unlimited_ocr' or 'deepseek_ocr').
	 * @param array  $options    Optional. Additional options.
	 *                           - 'mime_type'    (string)  Image MIME type. Default 'image/png'.
	 *                           - 'image_mode'   (string)  'gundam' or 'base'. Default model-specific.
	 *                           - 'timeout'      (int)     HTTP timeout in seconds. Default 120.
	 *                           - 'temperature'  (float)   Sampling temperature. Default 0.0.
	 * @return array|WP_Error OCR result with 'text', 'raw', 'model_type', 'metadata' keys, or error.
	 */
	public function ocr_image( $image_data, $prompt = '', $model_type = 'unlimited_ocr', $options = array() ) {
		return $this->ocr_images( array( $image_data ), $prompt, $model_type, $options );
	}

	/**
	 * Perform OCR on multiple images (multi-page document).
	 *
	 * @since 1.5.0
	 *
	 * @param string[] $image_data_array Array of base64-encoded image data strings.
	 * @param string   $prompt           OCR prompt. If empty, uses model default.
	 * @param string   $model_type       Model type ('unlimited_ocr' or 'deepseek_ocr').
	 * @param array    $options          Optional. Additional options.
	 *                                   - 'mime_type'    (string)  Image MIME type. Default 'image/png'.
	 *                                   - 'image_mode'   (string)  'base' only for multi-page. Default 'base'.
	 *                                   - 'timeout'      (int)     HTTP timeout in seconds. Default 300.
	 *                                   - 'temperature'  (float)   Sampling temperature. Default 0.0.
	 * @return array|WP_Error OCR result with 'text', 'raw', 'model_type', 'metadata' keys, or error.
	 */
	public function ocr_images( $image_data_array, $prompt = '', $model_type = 'unlimited_ocr', $options = array() ) {
		if ( ! $this->is_valid_model_type( $model_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_model_type',
				sprintf(
					/* translators: %s: model type */
					__( 'Invalid model type: %s.', 'mcp-ai-wpoos' ),
					esc_html( $model_type )
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $image_data_array ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_image',
				__( 'No image data provided for OCR.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$defaults = $this->get_model_defaults( $model_type );
		$endpoint = $this->get_endpoint_url( $model_type );

		if ( empty( $endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ocr_endpoint',
				sprintf(
					/* translators: %s: model type label */
					__( 'No endpoint URL configured for %s.', 'mcp-ai-wpoos' ),
					esc_html( $this->get_model_label( $model_type ) )
				),
				array( 'status' => 400 )
			);
		}

		$is_multi    = count( $image_data_array ) > 1;
		$mime_type   = isset( $options['mime_type'] ) ? sanitize_text_field( $options['mime_type'] ) : 'image/png';
		$image_mode  = isset( $options['image_mode'] )
			? sanitize_text_field( $options['image_mode'] )
			: ( $is_multi ? $defaults['multi_image_mode'] : $defaults['image_mode'] );
		$timeout     = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : ( $is_multi ? 300 : 120 );
		$temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : 0.0;

		// Build prompt.
		if ( empty( $prompt ) ) {
			$prompt = $defaults['prompt_template'];
		}
		// Ensure prompt starts with <image> prefix.
		if ( 0 !== strpos( $prompt, '<image>' ) ) {
			$prompt = '<image>' . $prompt;
		}

		// Build content array: text prompt + image(s).
		$content   = array();
		$content[] = array(
			'type' => 'text',
			'text' => $prompt,
		);

		foreach ( $image_data_array as $image_data ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:' . $mime_type . ';base64,' . $image_data,
				),
			);
		}

		// Assemble request payload.
		$payload = array(
			'model'                  => $defaults['model_name'],
			'messages'               => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'temperature'            => $temperature,
			'skip_special_tokens'    => false,
			'images_config'          => array(
				'image_mode' => $image_mode,
			),
			'custom_logit_processor' => 'DeepseekOCRNoRepeatNGramLogitProcessor',
			'custom_params'          => array(
				'ngram_size'  => $defaults['ngram_size'],
				'window_size' => $is_multi ? $defaults['window_size_multi'] : $defaults['window_size'],
			),
		);

		// Add whitelist token IDs for DeepSeek-OCR.
		if ( ! empty( $defaults['whitelist_token_ids'] ) ) {
			$payload['custom_params']['whitelist_token_ids'] = $defaults['whitelist_token_ids'];
		}

		/**
		 * Filter the OCR request payload before sending.
		 *
		 * @since 1.5.0
		 *
		 * @param array  $payload          Request payload.
		 * @param string $model_type       Model type.
		 * @param int    $image_count      Number of images.
		 * @param bool   $is_multi         Whether multi-page mode.
		 */
		$payload = apply_filters( 'wp_mcp_ai_self_hosted_ocr_payload', $payload, $model_type, count( $image_data_array ), $is_multi );

		$url = $endpoint . '/v1/chat/completions';

		$request_args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $timeout,
		);

		$start_time = microtime( true );
		$response   = wp_remote_post( $url, $request_args );
		$duration   = round( microtime( true ) - $start_time, 2 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_ocr_request_failed',
				sprintf(
					/* translators: 1: model type label, 2: error message */
					__( '%1$s OCR request failed: %2$s.', 'mcp-ai-wpoos' ),
					esc_html( $this->get_model_label( $model_type ) ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_ocr_api_error',
				sprintf(
					/* translators: 1: model type label, 2: HTTP status code */
					__( '%1$s returned HTTP %2$d.', 'mcp-ai-wpoos' ),
					esc_html( $this->get_model_label( $model_type ) ),
					$code
				),
				array(
					'status'   => $code,
					'response' => $body,
				)
			);
		}

		$data = json_decode( $body, true );

		if ( empty( $data['choices'] ) || ! is_array( $data['choices'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_ocr_empty_response',
				__( 'OCR model returned an empty or unexpected response.', 'mcp-ai-wpoos' ),
				array( 'status' => 502 )
			);
		}

		$raw_text = '';
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$raw_text = (string) $data['choices'][0]['message']['content'];
		} elseif ( isset( $data['choices'][0]['text'] ) ) {
			$raw_text = (string) $data['choices'][0]['text'];
		}

		// Post-process the response.
		$clean_text = $this->post_process_response( $raw_text, $model_type );

		/**
		 * Filter the OCR result after processing.
		 *
		 * @since 1.5.0
		 *
		 * @param array  $result     OCR result array.
		 * @param string $raw_text   Raw text from model.
		 * @param string $model_type Model type.
		 */
		$result = apply_filters(
			'wp_mcp_ai_self_hosted_ocr_result',
			array(
				'text'       => $clean_text,
				'raw'        => $raw_text,
				'model_type' => $model_type,
				'metadata'   => array(
					'provider'        => $model_type,
					'model'           => $defaults['model_name'],
					'image_count'     => count( $image_data_array ),
					'multi_page'      => $is_multi,
					'image_mode'      => $image_mode,
					'word_count'      => str_word_count( $clean_text ),
					'character_count' => strlen( $clean_text ),
					'duration_sec'    => $duration,
					'processed_at'    => current_time( 'mysql' ),
				),
			),
			$raw_text,
			$model_type
		);

		return $result;
	}

	/**
	 * Post-process the raw OCR response text.
	 *
	 * For Unlimited-OCR: strips <|det|> markers and groups blocks.
	 * For DeepSeek-OCR: passes through (no markers).
	 *
	 * @since 1.5.0
	 *
	 * @param string $raw_text  Raw text from model.
	 * @param string $model_type Model type.
	 * @return string Cleaned text.
	 */
	public function post_process_response( $raw_text, $model_type ) {
		$defaults = $this->get_model_defaults( $model_type );

		if ( ! $defaults || empty( $defaults['has_det_markers'] ) ) {
			return trim( $raw_text );
		}

		return $this->remove_det_markers( $raw_text );
	}

	/**
	 * Strip <|det|> markers and group text blocks.
	 *
	 * Adapted from the Unlimited-OCR README post-processing code.
	 * Strips <|det|>type [bbox]<|/det|> markers, groups lines belonging
	 * to the same block with \n, and separates different blocks with \n\n.
	 *
	 * @since 1.5.0
	 *
	 * @param string $raw Raw text with <|det|> markers.
	 * @return string Cleaned text.
	 */
	public function remove_det_markers( $raw ) {
		$blocks  = array();
		$current = array();

		// Regex matching <|det|>TYPE [bbox]<|/det|>content...
		$det_re = '/<\|det\|>([^<\s]+)(?:\s*\[[^\]]*\])?\s*<\|\\/det\|>(.*)/';

		foreach ( explode( "\n", $raw ) as $line ) {
			$line = rtrim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( $det_re, $line, $matches ) ) {
				$category = trim( $matches[1] );
				$content  = trim( $matches[2] );

				// Skip image blocks.
				if ( 'image' === $category ) {
					continue;
				}

				// Flush current block.
				if ( ! empty( $current ) ) {
					$blocks[] = $current;
				}

				$current = $content ? array( $content ) : array();
				continue;
			}

			$current[] = $line;
		}

		// Flush final block.
		if ( ! empty( $current ) ) {
			$blocks[] = $current;
		}

		// Join blocks: lines within block with \n, blocks separated by \n\n.
		$text = '';
		foreach ( $blocks as $block ) {
			if ( '' !== $text ) {
				$text .= "\n\n";
			}
			$text .= implode( "\n", $block );
		}

		return trim( $text );
	}

	/**
	 * Convert image file path to base64-encoded data.
	 *
	 * @since 1.5.0
	 *
	 * @param string $image_path Path to image file.
	 * @return string|WP_Error Base64-encoded image data or error.
	 */
	public function encode_image_file( $image_path ) {
		if ( ! file_exists( $image_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Image file not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$image_content = file_get_contents( $image_path );

		if ( false === $image_content ) {
			return new WP_Error(
				'wp_mcp_ai_file_read_error',
				__( 'Could not read image file.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for sending image data to vLLM API.
		return base64_encode( $image_content );
	}

	/**
	 * Fetch image from URL and return base64-encoded data.
	 *
	 * @since 1.5.0
	 *
	 * @param string $image_url Image URL.
	 * @return string|WP_Error Base64-encoded image data or error.
	 */
	public function fetch_and_encode_image( $image_url ) {
		$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_image_fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to fetch image: %s.', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_image_fetch_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Image fetch returned HTTP %d.', 'mcp-ai-wpoos' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_image_empty',
				__( 'Fetched image was empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 502 )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for sending image data to vLLM API.
		return base64_encode( $body );
	}
}
