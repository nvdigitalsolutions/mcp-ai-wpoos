<?php
/**
 * Tool for retrieving videos missing thumbnail images.
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
 * Retrieves videos that are missing thumbnail images, optionally
 * filtered by platform.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Get_Videos_Without_Thumbnails implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_videos_without_thumbnails';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Videos Without Thumbnails', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves videos missing thumbnail images.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'platform' => array(
					'type'        => 'string',
					'description' => __( 'Filter by target platform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'youtube', 'vimeo', 'local', 'all' ),
					'default'     => 'all',
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of videos to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
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
			'risk_level'            => 'info',
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
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
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

		return __( 'Get Videos Without Thumbnails tool is not available.', 'mcp-ai-wpoos-pro' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view video thumbnails.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse and sanitize arguments.
		$platform = isset( $arguments['platform'] ) ? sanitize_text_field( $arguments['platform'] ) : 'all';
		$limit    = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		// Build query args for video attachments without thumbnails.
		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'video',
			'post_status'    => 'inherit',
			'posts_per_page' => min( $limit, 500 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'relation' => 'OR',
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_thumbnail_id',
						'value'   => '',
						'compare' => '=',
					),
					array(
						'key'     => '_thumbnail_id',
						'value'   => '0',
						'compare' => '=',
					),
				),
			),
		);

		// Filter by platform.
		if ( ! empty( $platform ) && 'all' !== $platform ) {
			$query_args['meta_query'][] = array(
				'key'     => '_mcp_video_target_platform',
				'value'   => $platform,
				'compare' => '=',
			);
		}

		$query  = new WP_Query( $query_args );
		$videos = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$attachment_id = get_the_ID();
				$file_path     = get_attached_file( $attachment_id );

				$videos[] = array(
					'id'         => $attachment_id,
					'title'      => get_the_title(),
					'url'        => wp_get_attachment_url( $attachment_id ),
					'mime_type'  => get_post_mime_type( $attachment_id ),
					'file_size'  => file_exists( $file_path ) ? filesize( $file_path ) : 0,
					'platform'   => get_post_meta( $attachment_id, '_mcp_video_target_platform', true ),
					'created_at' => get_the_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of videos */
				__( 'Found %d videos without thumbnails.', 'mcp-ai-wpoos-pro' ),
				count( $videos )
			),
			'total'   => count( $videos ),
			'videos'  => $videos,
			'filters' => array(
				'platform' => $platform,
			),
		);
	}
}
