<?php
/**
 * Video Analysis Service
 *
 * Orchestrates video analysis workflow including file caching, upload management,
 * and AI provider integration. Follows SoC principles by centralizing business logic.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Video Analysis Service class
 *
 * Responsible for:
 * - Orchestrating video analysis workflow
 * - Managing file caching and upload lifecycle
 * - Provider selection and delegation
 * - Error recovery and logging
 *
 * SoC Architecture:
 * - Tools call this service for video analysis
 * - Service uses File Service for upload/caching
 * - Service uses AI Client for actual analysis
 * - Service handles all business logic
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Video_Analysis_Service {

	/**
	 * Analyze a video using AI vision models
	 *
	 * This is the main entry point for video analysis. It handles:
	 * - File caching to avoid re-uploads
	 * - Provider-specific upload and processing
	 * - Analysis with the specified prompt
	 * - Error handling and recovery
	 *
	 * @param array $args {
	 *     Video analysis arguments.
	 *
	 *     @type string   $video_url     Video URL (required if attachment_id not provided).
	 *     @type int      $attachment_id WordPress attachment ID (required if video_url not provided).
	 *     @type string   $prompt        Analysis prompt.
	 *     @type string   $provider      AI provider ('gemini', 'openai').
	 *     @type string   $model         Model identifier (optional, uses default if not specified).
	 *     @type int      $frame_count   Number of frames to extract for OpenAI (default 10, max 20).
	 * }
	 * @return array|WP_Error Analysis result with text, usage, model, and provider metadata.
	 */
	public function analyze_video( array $args ) {
		$video_url     = isset( $args['video_url'] ) ? $args['video_url'] : '';
		$attachment_id = isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : null;
		$prompt        = isset( $args['prompt'] ) ? $args['prompt'] : '';
		$provider      = isset( $args['provider'] ) ? $args['provider'] : 'gemini';
		$model         = isset( $args['model'] ) ? $args['model'] : '';
		$frame_count   = isset( $args['frame_count'] ) ? absint( $args['frame_count'] ) : 10;

		// Validate inputs.
		if ( empty( $video_url ) && ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_video',
				__( 'Either video_url or attachment_id must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Delegate to provider-specific method.
		if ( 'gemini' === $provider || 'google' === $provider ) {
			return $this->analyze_with_gemini( $video_url, $attachment_id, $prompt, $model );
		}

		if ( 'openai' === $provider ) {
			return $this->analyze_with_openai( $video_url, $attachment_id, $prompt, $model, $frame_count );
		}

		return new WP_Error(
			'wp_mcp_ai_unsupported_provider',
			sprintf(
				/* translators: %s: provider name */
				__( 'Video analysis is not supported for provider: %s. Please use Gemini or OpenAI.', 'wp-mcp-ai' ),
				$provider
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Analyze video using Gemini File API
	 *
	 * @param string   $video_url     Video URL.
	 * @param int|null $attachment_id WordPress attachment ID.
	 * @param string   $prompt        Analysis prompt.
	 * @param string   $model         Model identifier.
	 * @return array|WP_Error Analysis result.
	 */
	protected function analyze_with_gemini( $video_url, $attachment_id, $prompt, $model = '' ) {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';

		$file_service = new WP_MCP_AI_Gemini_File_Service();

		// Check cache first.
		$cached_file = $file_service->get_cached_file( $video_url, $attachment_id );

		if ( $cached_file ) {
			// Use cached file.
			$file_uri  = $cached_file['file_uri'];
			$mime_type = $cached_file['mime_type'];
		} else {
			// Upload new file.
			$upload_result = $this->upload_video_to_gemini( $video_url, $attachment_id, $file_service );

			if ( is_wp_error( $upload_result ) ) {
				return $upload_result;
			}

			$file_uri  = $upload_result['file_uri'];
			$mime_type = $upload_result['mime_type'];
		}

		// Perform analysis.
		$result = $this->call_gemini_for_analysis( $file_uri, $mime_type, $prompt, $model );

		return $result;
	}

	/**
	 * Upload video to Gemini File API
	 *
	 * @param string                        $video_url     Video URL.
	 * @param int|null                      $attachment_id Attachment ID.
	 * @param WP_MCP_AI_Gemini_File_Service $file_service  File service instance.
	 * @return array|WP_Error Upload result with file_uri and mime_type.
	 */
	protected function upload_video_to_gemini( $video_url, $attachment_id, $file_service ) {
		// Get file path and MIME type.
		$file_info = $this->get_file_info( $video_url, $attachment_id );

		if ( is_wp_error( $file_info ) ) {
			return $file_info;
		}

		$file_path = $file_info['file_path'];
		$mime_type = $file_info['mime_type'];
		$temp_file = $file_info['temp_file'];

		// Upload to Gemini.
		$upload_result = $file_service->upload_file( $file_path, $mime_type, basename( $file_path ) );

		// Clean up temp file.
		if ( $temp_file && $file_path ) {
			wp_delete_file( $file_path );
		}

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		// Wait for processing.
		$file_name         = $upload_result['file_name'];
		$file_uri          = $upload_result['file_uri'];
		$processing_result = $file_service->wait_for_processing( $file_name, 300 );

		if ( is_wp_error( $processing_result ) ) {
			$file_service->delete_file( $file_name );
			return $processing_result;
		}

		// Track for caching.
		$file_service->track_uploaded_file( $file_name, $file_uri, $mime_type, $video_url, $attachment_id );

		return array(
			'file_name' => $file_name,
			'file_uri'  => $file_uri,
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Call Gemini API for video analysis
	 *
	 * @param string $file_uri  Gemini file URI.
	 * @param string $mime_type File MIME type.
	 * @param string $prompt    Analysis prompt.
	 * @param string $model     Model identifier.
	 * @return array|WP_Error Analysis result.
	 */
	protected function call_gemini_for_analysis( $file_uri, $mime_type, $prompt, $model = '' ) {
		$client = new WP_MCP_AI_Gemini_Client();

		// Build message.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'      => 'file',
						'file_uri'  => $file_uri,
						'mime_type' => $mime_type,
					),
				),
			),
		);

		// Get model.
		if ( empty( $model ) ) {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$model    = isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.5-flash';
		}

		// Call API.
		$response = $client->create_chat_completion(
			$messages,
			array( 'model' => $model )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response['provider'] = 'gemini';

		return $response;
	}

	/**
	 * Analyze video using OpenAI Vision API with frame extraction
	 *
	 * @param string   $video_url     Video URL.
	 * @param int|null $attachment_id WordPress attachment ID.
	 * @param string   $prompt        Analysis prompt.
	 * @param string   $model         Model identifier.
	 * @param int      $frame_count   Number of frames to extract (default 10).
	 * @return array|WP_Error Analysis result.
	 */
	protected function analyze_with_openai( $video_url, $attachment_id, $prompt, $model = '', $frame_count = 10 ) {
		// Get file information.
		$file_info = $this->get_file_info( $video_url, $attachment_id );
		if ( is_wp_error( $file_info ) ) {
			return $file_info;
		}

		$file_path = $file_info['file_path'];
		$temp_file = $file_info['temp_file'];

		// Initialize frame extractor service.
		$frame_extractor = new WP_MCP_AI_Video_Frame_Extractor_Service();

		// Check if FFmpeg is available.
		if ( ! $frame_extractor->is_ffmpeg_available() ) {
			if ( $temp_file && file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file_path );
			}

			return new WP_Error(
				'wp_mcp_ai_ffmpeg_not_available',
				__( 'FFmpeg is not installed on this server. Video analysis for OpenAI requires FFmpeg to extract frames. Please install FFmpeg or use Gemini for video analysis.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'install_ffmpeg' => __( 'Install FFmpeg: https://ffmpeg.org/download.html', 'wp-mcp-ai' ),
						'use_gemini'     => __( 'Alternatively, use Gemini which supports direct video analysis without FFmpeg.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		// Extract frames from video with configurable frame count.
		$frame_paths = $frame_extractor->extract_frames( $file_path, $frame_count );

		// Clean up temporary video file if downloaded.
		if ( $temp_file && file_exists( $file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file_path );
		}

		if ( is_wp_error( $frame_paths ) ) {
			return $frame_paths;
		}

		// Convert frames to base64 data URLs.
		$base64_frames = $frame_extractor->frames_to_base64( $frame_paths );

		if ( empty( $base64_frames ) ) {
			$frame_extractor->cleanup_frames( $frame_paths );
			return new WP_Error(
				'wp_mcp_ai_no_frames',
				__( 'No frames could be extracted from the video.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Build message content with text prompt and frame images.
		$content = array(
			array(
				'type' => 'text',
				'text' => ! empty( $prompt ) ? $prompt : __( 'Analyze this video by examining the extracted frames. Describe what you see, including scenes, objects, actions, and any relevant details.', 'wp-mcp-ai' ),
			),
		);

		// Add each frame as an image_url.
		foreach ( $base64_frames as $frame_url ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => $frame_url,
				),
			);
		}

		// Build messages array for OpenAI.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);

		// Get OpenAI client.
		$openai_client = new WP_MCP_AI_OpenAI_Client();

		// Use GPT-4o or specified model (vision-capable model required).
		if ( empty( $model ) ) {
			$model = 'gpt-4o';
		}

		// Send request to OpenAI.
		$response = $openai_client->chat_completion(
			$messages,
			array(
				'model'       => $model,
				'max_tokens'  => 2000,
				'temperature' => 0.7,
			)
		);

		// Clean up extracted frames.
		$frame_extractor->cleanup_frames( $frame_paths );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Extract analysis text.
		$analysis_text = '';
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$analysis_text = $response['choices'][0]['message']['content'];
		}

		// Build result array.
		$result = array(
			'text'        => $analysis_text,
			'provider'    => 'openai',
			'model'       => isset( $response['model'] ) ? $response['model'] : $model,
			'frame_count' => count( $base64_frames ),
			'usage'       => isset( $response['usage'] ) ? $response['usage'] : array(),
		);

		WP_MCP_AI_Logger::log_event(
			'openai_video_analysis_complete',
			'Completed OpenAI video analysis via frame extraction',
			array(
				'model'       => $result['model'],
				'frame_count' => $result['frame_count'],
				'usage'       => $result['usage'],
			)
		);

		return $result;
	}

	/**
	 * Get file information from attachment or URL
	 *
	 * @param string   $video_url     Video URL.
	 * @param int|null $attachment_id Attachment ID.
	 * @return array|WP_Error Array with file_path, mime_type, and temp_file flag.
	 */
	protected function get_file_info( $video_url, $attachment_id ) {
		if ( $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			$mime_type = get_post_mime_type( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'Video file not found on server.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}

			return array(
				'file_path' => $file_path,
				'mime_type' => $mime_type,
				'temp_file' => false,
			);
		}

		// Download from URL.
		return $this->download_video_to_temp( $video_url );
	}

	/**
	 * Download video from URL to temporary file
	 *
	 * @param string $video_url Video URL.
	 * @return array|WP_Error Array with file_path, mime_type, and temp_file flag.
	 */
	protected function download_video_to_temp( $video_url ) {
		$response = wp_remote_get(
			$video_url,
			array( 'timeout' => 300 )
		);

		if ( is_wp_error( $response ) ) {
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

		$body      = wp_remote_retrieve_body( $response );
		$mime_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( ! $mime_type || false === strpos( $mime_type, 'video/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_video',
				__( 'Downloaded file is not a video.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$temp_file = wp_tempnam( 'video' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $temp_file, $body );

		if ( false === $written ) {
			return new WP_Error(
				'wp_mcp_ai_temp_file_failed',
				__( 'Failed to write video to temporary file.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'file_path' => $temp_file,
			'mime_type' => $mime_type,
			'temp_file' => true,
		);
	}
}
