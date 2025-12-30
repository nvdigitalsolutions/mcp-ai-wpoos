<?php
/**
 * Tool that generates videos using OpenAI's Sora API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Provides a tool for generating videos via OpenAI Sora and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_Sora_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface {
	const DEFAULT_MODEL    = 'sora-2';
	const DEFAULT_SIZE     = '1080p';
	const DEFAULT_DURATION = 5;
	const DEFAULT_FPS      = 24;
	const API_ENDPOINT     = 'https://api.openai.com/v1/videos';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_sora_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Sora video', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a video with OpenAI Sora and stores it in the Media Library. Supports both Sora 2 and Sora 2 Pro models for high-quality video generation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$defaults = $this->get_configured_defaults();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired video. Be detailed and specific about the visual elements, actions, and style you want to see.', 'wp-mcp-ai' ),
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'OpenAI video model to use: "sora-2" (standard quality) or "sora-2-pro" (higher quality, more coherent).', 'wp-mcp-ai' ),
					'enum'        => array( 'sora-2', 'sora-2-pro' ),
					'default'     => $defaults['model'],
				),
				'size'          => array(
					'type'        => 'string',
					'description' => __( 'Resolution of the generated video.', 'wp-mcp-ai' ),
					'enum'        => array( '480p', '720p', '1080p' ),
					'default'     => $defaults['size'],
				),
				'duration'      => array(
					'type'        => 'integer',
					'description' => __( 'Video duration in seconds (5-20 for sora-2, 5-60 for sora-2-pro).', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => $defaults['duration'],
				),
				'fps'           => array(
					'type'        => 'integer',
					'description' => __( 'Frames per second (24, 30, or 60).', 'wp-mcp-ai' ),
					'enum'        => array( 24, 30, 60 ),
					'default'     => $defaults['fps'],
				),
				'aspect_ratio'  => array(
					'type'        => 'string',
					'description' => __( 'Video aspect ratio: "16:9" (landscape), "9:16" (portrait), "1:1" (square).', 'wp-mcp-ai' ),
					'enum'        => array( '16:9', '9:16', '1:1' ),
					'default'     => '16:9',
				),
				'file_name'     => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved video attachment.', 'wp-mcp-ai' ),
				),
				'save_to_media' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the generated video to WordPress Media Library. Default is true.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Override the OpenAI request timeout in seconds. Video generation can take several minutes.', 'wp-mcp-ai' ),
					'minimum'     => 60,
					'maximum'     => 600,
					'default'     => 300,
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Retrieve the configured defaults for video generation.
	 *
	 * @return array
	 */
	protected function get_configured_defaults() {
		$defaults = array(
			'model'    => self::DEFAULT_MODEL,
			'size'     => self::DEFAULT_SIZE,
			'duration' => self::DEFAULT_DURATION,
			'fps'      => self::DEFAULT_FPS,
		);

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return $defaults;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( ! empty( $settings['openai_video_model'] ) ) {
			$defaults['model'] = sanitize_text_field( $settings['openai_video_model'] );
		}

		if ( ! empty( $settings['openai_video_size'] ) ) {
			$defaults['size'] = sanitize_text_field( $settings['openai_video_size'] );
		}

		if ( ! empty( $settings['openai_video_duration'] ) ) {
			$defaults['duration'] = absint( $settings['openai_video_duration'] );
		}

		if ( ! empty( $settings['openai_video_fps'] ) ) {
			$defaults['fps'] = absint( $settings['openai_video_fps'] );
		}

		return $defaults;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user capabilities.
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate videos.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Validate prompt.
		if ( empty( $arguments['prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Video generation requires a prompt.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Check if async mode should be used.
		$use_async = $this->should_use_async( $arguments, $context );

		// If async mode, queue the job and return immediately.
		if ( $use_async ) {
			return $this->queue_async_job( $arguments, $context );
		}

		// Execute synchronously.
		return $this->generate_video_sync( $arguments, $context );
	}

	/**
	 * Determine if async mode should be used.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return bool True if async mode should be used.
	 */
	protected function should_use_async( $arguments, $context = array() ) {
		// If already running in async executor context, do NOT use tool-level async.
		if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
			return false;
		}

		// Check if explicitly set in arguments.
		if ( isset( $arguments['async'] ) ) {
			return (bool) $arguments['async'];
		}

		// Default to async for video generation (can take several minutes).
		return true;
	}

	/**
	 * Queue an async job for video generation.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Job info with job_id and status.
	 */
	protected function queue_async_job( $arguments, $context ) {
		$job_id = 'sora_' . wp_generate_password( 12, false );

		// Store job info in transient.
		set_transient(
			'wp_mcp_ai_sora_job_' . $job_id,
			array(
				'status'    => 'pending',
				'arguments' => $arguments,
				'context'   => $context,
				'created'   => time(),
			),
			HOUR_IN_SECONDS * 2
		);

		// Schedule async execution.
		wp_schedule_single_event(
			time(),
			'wp_mcp_ai_sora_video_generate',
			array( $job_id )
		);

		WP_MCP_AI_Logger::log_event(
			'sora_video_queued',
			'Sora video generation queued',
			array(
				'job_id' => $job_id,
				'prompt' => substr( $arguments['prompt'], 0, 100 ),
			)
		);

		$expected_metadata = $this->get_async_pending_metadata( $job_id, $arguments, $context );

		return array(
			'success'           => true,
			'async'             => true,
			'status'            => 'pending',
			'job_id'            => $job_id,
			'message'           => $expected_metadata['message'],
			'expected_url'      => $expected_metadata['expected_url'],
			'expected_filename' => $expected_metadata['expected_filename'],
		);
	}

	/**
	 * Generate video synchronously.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Generation result or error.
	 */
	protected function generate_video_sync( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$client  = new WP_MCP_AI_OpenAI_Client();
		$api_key = $client->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		// Prepare API request.
		$defaults = $this->get_configured_defaults();
		$prompt   = sanitize_textarea_field( $arguments['prompt'] );
		$model    = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		$size     = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : $defaults['size'];
		$duration = isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : $defaults['duration'];
		$fps      = isset( $arguments['fps'] ) ? absint( $arguments['fps'] ) : $defaults['fps'];
		$aspect   = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '16:9';
		$timeout  = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 300;

		// Validate model-specific constraints.
		if ( 'sora-2' === $model && $duration > 20 ) {
			$duration = 20;
			WP_MCP_AI_Logger::log_warning(
				'sora_duration_adjusted',
				'Duration adjusted to 20s for sora-2 model',
				array( 'requested' => $arguments['duration'] )
			);
		}

		// Build API request payload.
		$payload = array(
			'model'        => $model,
			'prompt'       => $prompt,
			'size'         => $size,
			'duration'     => $duration,
			'fps'          => $fps,
			'aspect_ratio' => $aspect,
		);

		WP_MCP_AI_Logger::log_event(
			'sora_video_request',
			'Sending Sora video generation request',
			array(
				'model'    => $model,
				'size'     => $size,
				'duration' => $duration,
			)
		);

		// Make API request.
		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Sora video generation request failed',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'OpenAI Sora video generation failed.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Sora API returned error',
				array(
					'code'    => $code,
					'message' => $message,
					'body'    => $data,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_sora_error',
				$message,
				array(
					'status'   => $code,
					'response' => $data,
				)
			);
		}

		// OpenAI Sora API returns an async job response, not the video directly.
		// Response format: {"id": "video_123", "object": "video", "status": "queued", ...}
		if ( empty( $data['id'] ) || empty( $data['status'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Sora API returned unexpected response format',
				array(
					'response_keys' => array_keys( $data ),
					'response'      => wp_json_encode( $data ),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_sora_invalid_response',
				__( 'OpenAI Sora returned an unexpected response format. Expected job ID and status.', 'wp-mcp-ai' ),
				array(
					'status'        => 500,
					'response_keys' => array_keys( $data ),
				)
			);
		}

		$video_id     = $data['id'];
		$video_status = $data['status'];

		WP_MCP_AI_Logger::log_event(
			'sora_video_job_created',
			'Sora video job created',
			array(
				'video_id' => $video_id,
				'status'   => $video_status,
			)
		);

		// Poll for job completion.
		$max_polls  = 60; // Maximum number of polls (10 minutes at 10s intervals).
		$poll_delay = 10; // Seconds between polls.
		$poll_count = 0;
		$video_url  = null;

		while ( $poll_count < $max_polls ) {
			// Wait before polling (except first check).
			if ( $poll_count > 0 ) {
				sleep( $poll_delay );
			}

			// Poll job status.
			$status_response = wp_remote_get(
				self::API_ENDPOINT . '/' . $video_id,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $status_response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Sora status poll failed',
					array(
						'video_id' => $video_id,
						'error'    => $status_response->get_error_message(),
					)
				);
				++$poll_count;
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $status_response );
			$status_body = wp_remote_retrieve_body( $status_response );
			$status_data = json_decode( $status_body, true );

			if ( $status_code < 200 || $status_code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'Sora status poll error',
					array(
						'video_id' => $video_id,
						'code'     => $status_code,
						'body'     => $status_data,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_sora_status_error',
					isset( $status_data['error']['message'] ) ? $status_data['error']['message'] : __( 'Failed to check video status.', 'wp-mcp-ai' ),
					array(
						'status'   => $status_code,
						'response' => $status_data,
					)
				);
			}

			$video_status = isset( $status_data['status'] ) ? $status_data['status'] : '';

			WP_MCP_AI_Logger::log_event(
				'sora_video_status_poll',
				'Sora video status polled',
				array(
					'video_id'   => $video_id,
					'status'     => $video_status,
					'poll_count' => $poll_count + 1,
				)
			);

			// Check if completed.
			if ( 'completed' === $video_status ) {
				// Download the video.
				$video_url = self::API_ENDPOINT . '/' . $video_id . '/content';
				break;
			} elseif ( 'failed' === $video_status ) {
				$error_message = isset( $status_data['processing_error'] ) ? $status_data['processing_error'] : __( 'Video generation failed.', 'wp-mcp-ai' );

				WP_MCP_AI_Logger::log_error(
					'Sora video generation failed',
					array(
						'video_id' => $video_id,
						'error'    => $error_message,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_sora_generation_failed',
					$error_message,
					array( 'status' => 500 )
				);
			}

			++$poll_count;
		}

		// Check if we timed out.
		if ( null === $video_url ) {
			WP_MCP_AI_Logger::log_error(
				'Sora video generation timeout',
				array(
					'video_id'   => $video_id,
					'poll_count' => $poll_count,
					'status'     => $video_status,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_sora_timeout',
				__( 'Video generation timed out. The video may still be processing.', 'wp-mcp-ai' ),
				array(
					'status'    => 504,
					'video_id'  => $video_id,
					'last_poll' => $video_status,
				)
			);
		}

		// Validate video URL before download.
		$video_url = esc_url_raw( $video_url, array( 'https' ) );
		if ( ! $video_url || ! wp_http_validate_url( $video_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sora_invalid_video_url',
				__( 'Invalid video URL received from API.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Download the completed video.
		$video_response = wp_remote_get(
			$video_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $video_response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Sora video download failed',
				array(
					'video_id' => $video_id,
					'error'    => $video_response->get_error_message(),
				)
			);
			return $video_response;
		}

		$download_code = wp_remote_retrieve_response_code( $video_response );
		if ( $download_code < 200 || $download_code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'Sora video download error',
				array(
					'video_id' => $video_id,
					'code'     => $download_code,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_sora_download_failed',
				__( 'Failed to download generated video.', 'wp-mcp-ai' ),
				array( 'status' => $download_code )
			);
		}

		$video_data = wp_remote_retrieve_body( $video_response );

		if ( empty( $video_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_sora_download_empty',
				__( 'Downloaded video is empty.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Calculate cost.
		$cost = $this->calculate_video_cost( $duration, $model );

		// Save to media library if requested.
		$save_to_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : true;

		if ( $save_to_media ) {
			$job_id      = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
			$save_result = $this->save_video_to_media( $video_data, $prompt, $model, $user_id, $job_id, $video_id );

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			$edit_url = admin_url( 'post.php?post=' . $save_result['attachment_id'] . '&action=edit' );

			$final_result = array(
				'success'       => true,
				'attachment_id' => $save_result['attachment_id'],
				'url'           => $save_result['url'],
				'file_name'     => isset( $save_result['file_name'] ) ? $save_result['file_name'] : '',
				'edit_url'      => $edit_url,
				'prompt'        => $prompt,
				'duration'      => $duration,
				'size'          => $size,
				'model'         => $model,
				'provider'      => 'openai',
				'cost'          => $cost,
				'message'       => sprintf(
					/* translators: 1: attachment ID, 2: media library edit URL */
					__( 'Video generated successfully and saved as <a href="%2$s" target="_blank">attachment ID %1$d</a>.', 'wp-mcp-ai' ),
					$save_result['attachment_id'],
					esc_url( $edit_url )
				),
				'text'          => sprintf(
					/* translators: 1: attachment ID, 2: duration, 3: resolution */
					__( 'Successfully generated video (ID: %1$d). Format: %2$ds, %3$s', 'wp-mcp-ai' ),
					$save_result['attachment_id'],
					$duration,
					$size
				),
			);

			// Fire completion action.
			if ( ! empty( $context['agentic_loop'] ) ) {
				do_action( 'wp_mcp_ai_sora_video_completed', $final_result, $arguments, $context );
			}

			return $final_result;
		}

		// Return video data URL.
		$video_base64 = base64_encode( $video_data );
		$data_url     = 'data:video/mp4;base64,' . $video_base64;

		$final_result = array(
			'success'   => true,
			'video_url' => $data_url,
			'prompt'    => $prompt,
			'duration'  => $duration,
			'size'      => $size,
			'model'     => $model,
			'provider'  => 'openai',
			'cost'      => $cost,
			'message'   => __( 'Video generated successfully (temporary - not saved to Media Library).', 'wp-mcp-ai' ),
			'text'      => sprintf(
				/* translators: 1: duration, 2: resolution */
				__( 'Successfully generated temporary video. Format: %1$ds, %2$s', 'wp-mcp-ai' ),
				$duration,
				$size
			),
		);

		// Fire completion action.
		if ( ! empty( $context['agentic_loop'] ) ) {
			do_action( 'wp_mcp_ai_sora_video_completed', $final_result, $arguments, $context );
		}

		return $final_result;
	}

	/**
	 * Save generated video to Media Library.
	 *
	 * @param string $video_data Video binary data.
	 * @param string $prompt     Generation prompt.
	 * @param string $model      Model used.
	 * @param int    $user_id    User ID for ownership.
	 * @param string $job_id     Optional job ID for tracking.
	 * @param string $video_id   Optional OpenAI video ID.
	 * @return array|WP_Error Attachment result array or error.
	 */
	protected function save_video_to_media( $video_data, $prompt, $model, $user_id, $job_id = '', $video_id = '' ) {
		// Generate filename.
		if ( ! empty( $job_id ) ) {
			$filename = 'sora-video-' . sanitize_file_name( $job_id ) . '.mp4';
		} else {
			$filename = 'sora-video-' . wp_generate_password( 12, false ) . '.mp4';
		}

		// Include WordPress file functions if not already loaded.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Upload video.
		$upload = wp_upload_bits( $filename, null, $video_data );

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
				__( 'Sora Generated Video: %s', 'wp-mcp-ai' ),
				substr( $prompt, 0, 50 )
			),
			'post_content'   => $prompt,
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Add metadata.
		$metadata = array(
			'sora_prompt' => $prompt,
			'sora_model'  => $model,
			'provider'    => 'openai',
		);

		if ( ! empty( $job_id ) ) {
			$metadata['sora_job_id'] = sanitize_key( $job_id );
		}

		if ( ! empty( $video_id ) ) {
			$metadata['sora_video_id'] = sanitize_text_field( $video_id );
		}

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, '_' . $key, $value );
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		WP_MCP_AI_Logger::log_event(
			'sora_video_saved',
			'Sora generated video saved to Media Library',
			array(
				'attachment_id' => $attachment_id,
				'filename'      => $filename,
				'job_id'        => $job_id,
			)
		);

		// Return attachment result.
		return WP_MCP_AI_Media_URL_Utils::build_attachment_result( $attachment_id, $upload );
	}

	/**
	 * Calculate cost for video generation.
	 *
	 * @param int    $duration Duration in seconds.
	 * @param string $model    Model used.
	 * @return array Cost data array.
	 */
	protected function calculate_video_cost( $duration, $model ) {
		// Load cost calculator.
		if ( ! class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php';
		}

		// Get pricing for the model.
		$pricing = WP_MCP_AI_Cost_Calculator::get_model_pricing( 'openai', $model );

		$cost = array(
			'cost_usd'     => 0.0,
			'provider'     => 'openai',
			'model'        => $model,
			'is_estimated' => false,
		);

		// Check if model has per_second pricing.
		if ( isset( $pricing['per_second'] ) ) {
			$cost_per_second  = (float) $pricing['per_second'];
			$cost['cost_usd'] = round( $cost_per_second * $duration, 6 );
		} else {
			// Mark as estimated if no pricing available.
			$cost['is_estimated'] = true;
		}

		return $cost;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Requires OpenAI API key.
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates video files.
			'external-api',         // Makes external API requests.
			'network-dependent',    // Requires internet connection.
			'consumes-tokens',      // Uses AI credits.
			'async',                // Takes significant time.
			'long-running',         // Video generation is async.
			'background-only',      // Must run in background.
			'rate-limited',         // Subject to API rate limits.
			'may-timeout',          // May exceed typical HTTP timeouts.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array( 'video-generation' );
	}

	/**
	 * Sanitize video generation results for LLM consumption.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64-encoded video data URL if present.
		if ( isset( $result['video_url'] ) && is_string( $result['video_url'] ) ) {
			if ( strpos( $result['video_url'], 'data:video/' ) === 0 ) {
				unset( $result['video_url'] );
				$result['video_data_stripped'] = true;
			}
		}

		// Keep only essential metadata.
		$keep_fields = array(
			'success',
			'attachment_id',
			'url',
			'file_name',
			'edit_url',
			'async',
			'status',
			'job_id',
			'parent_job_id',
			'expected_filename',
			'expected_url',
			'prompt',
			'duration',
			'size',
			'model',
			'provider',
			'message',
			'video_data_stripped',
			'usage',
			'cost',
			'text',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add video_url structure for the chat client.
		$video_url = '';
		if ( isset( $result['url'] ) && '' !== $result['url'] ) {
			$video_url = $result['url'];
		} elseif ( isset( $result['expected_url'] ) && '' !== $result['expected_url'] ) {
			$video_url = $result['expected_url'];
		}

		if ( '' !== $video_url ) {
			$sanitized['video_url'] = array(
				'url' => $video_url,
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * Get pre-execution metadata for async pending response.
	 *
	 * @param string $job_id    The async job identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array Metadata including expected_url and expected_filename.
	 */
	public function get_async_pending_metadata( $job_id, array $arguments = array(), array $context = array() ) {
		$expected_filename = 'sora-video-' . sanitize_file_name( $job_id ) . '.mp4';

		$expected_url = '';
		$upload_dir   = wp_upload_dir();
		if ( ! empty( $upload_dir['url'] ) && empty( $upload_dir['error'] ) ) {
			$expected_url = trailingslashit( $upload_dir['url'] ) . $expected_filename;
		}

		$message = sprintf(
			/* translators: 1: expected filename, 2: job ID */
			__( 'Video generation started. Your video (%1$s) is being created and will be available within approximately 10 minutes. Job ID: %2$s', 'wp-mcp-ai' ),
			$expected_filename,
			$job_id
		);

		return array(
			'expected_url'      => $expected_url,
			'expected_filename' => $expected_filename,
			'message'           => $message,
		);
	}
}
