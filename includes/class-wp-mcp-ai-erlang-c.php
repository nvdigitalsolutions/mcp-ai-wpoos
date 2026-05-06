<?php
/**
 * Erlang C queuing-theory math helper.
 *
 * Provides pure-PHP implementations of the Erlang C formula for computing
 * service-level attainment, queue wait probabilities, and minimum staffing.
 * No external dependencies; fully PHP 7.4-compatible.
 *
 * Formula reference:
 *   A. K. Erlang (1917) — teletraffic engineering model for M/M/c queues.
 *   Industry standard: 80/20 service level (80 % answered ≤ 20 s).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Erlang C queuing-theory calculator.
 *
 * All public methods are static and stateless.  Inputs use consistent SI
 * units: arrival rate in contacts-per-second, service time in seconds.
 * Higher-level tools convert from natural units (e.g. calls/hour, minutes)
 * before calling this class.
 *
 * @since 1.1.8
 */
class WP_MCP_AI_Erlang_C {

	/**
	 * Maximum agents cap used in min-agent search to prevent infinite loops.
	 */
	const MAX_AGENTS_CAP = 500;

	/**
	 * Compute the Erlang C probability that an arriving contact must wait.
	 *
	 * Uses log-sum-exp arithmetic throughout to avoid floating-point overflow
	 * for large agent counts.
	 *
	 * @param float $traffic_intensity Erlangs (A = λ × AHT, dimensionless).
	 *                                 Must be strictly greater than 0.
	 * @param int   $agents            Number of agents (N). Must be > A for
	 *                                 a stable queue.
	 * @return float Probability of waiting [0, 1].  Returns 1.0 when N ≤ A
	 *              (system is overloaded / unstable).
	 */
	public static function probability_wait( $traffic_intensity, $agents ) {
		$a = (float) $traffic_intensity;
		$n = (int) $agents;

		if ( $a <= 0.0 ) {
			return 0.0;
		}

		if ( $n <= 0 || (float) $n <= $a ) {
			return 1.0; // Unstable queue.
		}

		// log( A^N / N! × N/(N-A) ) — numerator of Erlang C.
		$log_num = $n * log( $a ) - self::log_factorial( $n ) + log( (float) $n ) - log( (float) $n - $a );

		// Sum of log( A^k / k! ) for k = 0 … N-1.
		$log_terms   = array();
		$log_k_term  = 0.0; // k=0: A^0/0! = 1 → log = 0.
		$log_terms[] = $log_k_term;

		for ( $k = 1; $k < $n; $k++ ) {
			$log_k_term += log( $a ) - log( (float) $k );
			$log_terms[] = $log_k_term;
		}

		// Log-sum-exp over denominator terms (partial sum + numerator).
		$log_terms[] = $log_num;
		$max_log     = max( $log_terms );

		$sum_exp = 0.0;
		foreach ( $log_terms as $lt ) {
			$sum_exp += exp( $lt - $max_log );
		}

		$log_denom = $max_log + log( $sum_exp );
		$log_c     = $log_num - $log_denom;

		return min( 1.0, max( 0.0, exp( $log_c ) ) );
	}

	/**
	 * Compute the probability that a contact is answered within $target_seconds.
	 *
	 * Service level = 1 − C(N,A) × exp( −(N − A) × target_s / AHT_s )
	 *
	 * @param float $traffic_intensity Erlangs (A = λ × AHT, same units as below).
	 * @param int   $agents            Number of agents (N).
	 * @param float $aht_seconds       Average handle time in seconds.
	 * @param float $target_seconds    Target answer time threshold in seconds.
	 * @return float Service level [0, 1].
	 */
	public static function service_level( $traffic_intensity, $agents, $aht_seconds, $target_seconds ) {
		$a = (float) $traffic_intensity;
		$n = (int) $agents;
		$h = (float) $aht_seconds;
		$t = (float) $target_seconds;

		if ( $a <= 0.0 || $h <= 0.0 || $t < 0.0 ) {
			return 0.0;
		}

		if ( (float) $n <= $a ) {
			return 0.0; // Unstable.
		}

		$c = self::probability_wait( $a, $n );
		return 1.0 - $c * exp( -( (float) $n - $a ) * ( $t / $h ) );
	}

	/**
	 * Compute average queue wait time in seconds.
	 *
	 * W = C(N,A) × AHT / (N − A)
	 *
	 * @param float $traffic_intensity Erlangs.
	 * @param int   $agents            Number of agents.
	 * @param float $aht_seconds       Average handle time in seconds.
	 * @return float Average wait time in seconds, or PHP_FLOAT_MAX when unstable (N ≤ A).
	 */
	public static function avg_wait_time( $traffic_intensity, $agents, $aht_seconds ) {
		$a = (float) $traffic_intensity;
		$n = (int) $agents;
		$h = (float) $aht_seconds;

		if ( $h <= 0.0 || $a <= 0.0 ) {
			return 0.0;
		}

		if ( (float) $n <= $a ) {
			return PHP_FLOAT_MAX; // Unstable.
		}

		$c = self::probability_wait( $a, $n );
		return ( $c * $h ) / ( (float) $n - $a );
	}

	/**
	 * Find the minimum number of agents to achieve a target service level.
	 *
	 * Iterates from ceil(A)+1 upward until the service level constraint is met.
	 *
	 * @param float $traffic_intensity Erlangs.
	 * @param float $aht_seconds       Average handle time in seconds.
	 * @param float $target_sl_pct     Required service level fraction [0, 1].
	 * @param float $target_seconds    Answer-time threshold in seconds.
	 * @return int Minimum number of agents required.
	 */
	public static function min_agents_for_sl( $traffic_intensity, $aht_seconds, $target_sl_pct, $target_seconds ) {
		$a          = (float) $traffic_intensity;
		$target_pct = (float) $target_sl_pct;
		$n          = max( 1, (int) ceil( $a ) + 1 );
		$max_n      = $n + self::MAX_AGENTS_CAP;

		while ( $n <= $max_n ) {
			if ( self::service_level( $a, $n, $aht_seconds, $target_seconds ) >= $target_pct ) {
				return $n;
			}
			++$n;
		}

		return $n; // Capped result.
	}

	/**
	 * Convert arrival rate (contacts per hour) and AHT (seconds) to Erlangs.
	 *
	 * A = λ_per_hour × AHT_seconds / 3600
	 *
	 * @param float $arrival_rate_per_hour Contacts arriving per hour.
	 * @param float $aht_seconds           Average handle time in seconds.
	 * @return float Traffic intensity in Erlangs.
	 */
	public static function to_erlangs( $arrival_rate_per_hour, $aht_seconds ) {
		return (float) $arrival_rate_per_hour * (float) $aht_seconds / 3600.0;
	}

	/**
	 * Compute agent utilisation (traffic intensity per agent).
	 *
	 * @param float $traffic_intensity Erlangs.
	 * @param int   $agents            Number of agents.
	 * @return float Utilisation fraction [0, 1].
	 */
	public static function utilisation( $traffic_intensity, $agents ) {
		$n = (int) $agents;
		if ( $n <= 0 ) {
			return 0.0;
		}
		return min( 1.0, (float) $traffic_intensity / (float) $n );
	}

	/**
	 * Compute the natural log of N! using log-sum increments.
	 *
	 * @param int $n Non-negative integer.
	 * @return float log(N!)
	 */
	protected static function log_factorial( $n ) {
		$n      = (int) $n;
		$result = 0.0;
		for ( $i = 2; $i <= $n; $i++ ) {
			$result += log( (float) $i );
		}
		return $result;
	}
}
