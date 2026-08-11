<?php
/**
 * Analytics Metric Normalizer — Cross-platform metric name mapping.
 *
 * Maps platform-specific metric names (e.g. Meta's `page_impressions`, Twitter's
 * `impression_count`) to a unified set of metric names. Supports bidirectional
 * mapping and computation of derived metrics like engagement_rate.
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
 * Metric normalizer service.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Metric_Normalizer {

	/**
	 * Singleton instance.
	 *
	 * @since 1.7.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Unified → platform metric name mapping.
	 *
	 * Each unified metric maps to an associative array of platform => native name.
	 * Use null when a platform does not support the metric.
	 *
	 * @since 1.7.0
	 * @var array<string,array<string,string|null>>
	 */
	const METRIC_MAP = array(
		'impressions'     => array(
			'meta'            => 'impressions',
			'facebook'        => 'page_impressions',
			'instagram'       => 'impressions',
			'twitter'         => 'impression_count',
			'linkedin'        => 'impressionCount',
			'tiktok'          => 'video_views',
			'google_business' => null,
		),
		'reach'           => array(
			'meta'            => 'reach',
			'facebook'        => 'page_impressions_unique',
			'instagram'       => 'reach',
			'twitter'         => null,
			'linkedin'        => 'uniqueImpressionsCount',
			'tiktok'          => null,
			'google_business' => null,
		),
		'engagement'      => array(
			'meta'            => 'engagement',
			'facebook'        => 'page_engaged_users',
			'instagram'       => 'engagement',
			'twitter'         => 'engagements',
			'linkedin'        => 'engagement',
			'tiktok'          => 'total_engagement',
			'google_business' => null,
		),
		'likes'           => array(
			'meta'            => 'likes',
			'facebook'        => 'page_fans',
			'instagram'       => 'likes',
			'twitter'         => 'like_count',
			'linkedin'        => 'likeCount',
			'tiktok'          => 'digg_count',
			'google_business' => null,
		),
		'comments'        => array(
			'meta'            => 'comments',
			'facebook'        => null,
			'instagram'       => 'comments',
			'twitter'         => 'reply_count',
			'linkedin'        => 'commentCount',
			'tiktok'          => 'comment_count',
			'google_business' => null,
		),
		'shares'          => array(
			'meta'            => 'shares',
			'facebook'        => 'page_sharedposts',
			'instagram'       => 'shares',
			'twitter'         => 'retweet_count',
			'linkedin'        => 'shareCount',
			'tiktok'          => 'share_count',
			'google_business' => null,
		),
		'saves'           => array(
			'meta'            => 'saved',
			'facebook'        => null,
			'instagram'       => 'saved',
			'twitter'         => 'bookmark_count',
			'linkedin'        => null,
			'tiktok'          => null,
			'google_business' => null,
		),
		'followers'       => array(
			'meta'            => 'followers_count',
			'facebook'        => 'page_fans',
			'instagram'       => 'followers_count',
			'twitter'         => 'followers_count',
			'linkedin'        => 'followerCount',
			'tiktok'          => 'follower_count',
			'google_business' => null,
		),
		'profile_views'   => array(
			'meta'            => 'profile_views',
			'facebook'        => null,
			'instagram'       => 'profile_views',
			'twitter'         => null,
			'linkedin'        => null,
			'tiktok'          => 'profile_views',
			'google_business' => null,
		),
		'video_views'     => array(
			'meta'            => 'video_views',
			'facebook'        => 'page_video_views',
			'instagram'       => 'video_views',
			'twitter'         => null,
			'linkedin'        => null,
			'tiktok'          => 'video_views',
			'google_business' => null,
		),
		'clicks'          => array(
			'meta'            => null,
			'facebook'        => 'page_consumptions',
			'instagram'       => null,
			'twitter'         => 'url_link_clicks',
			'linkedin'        => 'clickCount',
			'tiktok'          => null,
			'google_business' => null,
		),
		'engagement_rate' => array(
			'meta'            => '__computed__',
			'facebook'        => '__computed__',
			'instagram'       => '__computed__',
			'twitter'         => '__computed__',
			'linkedin'        => '__computed__',
			'tiktok'          => '__computed__',
			'google_business' => '__computed__',
		),
	);

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.7.0
	 */
	private function __construct() {}

	/**
	 * Get singleton instance.
	 *
	 * @since 1.7.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Convert a platform-native metric name to the unified name.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform           Platform identifier.
	 * @param string $native_metric_name Native metric name from the platform API.
	 * @return string|null Unified metric name or null if not found.
	 */
	public function normalize( $platform, $native_metric_name ) {
		foreach ( self::METRIC_MAP as $unified => $platforms ) {
			if ( isset( $platforms[ $platform ] ) && $platforms[ $platform ] === $native_metric_name ) {
				return $unified;
			}
		}
		return null;
	}

	/**
	 * Convert a unified metric name to the platform-native name.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform          Platform identifier.
	 * @param string $unified_metric_name Unified metric name.
	 * @return string|null Platform-native metric name or null if not supported.
	 */
	public function denormalize( $platform, $unified_metric_name ) {
		if ( ! isset( self::METRIC_MAP[ $unified_metric_name ] ) ) {
			return null;
		}
		return isset( self::METRIC_MAP[ $unified_metric_name ][ $platform ] )
			? self::METRIC_MAP[ $unified_metric_name ][ $platform ]
			: null;
	}

	/**
	 * Get all native metric names for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return string[] Array of native metric names.
	 */
	public function get_native_metrics( $platform ) {
		$metrics = array();
		foreach ( self::METRIC_MAP as $unified => $platforms ) {
			if ( isset( $platforms[ $platform ] ) && null !== $platforms[ $platform ] && '__computed__' !== $platforms[ $platform ] ) {
				$metrics[] = $platforms[ $platform ];
			}
		}
		return array_unique( $metrics );
	}

	/**
	 * Get all unified metric names supported for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return string[] Array of unified metric names.
	 */
	public function get_supported_metrics( $platform ) {
		$metrics = array();
		foreach ( self::METRIC_MAP as $unified => $platforms ) {
			if ( isset( $platforms[ $platform ] ) && null !== $platforms[ $platform ] ) {
				$metrics[] = $unified;
			}
		}
		return $metrics;
	}

	/**
	 * Check if a unified metric is computed (not directly from an API).
	 *
	 * @since 1.7.0
	 *
	 * @param string $unified_metric_name Unified metric name.
	 * @return bool True if the metric is computed.
	 */
	public function is_computed( $unified_metric_name ) {
		if ( ! isset( self::METRIC_MAP[ $unified_metric_name ] ) ) {
			return false;
		}
		$platforms = self::METRIC_MAP[ $unified_metric_name ];
		$first     = reset( $platforms );
		return '__computed__' === $first;
	}

	/**
	 * Normalize an entire response payload from a platform adapter.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @param array  $raw_data Raw API response with native metric keys.
	 * @return array Normalized data with unified metric keys.
	 */
	public function normalize_payload( $platform, array $raw_data ) {
		$normalized = array();

		foreach ( $raw_data as $key => $value ) {
			$unified = $this->normalize( $platform, $key );
			if ( null !== $unified ) {
				$normalized[ $unified ] = $value;
			} else {
				// Preserve unknown keys for debugging.
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Compute the engagement rate from component metrics.
	 *
	 * Formula: (likes + comments + shares + saves) / impressions × 100
	 *
	 * @since 1.7.0
	 *
	 * @param array $metrics Associative array with unified metric keys.
	 * @return float Engagement rate as percentage (0-100).
	 */
	public function compute_engagement_rate( array $metrics ) {
		$impressions = isset( $metrics['impressions'] ) ? (float) $metrics['impressions'] : 0;

		if ( $impressions <= 0 ) {
			return 0.0;
		}

		$likes    = isset( $metrics['likes'] ) ? (float) $metrics['likes'] : 0;
		$comments = isset( $metrics['comments'] ) ? (float) $metrics['comments'] : 0;
		$shares   = isset( $metrics['shares'] ) ? (float) $metrics['shares'] : 0;
		$saves    = isset( $metrics['saves'] ) ? (float) $metrics['saves'] : 0;

		$engagement = $likes + $comments + $shares + $saves;

		return round( ( $engagement / $impressions ) * 100, 2 );
	}
}
