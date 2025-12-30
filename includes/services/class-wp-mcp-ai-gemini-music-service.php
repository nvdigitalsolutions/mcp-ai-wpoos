<?php
/**
 * Service for generating music using Google Gemini Lyria model.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Handles music generation via Gemini Lyria API.
 *
 * This service provides a clean separation of concerns by handling only
 * the music generation logic without WordPress-specific concerns.
 */
class WP_MCP_AI_Gemini_Music_Service {

	/**
	 * Default model for music generation.
	 */
	const DEFAULT_MODEL = 'models/lyria-realtime-exp';

	/**
	 * Default duration in seconds.
	 */
	const DEFAULT_DURATION = 30;

	/**
	 * Maximum duration in seconds.
	 */
	const MAX_DURATION = 300; // 5 minutes.

	/**
	 * Default BPM (beats per minute).
	 */
	const DEFAULT_BPM = 120;

	/**
	 * Default temperature for generation randomness.
	 */
	const DEFAULT_TEMPERATURE = 1.0;

	/**
	 * Gemini client instance.
	 *
	 * @var WP_MCP_AI_Gemini_Client
	 */
	protected $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Gemini_Client|null $client Optional Gemini client instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof WP_MCP_AI_Gemini_Client ? $client : new WP_MCP_AI_Gemini_Client();
	}

	/**
	 * Generate music from a text prompt.
	 *
	 * @param string $prompt  Text description of the desired music (e.g., "jazz piano trio").
	 * @param array  $options Optional configuration.
	 *                        - duration: int (seconds, default 30, max 300)
	 *                        - bpm: int (beats per minute, default 120)
	 *                        - temperature: float (0.0-2.0, default 1.0)
	 *                        - genre: string (optional genre specification)
	 *                        - mood: string (optional mood specification)
	 *                        - instrumentation: string (optional instruments)
	 *                        - key: string (optional musical key)
	 *                        - model: string (optional model override)
	 *                        - timeout: int (request timeout in seconds)
	 *
	 * @return array|WP_Error Array with audio data or WP_Error on failure.
	 *                        Success array contains:
	 *                        - audio: string (base64-encoded audio data)
	 *                        - format: string (audio format, e.g., 'mp3', 'wav')
	 *                        - mime_type: string (MIME type of the audio)
	 *                        - duration: float (actual duration in seconds)
	 *                        - sample_rate: int (audio sample rate)
	 *                        - prompt: string (the prompt used)
	 *                        - model: string (the model used)
	 */
	public function generate_music( $prompt, array $options = array() ) {
		$prompt = trim( (string) $prompt );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_music_prompt',
				__( 'Music generation prompt cannot be empty.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get API key.
		$api_key = $this->client->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Prepare options with defaults.
		$duration    = isset( $options['duration'] ) ? absint( $options['duration'] ) : self::DEFAULT_DURATION;
		$duration    = max( 1, min( $duration, self::MAX_DURATION ) );
		$bpm         = isset( $options['bpm'] ) ? absint( $options['bpm'] ) : self::DEFAULT_BPM;
		$bpm         = max( 20, min( $bpm, 300 ) ); // Reasonable BPM range.
		$temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : self::DEFAULT_TEMPERATURE;
		$temperature = max( 0.0, min( $temperature, 2.0 ) );
		$model       = isset( $options['model'] ) && ! empty( $options['model'] ) ? sanitize_text_field( $options['model'] ) : self::DEFAULT_MODEL;
		$timeout     = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 120;

		// Build the full prompt with optional modifiers.
		$full_prompt = $this->build_full_prompt( $prompt, $options );

		// Build request payload.
		$payload = array(
			'instances'  => array(
				array(
					'prompt'      => $full_prompt,
					'duration'    => $duration,
					'temperature' => $temperature,
				),
			),
			'parameters' => array(
				'bpm'         => $bpm,
				'sample_rate' => 48000, // Standard 48kHz.
			),
		);

		// Add optional parameters if provided.
		if ( isset( $options['key'] ) && ! empty( $options['key'] ) ) {
			$payload['parameters']['key'] = sanitize_text_field( $options['key'] );
		}

		WP_MCP_AI_Logger::log_event(
			'gemini_music_request',
			'Generating music with Gemini Lyria.',
			array(
				'prompt'      => $full_prompt,
				'duration'    => $duration,
				'bpm'         => $bpm,
				'temperature' => $temperature,
			)
		);

		// Make API request.
		// Note: This is a simplified implementation. In production, you would use.

		// the Vertex AI endpoint: https://LOCATION-aiplatform.googleapis.com/v1/projects/PROJECT_ID/locations/LOCATION/publishers/google/models/MODEL_ID:predict.

		$endpoint = $this->get_music_endpoint( $model );
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Gemini music generation request failed.', array( 'error' => $response->get_error_message() ) );
			return new WP_Error(
				'wp_mcp_ai_http_error',
				__( 'The music generation request failed to complete.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'Gemini music generation API error.',
				array(
					'status_code' => $status_code,
					'body'        => $body,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Music generation failed with status %d.', 'wp-mcp-ai' ),
					$status_code
				),
				array( 'status' => $status_code )
			);
		}

		$data = json_decode( $body, true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Music generation returned an invalid response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Extract audio data from response.
		// Note: The actual response structure may vary. This is a simplified example.
		$audio_data = $this->extract_audio_from_response( $data );

		if ( is_wp_error( $audio_data ) ) {
			return $audio_data;
		}

		WP_MCP_AI_Logger::log_event(
			'gemini_music_success',
			'Music generated successfully.',
			array(
				'duration'    => $audio_data['duration'],
				'format'      => $audio_data['format'],
				'sample_rate' => $audio_data['sample_rate'],
			)
		);

		return $audio_data;
	}

	/**
	 * Build full prompt with optional modifiers.
	 *
	 * @param string $base_prompt Base prompt text.
	 * @param array  $options     Options array.
	 * @return string Enhanced prompt.
	 */
	protected function build_full_prompt( $base_prompt, array $options ) {
		$parts = array( $base_prompt );

		if ( isset( $options['genre'] ) && ! empty( $options['genre'] ) ) {
			$parts[] = 'Genre: ' . sanitize_text_field( $options['genre'] );
		}

		if ( isset( $options['mood'] ) && ! empty( $options['mood'] ) ) {
			$parts[] = 'Mood: ' . sanitize_text_field( $options['mood'] );
		}

		if ( isset( $options['instrumentation'] ) && ! empty( $options['instrumentation'] ) ) {
			$parts[] = 'Instruments: ' . sanitize_text_field( $options['instrumentation'] );
		}

		return implode( '. ', $parts );
	}

	/**
	 * Get the music generation endpoint URL.
	 *
	 * @param string $model Model name.
	 * @return string Endpoint URL.
	 */
	protected function get_music_endpoint( $model ) {
		// For now, using a placeholder endpoint structure.
		// In production, this would be the Vertex AI endpoint or Gemini API endpoint.
		return sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s:generateMusic',
			rawurlencode( $model )
		);
	}

	/**
	 * Extract audio data from API response.
	 *
	 * @param array $response API response data.
	 * @return array|WP_Error Audio data or error.
	 */
	protected function extract_audio_from_response( array $response ) {
		// Note: This is a simplified implementation.
		// The actual response structure from Google's API should be consulted.

		if ( isset( $response['predictions'] ) && is_array( $response['predictions'] ) ) {
			$prediction = $response['predictions'][0] ?? array();

			if ( isset( $prediction['audio_content'] ) ) {
				return array(
					'audio'       => $prediction['audio_content'],
					'format'      => $prediction['audio_format'] ?? 'wav',
					'mime_type'   => $prediction['mime_type'] ?? 'audio/wav',
					'duration'    => $prediction['duration'] ?? 0,
					'sample_rate' => $prediction['sample_rate'] ?? 48000,
					'prompt'      => $prediction['prompt'] ?? '',
					'model'       => self::DEFAULT_MODEL,
				);
			}
		}

		// Fallback: try alternate response structures.
		if ( isset( $response['audio'] ) ) {
			return array(
				'audio'       => $response['audio'],
				'format'      => $response['format'] ?? 'wav',
				'mime_type'   => $response['mime_type'] ?? 'audio/wav',
				'duration'    => $response['duration'] ?? 0,
				'sample_rate' => $response['sample_rate'] ?? 48000,
				'prompt'      => $response['prompt'] ?? '',
				'model'       => self::DEFAULT_MODEL,
			);
		}

		return new WP_Error(
			'wp_mcp_ai_missing_audio_data',
			__( 'No audio data found in the API response.', 'wp-mcp-ai' ),
			array( 'status' => 500 )
		);
	}
}
