<?php
/**
 * Gemini Video Generation Service
 *
 * Handles video generation using Google's Veo 3.1 model through the Gemini API.
 * Manages async video generation, polling, and file download.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini Video Generation Service class
 *
 * Responsible for:
 * - Submitting video generation requests to Veo 3.1
 * - Polling for completion status
 * - Downloading generated videos
 * - Managing long-running operations
 *
 * SoC Architecture:
 * - Tools call this service for video generation
 * - Service uses Gemini Client for API communication
 * - Service handles async operation polling
 * - Returns video data for WordPress integration
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Gemini_Video_Generation_Service {

	/**
	 * Veo 3.1 model identifier
	 *
	 * @var string
	 */
	const VEO_MODEL = 'veo-3.1-generate-preview';

	/**
	 * Minimum video duration in seconds
	 *
	 * @var int
	 */
	const MIN_DURATION = 4;

	/**
	 * Maximum video duration in seconds
	 *
	 * @var int
	 */
	const MAX_DURATION = 8;

	/**
	 * Default video duration in seconds
	 *
	 * @var int
	 */
	const DEFAULT_DURATION = 5;

	/**
	 * Maximum polling attempts
	 *
	 * @var int
	 */
	const MAX_POLLING_ATTEMPTS = 60;

	/**
	 * Polling interval in seconds
	 *
	 * @var int
	 */
	const POLLING_INTERVAL = 5;

	/**
	 * Generate a video using Veo 3.1
	 *
	 * @param array $args {
	 *     Video generation arguments.
	 *
	 *     @type string $prompt           Video description/prompt (required).
	 *     @type int    $duration         Duration in seconds (4-8, default 5).
	 *     @type string $aspect_ratio     Aspect ratio: '16:9', '9:16' (default '16:9').
	 *     @type string $resolution       Resolution: '720p', '1080p' (default '720p').
	 *     @type string $negative_prompt  What to avoid in generation.
	 *     @type int    $seed             Random seed for reproducibility.
	 *     @type string $image_base64     Base64-encoded reference image.
	 *     @type string $image_mime_type  MIME type of reference image.
	 * }
	 * @return array|WP_Error Video data or error.
	 */
	public function generate_video( array $args ) {
		// Validate required parameters.
		if ( empty( $args['prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Video generation requires a prompt.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Build the request payload.
		$payload = $this->build_generation_payload( $args );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Submit the generation request.
		$operation = $this->submit_generation_request( $payload );

		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		// Poll for completion.
		$result = $this->poll_for_completion( $operation );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Download and return video data.
		return $this->process_completed_video( $result, $args );
	}

	/**
	 * Build the generation payload for Veo 3.1
	 *
	 * @param array $args Generation arguments.
	 * @return array|WP_Error Payload or error.
	 */
	protected function build_generation_payload( $args ) {
		$prompt = sanitize_textarea_field( $args['prompt'] );

		// Duration validation (Veo API requires 4-8 seconds).
		$duration = isset( $args['duration'] ) ? absint( $args['duration'] ) : self::DEFAULT_DURATION;
		if ( $duration < self::MIN_DURATION || $duration > self::MAX_DURATION ) {
			$duration = self::DEFAULT_DURATION;
		}

		// Aspect ratio validation.
		$aspect_ratio = isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9';
		if ( ! in_array( $aspect_ratio, array( '16:9', '9:16' ), true ) ) {
			$aspect_ratio = '16:9';
		}

		// Resolution validation.
		$resolution = isset( $args['resolution'] ) ? $args['resolution'] : '720p';
		if ( ! in_array( $resolution, array( '720p', '1080p' ), true ) ) {
			$resolution = '720p';
		}

		// 1080p only supported for 16:9.
		if ( '1080p' === $resolution && '9:16' === $aspect_ratio ) {
			$resolution = '720p';
		}

		// 1080p requires 8 seconds duration (2025 API requirement).
		if ( '1080p' === $resolution && 8 !== $duration ) {
			$duration = 8;
		}

		// Build instance data.
		$instance = array(
			'prompt' => $prompt,
		);

		// Add reference image if provided.
		if ( ! empty( $args['image_base64'] ) ) {
			$mime_type         = isset( $args['image_mime_type'] ) ? $args['image_mime_type'] : 'image/jpeg';
			$instance['image'] = array(
				'bytesBase64Encoded' => $args['image_base64'],
				'mimeType'           => $mime_type,
			);
		}

		// Build parameters.
		// Note: 'model' parameter is not included here as it's already specified in the API endpoint URL.
		// Including it causes "model isn't supported" error from the Gemini API.
		$parameters = array(
			'sampleCount'     => 1,
			'aspectRatio'     => $aspect_ratio,
			'resolution'      => $resolution,
			'durationSeconds' => $duration,
			// Note: 'generateAudio' is not supported by Veo 3.1 model - removed to prevent API errors.
		);

		// Add optional parameters.
		if ( ! empty( $args['negative_prompt'] ) ) {
			$parameters['negativePrompt'] = sanitize_textarea_field( $args['negative_prompt'] );
		}

		if ( isset( $args['seed'] ) ) {
			$parameters['seed'] = absint( $args['seed'] );
		}

		// Note: 'personGeneration' parameter is not supported by Veo 3.1 API - removed to prevent API errors.

		return array(
			'instances'  => array( $instance ),
			'parameters' => $parameters,
		);
	}

	/**
	 * Submit video generation request to Gemini API
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error Operation details or error.
	 */
	protected function submit_generation_request( $payload ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Veo endpoint uses predictLongRunning for async operations.
		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:predictLongRunning',
			rawurlencode( self::VEO_MODEL )
		);

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 60,
		);

		WP_MCP_AI_Logger::log_event(
			'veo_generation_request',
			'Submitting Veo video generation request',
			array(
				'prompt'   => substr( $payload['instances'][0]['prompt'], 0, 100 ),
				'duration' => $payload['parameters']['durationSeconds'],
			)
		);

		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Veo generation request failed',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'Veo generation request failed',
				array(
					'status' => $code,
					'body'   => $body,
				)
			);

			$error_message = __( 'Video generation request failed.', 'wp-mcp-ai' );
			if ( isset( $data['error']['message'] ) ) {
				$error_message = $data['error']['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_veo_request_failed',
				$error_message,
				array( 'status' => $code )
			);
		}

		// The response should contain an operation name.
		if ( ! isset( $data['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from Veo API - no operation name.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'operation_name' => $data['name'],
			'metadata'       => isset( $data['metadata'] ) ? $data['metadata'] : array(),
		);
	}

	/**
	 * Poll for video generation completion
	 *
	 * @param array $operation Operation details.
	 * @return array|WP_Error Completed operation data or error.
	 */
	protected function poll_for_completion( $operation ) {
		$operation_name = $operation['operation_name'];
		$settings       = get_option( 'wp_mcp_ai_settings', array() );
		$api_key        = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s',
			$operation_name
		);

		$attempts = 0;

		while ( $attempts < self::MAX_POLLING_ATTEMPTS ) {
			++$attempts;

			// Wait before polling.
			if ( $attempts > 1 ) {
				sleep( self::POLLING_INTERVAL );
			}

			$request_args = array(
				'headers' => array(
					'x-goog-api-key' => $api_key,
				),
				'timeout' => 30,
			);

			$response = wp_remote_get( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				// Continue polling on transient errors.
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			// Check if operation is done.
			if ( isset( $data['done'] ) && true === $data['done'] ) {
				// Check for errors.
				if ( isset( $data['error'] ) ) {
					WP_MCP_AI_Logger::log_error(
						'Veo generation failed',
						array( 'error' => $data['error'] )
					);

					$error_message = isset( $data['error']['message'] )
						? $data['error']['message']
						: __( 'Video generation failed.', 'wp-mcp-ai' );

					return new WP_Error(
						'wp_mcp_ai_veo_generation_failed',
						$error_message,
						array( 'status' => 500 )
					);
				}

				// Success - return the result.
				WP_MCP_AI_Logger::log_event(
					'veo_generation_complete',
					'Veo video generation completed',
					array( 'attempts' => $attempts )
				);

				return $data;
			}

			// Log progress.
			if ( 1 === $attempts || 0 === $attempts % 10 ) {
				WP_MCP_AI_Logger::log_event(
					'veo_generation_polling',
					sprintf( 'Polling for completion (attempt %d/%d)', $attempts, self::MAX_POLLING_ATTEMPTS ),
					array( 'operation' => $operation_name )
				);
			}
		}

		// Timeout.
		return new WP_Error(
			'wp_mcp_ai_veo_timeout',
			__( 'Video generation timed out. The operation may still be processing on Google\'s servers.', 'wp-mcp-ai' ),
			array( 'status' => 504 )
		);
	}

	/**
	 * Process completed video and download data
	 *
	 * @param array $result Completed operation result.
	 * @param array $args   Original generation arguments.
	 * @return array|WP_Error Video data or error.
	 */
	protected function process_completed_video( $result, $args ) {
		// Extract video URL from response.
		if ( ! isset( $result['response']['predictions'][0]['videoUri'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_video_uri',
				__( 'No video URI in completion response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$video_uri = $result['response']['predictions'][0]['videoUri'];

		// Download the video.
		$video_data = $this->download_video( $video_uri );

		if ( is_wp_error( $video_data ) ) {
			return $video_data;
		}

		// Return video data with metadata.
		return array(
			'video_data'   => $video_data,
			'video_uri'    => $video_uri,
			'prompt'       => $args['prompt'],
			'duration'     => isset( $args['duration'] ) ? $args['duration'] : self::DEFAULT_DURATION,
			'aspect_ratio' => isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9',
			'resolution'   => isset( $args['resolution'] ) ? $args['resolution'] : '720p',
			'model'        => self::VEO_MODEL,
			'provider'     => 'gemini',
		);
	}

	/**
	 * Download generated video from URI
	 *
	 * @param string $video_uri Video URI from Gemini.
	 * @return string|WP_Error Video binary data or error.
	 */
	protected function download_video( $video_uri ) {
		// The URI should be a signed GCS URL that we can download directly.
		$response = wp_remote_get(
			$video_uri,
			array(
				'timeout' => 300,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to download generated video',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Failed to download video. HTTP status: %d', 'wp-mcp-ai' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$video_data = wp_remote_retrieve_body( $response );

		if ( empty( $video_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_video',
				__( 'Downloaded video is empty.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'veo_video_downloaded',
			'Downloaded generated video',
			array( 'size' => strlen( $video_data ) )
		);

		return $video_data;
	}
}
