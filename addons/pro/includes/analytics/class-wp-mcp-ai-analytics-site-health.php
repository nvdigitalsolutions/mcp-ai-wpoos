<?php
/**
 * Analytics Site Health Integration
 *
 * Registers WordPress Site Health tests and debug information for the
 * Shared Analytics Service. Monitors adapter status, cache hit rates,
 * and rate limit consumption across all configured platforms.
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
 * Analytics Site Health integration.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Site_Health {

	/**
	 * Initialize site health integration.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function register_hooks() {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		add_filter( 'debug_information', array( $this, 'register_debug_info' ) );
	}

	/**
	 * Register analytics site health tests.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public function register_tests( $tests ) {
		$tests['direct']['wp_mcp_ai_analytics_adapters'] = array(
			'label' => __( 'NV oOS Analytics Adapters', 'mcp-ai-wpoos-pro' ),
			'test'  => array( $this, 'test_adapters' ),
		);

		$tests['direct']['wp_mcp_ai_analytics_cache'] = array(
			'label' => __( 'NV oOS Analytics Cache Health', 'mcp-ai-wpoos-pro' ),
			'test'  => array( $this, 'test_cache_health' ),
		);

		$tests['direct']['wp_mcp_ai_analytics_rate_limits'] = array(
			'label' => __( 'NV oOS Analytics Rate Limits', 'mcp-ai-wpoos-pro' ),
			'test'  => array( $this, 'test_rate_limits' ),
		);

		return $tests;
	}

	/**
	 * Register analytics debug information.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $debug_info Existing debug info.
	 * @return array<string,mixed>
	 */
	public function register_debug_info( $debug_info ) {
		$debug_info['mcp-ai-analytics'] = array(
			'label'  => __( 'NV oOS — Shared Analytics Service', 'mcp-ai-wpoos-pro' ),
			'fields' => $this->get_debug_fields(),
		);

		return $debug_info;
	}

	/**
	 * Test: Verify analytics adapters are loaded and configured.
	 *
	 * @since 1.7.0
	 * @return array{label:string,status:string,badge:array,description:string,actions:string}
	 */
	public function test_adapters() {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			return array(
				'label'       => __( 'Analytics service not loaded', 'mcp-ai-wpoos-pro' ),
				'status'      => 'critical',
				'badge'       => array(
					'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
					'color' => 'red',
				),
				'description' => '<p>' . esc_html__( 'The Shared Analytics Service is not loaded. Ensure the Pro addon is active and up to date.', 'mcp-ai-wpoos-pro' ) . '</p>',
				'actions'     => '',
				'test'        => 'wp_mcp_ai_analytics_adapters',
			);
		}

		$service  = WP_MCP_AI_Analytics_Service::instance();
		$adapters = $service->get_connected_platforms();

		$total_adapters   = 7;
		$active_adapters  = count( $adapters );
		$configured_names = array_map(
			function ( $p ) {
				return ucfirst( $p );
			},
			$adapters
		);

		if ( 0 === $active_adapters ) {
			return array(
				'label'       => __( 'No analytics adapters configured', 'mcp-ai-wpoos-pro' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
					'color' => 'orange',
				),
				'description' => '<p>' . esc_html__( 'No analytics platform adapters are configured with API credentials. Configure credentials in the NV oOS settings to enable cross-platform analytics.', 'mcp-ai-wpoos-pro' ) . '</p>',
				'actions'     => '',
				'test'        => 'wp_mcp_ai_analytics_adapters',
			);
		}

		return array(
			'label'       => sprintf(
				/* translators: 1: active count, 2: total count */
				__( '%1$d of %2$d analytics adapters active', 'mcp-ai-wpoos-pro' ),
				$active_adapters,
				$total_adapters
			),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
				'color' => 'green',
			),
			'description' => '<p>' . sprintf(
				/* translators: %s: comma-separated adapter names */
				esc_html__( 'Active adapters: %s.', 'mcp-ai-wpoos-pro' ),
				implode( ', ', $configured_names )
			) . '</p>',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_analytics_adapters',
		);
	}

	/**
	 * Test: Check analytics cache health.
	 *
	 * @since 1.7.0
	 * @return array{label:string,status:string,badge:array,description:string,actions:string}
	 */
	public function test_cache_health() {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Cache' ) ) {
			return array(
				'label'       => __( 'Analytics cache not available', 'mcp-ai-wpoos-pro' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
					'color' => 'orange',
				),
				'description' => '<p>' . esc_html__( 'Analytics cache service is not loaded.', 'mcp-ai-wpoos-pro' ) . '</p>',
				'actions'     => '',
				'test'        => 'wp_mcp_ai_analytics_cache',
			);
		}

		$cache    = WP_MCP_AI_Analytics_Cache::instance();
		$stats    = $cache->get_stats();
		$hit_rate = isset( $stats['hit_rate'] ) ? $stats['hit_rate'] : 0;

		if ( $hit_rate > 70 ) {
			$status = 'good';
			$color  = 'green';
		} elseif ( $hit_rate > 30 ) {
			$status = 'recommended';
			$color  = 'orange';
		} else {
			$status = 'recommended';
			$color  = 'orange';
		}

		$description = sprintf(
			/* translators: 1: hit rate, 2: hits, 3: misses, 4: cache sets */
			'<p>' . esc_html__( 'Cache hit rate: %1$s%%. %2$d hits, %3$d misses, %4$d sets.', 'mcp-ai-wpoos-pro' ) . '</p>',
			$hit_rate,
			isset( $stats['hits'] ) ? $stats['hits'] : 0,
			isset( $stats['misses'] ) ? $stats['misses'] : 0,
			isset( $stats['sets'] ) ? $stats['sets'] : 0
		);

		return array(
			'label'       => sprintf(
				/* translators: %s: cache hit rate percentage */
				__( 'Analytics cache hit rate: %s%%', 'mcp-ai-wpoos-pro' ),
				$hit_rate
			),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
				'color' => $color,
			),
			'description' => $description,
			'actions'     => '',
			'test'        => 'wp_mcp_ai_analytics_cache',
		);
	}

	/**
	 * Test: Check rate limit consumption.
	 *
	 * @since 1.7.0
	 * @return array{label:string,status:string,badge:array,description:string,actions:string}
	 */
	public function test_rate_limits() {
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Rate_Limiter' ) ) {
			return array(
				'label'       => __( 'Rate limiter not available', 'mcp-ai-wpoos-pro' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
					'color' => 'orange',
				),
				'description' => '<p>' . esc_html__( 'Analytics rate limiter is not loaded.', 'mcp-ai-wpoos-pro' ) . '</p>',
				'actions'     => '',
				'test'        => 'wp_mcp_ai_analytics_rate_limits',
			);
		}

		$limiter     = WP_MCP_AI_Analytics_Rate_Limiter::instance();
		$platforms   = array( 'meta', 'twitter', 'linkedin', 'tiktok' );
		$any_blocked = false;
		$any_warning = false;
		$lines       = array();

		foreach ( $platforms as $p ) {
			$pct = $limiter->get_usage_pct( $p );
			if ( $limiter->is_hard_blocked( $p ) ) {
				$any_blocked = true;
				$lines[]     = sprintf(
					/* translators: 1: platform name, 2: usage percentage */
					esc_html__( '%1$s: %2$s%% (BLOCKED)', 'mcp-ai-wpoos-pro' ),
					ucfirst( $p ),
					$pct
				);
			} elseif ( $limiter->is_soft_warning( $p ) ) {
				$any_warning = true;
				$lines[]     = sprintf(
					/* translators: 1: platform name, 2: usage percentage */
					esc_html__( '%1$s: %2$s%% (warning)', 'mcp-ai-wpoos-pro' ),
					ucfirst( $p ),
					$pct
				);
			} else {
				$lines[] = sprintf(
					/* translators: 1: platform name, 2: usage percentage */
					esc_html__( '%1$s: %2$s%%', 'mcp-ai-wpoos-pro' ),
					ucfirst( $p ),
					$pct
				);
			}
		}

		if ( $any_blocked ) {
			$status = 'critical';
			$color  = 'red';
		} elseif ( $any_warning ) {
			$status = 'recommended';
			$color  = 'orange';
		} else {
			$status = 'good';
			$color  = 'green';
		}

		return array(
			'label'       => __( 'Analytics API rate limits', 'mcp-ai-wpoos-pro' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
				'color' => $color,
			),
			'description' => '<p>' . implode( '<br>', $lines ) . '</p>',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_analytics_rate_limits',
		);
	}

	/**
	 * Get debug information fields for the Site Health Info tab.
	 *
	 * @since 1.7.0
	 * @return array<string,array{label:string,value:string}>
	 */
	private function get_debug_fields() {
		$fields = array(
			'service_loaded' => array(
				'label' => __( 'Service loaded', 'mcp-ai-wpoos-pro' ),
				'value' => class_exists( 'WP_MCP_AI_Analytics_Service' ) ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ),
			),
			'adapters_registered' => array(
				'label' => __( 'Adapters registered', 'mcp-ai-wpoos-pro' ),
				'value' => '—',
			),
			'cache_hit_rate' => array(
				'label' => __( 'Cache hit rate', 'mcp-ai-wpoos-pro' ),
				'value' => '—',
			),
		);

		if ( class_exists( 'WP_MCP_AI_Analytics_Service' ) ) {
			$service  = WP_MCP_AI_Analytics_Service::instance();
			$adapters = $service->get_connected_platforms();
			$fields['adapters_registered']['value'] = ! empty( $adapters ) ? implode( ', ', $adapters ) : __( 'None', 'mcp-ai-wpoos-pro' );
		}

		if ( class_exists( 'WP_MCP_AI_Analytics_Cache' ) ) {
			$cache = WP_MCP_AI_Analytics_Cache::instance();
			$stats = $cache->get_stats();
			$fields['cache_hit_rate']['value'] = sprintf(
				'%s%% (%d hits, %d misses)',
				isset( $stats['hit_rate'] ) ? $stats['hit_rate'] : 0,
				isset( $stats['hits'] ) ? $stats['hits'] : 0,
				isset( $stats['misses'] ) ? $stats['misses'] : 0
			);
		}

		return $fields;
	}
}
