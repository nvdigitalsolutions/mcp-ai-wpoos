<?php
/**
 * Analytics Adapter Interface — Contract for platform-specific adapters.
 *
 * Every platform adapter (Meta, Twitter, LinkedIn, TikTok, WooCommerce, GA4,
 * Cloudways) must implement this interface. The Analytics_Service delegates
 * all platform-specific API calls to registered adapters.
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
 * Platform analytics adapter contract.
 *
 * @since 1.7.0
 */
interface WP_MCP_AI_Analytics_Adapter {

	/**
	 * Get the platform identifier this adapter handles.
	 *
	 * @since 1.7.0
	 * @return string Platform slug (instagram, facebook, twitter, linkedin, tiktok, etc.).
	 */
	public function get_platform();

	/**
	 * Fetch account-level insights for a given account and metric set.
	 *
	 * @since 1.7.0
	 *
	 * @param string   $account_id Platform-native account ID.
	 * @param string[] $metrics    List of platform-native metric names to fetch.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array<string,mixed>|WP_Error Normalized metric data or error.
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until );

	/**
	 * Fetch post-level insights for a specific post.
	 *
	 * @since 1.7.0
	 *
	 * @param string   $post_id Platform-native post ID.
	 * @param string[] $metrics List of platform-native metric names to fetch.
	 * @return WP_MCP_AI_Analytics_Post_DTO|WP_Error Post DTO or error.
	 */
	public function get_post_insights( $post_id, array $metrics );

	/**
	 * Fetch follower growth time-series data.
	 *
	 * @since 1.7.0
	 *
	 * @param string $account_id  Platform-native account ID.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period (day, week, month).
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error Array of metric points or error.
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' );

	/**
	 * Fetch top-performing posts for an account.
	 *
	 * @since 1.7.0
	 *
	 * @param string $account_id Platform-native account ID.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Maximum number of posts to return.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error Array of post DTOs or error.
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 );

	/**
	 * Check if this adapter is configured with valid credentials.
	 *
	 * @since 1.7.0
	 * @return bool True if API credentials are configured.
	 */
	public function is_configured();

	/**
	 * Get the remaining rate limit for this platform, if available.
	 *
	 * @since 1.7.0
	 * @return int|null Remaining requests or null if unknown.
	 */
	public function get_rate_limit_remaining();
}
