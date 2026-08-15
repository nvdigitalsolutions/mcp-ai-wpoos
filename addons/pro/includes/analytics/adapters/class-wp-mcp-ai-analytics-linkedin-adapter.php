<?php
/**
 * LinkedIn Adapter — LinkedIn analytics via Marketing API.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for LinkedIn. Uses the LinkedIn
 * Marketing API REST endpoints for organizational share statistics and
 * follower analytics. Requires OAuth 2.0 with rw_organization_admin scope.
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
 * LinkedIn analytics adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_LinkedIn_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * LinkedIn API base URL (REST).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const BASE_URL = 'https://api.linkedin.com/rest/';

	/**
	 * LinkedIn API v2 base URL (legacy).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const BASE_URL_V2 = 'https://api.linkedin.com/v2/';

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
		return 'linkedin';
	}

	/**
	 * Check if LinkedIn credentials are configured.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return ! empty( $settings['linkedin_access_token'] );
	}

	/**
	 * Get the configured access token.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	private function get_access_token() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return isset( $settings['linkedin_access_token'] ) ? $settings['linkedin_access_token'] : null;
	}

	/**
	 * Make a GET request to the LinkedIn API.
	 *
	 * @since 1.7.0
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @param bool   $use_v2   Whether to use the v2 base URL (default: REST).
	 * @return array|WP_Error
	 */
	private function api_get( $endpoint, array $params = array(), $use_v2 = false ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error(
				'wp_mcp_ai_linkedin_not_configured',
				__( 'LinkedIn API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$base = $use_v2 ? self::BASE_URL_V2 : self::BASE_URL;
		$url  = $base . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization'             => 'Bearer ' . $token,
					'LinkedIn-Version'          => '202505',
					'X-Restli-Protocol-Version' => '2.0.0',
					'Accept'                    => 'application/json',
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
			$error_message = isset( $data['message'] )
				? $data['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'LinkedIn API returned status %d.', 'mcp-ai-wpoos-pro' ),
					$code
				);
			return new WP_Error(
				'wp_mcp_ai_linkedin_api_error',
				$error_message,
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * Get the organization URN from an account ID.
	 *
	 * @since 1.7.0
	 *
	 * @param string $account_id Organization ID.
	 * @return string Full URN.
	 */
	private function org_urn( $account_id ) {
		if ( 0 === strpos( $account_id, 'urn:li:organization:' ) ) {
			return $account_id;
		}
		return 'urn:li:organization:' . $account_id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id LinkedIn organization ID.
	 * @param string[] $metrics    List of metric names.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array|WP_Error
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_linkedin_not_configured',
				__( 'LinkedIn API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$org_urn = rawurlencode( $this->org_urn( $account_id ) );

		// Get share statistics (aggregate post performance).
		$result = $this->api_get(
			'organizationalEntityShareStatistics',
			array(
				'q'                    => 'organizationalEntity',
				'organizationalEntity' => $this->org_urn( $account_id ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized = array();
		$normalizer = WP_MCP_AI_Analytics_Metric_Normalizer::instance();

		$total_stats = isset( $result['elements'][0]['totalShareStatistics'] )
			? $result['elements'][0]['totalShareStatistics']
			: array();

		$metric_mapping = array(
			'impressionCount'        => 'impressions',
			'shareCount'             => 'shares',
			'likeCount'              => 'likes',
			'commentCount'           => 'comments',
			'clickCount'             => 'clicks',
			'engagement'             => 'engagement',
			'uniqueImpressionsCount' => 'reach',
		);

		foreach ( $metric_mapping as $native => $unified ) {
			if ( isset( $total_stats[ $native ] ) ) {
				$normalized[] = array(
					'metric_name'  => $unified,
					'metric_value' => (float) $total_stats[ $native ],
					'platform'     => 'linkedin',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => 'day',
				);
			}
		}

		// Get follower statistics.
		$follower_result = $this->api_get(
			'organizationFollowerStatistics',
			array(
				'q'            => 'organization',
				'organization' => $this->org_urn( $account_id ),
			),
			true
		);

		if ( ! is_wp_error( $follower_result ) && isset( $follower_result['elements'][0] ) ) {
			$follower_data = $follower_result['elements'][0];
			$follower_ct   = isset( $follower_data['totalFollowerCount'] )
				? (int) $follower_data['totalFollowerCount']
				: 0;

			$normalized[] = array(
				'metric_name'  => 'followers',
				'metric_value' => $follower_ct,
				'platform'     => 'linkedin',
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
	 * @param string   $post_id LinkedIn share URN.
	 * @param string[] $metrics List of metric names.
	 * @return WP_MCP_AI_Analytics_Post_DTO|WP_Error
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_linkedin_not_configured',
				__( 'LinkedIn API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$share_urn = 0 === strpos( $post_id, 'urn:li:share:' ) ? $post_id : 'urn:li:share:' . $post_id;

		$result = $this->api_get(
			'organizationalEntityShareStatistics',
			array(
				'q'                    => 'organizationalEntity',
				'organizationalEntity' => $share_urn,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$stats = isset( $result['elements'][0]['totalShareStatistics'] )
			? $result['elements'][0]['totalShareStatistics']
			: array();

		$normalized_metrics = array(
			'impressions' => isset( $stats['impressionCount'] ) ? (int) $stats['impressionCount'] : 0,
			'clicks'      => isset( $stats['clickCount'] ) ? (int) $stats['clickCount'] : 0,
			'likes'       => isset( $stats['likeCount'] ) ? (int) $stats['likeCount'] : 0,
			'comments'    => isset( $stats['commentCount'] ) ? (int) $stats['commentCount'] : 0,
			'shares'      => isset( $stats['shareCount'] ) ? (int) $stats['shareCount'] : 0,
			'engagement'  => isset( $stats['engagement'] ) ? (int) $stats['engagement'] : 0,
		);

		return WP_MCP_AI_Analytics_Post_DTO::from_array(
			array(
				'platform'  => 'linkedin',
				'post_id'   => $post_id,
				'permalink' => 'https://www.linkedin.com/feed/update/' . $post_id,
				'metrics'   => $normalized_metrics,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  LinkedIn organization ID.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period.
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_linkedin_not_configured',
				__( 'LinkedIn API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'organizationFollowerStatistics',
			array(
				'q'            => 'organization',
				'organization' => $this->org_urn( $account_id ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$follower_ct = isset( $result['elements'][0]['totalFollowerCount'] )
			? (int) $result['elements'][0]['totalFollowerCount']
			: 0;

		return array(
			WP_MCP_AI_Analytics_Metric_DTO::from_array(
				array(
					'metric_name'  => 'followers',
					'metric_value' => $follower_ct,
					'platform'     => 'linkedin',
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
	 * @param string $account_id LinkedIn organization ID.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Maximum posts.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_linkedin_not_configured',
				__( 'LinkedIn API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'organizationalEntityShareStatistics',
			array(
				'q'                    => 'organizationalEntity',
				'organizationalEntity' => $this->org_urn( $account_id ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$posts = array();
		if ( isset( $result['elements'] ) && is_array( $result['elements'] ) ) {
			$count = 0;
			foreach ( $result['elements'] as $element ) {
				if ( $count >= $limit ) {
					break;
				}

				$stats = isset( $element['totalShareStatistics'] ) ? $element['totalShareStatistics'] : array();

				$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
					array(
						'platform'   => 'linkedin',
						'post_id'    => isset( $element['share'] ) ? $element['share'] : '',
						'account_id' => $account_id,
						'metrics'    => array(
							'impressions' => isset( $stats['impressionCount'] ) ? (int) $stats['impressionCount'] : 0,
							'clicks'      => isset( $stats['clickCount'] ) ? (int) $stats['clickCount'] : 0,
							'likes'       => isset( $stats['likeCount'] ) ? (int) $stats['likeCount'] : 0,
							'comments'    => isset( $stats['commentCount'] ) ? (int) $stats['commentCount'] : 0,
							'shares'      => isset( $stats['shareCount'] ) ? (int) $stats['shareCount'] : 0,
							'engagement'  => isset( $stats['engagement'] ) ? (int) $stats['engagement'] : 0,
						),
					)
				);
				++$count;
			}
		}

		return $posts;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int|null
	 */
	public function get_rate_limit_remaining() {
		$limiter = WP_MCP_AI_Analytics_Rate_Limiter::instance();
		return $limiter->get_remaining( 'linkedin' );
	}
}
