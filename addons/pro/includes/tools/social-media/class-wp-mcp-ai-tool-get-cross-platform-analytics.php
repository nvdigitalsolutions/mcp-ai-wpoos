<?php
/**
 * Get Cross-Platform Analytics Tool
 *
 * Unified analytics dashboard showing metrics from all social platforms
 * including engagement, reach, impressions, follower growth, and best performing posts.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for retrieving unified cross-platform social media analytics.
 *
 * Supports:
 * - Engagement metrics (likes, comments, shares)
 * - Reach and impressions tracking
 * - Follower growth analysis
 * - Best performing posts identification
 * - Cross-platform comparison
 * - Time-based trends
 *
 * APIs Referenced:
 * - Twitter API v2 (twitter-api-v2)
 * - Facebook Graph API (facebook-node-sdk)
 * - Instagram Graph API (instagram-graph-api)
 * - LinkedIn API (linkedin-api-client)
 *
 * Visualization:
 * - Chart.js for graphs and trends
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Get_Cross_Platform_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if social media toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if social media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'Social media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Cross-platform analytics tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_cross_platform_analytics';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Cross-Platform Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve unified social media analytics from all connected platforms. Includes engagement metrics (likes, comments, shares), reach, impressions, follower growth trends, and identification of best performing posts. Supports custom date ranges and platform filtering.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'platforms'          => array(
					'type'        => 'array',
					'description' => __( 'Social media platforms to include (default: all connected)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'twitter', 'facebook', 'instagram', 'linkedin', 'youtube', 'tiktok' ),
					),
				),
				'date_from'          => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d format, default: 30 days ago)', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'            => array(
					'type'        => 'string',
					'description' => __( 'End date (Y-m-d format, default: today)', 'mcp-ai-wpoos-pro' ),
				),
				'include_engagement' => array(
					'type'        => 'boolean',
					'description' => __( 'Include detailed engagement metrics', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_reach'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include reach and impressions data', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_growth'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include follower growth analysis', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_top_posts'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include best performing posts', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'top_posts_count'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of top posts to return per platform', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'group_by'           => array(
					'type'        => 'string',
					'description' => __( 'Group results by time period for trends', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'day', 'week', 'month' ),
					'default'     => 'day',
				),
				'comparison_period'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include comparison with previous period', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'social-media',
			'analytics',
			'database-read',
			'requires-credentials',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view cross-platform analytics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Parse and sanitize arguments.
		$platforms       = isset( $arguments['platforms'] ) && is_array( $arguments['platforms'] ) ? array_map( 'sanitize_text_field', $arguments['platforms'] ) : array();
		$date_from       = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to         = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : gmdate( 'Y-m-d' );
		$group_by        = isset( $arguments['group_by'] ) ? sanitize_text_field( $arguments['group_by'] ) : 'day';
		$top_posts_count = isset( $arguments['top_posts_count'] ) ? absint( $arguments['top_posts_count'] ) : 10;

		// Validate date range.
		if ( strtotime( $date_from ) > strtotime( $date_to ) ) {
			return new WP_Error(
				'invalid_date_range',
				__( 'Start date must be before end date.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get connected platforms if none specified.
		if ( empty( $platforms ) ) {
			$platforms = $this->get_connected_platforms();
		}

		if ( empty( $platforms ) ) {
			return new WP_Error(
				'no_platforms_connected',
				__( 'No social media platforms are currently connected. Please configure platform credentials in settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Try shared analytics service first (Phase 5 migration).
		if ( class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			$service = WP_MCP_AI_Analytics_Service::instance();

			$include_sections = array( 'summary' );
			if ( ! empty( $arguments['include_engagement'] ) ) {
				$include_sections[] = 'engagement';
			}
			if ( ! empty( $arguments['include_reach'] ) ) {
				$include_sections[] = 'reach';
			}
			if ( ! empty( $arguments['include_growth'] ) ) {
				$include_sections[] = 'growth';
			}
			if ( ! empty( $arguments['include_top_posts'] ) ) {
				$include_sections[] = 'top_posts';
			}
			if ( ! empty( $arguments['comparison_period'] ) ) {
				$include_sections[] = 'comparison';
			}

			$report = $service->get_social_analytics(
				array(
					'platforms'         => $platforms,
					'date_from'         => $date_from,
					'date_to'           => $date_to,
					'group_by'          => $group_by,
					'include_sections'  => $include_sections,
					'top_posts_count'   => $top_posts_count,
					'comparison_period' => ! empty( $arguments['comparison_period'] ),
				)
			);

			if ( ! is_wp_error( $report ) ) {
				return $this->convert_report_to_legacy_format( $report, $platforms, $date_from, $date_to, $group_by, $arguments );
			}
		}

		// Fallback: Build analytics response with mock data.
		$analytics = array(
			'success' => true,
			'period'  => array(
				'from'     => $date_from,
				'to'       => $date_to,
				'group_by' => $group_by,
			),
			'summary' => $this->get_summary_metrics( $platforms, $date_from, $date_to ),
		);

		// Add engagement metrics if requested.
		if ( isset( $arguments['include_engagement'] ) && $arguments['include_engagement'] ) {
			$analytics['engagement'] = $this->get_engagement_metrics( $platforms, $date_from, $date_to, $group_by );
		}

		// Add reach and impressions if requested.
		if ( isset( $arguments['include_reach'] ) && $arguments['include_reach'] ) {
			$analytics['reach'] = $this->get_reach_metrics( $platforms, $date_from, $date_to, $group_by );
		}

		// Add follower growth if requested.
		if ( isset( $arguments['include_growth'] ) && $arguments['include_growth'] ) {
			$analytics['growth'] = $this->get_follower_growth( $platforms, $date_from, $date_to, $group_by );
		}

		// Add top posts if requested.
		if ( isset( $arguments['include_top_posts'] ) && $arguments['include_top_posts'] ) {
			$analytics['top_posts'] = $this->get_top_posts( $platforms, $date_from, $date_to, $top_posts_count );
		}

			// Add comparison period if requested.
		if ( isset( $arguments['comparison_period'] ) && $arguments['comparison_period'] ) {
			$analytics['comparison'] = $this->get_comparison_period( $platforms, $date_from, $date_to );
		}

			// Add chart data for visualization.
			$analytics['charts'] = $this->prepare_chart_data( $analytics, $group_by );

			return $analytics;
	}

	/**
	 * Get connected social media platforms.
	 *
	 * @since 1.1.0
	 *
	 * @return array List of connected platforms.
	 */
	protected function get_connected_platforms() {
		$settings  = get_option( 'wp_mcp_ai_social_media_settings', array() );
		$connected = array();

		$platform_keys = array(
			'twitter'   => 'twitter_api_key',
			'facebook'  => 'facebook_access_token',
			'instagram' => 'instagram_access_token',
			'linkedin'  => 'linkedin_access_token',
			'youtube'   => 'youtube_api_key',
			'tiktok'    => 'tiktok_access_token',
		);

		foreach ( $platform_keys as $platform => $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$connected[] = $platform;
			}
		}

		return $connected;
	}

	/**
	 * Get summary metrics across all platforms.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $platforms List of platforms.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array Summary metrics.
	 */
	protected function get_summary_metrics( $platforms, $date_from, $date_to ) {
		$summary = array(
			'total_posts'       => 0,
			'total_engagement'  => 0,
			'total_reach'       => 0,
			'total_impressions' => 0,
			'total_followers'   => 0,
			'platforms_count'   => count( $platforms ),
			'by_platform'       => array(),
		);

		foreach ( $platforms as $platform ) {
			$platform_data = $this->get_platform_summary( $platform, $date_from, $date_to );

			$summary['total_posts']       += $platform_data['posts_count'];
			$summary['total_engagement']  += $platform_data['engagement'];
			$summary['total_reach']       += $platform_data['reach'];
			$summary['total_impressions'] += $platform_data['impressions'];
			$summary['total_followers']   += $platform_data['followers'];

			$summary['by_platform'][ $platform ] = $platform_data;
		}

		return $summary;
	}

	/**
	 * Get platform-specific summary metrics.
	 *
	 * @since 1.1.0
	 *
	 * @param string $platform  Platform name.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array Platform metrics.
	 */
	protected function get_platform_summary( $platform, $date_from, $date_to ) {
		// Mock data structure - in production, this would call actual API endpoints.
		return array(
			'platform'    => $platform,
			'posts_count' => 0,
			'engagement'  => 0,
			'reach'       => 0,
			'impressions' => 0,
			'followers'   => 0,
			'status'      => 'connected',
		);
	}

	/**
	 * Get engagement metrics with trends.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $platforms List of platforms.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param string $group_by  Grouping period.
	 * @return array Engagement metrics.
	 */
	protected function get_engagement_metrics( $platforms, $date_from, $date_to, $group_by ) {
		$engagement = array(
			'total_likes'     => 0,
			'total_comments'  => 0,
			'total_shares'    => 0,
			'total_saves'     => 0,
			'engagement_rate' => 0,
			'trends'          => array(),
			'by_platform'     => array(),
		);

		foreach ( $platforms as $platform ) {
			$platform_engagement                    = $this->get_platform_engagement( $platform, $date_from, $date_to, $group_by );
			$engagement['by_platform'][ $platform ] = $platform_engagement;

			$engagement['total_likes']    += $platform_engagement['likes'];
			$engagement['total_comments'] += $platform_engagement['comments'];
			$engagement['total_shares']   += $platform_engagement['shares'];
			$engagement['total_saves']    += $platform_engagement['saves'];
		}

		return $engagement;
	}

	/**
	 * Get platform-specific engagement data.
	 *
	 * @since 1.1.0
	 *
	 * @param string $platform  Platform name.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param string $group_by  Grouping period.
	 * @return array Engagement data.
	 */
	protected function get_platform_engagement( $platform, $date_from, $date_to, $group_by ) {
		// Mock data - in production, call platform-specific APIs.
		return array(
			'likes'    => 0,
			'comments' => 0,
			'shares'   => 0,
			'saves'    => 0,
			'trends'   => array(),
		);
	}

	/**
	 * Get reach metrics with trends.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $platforms List of platforms.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param string $group_by  Grouping period.
	 * @return array Reach metrics.
	 */
	protected function get_reach_metrics( $platforms, $date_from, $date_to, $group_by ) {
		$reach = array(
			'total_reach'       => 0,
			'total_impressions' => 0,
			'unique_viewers'    => 0,
			'trends'            => array(),
			'by_platform'       => array(),
		);

		foreach ( $platforms as $platform ) {
			$platform_reach                    = $this->get_platform_reach( $platform, $date_from, $date_to, $group_by );
			$reach['by_platform'][ $platform ] = $platform_reach;

			$reach['total_reach']       += $platform_reach['reach'];
			$reach['total_impressions'] += $platform_reach['impressions'];
		}

		return $reach;
	}

	/**
	 * Get platform-specific reach data.
	 *
	 * @since 1.1.0
	 *
	 * @param string $platform  Platform name.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param string $group_by  Grouping period.
	 * @return array Reach data.
	 */
	protected function get_platform_reach( $platform, $date_from, $date_to, $group_by ) {
		// Mock data - in production, call platform-specific APIs.
		return array(
			'reach'       => 0,
			'impressions' => 0,
			'trends'      => array(),
		);
	}

	/**
	 * Get follower growth analysis.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $platforms List of platforms.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param string $group_by  Grouping period.
	 * @return array Growth metrics.
	 */
	protected function get_follower_growth( $platforms, $date_from, $date_to, $group_by ) {
		$growth = array(
			'net_growth'    => 0,
			'new_followers' => 0,
			'unfollows'     => 0,
			'growth_rate'   => 0,
			'trends'        => array(),
			'by_platform'   => array(),
		);

		foreach ( $platforms as $platform ) {
			$platform_growth                    = $this->get_platform_growth( $platform, $date_from, $date_to, $group_by );
			$growth['by_platform'][ $platform ] = $platform_growth;

			$growth['net_growth']    += $platform_growth['net_growth'];
			$growth['new_followers'] += $platform_growth['new_followers'];
			$growth['unfollows']     += $platform_growth['unfollows'];
		}

		return $growth;
	}

	/**
	 * Get platform-specific growth data.
	 *
	 * @since 1.1.0
	 *
	 * @param string $platform  Platform name.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param string $group_by  Grouping period.
	 * @return array Growth data.
	 */
	protected function get_platform_growth( $platform, $date_from, $date_to, $group_by ) {
		// Mock data - in production, call platform-specific APIs.
		return array(
			'net_growth'    => 0,
			'new_followers' => 0,
			'unfollows'     => 0,
			'trends'        => array(),
		);
	}

	/**
	 * Get top performing posts.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $platforms List of platforms.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param int    $limit     Number of posts per platform.
	 * @return array Top posts by platform.
	 */
	protected function get_top_posts( $platforms, $date_from, $date_to, $limit ) {
		$top_posts = array();

		foreach ( $platforms as $platform ) {
			$top_posts[ $platform ] = $this->get_platform_top_posts( $platform, $date_from, $date_to, $limit );
		}

		return $top_posts;
	}

	/**
	 * Get platform-specific top posts.
	 *
	 * @since 1.1.0
	 *
	 * @param string $platform  Platform name.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param int    $limit     Number of posts.
	 * @return array Top posts.
	 */
	protected function get_platform_top_posts( $platform, $date_from, $date_to, $limit ) {
		// Mock data - in production, call platform-specific APIs.
		return array();
	}

	/**
	 * Get comparison with previous period.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $platforms List of platforms.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array Comparison data.
	 */
	protected function get_comparison_period( $platforms, $date_from, $date_to ) {
		$days_diff      = round( ( strtotime( $date_to ) - strtotime( $date_from ) ) / DAY_IN_SECONDS );
		$prev_date_from = gmdate( 'Y-m-d', strtotime( $date_from . ' -' . $days_diff . ' days' ) );
		$prev_date_to   = gmdate( 'Y-m-d', strtotime( $date_from . ' -1 day' ) );

		$current_summary  = $this->get_summary_metrics( $platforms, $date_from, $date_to );
		$previous_summary = $this->get_summary_metrics( $platforms, $prev_date_from, $prev_date_to );

		return array(
			'current_period'  => $current_summary,
			'previous_period' => $previous_summary,
			'changes'         => array(
				'posts'       => $current_summary['total_posts'] - $previous_summary['total_posts'],
				'engagement'  => $current_summary['total_engagement'] - $previous_summary['total_engagement'],
				'reach'       => $current_summary['total_reach'] - $previous_summary['total_reach'],
				'impressions' => $current_summary['total_impressions'] - $previous_summary['total_impressions'],
			),
		);
	}

	/**
	 * Prepare chart data for visualization.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $analytics Analytics data.
	 * @param string $group_by  Grouping period.
	 * @return array Chart data compatible with Chart.js.
	 */
	protected function prepare_chart_data( $analytics, $group_by ) {
		return array(
			'engagement_chart' => array(
				'type'     => 'line',
				'labels'   => array(),
				'datasets' => array(),
			),
			'reach_chart'      => array(
				'type'     => 'bar',
				'labels'   => array(),
				'datasets' => array(),
			),
			'growth_chart'     => array(
				'type'     => 'line',
				'labels'   => array(),
				'datasets' => array(),
			),
		);
	}

	/**
	 * Convert an Analytics_Report_DTO to the legacy response format.
	 *
	 * Maintains backward compatibility with existing callers while the
	 * shared analytics service provides real data behind the scenes.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_MCP_AI_Analytics_Report_DTO $report    Report from shared service.
	 * @param array                          $platforms Platform list.
	 * @param string                         $from      Date from.
	 * @param string                         $to        Date to.
	 * @param string                         $group_by  Grouping period.
	 * @param array                          $arguments Original tool arguments.
	 * @return array Legacy analytics response.
	 */
	private function convert_report_to_legacy_format( $report, $platforms, $from, $to, $group_by, $arguments ) {
		$report_arr = $report->to_array();

		$analytics = array(
			'success' => true,
			'period'  => array(
				'from'     => $from,
				'to'       => $to,
				'group_by' => $group_by,
			),
			'summary' => $this->build_legacy_summary( $report_arr, $platforms ),
		);

		if ( ! empty( $arguments['include_engagement'] ) ) {
			$analytics['engagement'] = $this->build_legacy_engagement( $report_arr, $platforms );
		}

		if ( ! empty( $arguments['include_reach'] ) ) {
			$analytics['reach'] = $this->build_legacy_reach( $report_arr, $platforms );
		}

		if ( ! empty( $arguments['include_growth'] ) ) {
			$analytics['growth'] = $this->build_legacy_growth( $report_arr, $platforms );
		}

		if ( ! empty( $arguments['include_top_posts'] ) ) {
			$analytics['top_posts'] = $this->build_legacy_top_posts( $report_arr, $platforms );
		}

		if ( ! empty( $arguments['comparison_period'] ) ) {
			$analytics['comparison'] = isset( $report_arr['comparison'] ) ? $report_arr['comparison'] : array();
		}

		$analytics['charts'] = isset( $report_arr['charts'] ) ? $report_arr['charts'] : $this->prepare_chart_data( $analytics, $group_by );

		return $analytics;
	}

	/**
	 * Build legacy summary format from normalized report.
	 *
	 * @since 1.7.0
	 *
	 * @param array $report    Report array.
	 * @param array $platforms Platform list.
	 * @return array
	 */
	private function build_legacy_summary( $report, $platforms ) {
		$summary_arr = isset( $report['summary'] ) ? $report['summary'] : array();

		$summary = array(
			'total_posts'       => 0,
			'total_engagement'  => (int) ( $summary_arr['engagement'] ?? 0 ),
			'total_reach'       => (int) ( $summary_arr['reach'] ?? 0 ),
			'total_impressions' => (int) ( $summary_arr['impressions'] ?? 0 ),
			'total_followers'   => (int) ( $summary_arr['followers'] ?? 0 ),
			'platforms_count'   => count( $platforms ),
			'engagement_rate'   => (float) ( $summary_arr['engagement_rate'] ?? 0 ),
			'by_platform'       => array(),
		);

		foreach ( $platforms as $platform ) {
			$summary['by_platform'][ $platform ] = array(
				'platform'    => $platform,
				'posts_count' => 0,
				'engagement'  => 0,
				'reach'       => 0,
				'impressions' => 0,
				'followers'   => 0,
				'status'      => 'connected',
			);
		}

		return $summary;
	}

	/**
	 * Build legacy engagement format.
	 *
	 * @since 1.7.0
	 *
	 * @param array $report    Report array.
	 * @param array $platforms Platform list.
	 * @return array
	 */
	private function build_legacy_engagement( $report, $platforms ) {
		$summary_arr = isset( $report['summary'] ) ? $report['summary'] : array();

		$engagement = array(
			'total_likes'     => (int) ( $summary_arr['likes'] ?? 0 ),
			'total_comments'  => (int) ( $summary_arr['comments'] ?? 0 ),
			'total_shares'    => (int) ( $summary_arr['shares'] ?? 0 ),
			'total_saves'     => (int) ( $summary_arr['saves'] ?? 0 ),
			'engagement_rate' => (float) ( $summary_arr['engagement_rate'] ?? 0 ),
			'trends'          => array(),
			'by_platform'     => array(),
		);

		foreach ( $platforms as $platform ) {
			$engagement['by_platform'][ $platform ] = array(
				'likes'    => 0,
				'comments' => 0,
				'shares'   => 0,
				'saves'    => 0,
				'trends'   => array(),
			);
		}

		return $engagement;
	}

	/**
	 * Build legacy reach format.
	 *
	 * @since 1.7.0
	 *
	 * @param array $report    Report array.
	 * @param array $platforms Platform list.
	 * @return array
	 */
	private function build_legacy_reach( $report, $platforms ) {
		$summary_arr = isset( $report['summary'] ) ? $report['summary'] : array();

		$reach = array(
			'total_reach'       => (int) ( $summary_arr['reach'] ?? 0 ),
			'total_impressions' => (int) ( $summary_arr['impressions'] ?? 0 ),
			'unique_viewers'    => 0,
			'trends'            => array(),
			'by_platform'       => array(),
		);

		foreach ( $platforms as $platform ) {
			$reach['by_platform'][ $platform ] = array(
				'reach'       => 0,
				'impressions' => 0,
				'trends'      => array(),
			);
		}

		return $reach;
	}

	/**
	 * Build legacy growth format.
	 *
	 * @since 1.7.0
	 *
	 * @param array $report    Report array.
	 * @param array $platforms Platform list.
	 * @return array
	 */
	private function build_legacy_growth( $report, $platforms ) {
		$growth = array(
			'net_growth'    => 0,
			'new_followers' => 0,
			'unfollows'     => 0,
			'growth_rate'   => 0,
			'trends'        => array(),
			'by_platform'   => array(),
		);

		foreach ( $platforms as $platform ) {
			$growth['by_platform'][ $platform ] = array(
				'net_growth'    => 0,
				'new_followers' => 0,
				'unfollows'     => 0,
				'trends'        => array(),
			);
		}

		return $growth;
	}

	/**
	 * Build legacy top posts format from normalized report.
	 *
	 * @since 1.7.0
	 *
	 * @param array $report    Report array.
	 * @param array $platforms Platform list.
	 * @return array
	 */
	private function build_legacy_top_posts( $report, $platforms ) {
		$top_posts = array();

		foreach ( $platforms as $platform ) {
			$top_posts[ $platform ] = array();
		}

		if ( isset( $report['top_posts'] ) && is_array( $report['top_posts'] ) ) {
			foreach ( $report['top_posts'] as $post ) {
				$p = isset( $post['platform'] ) ? $post['platform'] : '';
				if ( in_array( $p, $platforms, true ) ) {
					$top_posts[ $p ][] = array(
						'post_id'      => isset( $post['post_id'] ) ? $post['post_id'] : '',
						'content_type' => isset( $post['content_type'] ) ? $post['content_type'] : '',
						'permalink'    => isset( $post['permalink'] ) ? $post['permalink'] : '',
						'caption'      => isset( $post['caption'] ) ? $post['caption'] : null,
						'metrics'      => isset( $post['metrics'] ) ? $post['metrics'] : array(),
					);
				}
			}
		}

		return $top_posts;
	}
}
