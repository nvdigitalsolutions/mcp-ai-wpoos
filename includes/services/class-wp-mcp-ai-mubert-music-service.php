<?php
/**
 * Service for generating music using Mubert API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Handles music generation via Mubert API.
 *
 * This service provides royalty-free background music generation through
 * Mubert's REST API with support for 150+ genres and 50+ moods.
 */
class WP_MCP_AI_Mubert_Music_Service {

	/**
	 * Mubert API base URL.
	 */
	const API_BASE_URL = 'https://music-api.mubert.com/api/v3/public';

	/**
	 * Track generation endpoint.
	 */
	const API_GENERATE_ENDPOINT = '/tracks';

	/**
	 * Streaming link endpoint.
	 */
	const API_STREAMING_ENDPOINT = '/streaming/get-link';

	/**
	 * Default duration in seconds.
	 */
	const DEFAULT_DURATION = 30;

	/**
	 * Minimum duration in seconds.
	 */
	const MIN_DURATION = 15;

	/**
	 * Maximum duration in seconds (25 minutes).
	 */
	const MAX_DURATION = 1500;

	/**
	 * Default audio format.
	 */
	const DEFAULT_FORMAT = 'mp3';

	/**
	 * Default timeout for API requests in seconds.
	 */
	const DEFAULT_TIMEOUT = 120;

	/**
	 * Get the configured Mubert API key.
	 *
	 * @return string API key or empty string if not configured.
	 */
	public function get_api_key() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return isset( $settings['mubert_api_key'] ) ? $settings['mubert_api_key'] : '';
	}

	/**
	 * Test the API connection.
	 *
	 * @return array|WP_Error Success array or WP_Error on failure.
	 */
	public function test_connection() {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_mubert_api_key',
				__( 'No Mubert API key configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Test with a simple health check or minimal generation request.
		$test_payload = array(
			'prompt'   => 'test',
			'duration' => self::MIN_DURATION,
		);

		$response = wp_remote_post(
			self::API_BASE_URL . self::API_GENERATE_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
				'body'    => wp_json_encode( $test_payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_mubert_connection_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Mubert API: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_mubert_auth_failed',
				__( 'Invalid Mubert API key. Please check your API key in Settings.', 'mcp-ai-wpoos' ),
				array( 'status' => $status_code )
			);
		}

		if ( 200 !== $status_code && 201 !== $status_code ) {
			$body          = wp_remote_retrieve_body( $response );
			$data          = json_decode( $body, true );
			$error_message = isset( $data['error'] ) ? $data['error'] : __( 'Unknown error', 'mcp-ai-wpoos' );

			return new WP_Error(
				'wp_mcp_ai_mubert_test_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'Mubert API test failed (status %1$d): %2$s', 'mcp-ai-wpoos' ),
					$status_code,
					$error_message
				),
				array( 'status' => $status_code )
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Mubert API connection successful.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Generate music from a text prompt.
	 *
	 * @param string $prompt  Text description of the desired music.
	 * @param array  $options Optional configuration.
	 *                        - duration: int (seconds, 15-1500)
	 *                        - genre: string (optional genre specification)
	 *                        - mood: string (optional mood specification)
	 *                        - format: string (mp3, wav, default: mp3)
	 *                        - timeout: int (request timeout in seconds).
	 *
	 * @return array|WP_Error Array with audio data or WP_Error on failure.
	 *                        Success array contains:
	 *                        - audio: string (base64-encoded audio data or URL)
	 *                        - format: string (audio format)
	 *                        - mime_type: string (MIME type)
	 *                        - duration: int (duration in seconds)
	 *                        - prompt: string (prompt used)
	 */
	public function generate_music( $prompt, array $options = array() ) {
		$prompt = trim( (string) $prompt );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_music_prompt',
				__( 'Music generation prompt cannot be empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Get API key.
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_mubert_api_key',
				__( 'No Mubert API key has been configured. Please add your Mubert API key in Settings → NV oOS → Tools → Connections.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Parse and validate options.
		$duration = isset( $options['duration'] ) ? absint( $options['duration'] ) : self::DEFAULT_DURATION;
		$duration = max( self::MIN_DURATION, min( $duration, self::MAX_DURATION ) );

		$format  = isset( $options['format'] ) ? sanitize_text_field( $options['format'] ) : self::DEFAULT_FORMAT;
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : self::DEFAULT_TIMEOUT;

		// Build prompt with optional modifiers.
		$full_prompt = $this->build_full_prompt( $prompt, $options );

		// Build API request payload for Mubert API v3.
		$payload = array(
			'prompt'   => $full_prompt,
			'duration' => $duration,
			'format'   => $format,
		);

		// Add optional parameters.
		if ( isset( $options['genre'] ) && ! empty( $options['genre'] ) ) {
			$payload['genre'] = sanitize_text_field( $options['genre'] );
		}

		if ( isset( $options['mood'] ) && ! empty( $options['mood'] ) ) {
			$payload['mood'] = sanitize_text_field( $options['mood'] );
		}

		WP_MCP_AI_Logger::log_event(
			'mubert_music_request',
			'Requesting music generation from Mubert API v3.',
			array(
				'prompt'   => $prompt,
				'duration' => $duration,
				'format'   => $format,
			)
		);

		// Make API request to Mubert API v3.
		$response = wp_remote_post(
			self::API_BASE_URL . self::API_GENERATE_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Mubert API request failed.',
				array( 'error' => $response->get_error_message() )
			);

			return new WP_Error(
				'wp_mcp_ai_mubert_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Mubert API: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$error_message = isset( $data['error'] ) ? $data['error'] : __( 'Unknown error', 'mcp-ai-wpoos' );

			WP_MCP_AI_Logger::log_error(
				'Mubert API returned error.',
				array(
					'status_code' => $status_code,
					'error'       => $error_message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_mubert_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'Mubert API error (status %1$d): %2$s', 'mcp-ai-wpoos' ),
					$status_code,
					$error_message
				),
				array( 'status' => $status_code )
			);
		}

		// Extract audio data from response.
		$audio_result = $this->extract_audio_from_response( $data, $format );

		if ( is_wp_error( $audio_result ) ) {
			return $audio_result;
		}

		WP_MCP_AI_Logger::log_event(
			'mubert_music_generated',
			'Music generated successfully from Mubert.',
			array(
				'duration' => $duration,
				'format'   => $format,
			)
		);

		return array_merge(
			$audio_result,
			array(
				'prompt'   => $prompt,
				'duration' => $duration,
			)
		);
	}

	/**
	 * Build full prompt with optional modifiers.
	 *
	 * @param string $base_prompt Base prompt text.
	 * @param array  $options     Options array.
	 * @return string Enhanced prompt/tags.
	 */
	protected function build_full_prompt( $base_prompt, array $options ) {
		$tags = array( $base_prompt );

		if ( isset( $options['genre'] ) && ! empty( $options['genre'] ) ) {
			$tags[] = sanitize_text_field( $options['genre'] );
		}

		if ( isset( $options['mood'] ) && ! empty( $options['mood'] ) ) {
			$tags[] = sanitize_text_field( $options['mood'] );
		}

		// Mubert expects comma-separated tags.
		return implode( ', ', $tags );
	}

	/**
	 * Extract audio data from Mubert API v3 response.
	 *
	 * @param array  $response API response data.
	 * @param string $format   Expected audio format.
	 * @return array|WP_Error Audio data or error.
	 */
	protected function extract_audio_from_response( array $response, $format ) {
		// Mubert API v3 may return different response structures.
		// Check for success indicators and audio URL/data.

		// Try to find audio URL in common response structures.
		$audio_url = '';

		if ( isset( $response['url'] ) ) {
			$audio_url = $response['url'];
		} elseif ( isset( $response['data']['url'] ) ) {
			$audio_url = $response['data']['url'];
		} elseif ( isset( $response['data']['file_url'] ) ) {
			$audio_url = $response['data']['file_url'];
		} elseif ( isset( $response['track_url'] ) ) {
			$audio_url = $response['track_url'];
		} elseif ( isset( $response['audio_url'] ) ) {
			$audio_url = $response['audio_url'];
		}

		if ( empty( $audio_url ) ) {
			// Log the full response for debugging.
			WP_MCP_AI_Logger::log_error(
				'No audio URL found in Mubert API response.',
				array( 'response' => $response )
			);

			return new WP_Error(
				'wp_mcp_ai_missing_audio_url',
				__( 'No audio URL found in the Mubert API response. The API may have changed its response format.', 'mcp-ai-wpoos' ),
				array(
					'status'        => 500,
					'response_keys' => array_keys( $response ),
				)
			);
		}

		// Download audio file.
		$audio_response = wp_remote_get(
			$audio_url,
			array(
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $audio_response ) ) {
			return new WP_Error(
				'wp_mcp_ai_audio_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download audio file: %s', 'mcp-ai-wpoos' ),
					$audio_response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$audio_data = wp_remote_retrieve_body( $audio_response );

		if ( empty( $audio_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_audio_data',
				__( 'Downloaded audio file is empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Determine MIME type.
		$mime_types = array(
			'mp3'  => 'audio/mpeg',
			'wav'  => 'audio/wav',
			'ogg'  => 'audio/ogg',
			'flac' => 'audio/flac',
		);

		$mime_type = isset( $mime_types[ $format ] ) ? $mime_types[ $format ] : 'audio/mpeg';

		return array(
			'audio'       => base64_encode( $audio_data ),
			'format'      => $format,
			'mime_type'   => $mime_type,
			'sample_rate' => 44100, // Mubert typically uses 44.1kHz.
			'url'         => $audio_url, // Keep original URL for reference.
		);
	}
}
