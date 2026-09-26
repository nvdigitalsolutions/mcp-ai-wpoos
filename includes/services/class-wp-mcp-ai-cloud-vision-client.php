<?php
/**
 * Shared Google Cloud Vision API client.
 *
 * Extracted from the vision tool implementations so that every tool that
 * talks to `vision.googleapis.com/v1/images:annotate` shares one key
 * resolution, timeout, and error-mapping path.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared client for the Google Cloud Vision `images:annotate` endpoint.
 *
 * The plugin reuses the configured Gemini API key as the Google Cloud Vision
 * key, which can be overridden via the `wp_mcp_ai_vision_api_key` filter.
 *
 * The client returns the raw decoded API response on success (the legacy
 * vision tools return that payload verbatim) and WP_Error on failure, so the
 * calling tool decides whether to wrap the payload in the canonical envelope.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Cloud_Vision_Client {

	/**
	 * Google Cloud Vision annotate endpoint.
	 */
	const VISION_API_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

	/**
	 * Resolve the Google Cloud Vision API key.
	 *
	 * Reuses the configured Gemini API key and honours the
	 * `wp_mcp_ai_vision_api_key` filter. Returns an empty string when no key
	 * is configured — callers must short-circuit before making any HTTP
	 * request so user image data never leaves the server without credentials.
	 *
	 * @param array $context   Execution context (user_id, assistant, etc.).
	 * @param array $arguments Tool arguments (available to the key filter).
	 * @return string API key, or empty string when unconfigured.
	 */
	public function get_api_key( array $context = array(), array $arguments = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = apply_filters(
			'wp_mcp_ai_vision_api_key',
			isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '',
			$context,
			$arguments
		);

		return is_string( $api_key ) ? $api_key : '';
	}

	/**
	 * Build the sanitised Vision API image source object.
	 *
	 * @param string $image_url     Image URL (alternative to image_content).
	 * @param string $image_content Base64-encoded image content.
	 * @return array Vision API image source (contains `source` or `content`).
	 */
	public function build_image_source( $image_url, $image_content ) {
		$image = array();

		if ( ! empty( $image_url ) ) {
			$image['source'] = array(
				'imageUri' => esc_url_raw( $image_url ),
			);
		} elseif ( ! empty( $image_content ) ) {
			$image['content'] = sanitize_text_field( $image_content );
		}

		return $image;
	}

	/**
	 * Run one or more detection features against an image.
	 *
	 * @param array  $features  Vision API feature list, e.g.
	 *                          array( array( 'type' => 'LABEL_DETECTION', 'maxResults' => 10 ) ).
	 * @param array  $image     Vision API image source (see build_image_source()).
	 * @param array  $context   Execution context (user_id, assistant, etc.).
	 * @param array  $arguments Tool arguments (available to the key filter).
	 * @param object $caller    Optional calling tool instance, passed as the
	 *                          final argument to the timeout filter to keep the
	 *                          legacy filter signature stable.
	 * @return array|WP_Error Decoded API response, or WP_Error.
	 */
	public function annotate( array $features, array $image, array $context = array(), array $arguments = array(), $caller = null ) {
		if ( empty( $image ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_missing_image',
				__( 'Either image_url or image_content must be provided.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$api_key = $this->get_api_key( $context, $arguments );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_missing_api_key',
				__( 'A Google Cloud API key with the Cloud Vision API enabled is required. Configure a Gemini API key in NV oOS settings, or supply one via the wp_mcp_ai_vision_api_key filter.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$timeout = apply_filters( 'wp_mcp_ai_vision_request_timeout', 30, $context, $arguments, $caller );

		$request_body = array(
			'requests' => array(
				array(
					'image'    => $image,
					'features' => $features,
				),
			),
		);

		$response = wp_remote_post(
			add_query_arg( 'key', $api_key, self::VISION_API_ENDPOINT ),
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => max( 5, absint( $timeout ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Vision API request failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		// Handle API errors.
		if ( $status_code >= 400 ) {
			$error_message = __( 'Vision API returned an error.', 'mcp-ai-wpoos' );
			if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
				$error_message = $decoded['error']['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_vision_api_error',
				$error_message,
				array(
					'status'       => $status_code,
					'api_response' => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_invalid_response',
				__( 'Vision API returned an invalid response.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return $decoded;
	}
}
