<?php
/**
 * Base class for Cloud Vision API tools with shared functionality.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class providing common functionality for Cloud Vision tools.
 */
abstract class WP_MCP_AI_Tool_Cloud_Vision_Base {

	/**
	 * Get image content as base64 from URL or attachment.
	 *
	 * @param array $arguments Tool arguments containing image_url or attachment_id.
	 * @return string|WP_Error Base64 encoded image or error.
	 */
	protected function get_image_content( $arguments ) {
		// Try attachment ID first.
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Attachment not found or file does not exist.', 'wp-mcp-ai' )
				);
			}

			$image_data = file_get_contents( $file_path );
			if ( false === $image_data ) {
				return new WP_Error(
					'read_error',
					__( 'Failed to read attachment file.', 'wp-mcp-ai' )
				);
			}

			return base64_encode( $image_data );
		}

		// Try image URL.
		if ( ! empty( $arguments['image_url'] ) ) {
			$image_url = esc_url_raw( $arguments['image_url'] );
			$response  = wp_remote_get( $image_url, array( 'timeout' => 15 ) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to download image: %s', 'wp-mcp-ai' ),
						$response->get_error_message()
					)
				);
			}

			$image_data = wp_remote_retrieve_body( $response );
			if ( empty( $image_data ) ) {
				return new WP_Error(
					'empty_image',
					__( 'Downloaded image is empty.', 'wp-mcp-ai' )
				);
			}

			return base64_encode( $image_data );
		}

		return new WP_Error(
			'missing_image',
			__( 'Either image_url or attachment_id must be provided.', 'wp-mcp-ai' )
		);
	}

	/**
	 * Get Google Cloud Vision API credentials from settings.
	 *
	 * @return array{api_key: string, credentials_json: string}
	 */
	protected function get_credentials() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return array(
			'api_key'          => isset( $settings['google_vision_api_key'] ) ? $settings['google_vision_api_key'] : '',
			'credentials_json' => isset( $settings['google_vision_credentials_json'] ) ? $settings['google_vision_credentials_json'] : '',
		);
	}

	/**
	 * Check if credentials are configured.
	 *
	 * @return bool True if credentials are configured.
	 */
	protected function has_credentials() {
		$credentials = $this->get_credentials();
		return ! empty( $credentials['api_key'] ) || ! empty( $credentials['credentials_json'] );
	}

	/**
	 * Make a request to the Cloud Vision API.
	 *
	 * @param array $request_body The request body to send.
	 * @return array|WP_Error Response data or error.
	 */
	protected function make_api_request( $request_body ) {
		$credentials = $this->get_credentials();
		$api_key     = $credentials['api_key'];

		$endpoint = 'https://vision.googleapis.com/v1/images:annotate';
		if ( ! empty( $api_key ) ) {
			$endpoint = add_query_arg( 'key', $api_key, $endpoint );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array( 'requests' => array( $request_body ) ) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Cloud Vision API request failed: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( 200 !== $response_code ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown error', 'wp-mcp-ai' );
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %1$d: HTTP status code, %2$s: error message */
					__( 'Cloud Vision API returned error %1$d: %2$s', 'wp-mcp-ai' ),
					$response_code,
					$error_message
				)
			);
		}

		return $data;
	}
}
