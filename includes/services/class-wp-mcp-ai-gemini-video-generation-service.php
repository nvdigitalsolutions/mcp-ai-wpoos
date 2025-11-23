<?php
/**
 * Gemini Video Generation Service
 *
 * Handles video generation using Google's Veo 3.1 model through the Gemini API.
 * Manages async video generation, polling, and file download.
 *
 * Google API Requirements Compliance (2025):
 * - Authentication: Uses x-goog-api-key header for API calls (line 235)
 * - Video Download Auth: Appends API key as query parameter for GCS URLs (line 565)
 * - Rate Limits: 10 RPM for preview access (documented in tool capability flags)
 * - Watermarking: All videos automatically include SynthID digital watermark by Google
 * - Content Policy: Relies on Google's API-side content moderation
 * - Timeout Handling: 60s initial timeout + 5min max polling (300s)
 * - 1080p Requirement: Enforces 8 seconds duration for 1080p resolution (REQUIRED_1080P_DURATION constant)
 * - Aspect Ratio: 1080p only supported for 16:9 (line 165-168)
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Gemini Video Generation Service class
 *
 * Responsible for:
 * - Submitting video generation requests to Veo 3.1
 * - Polling for completion status
 * - Downloading generated videos
 * - Managing long-running operations
 * - Supporting async mode with cron-based polling
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
	 * Veo 3.1 model identifier (primary)
	 *
	 * @var string
	 */
	const VEO_MODEL = 'veo-3.1-generate-preview';

	/**
	 * Veo 2.0 model identifier (fallback)
	 *
	 * @var string
	 */
	const VEO_2_MODEL = 'veo-2.0-generate-001';

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
	const DEFAULT_DURATION = 4;

	/**
	 * Required duration for 1080p resolution (2025 API requirement)
	 *
	 * @var int
	 */
	const REQUIRED_1080P_DURATION = 8;

	/**
	 * Minimum video duration for Veo 2 in seconds
	 *
	 * @var int
	 */
	const VEO_2_MIN_DURATION = 5;

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
	 * Cron hook for async video polling
	 *
	 * @var string
	 */
	const CRON_POLL_HOOK = 'wp_mcp_ai_poll_veo_video';

	/**
	 * Transient prefix for async operations
	 *
	 * @var string
	 */
	const ASYNC_OP_PREFIX = 'wp_mcp_ai_veo_async_';

	/**
	 * Initialize the service and register hooks
	 */
	public static function init() {
		// Register cron hook for async polling.
		add_action( self::CRON_POLL_HOOK, array( __CLASS__, 'poll_video_async_static' ), 10, 1 );
	}

	/**
	 * Static wrapper for cron callback
	 *
	 * @param string $job_id Job identifier.
	 */
	public static function poll_video_async_static( $job_id ) {
		$service = new self();
		$service->poll_video_async( $job_id );
	}

	/**
	 * Generate a video using Veo 3.1 with automatic Veo 2.0 fallback.
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
	 *     @type bool   $async            Whether to use async mode (cron fallback).
	 *     @type int    $user_id          User ID for async operations.
	 *     @type string $model            Optional. Force specific model ('veo-3.1' or 'veo-2.0').
	 * }
	 * @return array|WP_Error Video data or async job info on success, error on failure.
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

		// Try Veo 3.1 first, unless Veo 2 is explicitly requested.
		$force_veo_2 = isset( $args['model'] ) && 'veo-2.0' === $args['model'];

		if ( ! $force_veo_2 ) {
			$result = $this->generate_video_with_model( $args, self::VEO_MODEL );

			// If successful or async, return immediately.
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			// Check if this is a retryable error that warrants Veo 2 fallback.
			if ( $this->should_fallback_to_veo_2( $result ) ) {
				WP_MCP_AI_Logger::log_event(
					'veo_fallback_to_veo_2',
					'Veo 3.1 failed, attempting Veo 2.0 fallback',
					array(
						'veo_3_error' => $result->get_error_message(),
						'error_code'  => $result->get_error_code(),
					)
				);

				// Attempt with Veo 2.0.
				$veo_2_result = $this->generate_video_with_model( $args, self::VEO_2_MODEL );

				// If Veo 2 succeeds, add metadata about fallback.
				if ( ! is_wp_error( $veo_2_result ) ) {
					if ( isset( $veo_2_result['async'] ) && $veo_2_result['async'] ) {
						$veo_2_result['fallback_used'] = true;
						$veo_2_result['primary_model_error'] = $result->get_error_message();
					}
					return $veo_2_result;
				}

				// Both failed - return the original Veo 3 error with context.
				return new WP_Error(
					$result->get_error_code(),
					sprintf(
						/* translators: 1: Veo 3 error, 2: Veo 2 error */
						__( 'Video generation failed. Veo 3.1: %1$s. Veo 2.0 fallback also failed: %2$s', 'wp-mcp-ai' ),
						$result->get_error_message(),
						$veo_2_result->get_error_message()
					),
					array( 'status' => 500 )
				);
			}

			// Not a retryable error, return the original error.
			return $result;
		}

		// Veo 2 explicitly requested.
		return $this->generate_video_with_model( $args, self::VEO_2_MODEL );
	}

	/**
	 * Generate video with a specific model.
	 *
	 * @param array  $args  Generation arguments.
	 * @param string $model Model identifier.
	 * @return array|WP_Error Video data or async job info on success, error on failure.
	 */
	protected function generate_video_with_model( array $args, $model ) {
		// Check if async mode is requested.
		$use_async = isset( $args['async'] ) && $args['async'];

		// Build the request payload for the specified model.
		$payload = $this->build_generation_payload( $args, $model );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Submit the generation request.
		$operation = $this->submit_generation_request( $payload, $model );

		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		// Store model info in operation for later reference.
		$operation['model_used'] = $model;

		// If async mode, queue cron job and return job ID.
		if ( $use_async ) {
			return $this->queue_async_polling( $operation, $args );
		}

		// Otherwise, poll synchronously (existing behavior).
		// Pass args for potential async fallback on timeout.
		$result = $this->poll_for_completion( $operation, $args );

		// Check for error response.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Check if result is an async fallback response (from timeout detection).
		if ( isset( $result['async'] ) && $result['async'] ) {
			return $result;
		}

		// Download and return video data for synchronous completion.
		return $this->process_completed_video( $result, $args, $model );
	}

	/**
	 * Determine if error warrants fallback to Veo 2.
	 *
	 * @param WP_Error $error Error from Veo 3 attempt.
	 * @return bool True if should fallback to Veo 2.
	 */
	protected function should_fallback_to_veo_2( $error ) {
		$error_message = $error->get_error_message();

		// Quota/rate limit errors.
		$quota_indicators = array(
			'quota',
			'rate limit',
			'too many requests',
			'resource exhausted',
			'insufficient quota',
			'quota exceeded',
		);

		if ( $this->error_message_contains( $error_message, $quota_indicators ) ) {
			return true;
		}

		// Model unavailable errors.
		$availability_indicators = array(
			'not available',
			'unavailable',
			'not found',
			'does not exist',
			'not supported',
			'model not found',
			'invalid model',
		);

		if ( $this->error_message_contains( $error_message, $availability_indicators ) ) {
			return true;
		}

		// HTTP status codes that suggest quota/availability issues.
		$error_data = $error->get_error_data();
		if ( isset( $error_data['status'] ) ) {
			$status = $error_data['status'];
			// 429 = Too Many Requests, 403 = Forbidden (quota), 503 = Service Unavailable.
			if ( in_array( $status, array( 403, 429, 503 ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if error message contains any of the given indicators.
	 *
	 * @param string $message    Error message to check.
	 * @param array  $indicators Array of strings to search for (case-insensitive).
	 * @return bool True if any indicator is found in message.
	 */
	protected function error_message_contains( $message, $indicators ) {
		foreach ( $indicators as $indicator ) {
			if ( false !== stripos( $message, $indicator ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the generation payload for Veo models.
	 *
	 * Validates and sanitizes all parameters before sending to the Gemini API.
	 * Adjusts constraints based on the model being used (Veo 3.1 vs Veo 2.0).
	 * Duration validation is performed in multiple stages:
	 * 1. Initial validation: Convert to integer and check range based on model
	 * 2. Model-specific adjustments: Veo 2 min 5s, 1080p requires 8s for Veo 3
	 * 3. Final validation: Safety check to ensure valid duration before API call
	 *
	 * @param array  $args  Generation arguments.
	 * @param string $model Model identifier (VEO_MODEL or VEO_2_MODEL).
	 * @return array|WP_Error Payload or error.
	 */
	protected function build_generation_payload( $args, $model = null ) {
		// Default to Veo 3.1 if not specified.
		if ( null === $model ) {
			$model = self::VEO_MODEL;
		}

		$is_veo_2 = ( self::VEO_2_MODEL === $model );

		$prompt = sanitize_textarea_field( $args['prompt'] );

		// Duration validation - depends on model.
		// Stage 1: Initial validation and sanitization.
		$min_duration = $is_veo_2 ? self::VEO_2_MIN_DURATION : self::MIN_DURATION;
		$duration     = isset( $args['duration'] ) ? absint( $args['duration'] ) : self::DEFAULT_DURATION;

		// Adjust duration if below model minimum.
		if ( $duration < $min_duration ) {
			$duration = $min_duration;
		}

		// Ensure within max range.
		if ( $duration > self::MAX_DURATION ) {
			$duration = self::MAX_DURATION;
		}

		// Aspect ratio validation.
		$aspect_ratio = isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9';
		if ( ! in_array( $aspect_ratio, array( '16:9', '9:16' ), true ) ) {
			$aspect_ratio = '16:9';
		}

		// Resolution validation.
		// Note: Veo 2.0 does NOT support the 'resolution' parameter at all.
		// Only Veo 3.1 supports resolution parameter ('720p' or '1080p').
		// Initialize as null - will only be set for Veo 3.1.
		$resolution = null;

		if ( ! $is_veo_2 ) {
			// Veo 3.1: Validate and set resolution.
			$resolution = isset( $args['resolution'] ) ? $args['resolution'] : '720p';
			if ( ! in_array( $resolution, array( '720p', '1080p' ), true ) ) {
				$resolution = '720p';
			}

			// 1080p only supported for 16:9.
			if ( '1080p' === $resolution && '9:16' === $aspect_ratio ) {
				$resolution = '720p';
			}

			// Stage 2: Veo 3.1 - 1080p requires 8 seconds duration (2025 API requirement).
			if ( '1080p' === $resolution && self::REQUIRED_1080P_DURATION !== $duration ) {
				$duration = self::REQUIRED_1080P_DURATION;
			}
		} else {
			// Veo 2.0: Resolution parameter not supported - keep as null.
			// Log if resolution was requested (will be ignored regardless of value).
			if ( isset( $args['resolution'] ) ) {
				WP_MCP_AI_Logger::log_event(
					'veo_2_resolution_not_supported',
					'Veo 2.0 does not support resolution parameter, using model default (720p)',
					array( 'requested' => $args['resolution'] )
				);
			}
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
			'durationSeconds' => $duration,
			// Note: 'generateAudio' is not supported by Veo models - removed to prevent API errors.
		);

		// Only add resolution parameter for Veo 3.1 (not supported by Veo 2.0).
		if ( ! $is_veo_2 && null !== $resolution ) {
			$parameters['resolution'] = $resolution;
		}

		// Add optional parameters.
		if ( ! empty( $args['negative_prompt'] ) ) {
			$parameters['negativePrompt'] = sanitize_textarea_field( $args['negative_prompt'] );
		}

		if ( isset( $args['seed'] ) ) {
			$parameters['seed'] = absint( $args['seed'] );
		}

		// Note: 'personGeneration' parameter is not supported by Veo 3.1 API - removed to prevent API errors.

		// Stage 3: Final validation as a safety check.
		// This ensures duration is always within valid range (4-8 seconds) even if there are edge cases
		// in the validation logic above. This prevents "durationSeconds is out of bound" API errors.
		if ( ! is_int( $parameters['durationSeconds'] ) || $parameters['durationSeconds'] < self::MIN_DURATION || $parameters['durationSeconds'] > self::MAX_DURATION ) {
			$parameters['durationSeconds'] = self::DEFAULT_DURATION;
		}

		return array(
			'instances'  => array( $instance ),
			'parameters' => $parameters,
		);
	}

	/**
	 * Submit video generation request to Gemini API.
	 *
	 * @param array  $payload Request payload.
	 * @param string $model   Model identifier.
	 * @return array|WP_Error Operation details or error.
	 */
	protected function submit_generation_request( $payload, $model = null ) {
		// Default to Veo 3.1 if not specified.
		if ( null === $model ) {
			$model = self::VEO_MODEL;
		}

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
			rawurlencode( $model )
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
	 * Poll for video generation completion.
	 *
	 * @param array $operation Operation details.
	 * @param array $args Optional. Original generation arguments for async fallback on timeout.
	 * @return array|WP_Error Completed operation data or error.
	 */
	protected function poll_for_completion( $operation, $args = array() ) {
		$operation_name = $operation['operation_name'];
		$settings       = get_option( 'wp_mcp_ai_settings', array() );
		$api_key        = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s',
			$operation_name
		);

		$attempts = 0;

		// Initialize timeout detector for async fallback.
		// Note: Service is already loaded in services-init.php, but we require here
		// to ensure it's available even if called directly without full initialization.
		if ( ! class_exists( 'WP_MCP_AI_Timeout_Detection_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-timeout-detection-service.php';
		}
		$timeout_detector = new WP_MCP_AI_Timeout_Detection_Service( 10 );

		while ( $attempts < self::MAX_POLLING_ATTEMPTS ) {
			++$attempts;

			// Wait before polling.
			if ( $attempts > 1 ) {
				sleep( self::POLLING_INTERVAL );
			}

			// Check if we're approaching PHP timeout (10 seconds before).
			if ( $timeout_detector->is_approaching_timeout() ) {
				// Approaching timeout - fall back to async mode.
				WP_MCP_AI_Logger::log_event(
					'veo_timeout_async_fallback',
					sprintf( 'Approaching timeout after %.2fs, falling back to async mode', $timeout_detector->get_elapsed_time() ),
					$timeout_detector->get_metadata()
				);

				// Queue for async polling and return job info.
				return $this->queue_async_polling( $operation, $args );
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

		// Max polling attempts reached - fall back to async mode.
		WP_MCP_AI_Logger::log_event(
			'veo_max_attempts_async_fallback',
			'Max polling attempts reached, falling back to async mode',
			array( 'attempts' => $attempts )
		);

		return $this->queue_async_polling( $operation, $args );
	}

	/**
	 * Process completed video and download data.
	 *
	 * @param array  $result Completed operation result.
	 * @param array  $args   Original generation arguments.
	 * @param string $model  Model identifier used for generation.
	 * @return array|WP_Error Video data or error.
	 */
	protected function process_completed_video( $result, $args, $model = null ) {
		// Default to Veo 3.1 if not specified.
		if ( null === $model ) {
			$model = self::VEO_MODEL;
		}

		$is_veo_2 = ( self::VEO_2_MODEL === $model );

		// Extract video URL from response.
		// Support both old and new API response structures for backward compatibility.
		$video_uri = null;

		// New structure (2025): response.generateVideoResponse.generatedSamples[0].video.uri
		if ( isset( $result['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ) ) {
			$video_uri = $result['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'];
		}
		// Old structure (legacy): response.predictions[0].videoUri
		elseif ( isset( $result['response']['predictions'][0]['videoUri'] ) ) {
			$video_uri = $result['response']['predictions'][0]['videoUri'];
		}

		if ( empty( $video_uri ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_video_uri',
				__( 'No video URI in completion response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Download the video.
		$video_data = $this->download_video( $video_uri );

		if ( is_wp_error( $video_data ) ) {
			return $video_data;
		}

		// Determine actual resolution used.
		// Veo 2.0 always outputs 720p (resolution parameter not supported).
		// Veo 3.1 uses the requested resolution or defaults to 720p.
		$actual_resolution = $is_veo_2 ? '720p' : ( isset( $args['resolution'] ) ? $args['resolution'] : '720p' );

		// Return video data with metadata.
		return array(
			'video_data'   => $video_data,
			'video_uri'    => $video_uri,
			'prompt'       => $args['prompt'],
			'duration'     => isset( $args['duration'] ) ? $args['duration'] : self::DEFAULT_DURATION,
			'aspect_ratio' => isset( $args['aspect_ratio'] ) ? $args['aspect_ratio'] : '16:9',
			'resolution'   => $actual_resolution,
			'model'        => $model,
			'provider'     => 'gemini',
		);
	}

	/**
	 * Download generated video from URI.
	 *
	 * @param string $video_uri Video URI from Gemini.
	 * @return string|WP_Error Video binary data or error.
	 */
	protected function download_video( $video_uri ) {
		// Get API key for authenticated download.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// The URI from Gemini API may be a Google Cloud Storage URL that requires API key authentication.
		// Append the API key as a query parameter for authenticated download.
		$download_url = add_query_arg( 'key', $api_key, $video_uri );

		WP_MCP_AI_Logger::log_event(
			'veo_video_download_attempt',
			'Attempting to download generated video',
			array(
				'uri_host' => wp_parse_url( $video_uri, PHP_URL_HOST ),
				'uri_path' => wp_parse_url( $video_uri, PHP_URL_PATH ),
			)
		);

		$response = wp_remote_get(
			$download_url,
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
			WP_MCP_AI_Logger::log_error(
				'Video download failed with HTTP error',
				array(
					'status' => $code,
					'uri'    => wp_parse_url( $video_uri, PHP_URL_HOST ) . wp_parse_url( $video_uri, PHP_URL_PATH ),
				)
			);

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

	/**
	 * Queue async polling for video generation.
	 *
	 * @param array $operation Operation details from Gemini API.
	 * @param array $args      Original generation arguments.
	 * @return array Async job information.
	 */
	protected function queue_async_polling( $operation, $args ) {
		// Generate unique job ID.
		$job_id = 'veo_' . uniqid( '', true );

		// Get model from operation (set by generate_video_with_model).
		$model = isset( $operation['model_used'] ) ? $operation['model_used'] : self::VEO_MODEL;

		// Store operation metadata in transient.
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => $operation['operation_name'],
			'model'          => $model,
			'args'           => $args,
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => self::MAX_POLLING_ATTEMPTS,
		);

		// Save to transient (24 hour expiry).
		set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Schedule first poll with a 1-second delay to ensure transient is saved.
		// This prevents race condition where cron executes before database commit.
		$first_poll_time = time() + 1;
		wp_schedule_single_event( $first_poll_time, self::CRON_POLL_HOOK, array( $job_id ) );

		// Record cron job in cron manager for visibility.
		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
			WP_MCP_AI_Cron_Manager::record_job(
				self::CRON_POLL_HOOK,
				array( $job_id ),
				'single',
				$first_poll_time,
				$user_id
			);
		}

		WP_MCP_AI_Logger::log_event(
			'veo_async_queued',
			'Veo video generation queued for async polling',
			array(
				'job_id'    => $job_id,
				'operation' => $operation['operation_name'],
			)
		);

		return array(
			'async'   => true,
			'job_id'  => $job_id,
			'status'  => 'pending',
			'message' => __( 'Video generation started. Your video is being created in the background and will appear here when ready.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Poll for video completion (cron callback).
	 *
	 * @param string $job_id Async job identifier.
	 */
	public function poll_video_async( $job_id ) {
		// Retrieve operation metadata.
		$metadata = get_transient( self::ASYNC_OP_PREFIX . $job_id );

		if ( ! $metadata || ! isset( $metadata['operation_name'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Veo async job metadata not found',
				array( 'job_id' => $job_id )
			);
			return;
		}

		// Increment poll attempt.
		$metadata['poll_attempt']++;
		$metadata['last_poll'] = time();

		// Check if max attempts reached.
		if ( $metadata['poll_attempt'] > $metadata['max_attempts'] ) {
			$metadata['status'] = 'failed';
			$metadata['error']  = __( 'Video generation timed out after maximum polling attempts.', 'wp-mcp-ai' );
			set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

			WP_MCP_AI_Logger::log_error(
				'Veo async generation timeout',
				array(
					'job_id'   => $job_id,
					'attempts' => $metadata['poll_attempt'],
				)
			);
			return;
		}

		// Poll the Gemini API for status.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s',
			$metadata['operation_name']
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'x-goog-api-key' => $api_key,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Schedule retry.
			$this->schedule_next_poll( $job_id, $metadata );
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Check if operation is done.
		if ( isset( $data['done'] ) && true === $data['done'] ) {
			if ( isset( $data['error'] ) ) {
				// Operation failed.
				$metadata['status'] = 'failed';
				$metadata['error']  = isset( $data['error']['message'] )
					? $data['error']['message']
					: __( 'Video generation failed.', 'wp-mcp-ai' );
				set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

				WP_MCP_AI_Logger::log_error(
					'Veo async generation failed',
					array(
						'job_id' => $job_id,
						'error'  => $metadata['error'],
					)
				);
				return;
			}

			// Operation succeeded - process video.
			$model = isset( $metadata['model'] ) ? $metadata['model'] : self::VEO_MODEL;
			$result = $this->process_completed_video( $data, $metadata['args'], $model );

			if ( is_wp_error( $result ) ) {
				$metadata['status'] = 'failed';
				$metadata['error']  = $result->get_error_message();
			} else {
				// Check if we should save to media library.
				$save_to_media = isset( $metadata['args']['save_to_media'] )
					? (bool) $metadata['args']['save_to_media']
					: true;

				if ( $save_to_media ) {
					$save_result = $this->save_video_to_media(
						$result,
						isset( $metadata['args']['user_id'] ) ? $metadata['args']['user_id'] : 0
					);

					if ( is_wp_error( $save_result ) ) {
						$metadata['status'] = 'failed';
						$metadata['error']  = $save_result->get_error_message();
					} else {
						$metadata['status'] = 'completed';
						$metadata['result'] = array(
							'attachment_id' => $save_result['attachment_id'],
							'url'           => $save_result['url'],
							'prompt'        => $result['prompt'],
							'duration'      => $result['duration'],
							'aspect_ratio'  => $result['aspect_ratio'],
							'resolution'    => $result['resolution'],
							'model'         => $result['model'],
							'provider'      => $result['provider'],
						);
					}
				} else {
					// Video not saved to media library - return data URL instead of Google URL.
					$video_base64 = base64_encode( $result['video_data'] );
					$data_url     = 'data:video/mp4;base64,' . $video_base64;

					$metadata['status'] = 'completed';
					$metadata['result'] = array(
						'video_url'    => $data_url,
						'prompt'       => $result['prompt'],
						'duration'     => $result['duration'],
						'aspect_ratio' => $result['aspect_ratio'],
						'resolution'   => $result['resolution'],
						'model'        => $result['model'],
						'provider'     => $result['provider'],
					);
				}
			}

			set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

			WP_MCP_AI_Logger::log_event(
				'veo_async_completed',
				'Veo async video generation completed',
				array(
					'job_id'   => $job_id,
					'attempts' => $metadata['poll_attempt'],
				)
			);
			return;
		}

		// Operation still in progress - schedule next poll.
		$this->schedule_next_poll( $job_id, $metadata );
	}

	/**
	 * Schedule next polling attempt.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Job metadata.
	 */
	protected function schedule_next_poll( $job_id, $metadata ) {
		// Update metadata.
		$metadata['status'] = 'polling';
		set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Schedule next poll.
		$next_poll = time() + self::POLLING_INTERVAL;
		wp_schedule_single_event( $next_poll, self::CRON_POLL_HOOK, array( $job_id ) );

		// Record in cron manager.
		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$user_id = isset( $metadata['args']['user_id'] ) ? absint( $metadata['args']['user_id'] ) : 0;
			WP_MCP_AI_Cron_Manager::record_job(
				self::CRON_POLL_HOOK,
				array( $job_id ),
				'single',
				$next_poll,
				$user_id
			);
		}
	}

	/**
	 * Get async job status.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Job status or error.
	 */
	public function get_async_status( $job_id ) {
		$metadata = get_transient( self::ASYNC_OP_PREFIX . $job_id );

		if ( ! $metadata ) {
			return new WP_Error(
				'wp_mcp_ai_job_not_found',
				__( 'Video generation job not found or expired.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Return sanitized status.
		$response = array(
			'job_id'       => $metadata['job_id'],
			'status'       => $metadata['status'],
			'queued_at'    => $metadata['queued_at'],
			'poll_attempt' => $metadata['poll_attempt'],
			'max_attempts' => $metadata['max_attempts'],
		);

		if ( isset( $metadata['last_poll'] ) ) {
			$response['last_poll'] = $metadata['last_poll'];
		}

		if ( 'failed' === $metadata['status'] && isset( $metadata['error'] ) ) {
			$response['error'] = $metadata['error'];
		}

		if ( 'completed' === $metadata['status'] && isset( $metadata['result'] ) ) {
			$response['result'] = $metadata['result'];
		}

		// Include args for permission checking and context.
		if ( isset( $metadata['args'] ) ) {
			$response['args'] = $metadata['args'];
		}

		return $response;
	}

	/**
	 * Save generated video to Media Library.
	 *
	 * Note: This method is duplicated in the tool class for sync mode.
	 * This is intentional to keep the service and tool layers independent.
	 * The service needs it for async completion, the tool needs it for sync mode.
	 *
	 * @param array $result  Video generation result.
	 * @param int   $user_id User ID for ownership.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function save_video_to_media( $result, $user_id ) {
		// Generate unique filename.
		$filename = 'veo-video-' . uniqid( '', true ) . '.mp4';

		// Upload video.
		$upload = wp_upload_bits( $filename, null, $result['video_data'] );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => sprintf(
				/* translators: %s: truncated prompt */
				__( 'Veo Generated Video: %s', 'wp-mcp-ai' ),
				substr( $result['prompt'], 0, 50 )
			),
			'post_content'   => $result['prompt'],
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Add metadata.
		$metadata = array(
			'veo_prompt'       => $result['prompt'],
			'veo_duration'     => $result['duration'],
			'veo_aspect_ratio' => $result['aspect_ratio'],
			'veo_resolution'   => $result['resolution'],
			'veo_model'        => $result['model'],
			'veo_provider'     => $result['provider'],
		);

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, '_' . $key, $value );
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		WP_MCP_AI_Logger::log_event(
			'veo_video_saved',
			'Veo generated video saved to Media Library',
			array(
				'attachment_id' => $attachment_id,
				'duration'      => $result['duration'],
			)
		);

		// Return attachment result with local WordPress URL.
		// Uses utility class for SoC compliance and code reusability.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}
}
