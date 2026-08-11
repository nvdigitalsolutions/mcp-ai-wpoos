<?php
/**
 * Get Social Analytics Tool — Unified cross-platform social media analytics.
 *
 * Single entry point for all social media analytics across connected platforms.
 * Delegates to the shared Analytics_Service which coordinates adapters, caching,
 * rate limiting, and metric normalization.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified social media analytics tool.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Tool_Get_Social_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_social_analytics';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Social Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve unified cross-platform social media analytics including engagement, reach, follower growth, top posts, hashtag performance, competitor analysis, and influencer insights. Supports Meta (Facebook/Instagram), Twitter/X, LinkedIn, TikTok, and Google Business Profile. Returns normalized data with Chart.js visualization support.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'Social Media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			return __( 'Shared Analytics Service is not available.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Social analytics tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'platforms'          => array(
					'type'        => 'array',
					'description' => __( 'Platforms to include. Leave empty for all connected platforms.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'facebook',
							'instagram',
							'twitter',
							'linkedin',
							'tiktok',
							'google_business',
						),
					),
				),
				'accounts'           => array(
					'type'        => 'array',
					'description' => __( 'Specific account IDs to filter by platform.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'object',
						'properties' => array(
							'platform'   => array( 'type' => 'string' ),
							'account_id' => array( 'type' => 'string' ),
						),
					),
				),
				'date_from'          => array(
					'type'        => 'string',
					'description' => __( 'Start date in Y-m-d format. Default: 30 days ago.', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'            => array(
					'type'        => 'string',
					'description' => __( 'End date in Y-m-d format. Default: today.', 'mcp-ai-wpoos-pro' ),
				),
				'group_by'           => array(
					'type'        => 'string',
					'description' => __( 'Time period for grouping metrics.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'day', 'week', 'month' ),
					'default'     => 'day',
				),
				'include_sections'   => array(
					'type'        => 'array',
					'description' => __( 'Sections to include in the report.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'summary',
							'engagement',
							'reach',
							'growth',
							'top_posts',
							'comparison',
							'demographics',
							'hashtags',
							'competitors',
							'influencers',
						),
					),
					'default'     => array( 'summary', 'engagement', 'top_posts' ),
				),
				'top_posts_count'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of top posts to return per platform. Default: 10, Max: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'comparison_period'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include period-over-period comparison data.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'metrics'            => array(
					'type'        => 'array',
					'description' => __( 'Specific unified metrics to fetch. Leave empty for all available.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'impressions',
							'reach',
							'engagement',
							'likes',
							'comments',
							'shares',
							'saves',
							'followers',
							'profile_views',
							'video_views',
							'clicks',
							'engagement_rate',
						),
					),
				),
				'hashtags'           => array(
					'type'        => 'array',
					'description' => __( 'Hashtags to track performance for.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'competitor_handles' => array(
					'type'        => 'array',
					'description' => __( 'Competitor accounts to compare against.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'platform' => array( 'type' => 'string' ),
							'handle'   => array( 'type' => 'string' ),
						),
					),
				),
				'cache_ttl_override' => array(
					'type'        => 'integer',
					'description' => __( 'Override default cache TTL in seconds. Use 0 to force fresh data.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 86400,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string,bool>
	 */
	public function get_capability_flags() {
		return array(
			'pro'                  => true,
			'social-media'         => true,
			'analytics'            => true,
			'database-read'        => true,
			'requires-credentials' => true,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Canonical envelope (success array or WP_Error).
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Permission check.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view social analytics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Availability check.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Ensure the analytics service is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			return new WP_Error(
				'analytics_service_unavailable',
				__( 'Shared Analytics Service is not loaded.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize and build params.
		$params = array(
			'platforms'         => isset( $arguments['platforms'] ) && is_array( $arguments['platforms'] )
				? array_map( 'sanitize_text_field', $arguments['platforms'] )
				: array(),
			'date_from'         => isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'date_to'           => isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : gmdate( 'Y-m-d' ),
			'group_by'          => isset( $arguments['group_by'] ) ? sanitize_text_field( $arguments['group_by'] ) : 'day',
			'include_sections'  => isset( $arguments['include_sections'] ) && is_array( $arguments['include_sections'] )
				? array_map( 'sanitize_text_field', $arguments['include_sections'] )
				: array( 'summary', 'engagement', 'top_posts' ),
			'top_posts_count'   => isset( $arguments['top_posts_count'] ) ? absint( $arguments['top_posts_count'] ) : 10,
			'comparison_period' => ! empty( $arguments['comparison_period'] ),
		);

		// Validate date range.
		if ( strtotime( $params['date_from'] ) > strtotime( $params['date_to'] ) ) {
			return new WP_Error(
				'invalid_date_range',
				__( 'Start date must be before end date.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Delegate to shared analytics service.
		$service = WP_MCP_AI_Analytics_Service::instance();
		$report  = $service->get_social_analytics( $params );

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		// Return the canonical envelope.
		return array(
			'success' => true,
			'report'  => $report->to_array(),
		);
	}
}
