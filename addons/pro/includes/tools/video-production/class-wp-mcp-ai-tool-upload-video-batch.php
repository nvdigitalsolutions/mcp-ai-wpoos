<?php
/**
 * Tool for processing and uploading a batch of videos to configured platforms.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Video_Production_Toolkit
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes and uploads a batch of videos to configured platforms.
 *
 * Accepts local attachment IDs and uploads them to YouTube or Vimeo.
 * Supports dry_run mode for preview.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Upload_Video_Batch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upload_video_batch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upload Video Batch', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Processes and uploads a batch of videos to configured platforms. Supports dry_run mode.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of local attachment IDs to upload.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
				),
				'platform'  => array(
					'type'        => 'string',
					'description' => __( 'Target platform for upload.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'youtube', 'vimeo' ),
				),
				'dry_run'   => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview the upload without actually uploading.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'video_ids', 'platform' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'video_production',
			'post_type'             => 'attachment',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'video_producer', 'content_manager', 'editor' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Video Production Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_video_production_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_video_production_toolkit'] ) ) {
			return __( 'The video production toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Upload Video Batch tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to upload videos.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse arguments.
		$video_ids = isset( $arguments['video_ids'] ) ? $arguments['video_ids'] : array();
		$platform  = isset( $arguments['platform'] ) ? sanitize_text_field( $arguments['platform'] ) : '';
		$dry_run   = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// Validate platform.
		$valid_platforms = array( 'youtube', 'vimeo' );
		if ( ! in_array( $platform, $valid_platforms, true ) ) {
			return new WP_Error(
				'invalid_platform',
				sprintf(
					/* translators: %s: invalid platform name */
					__( 'Invalid platform "%s". Must be "youtube" or "vimeo".', 'mcp-ai-wpoos-pro' ),
					$platform
				)
			);
		}

		if ( empty( $video_ids ) || ! is_array( $video_ids ) ) {
			return new WP_Error(
				'missing_video_ids',
				__( 'At least one video attachment ID must be provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		$processed = array();
		$errors    = array();

		foreach ( $video_ids as $video_id ) {
			$video_id = absint( $video_id );
			if ( $video_id <= 0 ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Invalid video attachment ID.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$attachment = get_post( $video_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Attachment not found.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$mime_type = get_post_mime_type( $video_id );
			if ( 0 !== strpos( $mime_type, 'video/' ) ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Attachment is not a video file.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$file_path = get_attached_file( $video_id );
			if ( ! file_exists( $file_path ) ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Video file does not exist on disk.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$entry = array(
				'video_id'  => $video_id,
				'title'     => get_the_title( $video_id ),
				'file_size' => filesize( $file_path ),
				'mime_type' => $mime_type,
				'platform'  => $platform,
			);

			if ( ! $dry_run ) {
				// Mark as processing and set target platform.
				update_post_meta( $video_id, '_mcp_video_target_platform', $platform );
				update_post_meta( $video_id, '_mcp_video_queue_status', 'pending' );
				update_post_meta( $video_id, '_mcp_video_queued_at', gmdate( 'c' ) );

				$entry['status'] = 'queued';
			} else {
				$entry['status'] = 'dry_run';
			}

			$processed[] = $entry;
		}

		return array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'message'    => $dry_run
				? __( 'Dry run complete. No videos were actually uploaded.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: %d: number of videos */
					__( '%d videos queued for upload to %s.', 'mcp-ai-wpoos-pro' ),
					count( $processed ),
					ucfirst( $platform )
				),
			'processed'  => $processed,
			'errors'     => $errors,
			'total'      => count( $video_ids ),
			'successful' => count( $processed ),
			'failed'     => count( $errors ),
		);
	}
}
