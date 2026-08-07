<?php
/**
 * Service Status Registry
 *
 * Collects registered service status sources, runs health checks, and
 * stores aggregated status data. Provides public and admin-facing status
 * snapshots for the REST API and shortcode.
 *
 * Health check results are stored in the `wp_mcp_ai_service_status` option
 * (non-autoload) and refreshed via cron every 5 minutes.
 *
 * @package   WP_MCP_AI
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Status Registry class.
 *
 * Singleton that manages the lifecycle of service health checks and
 * provides status snapshots to consumers (REST, shortcode, admin).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Service_Status_Registry {

	/**
	 * Option key for the cached status snapshot.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_service_status';

	/**
	 * Option key for 30-day uptime history.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const HISTORY_KEY = 'wp_mcp_ai_service_uptime_history';

	/**
	 * Option key for the last health check timestamp.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const LAST_CHECK_KEY = 'wp_mcp_ai_last_health_check';

	/**
	 * Maximum age of cached status before a refresh is triggered (seconds).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_CACHE_AGE = 900;

	/**
	 * Transient key for status cache freshness flag.
	 *
	 * Set after each cron-driven health check; checked by get_status()
	 * to avoid triggering a full check on AJAX reads. TTL matches
	 * MAX_CACHE_AGE so the flag expires alongside the cache.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const CACHE_FRESH_KEY = 'wp_mcp_ai_status_cache_fresh';

	/**
	 * Singleton instance.
	 *
	 * @since 1.2.0
	 * @var WP_MCP_AI_Service_Status_Registry|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.2.0
	 *
	 * @return WP_MCP_AI_Service_Status_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor (private — singleton).
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		// Cron callbacks are now dispatched from the consolidated
		// wp_mcp_ai_five_minute_tick handler (includes/bootstrap/cron.php)
		// to reduce per-cycle PHP processes and MySQL connections.
		// Hourly/daily jobs remain on their own hooks.
		add_action( 'wp_mcp_ai_uptime_rollup_cron', array( $this, 'rollup_uptime_history' ) );
		add_action( 'wp_mcp_ai_status_history_cleanup', array( $this, 'cleanup_history' ) );
	}

	/**
	 * Collect all registered service status sources.
	 *
	 * Fires the `wp_mcp_ai_service_status_sources` filter and returns the
	 * filtered map. Non-conforming entries are dropped defensively.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, Interface_WP_MCP_AI_Service_Status_Source>
	 */
	public function get_sources() {
		/**
		 * Filter: register service status sources.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, Interface_WP_MCP_AI_Service_Status_Source> $sources Map of slug => source.
		 */
		$sources = apply_filters( 'wp_mcp_ai_service_status_sources', array() );

		if ( ! is_array( $sources ) ) {
			return array();
		}

		$valid = array();
		foreach ( $sources as $key => $source ) {
			if ( ! is_object( $source ) || ! ( $source instanceof Interface_WP_MCP_AI_Service_Status_Source ) ) {
				continue;
			}

			$slug = is_string( $key ) && '' !== $key ? $key : $source->get_slug();
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}

			$valid[ $slug ] = $source;
		}

		return $valid;
	}

	/**
	 * Run health checks for all registered sources.
	 *
	 * Each source is called in a try/catch so one misbehaving source cannot
	 * break the entire check cycle. Results are stored in the status option
	 * and the last-check timestamp is updated.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array> Map of slug => health check result.
	 */
	public function run_health_checks() {
		$sources = $this->get_sources();
		$results = array();

		foreach ( $sources as $slug => $source ) {
			try {
				$results[ $slug ] = $source->check_health();
			} catch ( Exception $e ) {
				$results[ $slug ] = array(
					'status'     => 'major_outage',
					'message'    => sprintf(
						/* translators: %s: error message from failed health check */
						__( 'Health check failed: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					),
					'checked_at' => time(),
					'latency_ms' => null,
				);

				if ( function_exists( 'wp_mcp_ai_log_error' ) ) {
					wp_mcp_ai_log_error(
						sprintf( 'Service status health check failed for "%s"', $slug ),
						array(
							'exception' => $e->getMessage(),
							'slug'      => $slug,
						)
					);
				}
			}
		}

		update_option( self::OPTION_KEY, $results, false );
		update_option( self::LAST_CHECK_KEY, time(), false );
		set_transient( self::CACHE_FRESH_KEY, 1, self::MAX_CACHE_AGE );

		/**
		 * Fires after all health checks have completed.
		 *
		 * @since 1.2.0
		 *
		 * @param array $results Map of slug => health check result.
		 */
		do_action( 'wp_mcp_ai_health_check_completed', $results );

		return $results;
	}

	/**
	 * Get the cached (last) status snapshot.
	 *
	 * If the cache is older than MAX_CACHE_AGE, a refresh is triggered.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $force_refresh Whether to bypass the cache and run checks immediately.
	 * @return array<string, array> Map of slug => health check result.
	 */
	public function get_status( $force_refresh = false ) {
		if ( $force_refresh ) {
			return $this->run_health_checks();
		}

		// Fast path: freshness transient avoids two get_option() calls
		// on every AJAX poll when the cron job keeps the cache warm.
		if ( get_transient( self::CACHE_FRESH_KEY ) ) {
			$status = get_option( self::OPTION_KEY, array() );
			if ( is_array( $status ) && ! empty( $status ) ) {
				return $status;
			}
		}

		$last_check = (int) get_option( self::LAST_CHECK_KEY, 0 );
		$cache_age  = time() - $last_check;

		if ( $cache_age > self::MAX_CACHE_AGE ) {
			return $this->run_health_checks();
		}

		$status = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $status ) || empty( $status ) ) {
			return $this->run_health_checks();
		}

		return $status;
	}

	/**
	 * Get the cached status snapshot WITHOUT triggering a refresh.
	 *
	 * This is the safe read path for AJAX polling endpoints (Pro Status
	 * dashboard, public status REST endpoint) that must never spawn a
	 * health check on the request thread. Returns an empty array when
	 * no cache exists — callers should handle the fallback gracefully.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, array> Map of slug => health check result, or empty array.
	 */
	public function get_cached_status() {
		$status = get_option( self::OPTION_KEY, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Get a status snapshot suitable for public consumption.
	 *
	 * Only components where is_public() returns true are included.
	 * Internal fields like latency_ms are stripped.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array> Map of slug => public status data.
	 */
	public function get_public_status() {
		$status  = $this->get_status();
		$sources = $this->get_sources();
		$public  = array();

		foreach ( $status as $slug => $data ) {
			if ( ! isset( $sources[ $slug ] ) || ! $sources[ $slug ]->is_public() ) {
				continue;
			}

			$public[ $slug ] = array(
				'slug'       => sanitize_key( $slug ),
				'name'       => wp_kses_post( $sources[ $slug ]->get_name() ),
				'group'      => sanitize_key( $sources[ $slug ]->get_group() ),
				'status'     => sanitize_text_field( isset( $data['status'] ) ? $data['status'] : 'unknown' ),
				'message'    => wp_kses_post( isset( $data['message'] ) ? $data['message'] : '' ),
				'checked_at' => isset( $data['checked_at'] ) ? absint( $data['checked_at'] ) : 0,
			);
		}

		return $public;
	}

	/**
	 * Compute an overall status from component statuses.
	 *
	 * The overall status is the worst status across all components:
	 * major_outage > partial_outage > degraded_performance > under_maintenance > operational.
	 *
	 * @since 1.2.0
	 *
	 * @param array $components Map of slug => status data (must contain 'status' key).
	 * @return string Overall status value.
	 */
	public function compute_overall_status( $components ) {
		$severity = array(
			'operational'          => 0,
			'under_maintenance'    => 1,
			'degraded_performance' => 2,
			'partial_outage'       => 3,
			'major_outage'         => 4,
		);

		$worst = 'operational';
		$max   = 0;

		foreach ( $components as $component ) {
			$status = isset( $component['status'] ) ? $component['status'] : 'operational';
			$level  = isset( $severity[ $status ] ) ? $severity[ $status ] : 0;

			if ( $level > $max ) {
				$max   = $level;
				$worst = $status;
			}
		}

		return $worst;
	}

	/**
	 * Get uptime history for the last N days.
	 *
	 * Returns daily uptime percentages keyed by date string (Y-m-d).
	 *
	 * @since 1.2.0
	 *
	 * @param int $days Number of days to retrieve (default 30).
	 * @return array<string, float> Map of date => uptime percentage.
	 */
	public function get_uptime_history( $days = 30 ) {
		$history = get_option( self::HISTORY_KEY, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}

		$cutoff = strtotime( '-' . absint( $days ) . ' days' );
		$result = array();

		foreach ( $history as $date => $pct ) {
			$timestamp = strtotime( $date );
			if ( $timestamp && $timestamp >= $cutoff ) {
				$result[ $date ] = (float) $pct;
			}
		}

		ksort( $result );
		return $result;
	}

	/**
	 * Roll up today's health checks into a daily uptime percentage.
	 *
	 * Called by wp_mcp_ai_uptime_rollup_cron (hourly).
	 * Computes uptime as: (operational checks / total checks) * 100.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function rollup_uptime_history() {
		// Read cached status directly — never trigger a health check from
		// the rollup cron, which would cause cascading refreshes.
		$status = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$history = get_option( self::HISTORY_KEY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$today    = gmdate( 'Y-m-d' );
		$total    = 0;
		$up_count = 0;

		foreach ( $status as $data ) {
			++$total;
			$component_status = isset( $data['status'] ) ? $data['status'] : 'unknown';
			if ( 'operational' === $component_status ) {
				++$up_count;
			}
		}

		if ( $total > 0 ) {
			$history[ $today ] = round( ( $up_count / $total ) * 100, 2 );
		}

		update_option( self::HISTORY_KEY, $history, false );
	}

	/**
	 * Clean up uptime history beyond 90 days.
	 *
	 * Called by wp_mcp_ai_status_history_cleanup (daily).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function cleanup_history() {
		$history = get_option( self::HISTORY_KEY, array() );
		if ( ! is_array( $history ) || empty( $history ) ) {
			return;
		}

		$cutoff = strtotime( '-90 days' );
		foreach ( $history as $date => $pct ) {
			$timestamp = strtotime( $date );
			if ( $timestamp && $timestamp < $cutoff ) {
				unset( $history[ $date ] );
			}
		}

		update_option( self::HISTORY_KEY, $history, false );
	}
}
