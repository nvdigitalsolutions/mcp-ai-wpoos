<?php
/**
 * Evolution Governor — unified budget and rate limits for all mutation paths.
 *
 * Phase G of the artifact-evolution plan. Every mutation path that can spend
 * provider budget (Continual Harness evolver, harness search engine, the
 * pluggable proposer, population batch runs) consults this governor before
 * mutating and records spend afterwards, so one per-assistant budget and one
 * set of rate limits covers the whole self-evolution loop.
 *
 * Compatibility:
 *   - The shared spend transient reuses the Phase A key
 *     (`wp_mcp_ai_evolution_budget_{assistant_id}`) so pre-existing spend
 *     carries over unchanged.
 *   - The budget limit keeps the Phase A filter
 *     `wp_mcp_ai_harness_evolution_budget_usd` (default $5.00/hour).
 *   - Default rate limit (60/hour/path) and site cap (unlimited) leave the
 *     evolver's observable behavior unchanged from Phases A–F.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evolution Governor class.
 *
 * @since 1.9.0
 */
class EvolutionGovernor {

	/**
	 * Shared per-assistant spend transient prefix (Phase A key for continuity).
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const BUDGET_TRANSIENT_PREFIX = 'wp_mcp_ai_evolution_budget_';

	/**
	 * Per-path spend stat transient prefix (observability only).
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const PATH_SPEND_PREFIX = 'wp_mcp_ai_evolution_path_spend_';

	/**
	 * Per-path mutation counter transient prefix.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const RATE_TRANSIENT_PREFIX = 'wp_mcp_ai_evolution_rate_';

	/**
	 * Site-wide mutation counter transient key.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const SITE_COUNTER_KEY = 'wp_mcp_ai_evolution_site_mutations';

	/**
	 * Default hourly evolution budget in USD (shared with the Phase A evolver).
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_BUDGET_USD = 5.0;

	/**
	 * Default mutations per hour per assistant and path.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_RATE_LIMIT = 60;

	/**
	 * Default site-wide mutation cap per hour (0 = unlimited).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_SITE_MAX = 0;

	/**
	 * Built-in mutation paths.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const BUILT_IN_PATHS = array( 'evolver', 'search', 'proposer', 'population' );

	/**
	 * Whether the governor layer is active for a request.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID (0 for site-level checks).
	 * @param string $path         Mutation path.
	 * @return bool
	 */
	public static function is_enabled( $assistant_id = 0, $path = '' ) {
		/**
		 * Filters whether the unified evolution governor is active.
		 *
		 * Default true. Disable to bypass all budget and rate checks.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $enabled      Whether the governor is active.
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $path         Mutation path.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_evolution_governor_enabled', true, (int) $assistant_id, (string) $path );
	}

	/**
	 * Registered mutation paths.
	 *
	 * @since 1.9.0
	 *
	 * @return array<int,string>
	 */
	public static function get_paths() {
		/**
		 * Filters the registered mutation paths.
		 *
		 * @since 1.9.0
		 *
		 * @param array<int,string> $paths Mutation paths.
		 */
		$paths = apply_filters( 'wp_mcp_ai_evolution_governor_paths', self::BUILT_IN_PATHS );

		return array_values( array_unique( array_map( 'sanitize_key', (array) $paths ) ) );
	}

	/**
	 * Resolve the per-assistant hourly evolution budget in USD.
	 *
	 * Keeps the Phase A filter name for backward compatibility.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return float Budget limit.
	 */
	public static function budget_limit_usd( $assistant_id ) {
		/**
		 * Filters the hourly harness evolution budget per assistant.
		 *
		 * @since 1.9.0
		 *
		 * @param float $budget_usd Budget in USD. Default 5.0.
		 * @param int   $assistant_id Assistant post ID.
		 */
		$limit = apply_filters( 'wp_mcp_ai_harness_evolution_budget_usd', self::DEFAULT_BUDGET_USD, (int) $assistant_id );

		return max( 0.0, (float) $limit );
	}

	/**
	 * Budget spent this hour for an assistant (shared across paths).
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return float Spent amount in USD.
	 */
	public static function budget_spent( $assistant_id ) {
		$spent = get_transient( self::BUDGET_TRANSIENT_PREFIX . (int) $assistant_id );

		return max( 0.0, (float) $spent );
	}

	/**
	 * Remaining evolution budget this hour for an assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return float Remaining budget.
	 */
	public static function budget_remaining( $assistant_id ) {
		return self::budget_limit_usd( $assistant_id ) - self::budget_spent( $assistant_id );
	}

	/**
	 * Mutations recorded this hour for an assistant + path.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $path         Mutation path.
	 * @return int
	 */
	public static function mutations_this_hour( $assistant_id, $path ) {
		$count = get_transient( self::RATE_TRANSIENT_PREFIX . (int) $assistant_id . '_' . sanitize_key( (string) $path ) );

		return max( 0, (int) $count );
	}

	/**
	 * Site-wide mutations recorded this hour.
	 *
	 * @since 1.9.0
	 *
	 * @return int
	 */
	public static function site_mutations_this_hour() {
		$count = get_transient( self::SITE_COUNTER_KEY );

		return max( 0, (int) $count );
	}

	/**
	 * Hourly mutation limit for an assistant + path.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $path         Mutation path.
	 * @return int
	 */
	public static function rate_limit( $assistant_id, $path ) {
		/**
		 * Filters the hourly mutation limit per assistant and path.
		 *
		 * Default 60.
		 *
		 * @since 1.9.0
		 *
		 * @param int    $limit        Mutations per hour. Default 60.
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $path         Mutation path.
		 */
		$limit = apply_filters( 'wp_mcp_ai_evolution_governor_rate_limit', self::DEFAULT_RATE_LIMIT, (int) $assistant_id, (string) $path );

		return max( 0, (int) $limit );
	}

	/**
	 * Site-wide hourly mutation cap.
	 *
	 * @since 1.9.0
	 *
	 * @return int 0 = unlimited.
	 */
	public static function site_max_mutations() {
		/**
		 * Filters the site-wide hourly mutation cap.
		 *
		 * Default 0 (unlimited).
		 *
		 * @since 1.9.0
		 *
		 * @param int $max Site-wide mutations per hour. Default 0.
		 */
		$max = apply_filters( 'wp_mcp_ai_evolution_governor_site_max_mutations', self::DEFAULT_SITE_MAX );

		return max( 0, (int) $max );
	}

	/**
	 * Decide whether a mutation may proceed.
	 *
	 * Checks, in order: governor enabled, valid path, remaining budget vs the
	 * estimated cost, per-path rate limit, site-wide cap.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id      Assistant post ID.
	 * @param string $path              Mutation path.
	 * @param float  $estimated_cost_usd Estimated provider cost of the mutation.
	 * @return array{allowed:bool,reason:string,budget_remaining:float,rate_used:int,rate_limit:int}
	 */
	public static function can_mutate( $assistant_id, $path, $estimated_cost_usd = 0.0 ) {
		$assistant_id = (int) $assistant_id;
		$path         = sanitize_key( (string) $path );

		if ( ! self::is_enabled( $assistant_id, $path ) ) {
			return array(
				'allowed'          => true,
				'reason'           => 'governor_disabled',
				'budget_remaining' => self::budget_remaining( $assistant_id ),
				'rate_used'        => self::mutations_this_hour( $assistant_id, $path ),
				'rate_limit'       => self::rate_limit( $assistant_id, $path ),
			);
		}

		if ( ! in_array( $path, self::get_paths(), true ) ) {
			return array(
				'allowed'          => false,
				'reason'           => 'unknown_path',
				'budget_remaining' => self::budget_remaining( $assistant_id ),
				'rate_used'        => 0,
				'rate_limit'       => 0,
			);
		}

		$remaining = self::budget_remaining( $assistant_id );
		if ( $remaining < max( 0.0, (float) $estimated_cost_usd ) ) {
			return array(
				'allowed'          => false,
				'reason'           => 'budget_exhausted',
				'budget_remaining' => $remaining,
				'rate_used'        => self::mutations_this_hour( $assistant_id, $path ),
				'rate_limit'       => self::rate_limit( $assistant_id, $path ),
			);
		}

		$rate_used = self::mutations_this_hour( $assistant_id, $path );
		$rate_max  = self::rate_limit( $assistant_id, $path );
		if ( $rate_used >= $rate_max ) {
			return array(
				'allowed'          => false,
				'reason'           => 'rate_limited',
				'budget_remaining' => $remaining,
				'rate_used'        => $rate_used,
				'rate_limit'       => $rate_max,
			);
		}

		$site_max  = self::site_max_mutations();
		$site_used = self::site_mutations_this_hour();
		if ( $site_max > 0 && $site_used >= $site_max ) {
			return array(
				'allowed'          => false,
				'reason'           => 'site_cap_exceeded',
				'budget_remaining' => $remaining,
				'rate_used'        => $rate_used,
				'rate_limit'       => $rate_max,
			);
		}

		return array(
			'allowed'          => true,
			'reason'           => 'allowed',
			'budget_remaining' => $remaining,
			'rate_used'        => $rate_used,
			'rate_limit'       => $rate_max,
		);
	}

	/**
	 * Record a mutation attempt (rate counters).
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $path         Mutation path.
	 * @return void
	 */
	public static function record_mutation( $assistant_id, $path ) {
		$assistant_id = (int) $assistant_id;
		$path         = sanitize_key( (string) $path );

		set_transient(
			self::RATE_TRANSIENT_PREFIX . $assistant_id . '_' . $path,
			self::mutations_this_hour( $assistant_id, $path ) + 1,
			HOUR_IN_SECONDS
		);

		set_transient(
			self::SITE_COUNTER_KEY,
			self::site_mutations_this_hour() + 1,
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Record provider spend against the shared per-assistant budget.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param float  $usd          Amount in USD.
	 * @param string $path         Mutation path (observability).
	 * @return void
	 */
	public static function record_spend( $assistant_id, $usd, $path = 'evolver' ) {
		$assistant_id = (int) $assistant_id;
		$usd          = max( 0.0, (float) $usd );
		$path         = sanitize_key( (string) $path );

		set_transient(
			self::BUDGET_TRANSIENT_PREFIX . $assistant_id,
			self::budget_spent( $assistant_id ) + $usd,
			HOUR_IN_SECONDS
		);

		$path_key   = self::PATH_SPEND_PREFIX . $assistant_id . '_' . $path;
		$path_spent = get_transient( $path_key );
		set_transient( $path_key, max( 0.0, (float) $path_spent ) + $usd, HOUR_IN_SECONDS );
	}

	/**
	 * Observability report for an assistant (admin surface / REST).
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Budget + per-path counters.
	 */
	public static function get_report( $assistant_id ) {
		$assistant_id = (int) $assistant_id;

		$per_path = array();
		foreach ( self::get_paths() as $path ) {
			$per_path[ $path ] = array(
				'mutations_this_hour' => self::mutations_this_hour( $assistant_id, $path ),
				'rate_limit'          => self::rate_limit( $assistant_id, $path ),
				'spend_usd'           => max( 0.0, (float) get_transient( self::PATH_SPEND_PREFIX . $assistant_id . '_' . $path ) ),
			);
		}

		return array(
			'enabled'              => self::is_enabled( $assistant_id, '' ),
			'budget_limit_usd'     => self::budget_limit_usd( $assistant_id ),
			'budget_spent_usd'     => self::budget_spent( $assistant_id ),
			'budget_remaining_usd' => self::budget_remaining( $assistant_id ),
			'site_mutations'       => self::site_mutations_this_hour(),
			'site_max_mutations'   => self::site_max_mutations(),
			'paths'                => $per_path,
		);
	}
}
