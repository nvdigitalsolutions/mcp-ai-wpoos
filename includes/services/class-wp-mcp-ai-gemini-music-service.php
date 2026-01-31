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
	public function generate_music( $prompt, array $options = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for advanced music options.
		$prompt = trim( (string) $prompt );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_music_prompt',
				__( 'Music generation prompt cannot be empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Get API key.
		$api_key = $this->client->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_gemini_api_key',
				__( 'No Gemini API key has been configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Return clear error about API unavailability.
		WP_MCP_AI_Logger::log_error(
			'Lyria music generation attempted but API not available.',
			array( 'prompt' => $prompt )
		);

		return new WP_Error(
			'wp_mcp_ai_lyria_api_unavailable',
			__( 'Google Lyria music generation is not currently available. The Lyria API requires either Vertex AI (Google Cloud Platform project with credentials) or WebSocket streaming, which are not supported by this plugin at this time. For music generation, please consider alternative services or wait for Google to release a REST API endpoint for Lyria.', 'mcp-ai-wpoos' ),
			array(
				'status'  => 501,
				'details' => array(
					'reason'       => 'api_not_implemented',
					'requirements' => array(
						'Vertex AI (requires GCP project and service account credentials)',
						'Or: WebSocket streaming (real-time only, complex implementation)',
					),
					'alternatives' => array(
						'OpenAI Jukebox (requires separate GPU server)',
						'Third-party music generation APIs',
					),
				),
			)
		);
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
			__( 'No audio data found in the API response.', 'mcp-ai-wpoos' ),
			array( 'status' => 500 )
		);
	}
}
