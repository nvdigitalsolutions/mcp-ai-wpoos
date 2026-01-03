<?php
/**
 * SLA Manager for job prioritization using Little's Law.
 *
 * Implements SLA-based prioritization for the cron/orchestration layer:
 * - Real-time tier: < 1s latency, priority 100
 * - Near real-time tier: 1-30s latency, priority 50
 * - Batch tier: > 30s latency, priority 10
 *
 * Uses Little's Law (L = λ × W) to calculate queue capacity:
 * - L = average number of items in queue
 * - λ (lambda) = average arrival rate
 * - W = average waiting time
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages SLA tiers and capacity planning for jobs.
 */
class WP_MCP_AI_SLA_Manager {
	/**
	 * SLA tier definitions.
	 */
	const TIER_REALTIME      = 'realtime';
	const TIER_NEAR_REALTIME = 'near_realtime';
	const TIER_BATCH         = 'batch';

	/**
	 * Priority values for each tier.
	 */
	const PRIORITY_REALTIME      = 100;
	const PRIORITY_NEAR_REALTIME = 50;
	const PRIORITY_BATCH         = 10;

	/**
	 * SLA target latencies in seconds.
	 */
	const SLA_REALTIME_MAX      = 1;    // < 1 second.
	const SLA_NEAR_REALTIME_MAX = 30;   // 1-30 seconds.
	const SLA_BATCH_MAX         = 300;  // 30-300 seconds (5 min).

	/**
	 * Default max concurrent jobs per tier.
	 */
	const DEFAULT_REALTIME_CONCURRENT      = 5;
	const DEFAULT_NEAR_REALTIME_CONCURRENT = 3;
	const DEFAULT_BATCH_CONCURRENT         = 2;

	/**
	 * Get SLA tier for a tool based on its capabilities.
	 *
	 * @param object $tool Tool instance implementing WP_MCP_AI_Tool_Interface.
	 * @return string SLA tier (realtime, near_realtime, batch).
	 */
	public static function get_tier_for_tool( $tool ) {
		if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_capabilities' ) ) {
			// Default to batch for unknown tools.
			return self::TIER_BATCH;
		}

		$capabilities = $tool->get_capabilities();

		if ( ! is_array( $capabilities ) ) {
			return self::TIER_BATCH;
		}

		// Check for explicit SLA tier in capabilities.
		if ( isset( $capabilities['sla_tier'] ) ) {
			$tier = sanitize_key( $capabilities['sla_tier'] );
			if ( in_array( $tier, self::get_valid_tiers(), true ) ) {
				return $tier;
			}
		}

		// Infer tier from capability flags.
		// Real-time: Tools that must respond quickly for UI.
		if ( in_array( 'realtime', $capabilities, true ) ||
			in_array( 'interactive', $capabilities, true ) ||
			in_array( 'ui-blocking', $capabilities, true ) ) {
			return self::TIER_REALTIME;
		}

		// Background-only and long-running are always batch.
		if ( in_array( 'background-only', $capabilities, true ) ||
			in_array( 'long-running', $capabilities, true ) ) {
			return self::TIER_BATCH;
		}

		// Async tools default to near real-time unless otherwise specified.
		if ( in_array( 'async', $capabilities, true ) ||
			in_array( 'may-timeout', $capabilities, true ) ) {
			return self::TIER_NEAR_REALTIME;
		}

		// Default to batch for safety.
		return self::TIER_BATCH;
	}

	/**
	 * Get priority value for a tier.
	 *
	 * @param string $tier SLA tier.
	 * @return int Priority value.
	 */
	public static function get_priority( $tier ) {
		switch ( $tier ) {
			case self::TIER_REALTIME:
				return self::PRIORITY_REALTIME;

			case self::TIER_NEAR_REALTIME:
				return self::PRIORITY_NEAR_REALTIME;

			case self::TIER_BATCH:
				return self::PRIORITY_BATCH;

			default:
				return self::PRIORITY_BATCH;
		}
	}

	/**
	 * Calculate queue capacity using Little's Law.
	 *
	 * Little's Law: L = λ × W
	 * Where:
	 * - L = average number of jobs in system (capacity)
	 * - λ (lambda) = average arrival rate (jobs per second)
	 * - W = average wait time (seconds)
	 *
	 * @param string $tier         SLA tier.
	 * @param float  $arrival_rate Jobs per second (lambda).
	 * @param float  $service_time Average service time per job (seconds).
	 * @return array Capacity calculations.
	 */
	public static function calculate_capacity( $tier, $arrival_rate, $service_time ) {
		$sla_target = self::get_sla_target( $tier );

		// Little's Law: L = λ × W.
		// W = Wait time = SLA target - service time.
		$wait_time = max( 0, $sla_target - $service_time );

		// Queue length (items waiting).
		$queue_length = $arrival_rate * $wait_time;

		// System capacity (items in queue + being processed).
		$system_capacity = $arrival_rate * $sla_target;

		// Server utilization (ρ = λ × service_time).
		$utilization = $arrival_rate * $service_time;

		// Max concurrent workers needed to meet SLA.
		// ρ / μ where μ = 1 / service_time.
		$required_workers = max( 1, ceil( $utilization ) );

		return array(
			'tier'                => $tier,
			'sla_target'          => $sla_target,
			'arrival_rate'        => $arrival_rate,
			'service_time'        => $service_time,
			'wait_time'           => $wait_time,
			'queue_length'        => $queue_length,
			'system_capacity'     => $system_capacity,
			'utilization'         => $utilization,
			'required_workers'    => $required_workers,
			'recommended_workers' => max( $required_workers, self::get_default_concurrent( $tier ) ),
		);
	}

	/**
	 * Get SLA target latency for a tier.
	 *
	 * @param string $tier SLA tier.
	 * @return float Target latency in seconds.
	 */
	public static function get_sla_target( $tier ) {
		switch ( $tier ) {
			case self::TIER_REALTIME:
				return self::SLA_REALTIME_MAX;

			case self::TIER_NEAR_REALTIME:
				return self::SLA_NEAR_REALTIME_MAX;

			case self::TIER_BATCH:
				return self::SLA_BATCH_MAX;

			default:
				return self::SLA_BATCH_MAX;
		}
	}

	/**
	 * Get default concurrent job limit for a tier.
	 *
	 * @param string $tier SLA tier.
	 * @return int Default concurrent jobs.
	 */
	public static function get_default_concurrent( $tier ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$setting_key = 'sla_' . $tier . '_concurrent';

		if ( isset( $settings[ $setting_key ] ) ) {
			return max( 1, absint( $settings[ $setting_key ] ) );
		}

		// Return hardcoded defaults.
		switch ( $tier ) {
			case self::TIER_REALTIME:
				return self::DEFAULT_REALTIME_CONCURRENT;

			case self::TIER_NEAR_REALTIME:
				return self::DEFAULT_NEAR_REALTIME_CONCURRENT;

			case self::TIER_BATCH:
				return self::DEFAULT_BATCH_CONCURRENT;

			default:
				return self::DEFAULT_BATCH_CONCURRENT;
		}
	}

	/**
	 * Check if SLA-based prioritization is enabled.
	 *
	 * @return bool True if enabled.
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( isset( $settings['sla_prioritization_enabled'] ) ) {
			return (bool) $settings['sla_prioritization_enabled'];
		}

		// Enabled by default.
		return true;
	}

	/**
	 * Get valid SLA tiers.
	 *
	 * @return array Valid tier names.
	 */
	public static function get_valid_tiers() {
		return array(
			self::TIER_REALTIME,
			self::TIER_NEAR_REALTIME,
			self::TIER_BATCH,
		);
	}

	/**
	 * Get tier information.
	 *
	 * @param string $tier SLA tier.
	 * @return array Tier information.
	 */
	public static function get_tier_info( $tier ) {
		$sla_target = self::get_sla_target( $tier );
		$priority   = self::get_priority( $tier );
		$concurrent = self::get_default_concurrent( $tier );

		$descriptions = array(
			self::TIER_REALTIME      => __( 'Real-time tier for interactive UI operations requiring < 1s response.', 'wp-mcp-ai' ),
			self::TIER_NEAR_REALTIME => __( 'Near real-time tier for async operations with 1-30s latency tolerance.', 'wp-mcp-ai' ),
			self::TIER_BATCH         => __( 'Batch tier for background processing with > 30s acceptable latency.', 'wp-mcp-ai' ),
		);

		return array(
			'tier'        => $tier,
			'priority'    => $priority,
			'sla_target'  => $sla_target,
			'concurrent'  => $concurrent,
			'description' => isset( $descriptions[ $tier ] ) ? $descriptions[ $tier ] : '',
		);
	}

	/**
	 * Get all tier information.
	 *
	 * @return array Array of tier information keyed by tier name.
	 */
	public static function get_all_tiers_info() {
		$tiers = self::get_valid_tiers();
		$info  = array();

		foreach ( $tiers as $tier ) {
			$info[ $tier ] = self::get_tier_info( $tier );
		}

		return $info;
	}

	/**
	 * Analyze current queue metrics.
	 *
	 * @param string $tier SLA tier to analyze.
	 * @return array Queue metrics.
	 */
	public static function analyze_queue_metrics( $tier ) {
		if ( ! class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
			return array(
				'error' => __( 'Job Queue Manager not available.', 'wp-mcp-ai' ),
			);
		}

		$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();

		// Calculate metrics for the tier.
		$priority      = self::get_priority( $tier );
		$sla_target    = self::get_sla_target( $tier );
		$max_concurrent = self::get_default_concurrent( $tier );

		// Estimate arrival rate (jobs per second) based on recent activity.
		// This is a simplified estimation - real systems would track this over time.
		$arrival_rate = $stats['pending'] > 0 ? $stats['pending'] / 60.0 : 0.1; // Assume jobs arrived in last minute.

		// Estimate average service time (seconds per job).
		// This varies by tool type - use conservative estimate.
		$service_time = $sla_target * 0.5; // Assume jobs take half the SLA on average.

		$capacity = self::calculate_capacity( $tier, $arrival_rate, $service_time );

		return array_merge(
			$capacity,
			array(
				'current_stats'   => $stats,
				'max_concurrent'  => $max_concurrent,
				'over_capacity'   => $stats['pending'] > $capacity['recommended_workers'],
				'meets_sla'       => $stats['pending'] <= $capacity['system_capacity'],
			)
		);
	}

	/**
	 * Get recommendations for tuning SLA settings.
	 *
	 * @return array Recommendations by tier.
	 */
	public static function get_tuning_recommendations() {
		$recommendations = array();

		foreach ( self::get_valid_tiers() as $tier ) {
			$metrics = self::analyze_queue_metrics( $tier );

			$recommendations[ $tier ] = array(
				'tier'        => $tier,
				'current'     => $metrics['max_concurrent'],
				'recommended' => isset( $metrics['recommended_workers'] ) ? $metrics['recommended_workers'] : $metrics['max_concurrent'],
				'status'      => 'ok',
				'message'     => '',
			);

			if ( isset( $metrics['over_capacity'] ) && $metrics['over_capacity'] ) {
				$recommendations[ $tier ]['status']  = 'warning';
				$recommendations[ $tier ]['message'] = sprintf(
					/* translators: %d: recommended worker count */
					__( 'Queue is over capacity. Consider increasing concurrent workers to %d.', 'wp-mcp-ai' ),
					$recommendations[ $tier ]['recommended']
				);
			}

			if ( isset( $metrics['meets_sla'] ) && ! $metrics['meets_sla'] ) {
				$recommendations[ $tier ]['status']  = 'critical';
				$recommendations[ $tier ]['message'] = sprintf(
					/* translators: 1: SLA target in seconds, 2: recommended worker count */
					__( 'SLA target of %1$ds is at risk. Increase concurrent workers to %2$d or optimize job execution time.', 'wp-mcp-ai' ),
					$metrics['sla_target'],
					$recommendations[ $tier ]['recommended']
				);
			}
		}

		return $recommendations;
	}
}
