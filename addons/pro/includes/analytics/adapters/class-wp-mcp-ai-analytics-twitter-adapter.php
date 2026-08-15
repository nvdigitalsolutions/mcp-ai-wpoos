<?php
/**
 * Twitter Adapter — Twitter/X analytics via Twitter API v2.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for Twitter/X. Uses Twitter API v2
 * endpoints with OAuth 2.0 Bearer Token or OAuth 1.0a authentication.
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
 * Twitter/X analytics adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Twitter_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * Twitter API v2 base URL.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const BASE_URL = 'https://api.twitter.com/2/';

	/**
	 * Default request timeout in seconds.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Get the platform slug.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return 'twitter';
	}

	/**
	 * Check if Twitter credentials are configured.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return ! empty( $settings['twitter_bearer_token'] ) || ! empty( $settings['twitter_api_key'] );
	}

	/**
	 * Get the configured bearer token.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	private function get_bearer_token() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return isset( $settings['twitter_bearer_token'] ) ? $settings['twitter_bearer_token'] : null;
	}

	/**
	 * Make a GET request to the Twitter API.
	 *
	 * @since 1.7.0
	 *
	 * @param string $endpoint API endpoint path (e.g. 'users/{id}/tweets').
	 * @param array  $params   Query parameters.
	 * @return array|WP_Error Decoded JSON response or error.
	 */
	private function api_get( $endpoint, array $params = array() ) {
		$token = $this->get_bearer_token();
		if ( ! $token ) {
			return new WP_Error(
				'wp_mcp_ai_twitter_not_configured',
				__( 'Twitter API credentials are not configured.', 'mcp-ai-wpoos-pro' )
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
			$error_message = isset( $data['detail'] )
				? $data['detail']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Twitter API returned status %d.', 'mcp-ai-wpoos-pro' ),
					$code
				);
			return new WP_Error(
				'wp_mcp_ai_twitter_api_error',
				$error_message,
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id Twitter user ID.
	 * @param string[] $metrics    List of metric names (native Twitter names).
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array|WP_Error
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_twitter_not_configured',
				__( 'Twitter API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'users/' . $account_id . '/tweets',
			array(
				'tweet.fields' => 'public_metrics,created_at',
				'max_results'  => 100,
				'start_time'   => gmdate( 'c', strtotime( $since ) ),
				'end_time'     => gmdate( 'c', strtotime( $until ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized = array();
		$normalizer = WP_MCP_AI_Analytics_Metric_Normalizer::instance();

		$aggregated = array();

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $tweet ) {
				if ( ! isset( $tweet['public_metrics'] ) ) {
					continue;
				}

				$created_at = isset( $tweet['created_at'] ) ? $tweet['created_at'] : '';

				foreach ( $tweet['public_metrics'] as $native_name => $value ) {
					$unified = $normalizer->normalize( 'twitter', $native_name );
					if ( null === $unified ) {
						continue;
					}

					if ( ! isset( $aggregated[ $unified ] ) ) {
						$aggregated[ $unified ] = array(
							'metric_name'  => $unified,
							'metric_value' => 0,
							'platform'     => 'twitter',
							'account_id'   => $account_id,
							'period_start' => $created_at ? gmdate( 'Y-m-d\TH:i:s', strtotime( $created_at ) ) : $since,
							'period_end'   => $created_at ? gmdate( 'Y-m-d\TH:i:s', strtotime( $created_at ) ) : $until,
							'granularity'  => 'day',
						);
					}
					$aggregated[ $unified ]['metric_value'] += (float) $value;
				}
			}
		}

		return array_values( $aggregated );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $post_id Tweet ID.
	 * @param string[] $metrics List of metric names.
	 * @return WP_MCP_AI_Analytics_Post_DTO|WP_Error
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_twitter_not_configured',
				__( 'Twitter API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'tweets/' . $post_id,
			array(
				'tweet.fields' => 'public_metrics,created_at,text',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data          = isset( $result['data'] ) ? $result['data'] : array();
		$normalizer    = WP_MCP_AI_Analytics_Metric_Normalizer::instance();
		$raw_metrics   = isset( $data['public_metrics'] ) ? $data['public_metrics'] : array();
		$norm_metrics  = $normalizer->normalize_payload( 'twitter', $raw_metrics );

		return WP_MCP_AI_Analytics_Post_DTO::from_array(
			array(
				'platform'     => 'twitter',
				'post_id'      => $post_id,
				'content_type' => 'text',
				'permalink'    => 'https://twitter.com/i/status/' . $post_id,
				'posted_at'    => isset( $data['created_at'] )
					? gmdate( 'c', strtotime( $data['created_at'] ) )
					: '',
				'caption'      => isset( $data['text'] ) ? $data['text'] : null,
				'metrics'      => $norm_metrics,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  Twitter user ID.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period.
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		$result = $this->api_get(
			'users/' . $account_id,
			array(
				'user.fields' => 'public_metrics',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data        = isset( $result['data'] ) ? $result['data'] : array();
		$public      = isset( $data['public_metrics'] ) ? $data['public_metrics'] : array();
		$follower_ct = isset( $public['followers_count'] ) ? (int) $public['followers_count'] : 0;

		return array(
			WP_MCP_AI_Analytics_Metric_DTO::from_array(
				array(
					'metric_name'  => 'followers',
					'metric_value' => $follower_ct,
					'platform'     => 'twitter',
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
	 * @param string $account_id Twitter user ID.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Maximum tweets to return.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		$result = $this->api_get(
			'users/' . $account_id . '/tweets',
			array(
				'tweet.fields' => 'public_metrics,created_at,text',
				'max_results'  => min( $limit, 100 ),
				'start_time'   => gmdate( 'c', strtotime( $since ) ),
				'end_time'     => gmdate( 'c', strtotime( $until ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$posts     = array();
		$normalizer = WP_MCP_AI_Analytics_Metric_Normalizer::instance();

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $tweet ) {
				$raw_metrics  = isset( $tweet['public_metrics'] ) ? $tweet['public_metrics'] : array();
				$norm_metrics = $normalizer->normalize_payload( 'twitter', $raw_metrics );

				$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
					array(
						'platform'     => 'twitter',
						'post_id'      => isset( $tweet['id'] ) ? $tweet['id'] : '',
						'account_id'   => $account_id,
						'content_type' => 'text',
						'permalink'    => isset( $tweet['id'] ) ? 'https://twitter.com/i/status/' . $tweet['id'] : '',
						'posted_at'    => isset( $tweet['created_at'] )
							? gmdate( 'c', strtotime( $tweet['created_at'] ) )
							: '',
						'caption'      => isset( $tweet['text'] ) ? $tweet['text'] : null,
						'metrics'      => $norm_metrics,
					)
				);
			}

			// Sort by engagement (impressions) descending.
			usort(
				$posts,
				function ( $a, $b ) {
					return $b->get_metric( 'impressions' ) - $a->get_metric( 'impressions' );
				}
			);

			$posts = array_slice( $posts, 0, $limit );
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
		return $limiter->get_remaining( 'twitter' );
	}
}
