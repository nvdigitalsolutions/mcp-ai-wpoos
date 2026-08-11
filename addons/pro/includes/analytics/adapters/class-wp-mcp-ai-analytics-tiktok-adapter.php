<?php
/**
 * TikTok Adapter — TikTok analytics via Open API.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for TikTok. Uses the TikTok
 * Open API (TikTok for Developers) for account insights, video metrics,
 * and follower data. Requires OAuth 2.0 access token.
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
 * TikTok analytics adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_TikTok_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * TikTok Open API base URL.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const BASE_URL = 'https://open-api.tiktok.com/';

	/**
	 * Default request timeout in seconds.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const TIMEOUT = 20;

	/**
	 * Get the platform slug.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return 'tiktok';
	}

	/**
	 * Check if TikTok credentials are configured.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return ! empty( $settings['tiktok_access_token'] );
	}

	/**
	 * Get the configured access token.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	private function get_access_token() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return isset( $settings['tiktok_access_token'] ) ? $settings['tiktok_access_token'] : null;
	}

	/**
	 * Make a GET request to the TikTok API.
	 *
	 * @since 1.7.0
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @return array|WP_Error
	 */
	private function api_get( $endpoint, array $params = array() ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error(
				'wp_mcp_ai_tiktok_not_configured',
				__( 'TikTok API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$url = self::BASE_URL . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'TikTok API returned status %d.', 'mcp-ai-wpoos-pro' ),
					$code
				);
			return new WP_Error(
				'wp_mcp_ai_tiktok_api_error',
				$error_message,
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id TikTok Open ID.
	 * @param string[] $metrics    List of metric names.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array|WP_Error
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_tiktok_not_configured',
				__( 'TikTok API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'video/list/',
			array(
				'open_id'    => $account_id,
				'start_date' => $since,
				'end_date'   => $until,
				'max_count'  => 50,
				'fields'     => 'id,title,create_time,share_count,comment_count,like_count,view_count',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized = array();
		$normalizer = WP_MCP_AI_Analytics_Metric_Normalizer::instance();

		$aggregated = array(
			'impressions'      => 0,
			'likes'            => 0,
			'comments'         => 0,
			'shares'           => 0,
			'video_views'      => 0,
			'engagement'       => 0,
		);

		$videos = isset( $result['data']['videos'] ) ? $result['data']['videos'] : array();
		if ( empty( $videos ) && isset( $result['data']['list'] ) ) {
			$videos = $result['data']['list'];
		}

		foreach ( $videos as $video ) {
			$likes    = isset( $video['like_count'] ) ? (int) $video['like_count'] : 0;
			$comments = isset( $video['comment_count'] ) ? (int) $video['comment_count'] : 0;
			$shares   = isset( $video['share_count'] ) ? (int) $video['share_count'] : 0;
			$views    = isset( $video['view_count'] ) ? (int) $video['view_count'] : 0;

			$aggregated['likes']        += $likes;
			$aggregated['comments']     += $comments;
			$aggregated['shares']       += $shares;
			$aggregated['video_views']  += $views;
			$aggregated['impressions']  += $views; // TikTok uses video_views as primary reach metric.
			$aggregated['engagement']   += $likes + $comments + $shares;
		}

		foreach ( $aggregated as $name => $value ) {
			if ( $value > 0 ) {
				$normalized[] = array(
					'metric_name'  => $name,
					'metric_value' => (float) $value,
					'platform'     => 'tiktok',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => 'day',
				);
			}
		}

		// Get follower count.
		$info_result = $this->api_get(
			'user/info/',
			array(
				'open_id' => $account_id,
				'fields'  => 'follower_count,following_count,display_name,avatar_url',
			)
		);

		if ( ! is_wp_error( $info_result ) ) {
			$user_data = isset( $info_result['data']['user'] ) ? $info_result['data']['user'] : $info_result['data'];
			$follower_ct = isset( $user_data['follower_count'] ) ? (int) $user_data['follower_count'] : 0;

			$normalized[] = array(
				'metric_name'  => 'followers',
				'metric_value' => (float) $follower_ct,
				'platform'     => 'tiktok',
				'account_id'   => $account_id,
				'period_start' => $since,
				'period_end'   => $until,
				'granularity'  => 'day',
			);
		}

		return $normalized;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $post_id TikTok video ID.
	 * @param string[] $metrics List of metric names.
	 * @return WP_MCP_AI_Analytics_Post_DTO|WP_Error
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_tiktok_not_configured',
				__( 'TikTok API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'video/query/',
			array(
				'video_id' => $post_id,
				'fields'   => 'id,title,create_time,share_count,comment_count,like_count,view_count',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) ? $result['data'] : array();

		$normalized_metrics = array(
			'impressions'  => isset( $data['view_count'] ) ? (int) $data['view_count'] : 0,
			'video_views'  => isset( $data['view_count'] ) ? (int) $data['view_count'] : 0,
			'likes'        => isset( $data['like_count'] ) ? (int) $data['like_count'] : 0,
			'comments'     => isset( $data['comment_count'] ) ? (int) $data['comment_count'] : 0,
			'shares'       => isset( $data['share_count'] ) ? (int) $data['share_count'] : 0,
			'engagement'   => ( isset( $data['like_count'] ) ? (int) $data['like_count'] : 0 )
				+ ( isset( $data['comment_count'] ) ? (int) $data['comment_count'] : 0 )
				+ ( isset( $data['share_count'] ) ? (int) $data['share_count'] : 0 ),
		);

		return WP_MCP_AI_Analytics_Post_DTO::from_array(
			array(
				'platform'     => 'tiktok',
				'post_id'      => $post_id,
				'content_type' => 'video',
				'posted_at'    => isset( $data['create_time'] )
					? gmdate( 'c', $data['create_time'] )
					: '',
				'caption'      => isset( $data['title'] ) ? $data['title'] : null,
				'metrics'      => $normalized_metrics,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  TikTok Open ID.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period.
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_tiktok_not_configured',
				__( 'TikTok API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'user/info/',
			array(
				'open_id' => $account_id,
				'fields'  => 'follower_count,following_count',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user_data   = isset( $result['data']['user'] ) ? $result['data']['user'] : ( isset( $result['data'] ) ? $result['data'] : array() );
		$follower_ct = isset( $user_data['follower_count'] ) ? (int) $user_data['follower_count'] : 0;

		return array(
			WP_MCP_AI_Analytics_Metric_DTO::from_array(
				array(
					'metric_name'  => 'followers',
					'metric_value' => $follower_ct,
					'platform'     => 'tiktok',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => $granularity,
				)
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id TikTok Open ID.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Maximum videos.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		$result = $this->api_get(
			'video/list/',
			array(
				'open_id'    => $account_id,
				'start_date' => $since,
				'end_date'   => $until,
				'max_count'  => min( $limit, 50 ),
				'fields'     => 'id,title,create_time,share_count,comment_count,like_count,view_count',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$posts  = array();
		$videos = isset( $result['data']['videos'] ) ? $result['data']['videos'] : array();
		if ( empty( $videos ) && isset( $result['data']['list'] ) ) {
			$videos = $result['data']['list'];
		}

		foreach ( $videos as $video ) {
			$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
				array(
					'platform'     => 'tiktok',
					'post_id'      => isset( $video['id'] ) ? $video['id'] : '',
					'account_id'   => $account_id,
					'content_type' => 'video',
					'posted_at'    => isset( $video['create_time'] )
						? gmdate( 'c', $video['create_time'] )
						: '',
					'caption'      => isset( $video['title'] ) ? $video['title'] : null,
					'metrics'      => array(
						'impressions'  => isset( $video['view_count'] ) ? (int) $video['view_count'] : 0,
						'video_views'  => isset( $video['view_count'] ) ? (int) $video['view_count'] : 0,
						'likes'        => isset( $video['like_count'] ) ? (int) $video['like_count'] : 0,
						'comments'     => isset( $video['comment_count'] ) ? (int) $video['comment_count'] : 0,
						'shares'       => isset( $video['share_count'] ) ? (int) $video['share_count'] : 0,
					),
				)
			);
		}

		// Sort by views descending.
		usort(
			$posts,
			function ( $a, $b ) {
				return $b->get_metric( 'impressions' ) - $a->get_metric( 'impressions' );
			}
		);

		return array_slice( $posts, 0, $limit );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int|null
	 */
	public function get_rate_limit_remaining() {
		$limiter = WP_MCP_AI_Analytics_Rate_Limiter::instance();
		return $limiter->get_remaining( 'tiktok' );
	}
}
