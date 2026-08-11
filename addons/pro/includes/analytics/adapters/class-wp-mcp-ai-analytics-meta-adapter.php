<?php
/**
 * Meta Adapter — Facebook & Instagram analytics via Meta Graph API.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for Meta platforms (Facebook Pages
 * and Instagram Business/Creator accounts). Uses Meta Graph API v22.0.
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
 * Meta (Facebook & Instagram) analytics adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Meta_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * Graph API base URL.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const BASE_URL = 'https://graph.facebook.com/';

	/**
	 * Graph API version.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const API_VERSION = 'v22.0';

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
		return 'meta';
	}

	/**
	 * Check if Meta credentials are configured.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return ! empty( $settings['facebook_access_token'] );
	}

	/**
	 * Get the configured access token.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	private function get_access_token() {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		return isset( $settings['facebook_access_token'] ) ? $settings['facebook_access_token'] : null;
	}

	/**
	 * Build a Graph API endpoint URL.
	 *
	 * @since 1.7.0
	 *
	 * @param string $edge   API edge path (e.g. '/{page-id}/insights').
	 * @param array  $params Query parameters.
	 * @return string Full API URL.
	 */
	private function build_url( $edge, array $params = array() ) {
		$url                    = self::BASE_URL . self::API_VERSION . $edge;
		$params['access_token'] = $this->get_access_token();
		return add_query_arg( $params, $url );
	}

	/**
	 * Make a GET request to the Graph API.
	 *
	 * @since 1.7.0
	 *
	 * @param string $edge   API edge path.
	 * @param array  $params Query parameters.
	 * @return array|WP_Error Decoded JSON response or error.
	 */
	private function api_get( $edge, array $params = array() ) {
		$url      = $this->build_url( $edge, $params );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Accept' => 'application/json',
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
					__( 'Meta Graph API returned status %d.', 'mcp-ai-wpoos-pro' ),
					$code
				);
			return new WP_Error(
				'wp_mcp_ai_meta_api_error',
				$error_message,
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * Normalize metric names using the shared normalizer.
	 *
	 * @since 1.7.0
	 *
	 * @param string $native_name Native metric name.
	 * @return string|null Unified name.
	 */
	private function normalize_metric( $native_name ) {
		$normalizer = WP_MCP_AI_Analytics_Metric_Normalizer::instance();
		return $normalizer->normalize( 'meta', $native_name );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id Platform-native account ID.
	 * @param string[] $metrics    List of metric names.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_meta_not_configured',
				__( 'Meta (Facebook/Instagram) API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$metric_string = implode( ',', $metrics );

		$since_ts = strtotime( $since );
		$until_ts = strtotime( $until );

		if ( false === $since_ts || false === $until_ts ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'Invalid date format for since/until parameters.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->api_get(
			'/' . $account_id . '/insights',
			array(
				'metric' => $metric_string,
				'period' => 'day',
				'since'  => $since_ts,
				'until'  => $until_ts,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalizer = WP_MCP_AI_Analytics_Metric_Normalizer::instance();

		$normalized = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $metric_entry ) {
				$name    = isset( $metric_entry['name'] ) ? $metric_entry['name'] : '';
				$unified = $this->normalize_metric( $name );

				if ( null === $unified ) {
					continue;
				}

				$values = isset( $metric_entry['values'] ) ? $metric_entry['values'] : array();
				foreach ( $values as $value_entry ) {
					$normalized[] = array(
						'metric_name'  => $unified,
						'metric_value' => isset( $value_entry['value'] ) ? (float) $value_entry['value'] : 0,
						'period_start' => isset( $value_entry['end_time'] )
							? gmdate( 'Y-m-d\TH:i:s', strtotime( $value_entry['end_time'] . ' -1 day' ) )
							: $since,
						'period_end'   => isset( $value_entry['end_time'] )
							? gmdate( 'Y-m-d\TH:i:s', strtotime( $value_entry['end_time'] ) )
							: $until,
					);
				}
			}
		}

		return $normalized;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $post_id Platform-native post ID.
	 * @param string[] $metrics List of metric names.
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_meta_not_configured',
				__( 'Meta API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$metric_string = implode( ',', $metrics );

		$result = $this->api_get(
			'/' . $post_id . '/insights',
			array(
				'metric' => $metric_string,
				'period' => 'lifetime',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized_metrics = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $metric_entry ) {
				$name    = isset( $metric_entry['name'] ) ? $metric_entry['name'] : '';
				$unified = $this->normalize_metric( $name );
				if ( null === $unified ) {
					continue;
				}
				$normalized_metrics[ $unified ] = isset( $metric_entry['values'][0]['value'] )
					? (int) $metric_entry['values'][0]['value']
					: 0;
			}
		}

		return WP_MCP_AI_Analytics_Post_DTO::from_array(
			array(
				'platform' => 'meta',
				'post_id'  => $post_id,
				'metrics'  => $normalized_metrics,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  Platform-native account ID.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period.
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		$result = $this->get_account_insights(
			$account_id,
			array( 'page_fans', 'follower_count' ),
			$since,
			$until
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$points = array();
		foreach ( $result as $entry ) {
			if ( 'followers' === $entry['metric_name'] ) {
				$points[] = WP_MCP_AI_Analytics_Metric_DTO::from_array(
					array(
						'metric_name'  => 'followers',
						'metric_value' => $entry['metric_value'],
						'platform'     => 'meta',
						'account_id'   => $account_id,
						'period_start' => $entry['period_start'],
						'period_end'   => $entry['period_end'],
						'granularity'  => $granularity,
					)
				);
			}
		}

		return $points;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id Platform-native account ID.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Maximum posts to return.
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_meta_not_configured',
				__( 'Meta API credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$since_ts = strtotime( $since );
		$until_ts = strtotime( $until );

		$result = $this->api_get(
			'/' . $account_id . '/media',
			array(
				'fields' => 'id,caption,media_type,permalink,timestamp,like_count,comments_count,insights.metric(impressions,reach,engagement)',
				'since'  => $since_ts,
				'until'  => $until_ts,
				'limit'  => $limit,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$posts = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $media ) {
				$metrics = array();
				if ( isset( $media['insights']['data'] ) ) {
					foreach ( $media['insights']['data'] as $insight ) {
						$unified = $this->normalize_metric( $insight['name'] );
						if ( null !== $unified ) {
							$metrics[ $unified ] = isset( $insight['values'][0]['value'] )
								? (int) $insight['values'][0]['value']
								: 0;
						}
					}
				}

				$metrics['likes']    = isset( $media['like_count'] ) ? (int) $media['like_count'] : 0;
				$metrics['comments'] = isset( $media['comments_count'] ) ? (int) $media['comments_count'] : 0;

				$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
					array(
						'platform'     => 'meta',
						'post_id'      => isset( $media['id'] ) ? $media['id'] : '',
						'account_id'   => $account_id,
						'content_type' => isset( $media['media_type'] ) ? strtolower( $media['media_type'] ) : 'unknown',
						'permalink'    => isset( $media['permalink'] ) ? $media['permalink'] : '',
						'posted_at'    => isset( $media['timestamp'] )
							? gmdate( 'c', strtotime( $media['timestamp'] ) )
							: '',
						'caption'      => isset( $media['caption'] ) ? $media['caption'] : null,
						'metrics'      => $metrics,
					)
				);
			}
		}

		return $posts;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_rate_limit_remaining() {
		$limiter = WP_MCP_AI_Analytics_Rate_Limiter::instance();
		return $limiter->get_remaining( 'meta' );
	}
}
