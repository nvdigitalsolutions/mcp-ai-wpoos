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
	const API_BASE_URL = 'https://api-b2b.mubert.com/v2';

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
	 * Generate music from a text prompt.
	 *
	 * @param string $prompt  Text description of the desired music.
	 * @param array  $options Optional configuration.
	 *                        - duration: int (seconds, 15-1500)
	 *                        - genre: string (optional genre specification)
	 *                        - mood: string (optional mood specification)
	 *                        - format: string (mp3, wav, default: mp3)
	 *                        - timeout: int (request timeout in seconds)
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

		// Build API request payload.
		$payload = array(
			'method' => 'GenerateTrackByTags',
			'params' => array(
				'tags'     => $full_prompt,
				'duration' => $duration,
				'format'   => $format,
			),
		);

		// Add optional parameters.
		if ( isset( $options['genre'] ) && ! empty( $options['genre'] ) ) {
			$payload['params']['genre'] = sanitize_text_field( $options['genre'] );
		}

		if ( isset( $options['mood'] ) && ! empty( $options['mood'] ) ) {
			$payload['params']['mood'] = sanitize_text_field( $options['mood'] );
		}

		WP_MCP_AI_Logger::log_event(
			'mubert_music_request',
			'Requesting music generation from Mubert.',
			array(
				'prompt'   => $prompt,
				'duration' => $duration,
				'format'   => $format,
			)
		);

		// Make API request.
		$response = wp_remote_post(
			self::API_BASE_URL . '/generate',
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

		if ( $status_code !== 200 ) {
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
	 * Extract audio data from Mubert API response.
	 *
	 * @param array  $response API response data.
	 * @param string $format   Expected audio format.
	 * @return array|WP_Error Audio data or error.
	 */
	protected function extract_audio_from_response( array $response, $format ) {
		// Check for success status.
		if ( empty( $response['success'] ) || $response['success'] !== true ) {
			$error_message = isset( $response['error'] ) ? $response['error'] : __( 'Unknown error', 'mcp-ai-wpoos' );

			return new WP_Error(
				'wp_mcp_ai_mubert_generation_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Music generation failed: %s', 'mcp-ai-wpoos' ),
					$error_message
				),
				array( 'status' => 500 )
			);
		}

		// Extract audio URL or data.
		$audio_url = isset( $response['data']['url'] ) ? $response['data']['url'] : '';

		if ( empty( $audio_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_audio_url',
				__( 'No audio URL found in the Mubert API response.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
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
