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
	const DEFAULT_DURATION = 5;

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
	 * Estimated number of polls for typical video completion (used for progress calculation)
	 *
	 * @var int
	 */
	const ESTIMATED_COMPLETION_POLLS = 50;

	/**
	 * Maximum progress percentage before completion (100% only on actual completion)
	 *
	 * @var int
	 */
	const MAX_PROGRESS_BEFORE_COMPLETE = 95;

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

		// Validate 1080p requirements upfront.
		if ( isset( $args['resolution'] ) && '1080p' === $args['resolution'] ) {
			// 1080p only supported for 16:9 aspect ratio.
			if ( isset( $args['aspect_ratio'] ) && '9:16' === $args['aspect_ratio'] ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_arguments',
					__( '1080p resolution is only supported with 16:9 aspect ratio. Please use 720p for 9:16 videos.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			// 1080p requires exactly 8 seconds duration.
			if ( isset( $args['duration'] ) && self::REQUIRED_1080P_DURATION !== absint( $args['duration'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_arguments',
					sprintf(
						/* translators: %d: required duration in seconds */
						__( '1080p resolution requires exactly %d seconds duration. Please adjust the duration or use 720p resolution.', 'wp-mcp-ai' ),
						self::REQUIRED_1080P_DURATION
					),
					array( 'status' => 400 )
				);
			}
		}

		// Try Veo 3.1 first, unless Veo 2 is explicitly requested.
		// Check for both short form (veo-2.0) and full model ID (veo-2.0-generate-001).
		$force_veo_2 = false;
		if ( isset( $args['model'] ) ) {
			$model = $args['model'];
			// Match short form or full Veo 2 model ID.
			$force_veo_2 = ( 'veo-2.0' === $model || self::VEO_2_MODEL === $model || false !== stripos( $model, 'veo-2' ) );
		}

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

				// Adjust args for Veo 2.0 compatibility before fallback.
				// Veo 2.0 requires minimum 5 seconds, while Veo 3.1 allows 4 seconds.
				// If duration is 4, adjust to 5 to prevent "out of bound" errors.
				$veo_2_args = $args;
				if ( isset( $veo_2_args['duration'] ) && absint( $veo_2_args['duration'] ) < self::VEO_2_MIN_DURATION ) {
					$original_duration      = $veo_2_args['duration'];
					$veo_2_args['duration'] = self::VEO_2_MIN_DURATION;
					WP_MCP_AI_Logger::log_event(
						'veo_2_duration_adjusted',
						'Adjusted duration for Veo 2.0 compatibility',
						array(
							'original' => $original_duration,
							'adjusted' => $veo_2_args['duration'],
						)
					);
				}

				// Attempt with Veo 2.0.
				$veo_2_result = $this->generate_video_with_model( $veo_2_args, self::VEO_2_MODEL );

				// If Veo 2 succeeds, add metadata about fallback.
				if ( ! is_wp_error( $veo_2_result ) ) {
					if ( isset( $veo_2_result['async'] ) && $veo_2_result['async'] ) {
						$veo_2_result['fallback_used']       = true;
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
		// This ensures duration is always within model-specific valid range as a final safeguard
		// before sending to the API. This prevents "durationSeconds is out of bound" API errors.
		// IMPORTANT: Use model-specific minimum (Veo 2 requires 5s minimum, Veo 3.1 requires 4s minimum).
		if ( ! is_int( $parameters['durationSeconds'] ) || $parameters['durationSeconds'] < $min_duration || $parameters['durationSeconds'] > self::MAX_DURATION ) {
			// Default to model minimum if duration is invalid.
			$parameters['durationSeconds'] = $min_duration;
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
			$error_code    = 'wp_mcp_ai_veo_request_failed';

			if ( isset( $data['error']['message'] ) ) {
				$api_error_message = $data['error']['message'];
				$error_message     = $api_error_message;

				// Provide more helpful error messages for common issues.
				$quota_keywords = array( 'quota', 'rate limit' );
				if ( 429 === $code || $this->error_message_contains( $api_error_message, $quota_keywords ) ) {
					$error_code    = 'wp_mcp_ai_quota_exceeded';
					$error_message = sprintf(
						/* translators: %s: API error message */
						__( 'Video generation quota exceeded. Please try again later or upgrade your Gemini API plan for higher limits. Details: %s', 'wp-mcp-ai' ),
						$api_error_message
					);
				} else {
					$invalid_keywords = array( 'invalid', 'argument', 'parameter' );
					if ( $this->error_message_contains( $api_error_message, $invalid_keywords ) ) {
						$error_code    = 'wp_mcp_ai_invalid_arguments';
						$error_message = sprintf(
							/* translators: %s: API error message */
							__( 'Invalid video generation parameters: %s', 'wp-mcp-ai' ),
							$api_error_message
						);
					}
				}
			}

			return new WP_Error(
				$error_code,
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

		// Check if we're running in async executor context.
		// If so, we should NOT fall back to async again (prevents dual async).
		$in_async_executor = isset( $args['in_async_executor'] ) && $args['in_async_executor'];

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
			// BUT: If running in async executor context, skip async fallback to prevent dual async.
			if ( ! $in_async_executor && $timeout_detector->is_approaching_timeout() ) {
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

		// Max polling attempts reached.
		// If running in async executor, return error instead of falling back to async (prevents dual async).
		if ( $in_async_executor ) {
			$error_message = sprintf(
				/* translators: %d: number of polling attempts */
				__( 'Video generation timed out after %d polling attempts. The video may still be processing. Please check back later.', 'wp-mcp-ai' ),
				$attempts
			);

			WP_MCP_AI_Logger::log_error(
				'veo_sync_polling_timeout',
				$error_message,
				array(
					'attempts'          => $attempts,
					'in_async_executor' => true,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_veo_polling_timeout',
				$error_message,
				array( 'status' => 500 )
			);
		}

		// Not in async executor - fall back to async mode.
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

		// Store parent_job_id if provided (from async executor).
		// This allows us to complete the parent job when video generation finishes.
		if ( isset( $args['parent_job_id'] ) ) {
			$metadata['parent_job_id'] = sanitize_key( $args['parent_job_id'] );
		}

		// Store assistant_id if provided for proper completion hook routing.
		if ( isset( $args['assistant_id'] ) ) {
			$metadata['assistant_id'] = absint( $args['assistant_id'] );
		}

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

		// Trigger WordPress cron immediately to ensure the polling job runs.
		// WordPress cron is virtual and only runs on page loads by default.
		// Calling spawn_cron() ensures the cron job executes even if no subsequent page loads occur.
		// This is critical for SSE connections where the client may remain on the same page.
		spawn_cron();

		WP_MCP_AI_Logger::log_event(
			'veo_async_queued',
			'Veo video generation queued for async polling',
			array(
				'job_id'    => $job_id,
				'operation' => $operation['operation_name'],
			)
		);

		// Fire job started hook to notify the chat client that the veo job has been created.
		// This allows the UI to display the job_id and status to the user immediately.
		do_action(
			'wp_mcp_ai_job_started',
			$job_id,
			array(
				'tool'   => 'generate_veo_video',
				'status' => 'pending',
			)
		);

		return array(
			'async'   => true,
			'job_id'  => $job_id,
			'status'  => 'pending',
			'message' => sprintf(
				/* translators: %s: job ID */
				__( 'Video generation started (Job ID: %s). Your video is being created in the background and will appear here when ready.', 'wp-mcp-ai' ),
				$job_id
			),
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
		++$metadata['poll_attempt'];
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

			// Fire job failed hook for notification system.
			do_action(
				'wp_mcp_ai_job_failed',
				$job_id,
				new WP_Error( 'veo_timeout', $metadata['error'] ),
				array(
					'tool'   => 'generate_veo_video',
					'prompt' => isset( $metadata['args']['prompt'] ) ? $metadata['args']['prompt'] : '',
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

				// Fire job failed hook for notification system.
				do_action(
					'wp_mcp_ai_job_failed',
					$job_id,
					new WP_Error( 'veo_generation_failed', $metadata['error'] ),
					array(
						'tool'   => 'generate_veo_video',
						'prompt' => isset( $metadata['args']['prompt'] ) ? $metadata['args']['prompt'] : '',
					)
				);
				return;
			}

			// Operation succeeded - process video.
			$model  = isset( $metadata['model'] ) ? $metadata['model'] : self::VEO_MODEL;
			$result = $this->process_completed_video( $data, $metadata['args'], $model );

			if ( is_wp_error( $result ) ) {
				$metadata['status'] = 'failed';
				$metadata['error']  = $result->get_error_message();
				set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

				// Fire job failed hook for notification system.
				do_action(
					'wp_mcp_ai_job_failed',
					$job_id,
					$result,
					array(
						'tool'   => 'generate_veo_video',
						'prompt' => isset( $metadata['args']['prompt'] ) ? $metadata['args']['prompt'] : '',
					)
				);
				return;
			} else {
				// Check if we should save to media library.
				$save_to_media = isset( $metadata['args']['save_to_media'] )
					? (bool) $metadata['args']['save_to_media']
					: true;

				if ( $save_to_media ) {
					$save_result = $this->save_video_to_media(
						$result,
						isset( $metadata['args']['user_id'] ) ? $metadata['args']['user_id'] : 0,
						$job_id
					);

					if ( is_wp_error( $save_result ) ) {
						$metadata['status'] = 'failed';
						$metadata['error']  = $save_result->get_error_message();
						set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

						// Fire job failed hook for notification system.
						do_action(
							'wp_mcp_ai_job_failed',
							$job_id,
							$save_result,
							array(
								'tool'   => 'generate_veo_video',
								'prompt' => isset( $metadata['args']['prompt'] ) ? $metadata['args']['prompt'] : '',
							)
						);
						return;
					} else {
						// Generate media library edit link.
						$edit_url = admin_url( 'post.php?post=' . $save_result['attachment_id'] . '&action=edit' );

						$metadata['status'] = 'completed';
						$metadata['result'] = array(
							'success'       => true,
							'job_id'        => $job_id,
							'attachment_id' => $save_result['attachment_id'],
							'url'           => $save_result['url'],
							'edit_url'      => $edit_url,
							'prompt'        => $result['prompt'],
							'duration'      => $result['duration'],
							'aspect_ratio'  => $result['aspect_ratio'],
							'resolution'    => $result['resolution'],
							'model'         => $result['model'],
							'provider'      => $result['provider'],
						);

						// Add video_url structure for the chat client to display the video inline.
						// This mirrors how generate_gemini_image adds image_url for display.
						$metadata['result']['video_url'] = array(
							'url' => $save_result['url'],
						);

						// Build descriptive text message for the LLM and chat UI.
						$metadata['result']['text'] = sprintf(
							/* translators: 1: attachment ID, 2: duration in seconds, 3: resolution, 4: aspect ratio */
							__( 'Successfully generated video (ID: %1$d). Format: %2$ds, %3$s, %4$s', 'wp-mcp-ai' ),
							$save_result['attachment_id'],
							$result['duration'],
							$result['resolution'],
							$result['aspect_ratio']
						);

						// Include parent_job_id if available.
						if ( isset( $metadata['parent_job_id'] ) && ! empty( $metadata['parent_job_id'] ) ) {
							$metadata['result']['parent_job_id'] = $metadata['parent_job_id'];
						}

						// Build message with job IDs.
						$job_info = 'Job ID: ' . $job_id;
						if ( isset( $metadata['result']['parent_job_id'] ) ) {
							$job_info .= ', Parent Job ID: ' . $metadata['result']['parent_job_id'];
						}

						$metadata['result']['message'] = sprintf(
							/* translators: 1: duration in seconds, 2: resolution, 3: aspect ratio, 4: media library edit URL, 5: attachment ID, 6: job information string */
							__( 'Video generated successfully (%1$ds, %2$s, %3$s) and saved to <a href="%4$s" target="_blank">Media Library (ID %5$d)</a>. %6$s', 'wp-mcp-ai' ),
							$result['duration'],
							$result['resolution'],
							$result['aspect_ratio'],
							esc_url( $edit_url ),
							$save_result['attachment_id'],
							$job_info
						);
					}
				} else {
					// Video not saved to media library - return data URL instead of Google URL.
					$video_base64 = base64_encode( $result['video_data'] );
					$data_url     = 'data:video/mp4;base64,' . $video_base64;

					$metadata['status'] = 'completed';
					$metadata['result'] = array(
						'success'      => true,
						'job_id'       => $job_id,
						'video_url'    => $data_url,
						'prompt'       => $result['prompt'],
						'duration'     => $result['duration'],
						'aspect_ratio' => $result['aspect_ratio'],
						'resolution'   => $result['resolution'],
						'model'        => $result['model'],
						'provider'     => $result['provider'],
					);

					// Build descriptive text message for the LLM and chat UI.
					$metadata['result']['text'] = sprintf(
						/* translators: 1: duration in seconds, 2: resolution, 3: aspect ratio */
						__( 'Successfully generated temporary video. Format: %1$ds, %2$s, %3$s', 'wp-mcp-ai' ),
						$result['duration'],
						$result['resolution'],
						$result['aspect_ratio']
					);

					// Include parent_job_id if available.
					if ( isset( $metadata['parent_job_id'] ) && ! empty( $metadata['parent_job_id'] ) ) {
						$metadata['result']['parent_job_id'] = $metadata['parent_job_id'];
					}

					// Build message with job IDs.
					$job_info = 'Job ID: ' . $job_id;
					if ( isset( $metadata['result']['parent_job_id'] ) ) {
						$job_info .= ', Parent Job ID: ' . $metadata['result']['parent_job_id'];
					}

					$metadata['result']['message'] = sprintf(
						/* translators: 1: duration in seconds, 2: resolution, 3: aspect ratio, 4: job information string */
						__( 'Video generated successfully (%1$ds, %2$s, %3$s). Temporary video not saved to Media Library. %4$s', 'wp-mcp-ai' ),
						$result['duration'],
						$result['resolution'],
						$result['aspect_ratio'],
						$job_info
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

			// If there's a parent async job (from async executor), complete it with the final result.
			// This ensures the chat client can retrieve the video URL from the original job ID.
			// The parent job completion also fires its own wp_mcp_ai_job_completed hook.
			if ( isset( $metadata['parent_job_id'] ) && ! empty( $metadata['parent_job_id'] ) ) {
				$this->complete_parent_job( $metadata['parent_job_id'], $metadata['result'] );
			}

			// Prepare hook metadata with user_id and assistant_id for chat client routing.
			$hook_metadata = array(
				'tool'     => 'generate_veo_video',
				'prompt'   => isset( $metadata['args']['prompt'] ) ? $metadata['args']['prompt'] : '',
				'duration' => isset( $metadata['args']['duration'] ) ? $metadata['args']['duration'] : 0,
			);

			// Include user_id if available.
			if ( isset( $metadata['args']['user_id'] ) ) {
				$hook_metadata['user_id'] = absint( $metadata['args']['user_id'] );
			}

			// Include assistant_id if available.
			if ( isset( $metadata['assistant_id'] ) ) {
				$hook_metadata['assistant_id'] = absint( $metadata['assistant_id'] );
			}

			// Fire job completed hook for the veo job itself.
			// This allows the chat client to receive the completion notification via SSE/polling.
			// Note: If there's a parent job, two completion hooks are fired:
			// 1. This one for the veo job (veo_xxx)
			// 2. Another in complete_parent_job for the parent job (async_xxx)
			// This allows the client to poll either job ID and get the final result.
			do_action(
				'wp_mcp_ai_job_completed',
				$job_id,
				isset( $metadata['result'] ) ? $metadata['result'] : array(),
				$hook_metadata
			);

			// Fire tool execution hook for token tracking.
			// This ensures veo jobs without parent async jobs are still tracked.
			// For veo jobs with parent jobs, the parent completion will also fire this hook,
			// but firing it here ensures direct veo job token tracking when there's no parent.
			// The hook handler (WP_MCP_AI_Tool_Token_Limits::record_tool_usage) is idempotent
			// per session, so duplicate calls for the same job won't double-count tokens.
			$tool_slug = 'generate_veo_video';
			$arguments = isset( $metadata['args'] ) ? $metadata['args'] : array();
			$context   = array();

			// Build context from metadata.
			if ( isset( $metadata['args']['user_id'] ) ) {
				$context['user_id'] = absint( $metadata['args']['user_id'] );
			}
			if ( isset( $metadata['assistant_id'] ) ) {
				$context['assistant_id'] = absint( $metadata['assistant_id'] );
			}

			do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, isset( $metadata['result'] ) ? $metadata['result'] : array() );
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

		// Calculate progress percentage based on poll attempts.
		// Veo video generation typically completes within 30-60 polls.
		$poll_attempt  = isset( $metadata['poll_attempt'] ) ? absint( $metadata['poll_attempt'] ) : 1;
		$max_attempts  = isset( $metadata['max_attempts'] ) ? absint( $metadata['max_attempts'] ) : self::MAX_POLLING_ATTEMPTS;
		$estimated_max = min( self::ESTIMATED_COMPLETION_POLLS, $max_attempts );
		$progress      = min( self::MAX_PROGRESS_BEFORE_COMPLETE, ( $poll_attempt / $estimated_max ) * 100 );

		// Fire progress hook to notify SSE clients about ongoing video generation.
		// This allows the chat UI to display intermediate status messages.
		do_action(
			'wp_mcp_ai_job_progress',
			$job_id,
			$progress,
			array(
				'tool'         => 'generate_veo_video',
				'status'       => 'polling',
				'poll_attempt' => $poll_attempt,
				'max_attempts' => $max_attempts,
				'message'      => sprintf(
					/* translators: %d: poll attempt number */
					__( 'Video generation in progress (check %d)…', 'wp-mcp-ai' ),
					$poll_attempt
				),
			)
		);

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

		// Trigger WordPress cron to ensure continued polling.
		// This is necessary because WordPress cron only runs on page loads,
		// and during video generation polling, there may be no user activity.
		spawn_cron();
	}

	/**
	 * Get async job status.
	 *
	 * When the job transient is not found (expired or deleted), this method
	 * checks if a media attachment was created with the matching job_id stored
	 * in metadata. This allows recovery of completion status for jobs where
	 * the video was successfully generated but the transient expired.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Job status or error.
	 */
	public function get_async_status( $job_id ) {
		$metadata = get_transient( self::ASYNC_OP_PREFIX . $job_id );

		if ( ! $metadata ) {
			// Transient not found - check if media file was created with this job_id.
			// This handles the case where video generation completed but transient expired.
			$attachment = $this->find_attachment_by_job_id( $job_id );

			if ( $attachment ) {
				WP_MCP_AI_Logger::log_event(
					'veo_status_recovered_from_media',
					'Job status recovered from media attachment',
					array(
						'job_id'        => $job_id,
						'attachment_id' => $attachment['attachment_id'],
					)
				);

				// Fire job completed hook to update notification cache.
				// This ensures the chat client can receive the completion notification
				// even when the original transient has expired.
				do_action(
					'wp_mcp_ai_job_completed',
					$job_id,
					$attachment,
					array(
						'tool'      => 'generate_veo_video',
						'recovered' => true,
					)
				);

				// Return completed status with attachment info.
				return array(
					'job_id'       => $job_id,
					'status'       => 'completed',
					'poll_attempt' => 0,
					'max_attempts' => self::MAX_POLLING_ATTEMPTS,
					'result'       => $attachment,
					'recovered'    => true, // Flag indicating this was recovered from media.
				);
			}

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
	 * Find media attachment by job ID.
	 *
	 * Searches for attachments with the veo_job_id metadata matching the given job_id.
	 * This allows recovery of completion status when the job transient has expired.
	 *
	 * @param string $job_id Job identifier to search for.
	 * @return array|null Attachment data array or null if not found.
	 */
	protected function find_attachment_by_job_id( $job_id ) {
		$sanitized_job_id = sanitize_key( $job_id );

		if ( empty( $sanitized_job_id ) ) {
			return null;
		}

		// Query for attachments with matching job_id in metadata.
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => '_veo_job_id',
					'value'   => $sanitized_job_id,
					'compare' => '=',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return null;
		}

		$attachment    = $query->posts[0];
		$attachment_id = $attachment->ID;

		// Get stored metadata.
		$prompt       = get_post_meta( $attachment_id, '_veo_prompt', true );
		$duration     = get_post_meta( $attachment_id, '_veo_duration', true );
		$aspect_ratio = get_post_meta( $attachment_id, '_veo_aspect_ratio', true );
		$resolution   = get_post_meta( $attachment_id, '_veo_resolution', true );
		$model        = get_post_meta( $attachment_id, '_veo_model', true );
		$provider     = get_post_meta( $attachment_id, '_veo_provider', true );

		// Build result matching the format from poll_video_async completion.
		$url      = wp_get_attachment_url( $attachment_id );
		$edit_url = admin_url( 'post.php?post=' . $attachment_id . '&action=edit' );

		return array(
			'success'       => true,
			'job_id'        => $job_id,
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'edit_url'      => $edit_url,
			'prompt'        => $prompt,
			'duration'      => absint( $duration ),
			'aspect_ratio'  => $aspect_ratio,
			'resolution'    => $resolution,
			'model'         => $model,
			'provider'      => $provider,
			'video_url'     => array(
				'url' => $url,
			),
			'text'          => sprintf(
				/* translators: 1: attachment ID, 2: duration in seconds, 3: resolution, 4: aspect ratio */
				__( 'Successfully generated video (ID: %1$d). Format: %2$ds, %3$s, %4$s', 'wp-mcp-ai' ),
				$attachment_id,
				absint( $duration ),
				$resolution,
				$aspect_ratio
			),
			'message'       => sprintf(
				/* translators: 1: media library edit URL, 2: attachment ID */
				__( 'Video generation completed. Saved to <a href="%1$s" target="_blank">Media Library (ID %2$d)</a>.', 'wp-mcp-ai' ),
				esc_url( $edit_url ),
				$attachment_id
			),
		);
	}

	/**
	 * Save generated video to Media Library.
	 *
	 * Note: This method is duplicated in the tool class for sync mode.
	 * This is intentional to keep the service and tool layers independent.
	 * The service needs it for async completion, the tool needs it for sync mode.
	 *
	 * @param array  $result  Video generation result.
	 * @param int    $user_id User ID for ownership.
	 * @param string $job_id  Optional. Job ID for tracking. Stored as metadata to allow
	 *                        recovery of completion status when transient expires.
	 * @return array|WP_Error Attachment result array or error.
	 */
	protected function save_video_to_media( $result, $user_id, $job_id = '' ) {
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

		// Store job_id if provided - allows recovery of completion status when transient expires.
		if ( ! empty( $job_id ) ) {
			$metadata['veo_job_id'] = sanitize_key( $job_id );
		}

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
				'job_id'        => $job_id,
			)
		);

		// Return attachment result with local WordPress URL.
		// Uses utility class for SoC compliance and code reusability.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}

	/**
	 * Complete parent async job with final video result.
	 *
	 * When video generation is called through async executor, there are two job IDs:
	 * 1. async_xxx (from async executor)
	 * 2. veo_yyy (from video generation service)
	 *
	 * When polling times out, the tool returns a nested async response to the executor.
	 * This method updates the parent async job with the final video result.
	 *
	 * @param string $parent_job_id Parent async job ID.
	 * @param array  $result        Final video generation result.
	 */
	protected function complete_parent_job( $parent_job_id, $result ) {
		// Check if parent job exists.
		$parent_metadata = get_transient( 'wp_mcp_ai_async_meta_' . $parent_job_id );

		if ( ! $parent_metadata ) {
			// Parent job not found or expired - log and continue.
			WP_MCP_AI_Logger::log_event(
				'veo_parent_job_not_found',
				'Parent async job not found when completing veo job',
				array(
					'parent_job_id' => $parent_job_id,
				)
			);
			return;
		}

		// Update parent job with final result.
		// Wrap result in async executor's expected format (compress_result structure).
		// The async executor expects results to have 'compressed' and 'data' keys.
		$serialized     = serialize( $result );
		$wrapped_result = array(
			'compressed'    => false,
			'data'          => $result,
			'original_size' => strlen( $serialized ),
		);

		$parent_metadata['status']       = 'completed';
		$parent_metadata['completed_at'] = time();
		$parent_metadata['result']       = $wrapped_result;

		// Save updated metadata.
		set_transient( 'wp_mcp_ai_async_meta_' . $parent_job_id, $parent_metadata, DAY_IN_SECONDS );

		WP_MCP_AI_Logger::log_event(
			'veo_parent_job_completed',
			'Completed parent async job with video result',
			array(
				'parent_job_id' => $parent_job_id,
				'has_url'       => isset( $result['url'] ),
			)
		);

		// Fire completion hook for the parent job as well.
		// This ensures the chat client can poll either job ID and get the result.
		do_action(
			'wp_mcp_ai_job_completed',
			$parent_job_id,
			$result,
			array(
				'tool' => 'generate_veo_video',
				'note' => 'Parent async job completed by veo service',
			)
		);

		// Fire tool execution hook for token tracking.
		// This ensures the parent async job's token usage is tracked when
		// veo completes it, enabling proper orchestration and agentic loop completion.
		// Extract tool_slug, arguments, and context from parent metadata.
		$tool_slug = isset( $parent_metadata['tool_slug'] ) ? $parent_metadata['tool_slug'] : 'generate_veo_video';
		$arguments = isset( $parent_metadata['arguments'] ) ? $parent_metadata['arguments'] : array();
		$context   = isset( $parent_metadata['context'] ) ? $parent_metadata['context'] : array();

		do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );
	}
}
