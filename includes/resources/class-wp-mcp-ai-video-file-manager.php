<?php
/**
 * Video File Manager
 *
 * Manages video file uploads, caching, and lifecycle for Gemini File API.
 * Extends the base file resource manager with video-specific functionality.
 *
 * Part of Phase 2.1: File Management Enhancement (#1288)
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-wp-mcp-ai-file-resource-manager.php';

/**
 * Video File Manager class
 *
 * Handles:
 * - Video upload tracking for Gemini File API
 * - Caching to avoid re-uploading same videos
 * - Automatic cleanup of old video files
 * - Integration with WP_MCP_AI_Gemini_File_Service
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Video_File_Manager extends WP_MCP_AI_File_Resource_Manager {

	/**
	 * Gemini File Service instance
	 *
	 * @var WP_MCP_AI_Gemini_File_Service
	 */
	private $file_service;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'video' );

		// Video files can be cached for longer (48 hours).
		$this->cache_duration = 2 * DAY_IN_SECONDS;
	}

	/**
	 * Set Gemini File Service instance
	 *
	 * @param WP_MCP_AI_Gemini_File_Service $service File service instance.
	 * @return void
	 */
	public function set_file_service( $service ) {
		$this->file_service = $service;
	}

	/**
	 * Get or upload video file
	 *
	 * Checks cache first. If not cached or expired, uploads the video.
	 *
	 * @param string $video_url     Video URL or file path.
	 * @param string $mime_type     Video MIME type.
	 * @param int    $attachment_id WordPress attachment ID (optional).
	 * @return array|WP_Error Upload result with file_name and file_uri, or error.
	 */
	public function get_or_upload_video( $video_url, $mime_type, $attachment_id = null ) {
		// Generate unique identifier for this video.
		$source          = $attachment_id ? 'attachment:' . $attachment_id : $video_url;
		$file_identifier = $this->generate_file_identifier( $source );

		// Check cache.
		$cached_file = $this->get_cached_file( $file_identifier );
		if ( $cached_file && isset( $cached_file['file_name'] ) && isset( $cached_file['file_uri'] ) ) {
			WP_MCP_AI_Logger::log_event(
				'video_cache_hit',
				'Retrieved video from cache',
				array(
					'file_identifier' => $file_identifier,
					'file_name'       => $cached_file['file_name'],
				)
			);

			return array(
				'file_name' => $cached_file['file_name'],
				'file_uri'  => $cached_file['file_uri'],
				'state'     => isset( $cached_file['state'] ) ? $cached_file['state'] : 'ACTIVE',
				'cached'    => true,
			);
		}

		// Not cached, need to upload.
		$upload_result = $this->upload_video( $video_url, $mime_type, $attachment_id );

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		// Track the uploaded file.
		$this->track_file(
			$file_identifier,
			array(
				'file_name'     => $upload_result['file_name'],
				'file_uri'      => $upload_result['file_uri'],
				'state'         => isset( $upload_result['state'] ) ? $upload_result['state'] : 'ACTIVE',
				'source'        => $source,
				'mime_type'     => $mime_type,
				'attachment_id' => $attachment_id,
			)
		);

		$upload_result['cached'] = false;

		return $upload_result;
	}

	/**
	 * Upload video to Gemini File API
	 *
	 * @param string $video_url     Video URL or file path.
	 * @param string $mime_type     Video MIME type.
	 * @param int    $attachment_id WordPress attachment ID (optional).
	 * @return array|WP_Error Upload result or error.
	 */
	private function upload_video( $video_url, $mime_type, $attachment_id = null ) {
		// Ensure Gemini File Service is loaded.
		if ( ! $this->file_service ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
			$this->file_service = new WP_MCP_AI_Gemini_File_Service();
		}

		// Get file path.
		$file_path = null;
		$temp_file = false;

		if ( $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'Video file not found on server.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}
		} else {
			// Download video to temp file.
			$download_result = $this->download_video_to_temp( $video_url );
			if ( is_wp_error( $download_result ) ) {
				return $download_result;
			}

			$file_path = $download_result['file_path'];
			$temp_file = true;
		}

		// Upload to Gemini.
		$upload_result = $this->file_service->upload_file(
			$file_path,
			$mime_type,
			basename( $file_path )
		);

		// Clean up temp file.
		if ( $temp_file && $file_path ) {
			wp_delete_file( $file_path );
		}

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		// Wait for processing.
		$file_name         = $upload_result['file_name'];
		$processing_result = $this->file_service->wait_for_processing( $file_name, 300 );

		if ( is_wp_error( $processing_result ) ) {
			// Try to clean up file even if processing failed.
			$this->file_service->delete_file( $file_name );
			return $processing_result;
		}

		return $upload_result;
	}

	/**
	 * Download video from URL to temporary file
	 *
	 * @param string $video_url Video URL.
	 * @return array|WP_Error Array with file_path, or error.
	 */
	private function download_video_to_temp( $video_url ) {
		$response = wp_remote_get(
			$video_url,
			array(
				'timeout' => 300, // 5 minutes for large videos.
			)
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

		$body = wp_remote_retrieve_body( $response );

		// Create temporary file.
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
		);
	}

	/**
	 * Delete remote file from Gemini File API
	 *
	 * @param array $metadata File metadata.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected function delete_remote_file( $metadata ) {
		if ( ! isset( $metadata['file_name'] ) ) {
			return false;
		}

		// Ensure Gemini File Service is loaded.
		if ( ! $this->file_service ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
			$this->file_service = new WP_MCP_AI_Gemini_File_Service();
		}

		return $this->file_service->delete_file( $metadata['file_name'] );
	}

	/**
	 * Cleanup video file by identifier
	 *
	 * Public method to manually cleanup a specific video.
	 *
	 * @param string $file_identifier File identifier.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function cleanup_video( $file_identifier ) {
		$metadata = $this->get_tracked_file( $file_identifier );

		if ( ! $metadata ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Video file not found in tracking.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Delete remote file.
		$deleted = $this->delete_remote_file( $metadata );

		// Remove from tracking.
		$this->untrack_file( $file_identifier );

		return $deleted;
	}
}
