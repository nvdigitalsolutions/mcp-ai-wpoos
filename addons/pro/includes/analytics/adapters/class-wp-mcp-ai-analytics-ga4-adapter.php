<?php
/**
 * Google Analytics Adapter — GA4 analytics via Data API v1.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for Google Analytics 4 properties.
 * Uses the Google Analytics Data API v1 with service account credentials
 * configured in the NV oOS settings.
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
 * Google Analytics 4 adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_GA4_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * GA4 Data API base URL.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta/';

	/**
	 * OAuth 2.0 token URI.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/**
	 * OAuth scope for analytics read-only.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

	/**
	 * Token cache prefix.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const TOKEN_CACHE_PREFIX = 'wp_mcp_ai_ga4_token_';

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
		return 'google_analytics';
	}

	/**
	 * Check if GA4 credentials are configured.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['google_analytics_property_id'] );
	}

	/**
	 * Get the configured property ID.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	private function get_property_id() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return isset( $settings['google_analytics_property_id'] ) ? $settings['google_analytics_property_id'] : null;
	}

	/**
	 * Get credentials from settings or filter.
	 *
	 * @since 1.7.0
	 * @return array|null
	 */
	private function get_credentials() {
		$settings    = get_option( 'wp_mcp_ai_settings', array() );
		$credentials = isset( $settings['google_analytics_credentials'] ) ? $settings['google_analytics_credentials'] : null;

		if ( is_string( $credentials ) ) {
			$credentials = json_decode( $credentials, true );
		}

		return is_array( $credentials ) ? $credentials : null;
	}

	/**
	 * Get an OAuth 2.0 access token for the service account.
	 *
	 * @since 1.7.0
	 * @return string|WP_Error
	 */
	private function get_access_token() {
		$credentials = $this->get_credentials();
		if ( ! $credentials ) {
			return new WP_Error(
				'wp_mcp_ai_ga4_no_credentials',
				__( 'Google Analytics service account credentials are not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$cache_key = self::TOKEN_CACHE_PREFIX . md5( wp_json_encode( $credentials ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_string( $cached ) ) {
			return $cached;
		}

		$now  = time();
		$jwt  = $this->build_jwt( $credentials, $now );
		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_ga4_token_error',
				__( 'Failed to obtain Google Analytics access token.', 'mcp-ai-wpoos-pro' )
			);
		}

		$token = $body['access_token'];
		$ttl   = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3500;
		set_transient( $cache_key, $token, max( $ttl - 60, 60 ) );

		return $token;
	}

	/**
	 * Build a JWT assertion for service account authentication.
	 *
	 * @since 1.7.0
	 *
	 * @param array $credentials Service account credentials.
	 * @param int   $now         Current Unix timestamp.
	 * @return string Signed JWT.
	 */
	private function build_jwt( $credentials, $now ) {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$payload = array(
			'iss'   => $credentials['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URI,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$segments   = array();
		$segments[] = $this->base64url_encode( wp_json_encode( $header ) );
		$segments[] = $this->base64url_encode( wp_json_encode( $payload ) );

		$signing_input = implode( '.', $segments );
		$private_key   = $credentials['private_key'];

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		openssl_sign( $signing_input, $signature, $private_key, 'SHA256' );
		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Base64url-encode data.
	 *
	 * @since 1.7.0
	 *
	 * @param string $data Raw data.
	 * @return string Encoded string.
	 */
	private function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Make a POST request to the GA4 Data API.
	 *
	 * @since 1.7.0
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     JSON request body.
	 * @return array|WP_Error
	 */
	private function api_post( $endpoint, array $body ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::BASE_URL . ltrim( $endpoint, '/' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown GA4 API error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'wp_mcp_ai_ga4_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Run a GA4 report with given metrics and date range.
	 *
	 * @since 1.7.0
	 *
	 * @param string[] $metrics   Metric names.
	 * @param string   $since     ISO 8601 start.
	 * @param string   $until     ISO 8601 end.
	 * @return array|WP_Error
	 */
	private function run_report( array $metrics, $since, $until ) {
		$property_id = $this->get_property_id();
		if ( ! $property_id ) {
			return new WP_Error(
				'wp_mcp_ai_ga4_no_property',
				__( 'Google Analytics property ID is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$metric_entries = array();
		foreach ( $metrics as $m ) {
			$metric_entries[] = array( 'name' => $m );
		}

		return $this->api_post(
			'properties/' . $property_id . ':runReport',
			array(
				'dateRanges' => array(
					array(
						'startDate' => $since,
						'endDate'   => $until,
					),
				),
				'metrics'    => $metric_entries,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id GA4 property ID.
	 * @param string[] $metrics    Metric names.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array|WP_Error
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_ga4_not_configured',
				__( 'Google Analytics is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$ga_metrics = array();
		$metric_map = array(
			'impressions'          => 'screenPageViews',
			'engagement'           => 'engagedSessions',
			'followers'            => 'activeUsers',
			'new_users'            => 'newUsers',
			'sessions'             => 'sessions',
			'bounce_rate'          => 'bounceRate',
			'avg_session_duration' => 'averageSessionDuration',
		);

		foreach ( $metrics as $m ) {
			if ( isset( $metric_map[ $m ] ) ) {
				$ga_metrics[] = $metric_map[ $m ];
			}
		}

		if ( empty( $ga_metrics ) ) {
			$ga_metrics = array( 'activeUsers', 'screenPageViews', 'sessions' );
		}

		$result = $this->run_report( $ga_metrics, $since, $until );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized  = array();
		$reverse_map = array_flip( $metric_map );

		if ( isset( $result['rows'][0]['metricValues'] ) ) {
			$values = $result['rows'][0]['metricValues'];
			$i      = 0;

			foreach ( $ga_metrics as $ga_m ) {
				$unified = isset( $reverse_map[ $ga_m ] ) ? $reverse_map[ $ga_m ] : $ga_m;
				$value   = isset( $values[ $i ]['value'] ) ? (float) $values[ $i ]['value'] : 0;

				$normalized[] = array(
					'metric_name'  => $unified,
					'metric_value' => $value,
					'platform'     => 'google_analytics',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => 'day',
				);
				$i++;
			}
		}

		return $normalized;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $post_id Not used for GA4.
	 * @param string[] $metrics Not used.
	 * @return WP_Error Always returns not-implemented.
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		return new WP_Error(
			'not_applicable',
			__( 'Post-level insights are not available for Google Analytics.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  GA4 property ID.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period.
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		$result = $this->get_account_insights( $account_id, array( 'followers' ), $since, $until );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$points = array();
		foreach ( $result as $entry ) {
			if ( 'followers' === $entry['metric_name'] ) {
				$points[] = WP_MCP_AI_Analytics_Metric_DTO::from_array( $entry );
			}
		}

		return $points;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id GA4 property ID.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Not used for GA4.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_ga4_not_configured',
				__( 'Google Analytics is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$property_id = $this->get_property_id();

		$result = $this->api_post(
			'properties/' . $property_id . ':runReport',
			array(
				'dateRanges' => array(
					array(
						'startDate' => $since,
						'endDate'   => $until,
					),
				),
				'dimensions' => array(
					array( 'name' => 'pagePath' ),
					array( 'name' => 'pageTitle' ),
				),
				'metrics'    => array(
					array( 'name' => 'screenPageViews' ),
				),
				'limit'      => $limit,
				'orderBys'   => array(
					array(
						'metric' => array( 'metricName' => 'screenPageViews' ),
						'desc'   => true,
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$posts = array();
		if ( isset( $result['rows'] ) && is_array( $result['rows'] ) ) {
			foreach ( $result['rows'] as $row ) {
				$path  = isset( $row['dimensionValues'][0]['value'] ) ? $row['dimensionValues'][0]['value'] : '';
				$title = isset( $row['dimensionValues'][1]['value'] ) ? $row['dimensionValues'][1]['value'] : '';
				$views = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;

				$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
					array(
						'platform'     => 'google_analytics',
						'post_id'      => md5( $path ),
						'account_id'   => $account_id,
						'content_type' => 'page',
						'permalink'    => $path,
						'caption'      => $title,
						'metrics'      => array(
							'impressions' => $views,
						),
					)
				);
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
		return $limiter->get_remaining( 'google_analytics' );
	}
}
