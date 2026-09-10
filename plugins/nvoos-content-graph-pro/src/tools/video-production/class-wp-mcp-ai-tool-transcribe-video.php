<?php
/**
 * Video-production tool batch (ecosystem port - Wave F2, video-production toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/video-production/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no path
 * constants in the gated tool batch - the D8-compat interface copies resolve via the entry's spl autoloader).
 *
 * Tool for generating transcripts for videos.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Video_Production_Toolkit
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates transcripts for videos.
 *
 * Accepts an array of video attachment IDs and generates transcripts.
 * Supports dry_run mode for preview.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Transcribe_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'transcribe_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Transcribe Video', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates transcripts for videos. Supports dry_run mode.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Array of video attachment IDs to transcribe.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
				),
				'language'  => array(
					'type'        => 'string',
					'description' => __( 'Language code for transcription (e.g. "en", "es", "fr").', 'nvoos-content-graph-pro' ),
					'default'     => 'en',
				),
				'dry_run'   => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview the transcription without actually processing.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'video_ids' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'profession_tags'       => array( 'video_producer', 'content_manager', 'accessibility' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return __( 'The video production toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Transcribe Video tool is not available.', 'nvoos-content-graph-pro' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to transcribe videos.', 'nvoos-content-graph-pro' )
			);
		}

		// Parse arguments.
		$video_ids = isset( $arguments['video_ids'] ) ? $arguments['video_ids'] : array();
		$language  = isset( $arguments['language'] ) ? sanitize_text_field( $arguments['language'] ) : 'en';
		$dry_run   = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $video_ids ) || ! is_array( $video_ids ) ) {
			return new WP_Error(
				'missing_video_ids',
				__( 'At least one video attachment ID must be provided.', 'nvoos-content-graph-pro' )
			);
		}

		$processed = array();
		$errors    = array();

		foreach ( $video_ids as $video_id ) {
			$video_id = absint( $video_id );
			if ( $video_id <= 0 ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Invalid video attachment ID.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$attachment = get_post( $video_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Attachment not found.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$mime_type = get_post_mime_type( $video_id );
			if ( 0 !== strpos( $mime_type, 'video/' ) ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Attachment is not a video file.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$file_path = get_attached_file( $video_id );
			if ( ! file_exists( $file_path ) ) {
				$errors[] = array(
					'video_id' => $video_id,
					'message'  => __( 'Video file does not exist on disk.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$entry = array(
				'video_id'  => $video_id,
				'title'     => get_the_title( $video_id ),
				'file_size' => filesize( $file_path ),
				'mime_type' => $mime_type,
				'language'  => $language,
			);

			if ( ! $dry_run ) {
				// Mark the video for transcription processing.
				update_post_meta( $video_id, '_mcp_video_transcribe_language', $language );
				update_post_meta( $video_id, '_mcp_video_transcribe_status', 'pending' );
				update_post_meta( $video_id, '_mcp_video_transcribe_requested_at', gmdate( 'c' ) );

				$entry['status'] = 'queued_for_transcription';
			} else {
				$entry['status'] = 'dry_run';
			}

			$processed[] = $entry;
		}

		return array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'message'    => $dry_run
				? __( 'Dry run complete. No videos were actually processed.', 'nvoos-content-graph-pro' )
				: sprintf(
					/* translators: %d: number of videos */
					__( '%d videos queued for transcription.', 'nvoos-content-graph-pro' ),
					count( $processed )
				),
			'processed'  => $processed,
			'errors'     => $errors,
			'total'      => count( $video_ids ),
			'successful' => count( $processed ),
			'failed'     => count( $errors ),
		);
	}
}
