<?php
/**
 * Gemini Music Generation Service
 *
 * Handles music generation using Google's Lyria models through third-party APIs.
 * Supports prompt-based music creation with customizable parameters.
 *
 * Note: Direct Gemini API access to Lyria is currently WebSocket-based and requires
 * Python SDK. This service uses third-party REST APIs (segmind.com, AIMLAPI) that
 * provide HTTP access to the Lyria model.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini Music Service class
 *
 * Responsible for:
 * - Submitting music generation requests to Lyria APIs
 * - Processing prompt-based music creation
 * - Downloading generated audio files
 * - Managing audio file uploads to WordPress media library
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Gemini_Music_Service {

	/**
	 * Segmind Lyria API endpoint
	 *
	 * @var string
	 */
	const SEGMIND_API_ENDPOINT = 'https://api.segmind.com/v1/lyria-2';

	/**
	 * AIMLAPI Lyria endpoint
	 *
	 * @var string
	 */
	const AIMLAPI_ENDPOINT = 'https://api.aimlapi.com/v2/generate/audio';

	/**
	 * Default music duration in seconds
	 *
	 * @var int
	 */
	const DEFAULT_DURATION = 30;

	/**
	 * Maximum music duration in seconds
	 *
	 * @var int
	 */
	const MAX_DURATION = 120;

	/**
	 * Minimum music duration in seconds
	 *
	 * @var int
	 */
	const MIN_DURATION = 5;

	/**
	 * Generate music using the Lyria model via third-party API.
	 *
	 * @param string $prompt Music generation prompt describing desired music.
	 * @param array  $options {
	 *     Optional. Music generation options.
	 *
	 *     @type string $negative_prompt Elements to exclude from generation.
	 *     @type int    $duration        Duration in seconds (5-120).
	 *     @type int    $seed            Random seed for reproducibility.
	 *     @type string $api_provider    API provider ('segmind' or 'aimlapi'). Default 'segmind'.
	 *     @type string $api_key         Third-party API key (overrides settings).
	 *     @type int    $timeout         Request timeout in seconds.
	 * }
	 * @return array|WP_Error {
	 *     Success: Array with audio data and metadata.
	 *     Error: WP_Error object.
	 *
	 *     @type string $audio_url     URL to generated audio file (temporary).
	 *     @type string $audio_data    Base64-encoded audio data (if applicable).
	 *     @type string $format        Audio format (mp3, wav).
	 *     @type int    $duration      Actual duration in seconds.
	 *     @type string $prompt        Original prompt used.
	 * }
	 */
	public function generate_music( $prompt, $options = array() ) {
		$prompt = sanitize_textarea_field( $prompt );
		$prompt = trim( $prompt );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_music_prompt',
				__( 'Music generation prompt cannot be empty.', 'wp-mcp-ai' )
			);
		}

		// Parse options.
		$defaults = array(
			'negative_prompt' => '',
			'duration'        => self::DEFAULT_DURATION,
			'seed'            => null,
			'api_provider'    => 'segmind',
			'api_key'         => null,
			'timeout'         => 120,
		);

		$options = wp_parse_args( $options, $defaults );

		// Validate duration.
		$duration = absint( $options['duration'] );
		if ( $duration < self::MIN_DURATION || $duration > self::MAX_DURATION ) {
			$duration = self::DEFAULT_DURATION;
		}

		// Get API key.
		$api_key = $this->get_api_key( $options['api_provider'], $options['api_key'] );
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		// Build request payload.
		$payload = $this->build_request_payload( $prompt, $options );

		// Select endpoint.
		$endpoint = $this->get_api_endpoint( $options['api_provider'] );

		// Make API request.
		$result = $this->make_api_request( $endpoint, $api_key, $payload, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Get API key for the specified provider.
	 *
	 * @param string      $provider Provider name ('segmind' or 'aimlapi').
	 * @param string|null $override Override API key.
	 * @return string|WP_Error API key or error.
	 */
	protected function get_api_key( $provider, $override = null ) {
		if ( ! empty( $override ) ) {
			return sanitize_text_field( $override );
		}

		// Get from settings.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_settings_class',
				__( 'Settings class not available.', 'wp-mcp-ai' )
			);
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$setting_key = 'segmind' === $provider ? 'segmind_api_key' : 'aimlapi_key';

		if ( empty( $settings[ $setting_key ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_music_api_key',
				sprintf(
					/* translators: %s: provider name */
					__( 'No %s API key configured for music generation.', 'wp-mcp-ai' ),
					ucfirst( $provider )
				)
			);
		}

		return $settings[ $setting_key ];
	}

	/**
	 * Get API endpoint for the specified provider.
	 *
	 * @param string $provider Provider name.
	 * @return string API endpoint URL.
	 */
	protected function get_api_endpoint( $provider ) {
		return 'aimlapi' === $provider ? self::AIMLAPI_ENDPOINT : self::SEGMIND_API_ENDPOINT;
	}

	/**
	 * Build request payload for the API.
	 *
	 * @param string $prompt  Music prompt.
	 * @param array  $options Generation options.
	 * @return array Request payload.
	 */
	protected function build_request_payload( $prompt, $options ) {
		$payload = array(
			'prompt' => $prompt,
		);

		if ( ! empty( $options['negative_prompt'] ) ) {
			$payload['negative_prompt'] = sanitize_textarea_field( $options['negative_prompt'] );
		}

		if ( ! empty( $options['seed'] ) ) {
			$payload['seed'] = absint( $options['seed'] );
		}

		// Add duration if supported by provider.
		if ( isset( $options['duration'] ) ) {
			$payload['duration'] = absint( $options['duration'] );
		}

		return $payload;
	}

	/**
	 * Make API request to generate music.
	 *
	 * @param string $endpoint API endpoint URL.
	 * @param string $api_key  API key.
	 * @param array  $payload  Request payload.
	 * @param array  $options  Generation options.
	 * @return array|WP_Error Response data or error.
	 */
	protected function make_api_request( $endpoint, $api_key, $payload, $options ) {
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 120;

		$request_args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $timeout,
		);

		WP_MCP_AI_Logger::log_event(
			'gemini_music_request',
			'Sending music generation request.',
			array(
				'endpoint' => $endpoint,
				'prompt'   => $payload['prompt'],
			)
		);

		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_event(
				'gemini_music_error',
				'Music generation request failed.',
				array(
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_music_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Music generation request failed: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_message = $this->parse_error_response( $response_body, $response_code );

			WP_MCP_AI_Logger::log_event(
				'gemini_music_error',
				'Music generation API returned error.',
				array(
					'status_code' => $response_code,
					'error'       => $error_message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_music_api_error',
				$error_message,
				array( 'status' => $response_code )
			);
		}

		// Parse response.
		$data = json_decode( $response_body, true );

		if ( null === $data ) {
			return new WP_Error(
				'wp_mcp_ai_music_invalid_response',
				__( 'Invalid JSON response from music generation API.', 'wp-mcp-ai' )
			);
		}

		// Extract audio data/URL.
		return $this->parse_music_response( $data, $payload );
	}

	/**
	 * Parse error response from API.
	 *
	 * @param string $response_body Response body.
	 * @param int    $status_code   HTTP status code.
	 * @return string Error message.
	 */
	protected function parse_error_response( $response_body, $status_code ) {
		$data = json_decode( $response_body, true );

		if ( isset( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}

		if ( isset( $data['message'] ) ) {
			return $data['message'];
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Music generation failed with status code %d.', 'wp-mcp-ai' ),
			$status_code
		);
	}

	/**
	 * Parse successful music generation response.
	 *
	 * @param array $data    Response data.
	 * @param array $payload Original request payload.
	 * @return array Parsed music data.
	 */
	protected function parse_music_response( $data, $payload ) {
		$result = array(
			'prompt' => $payload['prompt'],
			'format' => 'mp3', // Default format.
		);

		// Handle different response formats from providers.
		if ( isset( $data['url'] ) ) {
			$result['audio_url'] = esc_url_raw( $data['url'] );
		} elseif ( isset( $data['audio_url'] ) ) {
			$result['audio_url'] = esc_url_raw( $data['audio_url'] );
		} elseif ( isset( $data['data'] ) ) {
			$result['audio_data'] = $data['data'];
		} elseif ( isset( $data['audio'] ) ) {
			$result['audio_data'] = $data['audio'];
		}

		if ( isset( $data['duration'] ) ) {
			$result['duration'] = absint( $data['duration'] );
		}

		if ( isset( $data['format'] ) ) {
			$result['format'] = sanitize_key( $data['format'] );
		}

		return $result;
	}

	/**
	 * Download music from URL and save to WordPress media library.
	 *
	 * @param string $audio_url  URL to audio file.
	 * @param string $title      Audio title.
	 * @param string $prompt     Original generation prompt.
	 * @param string $format     Audio format (mp3, wav).
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function save_to_media_library( $audio_url, $title, $prompt, $format = 'mp3' ) {
		if ( empty( $audio_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_audio_url',
				__( 'No audio URL provided.', 'wp-mcp-ai' )
			);
		}

		// Download audio file.
		$temp_file = download_url( $audio_url );

		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error(
				'wp_mcp_ai_music_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download generated music: %s', 'wp-mcp-ai' ),
					$temp_file->get_error_message()
				)
			);
		}

		// Prepare file for upload.
		$file_name = sanitize_file_name( $title ) . '.' . $format;

		$file_array = array(
			'name'     => $file_name,
			'tmp_name' => $temp_file,
		);

		// Upload to media library.
		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			$title,
			array(
				'post_excerpt' => $prompt,
			)
		);

		// Clean up temp file.
		if ( file_exists( $temp_file ) ) {
			@unlink( $temp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_music_upload_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to upload music to media library: %s', 'wp-mcp-ai' ),
					$attachment_id->get_error_message()
				)
			);
		}

		// Add metadata.
		update_post_meta( $attachment_id, '_wp_mcp_ai_generated', true );
		update_post_meta( $attachment_id, '_wp_mcp_ai_generation_prompt', $prompt );
		update_post_meta( $attachment_id, '_wp_mcp_ai_generation_model', 'gemini-lyria' );

		return $attachment_id;
	}
}
