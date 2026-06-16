<?php
/**
 * Tool for retrieving the social media content calendar.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Social_Media_Toolkit
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves the social media content calendar showing scheduled posts,
 * optionally filtered by platform, status, or date range.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Get_Content_Calendar implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_content_calendar';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Content Calendar', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the social media content calendar showing scheduled posts, optionally filtered by platform, status, or date range.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'platform'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by social media platform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'all' ),
					'default'     => 'all',
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by post status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'draft', 'published' ),
				),
				'date_from' => array(
					'type'        => 'string',
					'description' => __( 'Start date for filtering (ISO 8601 format).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'   => array(
					'type'        => 'string',
					'description' => __( 'End date for filtering (ISO 8601 format).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of calendar entries to return.', 'mcp-ai-wpoos-pro' ),
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
			'toolkit'               => 'social_media',
			'post_type'             => 'mcp_social_post',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'social_media_manager', 'content_manager', 'marketer' ),
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
	 * Requires the Social Media Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'The social media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Get Content Calendar tool is not available.', 'mcp-ai-wpoos-pro' );
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
				__( 'You do not have permission to view the content calendar.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse and sanitize arguments.
		$platform  = isset( $arguments['platform'] ) ? sanitize_text_field( $arguments['platform'] ) : 'all';
		$status    = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$date_from = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to   = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$limit     = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		// Build query args for social media posts.
		$query_args = array(
			'post_type'      => 'mcp_social_post',
			'post_status'    => array( 'draft', 'future', 'publish' ),
			'posts_per_page' => min( $limit, 500 ),
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(),
		);

		// Filter by status.
		if ( ! empty( $status ) ) {
			$status_map = array(
				'scheduled'  => 'future',
				'draft'      => 'draft',
				'published'  => 'publish',
			);
			if ( isset( $status_map[ $status ] ) ) {
				$query_args['post_status'] = array( $status_map[ $status ] );
			}
		}

		// Filter by platform (meta query).
		if ( ! empty( $platform ) && 'all' !== $platform ) {
			$query_args['meta_query'][] = array(
				'key'     => '_mcp_social_platform',
				'value'   => $platform,
				'compare' => '=',
			);
		}

		// Filter by date range.
		if ( ! empty( $date_from ) ) {
			$query_args['date_query'][] = array(
				'after'     => $date_from,
				'inclusive' => true,
			);
		}
		if ( ! empty( $date_to ) ) {
			$query_args['date_query'][] = array(
				'before'    => $date_to,
				'inclusive' => true,
			);
		}

		$query   = new WP_Query( $query_args );
		$entries = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$entries[] = array(
					'id'             => $post_id,
					'title'          => get_the_title(),
					'platform'       => get_post_meta( $post_id, '_mcp_social_platform', true ),
					'status'         => get_post_status(),
					'scheduled_time' => get_post_meta( $post_id, '_mcp_social_scheduled_time', true ),
					'content'        => get_the_content(),
					'image_url'      => get_post_meta( $post_id, '_mcp_social_image_url', true ),
					'link_url'       => get_post_meta( $post_id, '_mcp_social_link_url', true ),
					'created_at'     => get_the_date( 'c' ),
					'modified_at'    => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of calendar entries */
				__( 'Retrieved %d content calendar entries.', 'mcp-ai-wpoos-pro' ),
				count( $entries )
			),
			'total'   => count( $entries ),
			'entries' => $entries,
			'filters' => array(
				'platform'  => $platform,
				'status'    => $status,
				'date_from' => $date_from,
				'date_to'   => $date_to,
			),
		);
	}
}
