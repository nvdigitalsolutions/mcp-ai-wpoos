<?php
/**
 * Cloudways Adapter — Server & app analytics via Cloudways API.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for Cloudways managed hosting.
 * Uses the Cloudways API Client singleton already established in the Pro
 * Cloudways toolkit for server and application metrics.
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
 * Cloudways analytics adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Cloudways_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * Get the platform slug.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return 'cloudways';
	}

	/**
	 * Check if Cloudways credentials are configured.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			return false;
		}
		$client = WP_MCP_AI_Cloudways_Client::instance();
		return $client->is_configured();
	}

	/**
	 * Get the Cloudways API client.
	 *
	 * @since 1.7.0
	 * @return WP_MCP_AI_Cloudways_Client|null
	 */
	private function get_client() {
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			return null;
		}
		return WP_MCP_AI_Cloudways_Client::instance();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id Server or app identifier.
	 * @param string[] $metrics    Metric names.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array|WP_Error
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		$client = $this->get_client();
		if ( ! $client || ! $client->is_authenticated() ) {
			return new WP_Error(
				'wp_mcp_ai_cloudways_not_configured',
				__( 'Cloudways API is not configured or authenticated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$normalized = array();

		// Try to fetch server monitoring data.
		try {
			$servers = $client->get_servers();
			if ( is_array( $servers ) ) {
				$server_count  = count( $servers );
				$app_count     = 0;
				$total_traffic = 0;

				foreach ( $servers as $server ) {
					if ( isset( $server['apps'] ) && is_array( $server['apps'] ) ) {
						$app_count += count( $server['apps'] );
					}
				}

				$normalized[] = array(
					'metric_name'  => 'servers',
					'metric_value' => $server_count,
					'platform'     => 'cloudways',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => 'day',
				);

				$normalized[] = array(
					'metric_name'  => 'applications',
					'metric_value' => $app_count,
					'platform'     => 'cloudways',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => 'day',
				);
			}
		} catch ( \Exception $e ) {
			// Graceful degradation — Cloudways client errors are logged internally.
			unset( $e );
		}

		if ( empty( $normalized ) ) {
			return new WP_Error(
				'wp_mcp_ai_cloudways_no_data',
				__( 'Unable to retrieve Cloudways monitoring data.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $normalized;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $post_id Not used for Cloudways.
	 * @param string[] $metrics Not used.
	 * @return WP_Error
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		return new WP_Error(
			'not_applicable',
			__( 'Post-level insights are not available for Cloudways.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  Server ID.
	 * @param string $since       Not used for Cloudways.
	 * @param string $until       Not used for Cloudways.
	 * @param string $granularity Not used.
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		$result = $this->get_account_insights( $account_id, array(), $since, $until );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$points = array();
		foreach ( $result as $entry ) {
			$points[] = WP_MCP_AI_Analytics_Metric_DTO::from_array( $entry );
		}

		return $points;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id Server ID.
	 * @param string $since      Not used.
	 * @param string $until      Not used.
	 * @param int    $limit      Not used.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		$client = $this->get_client();
		if ( ! $client || ! $client->is_authenticated() ) {
			return new WP_Error(
				'wp_mcp_ai_cloudways_not_configured',
				__( 'Cloudways API is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$posts = array();

		try {
			$servers = $client->get_servers();
			if ( is_array( $servers ) ) {
				foreach ( $servers as $server ) {
					$server_id   = isset( $server['id'] ) ? (string) $server['id'] : '';
					$server_name = isset( $server['label'] ) ? $server['label'] : '';

					if ( ! isset( $server['apps'] ) || ! is_array( $server['apps'] ) ) {
						continue;
					}

					foreach ( $server['apps'] as $app ) {
						$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
							array(
								'platform'     => 'cloudways',
								'post_id'      => isset( $app['id'] ) ? (string) $app['id'] : '',
								'account_id'   => $account_id,
								'content_type' => 'application',
								'caption'      => isset( $app['label'] ) ? $app['label'] : $server_name,
								'metrics'      => array(
									'app_count' => 1,
								),
								'extra'        => array(
									'server_id'   => $server_id,
									'server_name' => $server_name,
								),
							)
						);

						if ( count( $posts ) >= $limit ) {
							break 2;
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_cloudways_error',
				$e->getMessage()
			);
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
		return $limiter->get_remaining( 'cloudways' );
	}
}
