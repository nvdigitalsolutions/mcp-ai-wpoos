<?php
/**
 * Metric Retention
 *
 * Enforces per-tier TTL on the `{prefix}mcp_ai_metric_events` table via
 * a once-daily cron job. Defaults (in days):
 *
 *   - public:    365
 *   - internal:   90
 *   - sensitive:  30
 *   - restricted: (never persisted — tier is hard-dropped upstream)
 *
 * Rollout-plan.md lists "Restricted 7d" as an aspirational retention
 * value, but `privacy-matrix.md` declares that Restricted raw events
 * are never persisted at all. The latter is the stronger invariant
 * and wins: the persister drops Restricted events before they reach
 * the table, so there is nothing for the retention cron to purge.
 *
 * All per-tier TTLs are filterable via `wp_mcp_ai_measurement_retention`.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metric retention controller.
 */
class MetricRetention {

	/**
	 * Cron hook fired daily by WP-Cron.
	 */
	const CRON_HOOK = 'wp_mcp_ai_metric_retention_purge';

	/**
	 * Stock TTLs (in days) keyed by privacy tier.
	 *
	 * @return array<string,int>
	 */
	public static function default_ttls_days() {
		return array(
			MeasurementRegistry::PRIVACY_PUBLIC    => 365,
			MeasurementRegistry::PRIVACY_INTERNAL  => 90,
			MeasurementRegistry::PRIVACY_SENSITIVE => 30,
		);
	}

	/**
	 * Resolve the current per-tier TTLs, applying the
	 * `wp_mcp_ai_measurement_retention` filter and clamping.
	 *
	 * @return array<string,int>
	 */
	public static function resolve_ttls_days() {
		$defaults = self::default_ttls_days();

		/**
		 * Filter the per-tier retention TTLs (in days).
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,int> $ttls Defaults keyed by privacy tier.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_measurement_retention', $defaults );

		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		$out = array();
		foreach ( $defaults as $tier => $default_days ) {
			$value = isset( $filtered[ $tier ] ) && is_numeric( $filtered[ $tier ] )
				? (int) $filtered[ $tier ]
				: $default_days;
			// Floor of 1 day, ceiling of 10 years — anything outside
			// this range is almost certainly a misconfiguration.
			if ( $value < 1 ) {
				$value = 1;
			} elseif ( $value > 3650 ) {
				$value = 3650;
			}
			$out[ $tier ] = $value;
		}
		return $out;
	}

	/**
	 * Schedule the daily cron if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron. Used on deactivation and by tests.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Wire the cron callback. Called from the measurement bootstrap.
	 *
	 * @return void
	 */
	public static function register_cron_callback() {
		if ( ! has_action( self::CRON_HOOK, array( __CLASS__, 'run' ) ) ) {
			add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		}
	}

	/**
	 * Run the purge. Safe to call outside cron for tests or
	 * administrative tooling.
	 *
	 * @return array<string,int> Rows deleted per tier.
	 */
	public static function run() {
		if ( ! class_exists( 'MetricEventStore' ) ) {
			return array();
		}

		$store = MetricEventStore::get_instance();
		$now   = time();
		$ttls  = self::resolve_ttls_days();

		$deleted = array();
		foreach ( $ttls as $tier => $days ) {
			$cutoff_ts        = $now - ( $days * DAY_IN_SECONDS );
			$deleted[ $tier ] = $store->purge_older_than( $tier, $cutoff_ts );
		}

		/**
		 * Fires after a retention sweep completes.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,int> $deleted Rows deleted per tier.
		 * @param array<string,int> $ttls    TTLs applied (days).
		 */
		do_action( 'wp_mcp_ai_measurement_retention_completed', $deleted, $ttls );

		return $deleted;
	}
}
