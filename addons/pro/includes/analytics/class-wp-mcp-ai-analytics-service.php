<?php
/**
 * Analytics Service — Shared facade for all pro-toolkit analytics.
 *
 * Singleton service that coordinates platform adapters, caching, rate limiting,
 * and metric normalization. All pro-toolkits consume analytics through this
 * single entry point.
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
 * Shared analytics service singleton.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Service {

	/**
	 * Singleton instance.
	 *
	 * @since 1.7.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered platform adapters.
	 *
	 * @since 1.7.0
	 * @var array<string,WP_MCP_AI_Analytics_Adapter>
	 */
	private $adapters = array();

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
	 * Register a platform adapter.
	 *
	 * @since 1.7.0
	 *
	 * @param string                      $platform Platform identifier.
	 * @param WP_MCP_AI_Analytics_Adapter $adapter  Adapter instance.
	 * @return void
	 */
	public function register_adapter( $platform, WP_MCP_AI_Analytics_Adapter $adapter ) {
		$this->adapters[ $platform ] = $adapter;
	}

	/**
	 * Get a registered adapter for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return WP_MCP_AI_Analytics_Adapter|null Adapter instance or null if not registered.
	 */
	public function get_adapter( $platform ) {
		return isset( $this->adapters[ $platform ] ) ? $this->adapters[ $platform ] : null;
	}

	/**
	 * Get all connected (configured) platforms.
	 *
	 * @since 1.7.0
	 * @return string[] Array of platform identifiers.
	 */
	public function get_connected_platforms() {
		$connected = array();
		foreach ( $this->adapters as $platform => $adapter ) {
			if ( $adapter->is_configured() ) {
				$connected[] = $platform;
			}
		}
		return $connected;
	}

	/**
	 * Get social media analytics across configured platforms.
	 *
	 * This is the primary entry point for the `get_social_analytics` tool.
	 *
	 * @since 1.7.0
	 *
	 * @param array $params Request parameters. See schema below.
	 * @return WP_MCP_AI_Analytics_Report_DTO|WP_Error
	 */
	public function get_social_analytics( array $params = array() ) {
		$platforms        = isset( $params['platforms'] ) && is_array( $params['platforms'] )
			? array_map( 'sanitize_text_field', $params['platforms'] )
			: $this->get_connected_platforms();
		$date_from        = isset( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to          = isset( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : gmdate( 'Y-m-d' );
		$group_by         = isset( $params['group_by'] ) ? sanitize_text_field( $params['group_by'] ) : 'day';
		$include_sections = isset( $params['include_sections'] ) && is_array( $params['include_sections'] )
			? array_map( 'sanitize_text_field', $params['include_sections'] )
			: array( 'summary', 'engagement', 'reach', 'growth', 'top_posts' );
		$top_posts_count  = isset( $params['top_posts_count'] ) ? absint( $params['top_posts_count'] ) : 10;
		$comparison       = ! empty( $params['comparison_period'] );

		if ( empty( $platforms ) ) {
			return new WP_Error(
				'no_platforms',
				__( 'No social media platforms are connected.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build report data incrementally.
		$report_data = array(
			'report_id'    => 'social_' . gmdate( 'YmdHis' ) . '_' . substr( md5( wp_json_encode( $params ) ), 0, 8 ),
			'report_type'  => 'social',
			'generated_at' => gmdate( 'c' ),
			'period'       => array(
				'from' => $date_from,
				'to'   => $date_to,
			),
			'accounts'     => array(),
			'summary'      => array(),
			'trends'       => array(),
			'top_posts'    => array(),
			'comparison'   => array(),
			'charts'       => array(),
		);

		// Aggregate across platforms.
		foreach ( $platforms as $platform ) {
			$adapter = $this->get_adapter( $platform );
			if ( ! $adapter || ! $adapter->is_configured() ) {
				continue;
			}

			$cache   = WP_MCP_AI_Analytics_Cache::instance();
			$limiter = WP_MCP_AI_Analytics_Rate_Limiter::instance();

			if ( $limiter->is_hard_blocked( $platform ) ) {
				continue;
			}

			$accounts = $this->get_platform_accounts( $platform );

			foreach ( $accounts as $account_id ) {
				$cache_params = array(
					'account_id' => $account_id,
					'from'       => $date_from,
					'to'         => $date_to,
					'group_by'   => $group_by,
				);

				$cached = $cache->get( $platform, 'account', $cache_params );
				if ( null !== $cached && is_array( $cached ) ) {
					$report_data['accounts'] = array_merge( $report_data['accounts'], $cached['accounts'] ?? array() );
					if ( in_array( 'engagement', $include_sections, true ) ) {
						$report_data['summary'] = $this->merge_summary( $report_data['summary'], $cached['summary'] ?? array() );
					}
					continue;
				}

				$limiter->consume( $platform );

				$native_metrics = array( 'impressions', 'reach', 'engagement', 'followers' );
				$insights       = $adapter->get_account_insights( $account_id, $native_metrics, $date_from, $date_to );

				if ( is_wp_error( $insights ) ) {
					continue;
				}

				$to_cache = array(
					'accounts' => array(
						WP_MCP_AI_Analytics_Account_DTO::from_array(
							array(
								'platform'   => $platform,
								'account_id' => $account_id,
							)
						),
					),
					'summary'  => $this->compute_summary_from_insights( $insights ),
				);

				$report_data['accounts'] = array_merge( $report_data['accounts'], $to_cache['accounts'] );
				if ( in_array( 'engagement', $include_sections, true ) ) {
					$report_data['summary'] = $this->merge_summary( $report_data['summary'], $to_cache['summary'] );
				}

				$cache->set( $platform, 'account', $cache_params, $to_cache );
			}
		}

		// Compute engagement rate if we have impressions and engagement data.
		if ( isset( $report_data['summary']['impressions'] ) && $report_data['summary']['impressions'] > 0 ) {
			$normalizer                                = WP_MCP_AI_Analytics_Metric_Normalizer::instance();
			$report_data['summary']['engagement_rate'] = $normalizer->compute_engagement_rate( $report_data['summary'] );
		}

		if ( $comparison ) {
			$report_data['comparison'] = $this->compute_comparison( $platforms, $date_from, $date_to );
		}

		$report_data['charts'] = $this->prepare_chart_data( $report_data );

		return WP_MCP_AI_Analytics_Report_DTO::from_array( $report_data );
	}

	/**
	 * Get ecommerce analytics.
	 *
	 * @since 1.7.0
	 *
	 * @param array $params Request parameters.
	 * @return WP_MCP_AI_Analytics_Report_DTO|WP_Error
	 */
	public function get_ecommerce_analytics( array $params = array() ) {
		return new WP_Error(
			'not_implemented',
			__( 'Ecommerce analytics service is not yet implemented.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get SEO analytics.
	 *
	 * @since 1.7.0
	 *
	 * @param array $params Request parameters.
	 * @return WP_MCP_AI_Analytics_Report_DTO|WP_Error
	 */
	public function get_seo_analytics( array $params = array() ) {
		return new WP_Error(
			'not_implemented',
			__( 'SEO analytics service is not yet implemented.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get Cloudways analytics.
	 *
	 * @since 1.7.0
	 *
	 * @param array $params Request parameters.
	 * @return WP_MCP_AI_Analytics_Report_DTO|WP_Error
	 */
	public function get_cloudways_analytics( array $params = array() ) {
		return new WP_Error(
			'not_implemented',
			__( 'Cloudways analytics service is not yet implemented.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get custom analytics for a specific toolkit.
	 *
	 * @since 1.7.0
	 *
	 * @param string $toolkit Toolkit identifier.
	 * @param array  $params  Request parameters.
	 * @return WP_MCP_AI_Analytics_Report_DTO|WP_Error
	 */
	public function get_custom_analytics( $toolkit, array $params = array() ) {
		return new WP_Error(
			'not_implemented',
			/* translators: %s: toolkit name */
			sprintf( __( 'Custom analytics for %s are not yet implemented.', 'mcp-ai-wpoos-pro' ), $toolkit )
		);
	}

	/**
	 * Invalidate cached analytics data.
	 *
	 * @since 1.7.0
	 *
	 * @param string      $platform   Platform identifier.
	 * @param string|null $account_id Optional account ID.
	 * @return void
	 */
	public function invalidate_cache( $platform, $account_id = null ) {
		WP_MCP_AI_Analytics_Cache::instance()->invalidate( $platform, $account_id );
	}

	/**
	 * Get configured account IDs for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return string[] Account IDs.
	 */
	private function get_platform_accounts( $platform ) {
		$settings = get_option( 'wp_mcp_ai_social_media_settings', array() );
		$key      = $platform . '_account_ids';

		if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		// Fallback: check single account ID.
		$single_key = $platform . '_account_id';
		if ( isset( $settings[ $single_key ] ) && ! empty( $settings[ $single_key ] ) ) {
			return array( $settings[ $single_key ] );
		}

		return array();
	}

	/**
	 * Compute summary metrics from normalized insights.
	 *
	 * @since 1.7.0
	 *
	 * @param array $insights Normalized metric entries.
	 * @return array<string,float>
	 */
	private function compute_summary_from_insights( array $insights ) {
		$summary = array();

		foreach ( $insights as $entry ) {
			$name  = isset( $entry['metric_name'] ) ? $entry['metric_name'] : '';
			$value = isset( $entry['metric_value'] ) ? (float) $entry['metric_value'] : 0;

			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $summary[ $name ] ) ) {
				$summary[ $name ] = 0;
			}
			$summary[ $name ] += $value;
		}

		return $summary;
	}

	/**
	 * Merge two summary arrays (additive merge).
	 *
	 * @since 1.7.0
	 *
	 * @param array $a First summary.
	 * @param array $b Second summary.
	 * @return array Merged summary.
	 */
	private function merge_summary( array $a, array $b ) {
		foreach ( $b as $key => $val ) {
			if ( ! isset( $a[ $key ] ) ) {
				$a[ $key ] = 0;
			}
			$a[ $key ] += (float) $val;
		}
		return $a;
	}

	/**
	 * Compute period-over-period comparison.
	 *
	 * @since 1.7.0
	 *
	 * @param string[] $platforms Platform identifiers.
	 * @param string   $from      Period start.
	 * @param string   $to        Period end.
	 * @return array Comparison data.
	 */
	private function compute_comparison( array $platforms, $from, $to ) {
		$from_ts = strtotime( $from );
		$to_ts   = strtotime( $to );
		$diff    = $to_ts - $from_ts;

		$prev_from = gmdate( 'Y-m-d', $from_ts - $diff );
		$prev_to   = gmdate( 'Y-m-d', $from_ts );

		return array(
			'previous_period' => array(
				'from' => $prev_from,
				'to'   => $prev_to,
			),
			'current_period'  => array(
				'from' => $from,
				'to'   => $to,
			),
		);
	}

	/**
	 * Prepare Chart.js compatible chart data.
	 *
	 * @since 1.7.0
	 *
	 * @param array $report_data Report data array.
	 * @return array Chart.js configuration.
	 */
	private function prepare_chart_data( array $report_data ) {
		return array(
			'summary' => array(
				'type' => 'bar',
				'data' => array(
					'labels'   => array_keys( $report_data['summary'] ?? array() ),
					'datasets' => array(
						array(
							'label' => __( 'Aggregated Metrics', 'mcp-ai-wpoos-pro' ),
							'data'  => array_values( $report_data['summary'] ?? array() ),
						),
					),
				),
			),
		);
	}
}
