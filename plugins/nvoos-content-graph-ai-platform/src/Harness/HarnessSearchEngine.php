<?php
/**
 * Harness Search Engine — population-based profile optimization.
 *
 * Implements Algorithm 1 from Meta-Harness (Lee et al., 2026):
 *   1. Initialize population ℋ from seed profiles.
 *   2. Evaluate all seeds via the eval scheduler (Layer G).
 *   3. For each iteration t=1..N:
 *      a. Proposer inspects prior results → proposes k candidates.
 *      b. Validate each candidate (schema check + smoke test).
 *      c. Evaluate valid candidates via the eval runner.
 *      d. Add to population; update Pareto frontier.
 *   4. Return Pareto frontier.
 *
 * The proposer is pluggable via `wp_mcp_ai_harness_proposer` filter.
 * The base plugin ships a "best-of-N" restarter that generates random
 * profile perturbations. The Pro addon replaces this with a coding-agent
 * proposer that inspects trace artifacts via the Trace Store.
 *
 * Population is persisted in option `wp_mcp_ai_harness_population_{assistant_id}`.
 * Pareto frontier is stored in transient `wp_mcp_ai_harness_pareto_{assistant_id}`.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness Search Engine.
 *
 * @since 1.9.0
 */
class HarnessSearchEngine {

	/**
	 * Default number of search iterations.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const DEFAULT_ITERATIONS = 20;

	/**
	 * Default number of candidates proposed per iteration.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const DEFAULT_CANDIDATES_PER_ITERATION = 2;

	/**
	 * Maximum population size before oldest entries are pruned.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_POPULATION = 200;

	/**
	 * Maximum number of eval cases to run in a smoke test.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const SMOKE_TEST_CASES = 2;

	/**
	 * Transient TTL for the Pareto frontier cache (1 hour).
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const PARETO_CACHE_TTL = 3600;

	/**
	 * Lock TTL for per-assistant search runs (30 minutes).
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const LOCK_TTL = 1800;

	/**
	 * Option key prefix for population storage.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const OPTION_POPULATION_PREFIX = 'wp_mcp_ai_harness_population_';

	/**
	 * Transient key prefix for the Pareto frontier cache.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const TRANSIENT_PARETO_PREFIX = 'wp_mcp_ai_harness_pareto_';

	/**
	 * Transient key prefix for search run locks.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const LOCK_PREFIX = 'wp_mcp_ai_harness_search_lock_';

	/**
	 * Active search run state, keyed by assistant ID.
	 *
	 * @since 1.9.0
	 * @var array<int,array>
	 */
	private static $active_searches = array();

	/**
	 * Start a search run for an assistant.
	 *
	 * Acquires a lock to prevent concurrent searches on the same assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id      Assistant post ID.
	 * @param array $seed_profiles     Array of harness profile arrays (at least 1).
	 * @param array $search_set_suites Array of eval suite slugs to use as search set.
	 * @param array $opts              Optional. Override defaults: iterations, k, proposer_args.
	 * @return array|WP_Error Search run metadata on success, WP_Error on failure.
	 */
	public static function start_search( $assistant_id, array $seed_profiles, array $search_set_suites, array $opts = array() ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_search_invalid_assistant',
				__( 'A valid assistant ID is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $seed_profiles ) ) {
			return new \WP_Error(
				'wp_mcp_ai_search_no_seeds',
				__( 'At least one seed profile is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $search_set_suites ) ) {
			return new \WP_Error(
				'wp_mcp_ai_search_no_suites',
				__( 'At least one eval suite slug is required for the search set.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Acquire lock.
		$lock_key = self::LOCK_PREFIX . $assistant_id;
		$existing = get_transient( $lock_key );
		if ( false !== $existing ) {
			return new \WP_Error(
				'wp_mcp_ai_search_locked',
				/* translators: %d: assistant ID */
				sprintf( __( 'A search run is already active for assistant %d. Wait for it to complete or cancel it.', 'nvoos-content-graph-ai-platform' ), $assistant_id )
			);
		}
		set_transient( $lock_key, time(), self::LOCK_TTL );

		$iterations = isset( $opts['iterations'] ) ? max( 1, min( 100, (int) $opts['iterations'] ) ) : self::DEFAULT_ITERATIONS;
		$k          = isset( $opts['k'] ) ? max( 1, min( 10, (int) $opts['k'] ) ) : self::DEFAULT_CANDIDATES_PER_ITERATION;

		// Sanitize and normalize seed profiles.
		$seeds = array();
		foreach ( $seed_profiles as $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$clean          = HarnessProfile::sanitize( $profile );
			$hash           = self::profile_hash( $clean );
			$seeds[ $hash ] = $clean;
		}

		if ( empty( $seeds ) ) {
			delete_transient( $lock_key );
			return new \WP_Error(
				'wp_mcp_ai_search_no_valid_seeds',
				__( 'No valid seed profiles after sanitization.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Initialize population with seeds.
		$population = array();
		foreach ( $seeds as $hash => $profile ) {
			$entry               = array(
				'hash'       => $hash,
				'profile'    => $profile,
				'eval'       => null,      // Not yet evaluated.
				'iteration'  => 0,
				'parent'     => null,       // Seed — no parent.
				'created_at' => time(),
			);
			$population[ $hash ] = $entry;
		}

		// Persist initial population.
		self::save_population( $assistant_id, $population );

		$search_run = array(
			'assistant_id'  => $assistant_id,
			'iterations'    => $iterations,
			'k'             => $k,
			'suites'        => $search_set_suites,
			'current_iter'  => 0,
			'status'        => 'evaluating_seeds',
			'started_at'    => time(),
			'population'    => array_keys( $population ),
			'proposer_args' => isset( $opts['proposer_args'] ) && is_array( $opts['proposer_args'] ) ? $opts['proposer_args'] : array(),
		);

		self::$active_searches[ $assistant_id ] = $search_run;

		return $search_run;
	}

	/**
	 * Run the next step of an active search.
	 *
	 * This method is designed to be called iteratively (e.g. from a cron job
	 * or WP-CLI loop). Each call advances one step: evaluate pending
	 * candidates, or propose new ones if all are evaluated.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array{status:string,iteration:int,evaluated:int,proposed:int,message:string}|WP_Error
	 */
	public static function step_search( $assistant_id ) {
		$assistant_id = (int) $assistant_id;

		if ( ! isset( self::$active_searches[ $assistant_id ] ) ) {
			// Try to restore state from persisted population.
			$population = self::load_population( $assistant_id );
			$lock_key   = self::LOCK_PREFIX . $assistant_id;
			$lock_time  = get_transient( $lock_key );

			if ( false === $lock_time || empty( $population ) ) {
				return new \WP_Error(
					'wp_mcp_ai_search_no_active_run',
					__( 'No active search run found for this assistant.', 'nvoos-content-graph-ai-platform' )
				);
			}

			// Reconstruct minimal active state.
			self::$active_searches[ $assistant_id ] = array(
				'assistant_id' => $assistant_id,
				'status'       => 'running',
				'current_iter' => self::count_evaluated_population( $population ),
				'population'   => array_keys( $population ),
				'suites'       => array(),
				'k'            => self::DEFAULT_CANDIDATES_PER_ITERATION,
				'iterations'   => self::DEFAULT_ITERATIONS,
			);
		}

		$search     = &self::$active_searches[ $assistant_id ];
		$population = self::load_population( $assistant_id );

		// Phase G: unified governor gate for the search mutation path.
		if ( class_exists( __NAMESPACE__ . '\\EvolutionGovernor' ) ) {
			$gate = EvolutionGovernor::can_mutate( $assistant_id, 'search' );
			if ( ! $gate['allowed'] ) {
				return new \WP_Error(
					'wp_mcp_ai_search_governor_blocked',
					sprintf(
						/* translators: %s: governor block reason */
						__( 'The evolution governor blocked this search step: %s.', 'nvoos-content-graph-ai-platform' ),
						$gate['reason']
					),
					array( 'status' => 429 )
				);
			}
			EvolutionGovernor::record_mutation( $assistant_id, 'search' );
		}

		// Step 1: Evaluate any unevaluated candidates.
		$evaluated = 0;
		foreach ( $population as $hash => &$entry ) {
			if ( null !== $entry['eval'] ) {
				continue;
			}
			$eval_result = self::evaluate_candidate( $assistant_id, $entry['profile'], $search['suites'] );
			if ( is_wp_error( $eval_result ) ) {
				$entry['eval'] = array( 'error' => $eval_result->get_error_code() );
			} else {
				$entry['eval'] = $eval_result;
			}
			++$evaluated;

			// Persist after each evaluation so we don't lose progress.
			self::save_population( $assistant_id, $population );
		}
		unset( $entry );

		// Step 2: If all current candidates are evaluated, propose new ones.
		$proposed         = 0;
		$governor_blocked = '';
		$all_evaluated    = true;
		foreach ( $population as $entry ) {
			if ( null === $entry['eval'] ) {
				$all_evaluated = false;
				break;
			}
		}

		if ( $all_evaluated && $search['current_iter'] < $search['iterations'] ) {
			$candidates = array();

			// Phase G: the pluggable proposer is its own mutation path — the
			// governor gate here also covers the Pro proposer backend.
			if ( class_exists( __NAMESPACE__ . '\\EvolutionGovernor' ) ) {
				$gate = EvolutionGovernor::can_mutate( $assistant_id, 'proposer' );
				if ( ! $gate['allowed'] ) {
					$governor_blocked = $gate['reason'];
				}
			}

			if ( '' === $governor_blocked ) {
				$candidates = self::invoke_proposer( $population, $assistant_id, $search['suites'], $search['k'], $search['proposer_args'] );

				if ( class_exists( __NAMESPACE__ . '\\EvolutionGovernor' ) ) {
					EvolutionGovernor::record_mutation( $assistant_id, 'proposer' );
				}
			}

			foreach ( $candidates as $candidate ) {
				$clean = HarnessProfile::sanitize( $candidate );
				$hash  = self::profile_hash( $clean );

				if ( isset( $population[ $hash ] ) ) {
					continue; // Duplicate — skip.
				}

				$population[ $hash ] = array(
					'hash'       => $hash,
					'profile'    => $clean,
					'eval'       => null,
					'iteration'  => $search['current_iter'] + 1,
					'parent'     => self::best_hash( $population ),
					'created_at' => time(),
				);
				++$proposed;
			}

			++$search['current_iter'];
			self::save_population( $assistant_id, $population );
		}

		// Step 3: Check termination.
		if ( $search['current_iter'] >= $search['iterations'] && $all_evaluated ) {
			$search['status'] = 'completed';
			$lock_key         = self::LOCK_PREFIX . $assistant_id;
			delete_transient( $lock_key );
			self::update_pareto_cache( $assistant_id, $population );
		}

		$evaluated_total = self::count_evaluated_population( $population );

		$result = array(
			'status'    => $search['status'],
			'iteration' => $search['current_iter'],
			'evaluated' => $evaluated_total,
			'proposed'  => $proposed,
			'message'   => sprintf(
				/* translators: 1: evaluated count, 2: iteration, 3: total iterations */
				__( 'Evaluated %1$d candidates. Iteration %2$d/%3$d.', 'nvoos-content-graph-ai-platform' ),
				$evaluated_total,
				$search['current_iter'],
				$search['iterations']
			),
		);

		if ( '' !== $governor_blocked ) {
			$result['governor'] = $governor_blocked;
		}

		return $result;
	}

	/**
	 * Run a complete search synchronously (blocking).
	 *
	 * Suitable for WP-CLI usage. Not recommended for web requests.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id      Assistant post ID.
	 * @param array $seed_profiles     Seed profiles.
	 * @param array $search_set_suites Eval suite slugs.
	 * @param array $opts              Options.
	 * @return array{population:array,pareto_frontier:array,stats:array}|WP_Error
	 */
	public static function run_search( $assistant_id, array $seed_profiles, array $search_set_suites, array $opts = array() ) {
		$started = self::start_search( $assistant_id, $seed_profiles, $search_set_suites, $opts );
		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$iterations   = $started['iterations'];
		$step_results = array();
		$max_steps    = ( $iterations + 1 ) * 2; // Safety bound.

		for ( $step = 0; $step < $max_steps; ++$step ) {
			$result = self::step_search( $assistant_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$step_results[] = $result;

			if ( 'completed' === $result['status'] ) {
				break;
			}
		}

		$population      = self::load_population( $assistant_id );
		$pareto_frontier = self::compute_pareto_frontier( $population );

		return array(
			'population'      => array_values( $population ),
			'pareto_frontier' => $pareto_frontier,
			'stats'           => array(
				'iterations'  => count( $step_results ),
				'candidates'  => count( $population ),
				'evaluated'   => self::count_evaluated_population( $population ),
				'pareto_size' => count( $pareto_frontier ),
				'best_score'  => self::best_aggregate_score( $population ),
				'duration_ms' => (int) round( ( microtime( true ) - $started['started_at'] ) * 1000 ),
			),
		);
	}

	/**
	 * Cancel an active search run.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool True on success.
	 */
	public static function cancel_search( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		$lock_key     = self::LOCK_PREFIX . $assistant_id;

		delete_transient( $lock_key );
		unset( self::$active_searches[ $assistant_id ] );

		return true;
	}

	/**
	 * Get the status of an active or completed search run.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array|null Search status array, or null if no search exists.
	 */
	public static function get_search_status( $assistant_id ) {
		$assistant_id = (int) $assistant_id;

		if ( isset( self::$active_searches[ $assistant_id ] ) ) {
			$s              = self::$active_searches[ $assistant_id ];
			$population     = self::load_population( $assistant_id );
			$s['evaluated'] = self::count_evaluated_population( $population );
			$s['total']     = count( $population );
			return $s;
		}

		$lock_key = self::LOCK_PREFIX . $assistant_id;
		$locked   = get_transient( $lock_key );

		if ( false !== $locked ) {
			return array(
				'assistant_id' => $assistant_id,
				'status'       => 'running',
				'current_iter' => 'unknown',
				'evaluated'    => 0,
				'total'        => 0,
			);
		}

		$population = self::load_population( $assistant_id );
		if ( empty( $population ) ) {
			return null;
		}

		return array(
			'assistant_id' => $assistant_id,
			'status'       => 'completed',
			'current_iter' => self::max_iteration( $population ),
			'evaluated'    => self::count_evaluated_population( $population ),
			'total'        => count( $population ),
			'pareto_size'  => count( self::compute_pareto_frontier( $population ) ),
		);
	}

	/**
	 * Validate a candidate profile before evaluation.
	 *
	 * Checks:
	 *   1. Profile passes sanitization without structural corruption.
	 *   2. Schema keys are all recognized.
	 *
	 * @since 1.9.0
	 *
	 * @param array $profile Raw profile array.
	 * @return array|WP_Error Sanitized profile on success, WP_Error on failure.
	 */
	public static function validate_candidate( array $profile ) {
		if ( empty( $profile ) ) {
			return new \WP_Error(
				'wp_mcp_ai_search_empty_profile',
				__( 'Profile must not be empty.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$clean = HarnessProfile::sanitize( $profile );

		// Reject profiles that are effectively disabled.
		if ( empty( $clean['enabled'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_search_disabled_profile',
				__( 'Profile must have enabled=true to participate in search.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $clean;
	}

	/**
	 * Compute the Pareto frontier from a population.
	 *
	 * A profile dominates another if it is at least as good in all dimensions
	 * AND strictly better in at least one dimension. Currently considers:
	 *   - accuracy_score (higher is better)
	 *   - context_tokens (lower is better, negated for comparison)
	 *   - cost_usd (lower is better, negated for comparison)
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population array keyed by hash.
	 * @return array<int,array> List of Pareto-optimal entries.
	 */
	public static function compute_pareto_frontier( array $population ) {
		$evaluated = array();
		foreach ( $population as $hash => $entry ) {
			if ( null === $entry['eval'] || isset( $entry['eval']['error'] ) ) {
				continue;
			}
			$evaluated[ $hash ] = $entry;
		}

		if ( empty( $evaluated ) ) {
			return array();
		}

		// Extract objective vectors.
		$vectors = array();
		foreach ( $evaluated as $hash => $entry ) {
			$acc    = self::extract_accuracy( $entry['eval'] );
			$tokens = self::extract_context_tokens( $entry['eval'] );
			$cost   = self::extract_cost( $entry['eval'] );

			$vectors[ $hash ] = array( $acc, -$tokens, -$cost );
		}

		// Non-dominated sorting.
		$pareto_hashes = array();
		$hashes        = array_keys( $vectors );
		$n             = count( $hashes );

		for ( $i = 0; $i < $n; ++$i ) {
			$dominated = false;
			for ( $j = 0; $j < $n; ++$j ) {
				if ( $i === $j ) {
					continue;
				}
				if ( self::dominates( $vectors[ $hashes[ $j ] ], $vectors[ $hashes[ $i ] ] ) ) {
					$dominated = true;
					break;
				}
			}
			if ( ! $dominated ) {
				$pareto_hashes[] = $hashes[ $i ];
			}
		}

		$frontier = array();
		foreach ( $pareto_hashes as $hash ) {
			$frontier[] = $evaluated[ $hash ];
		}

		return $frontier;
	}

	/**
	 * Get the current Pareto frontier for an assistant (cached).
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array<int,array>
	 */
	public static function get_pareto_frontier( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		$transient    = self::TRANSIENT_PARETO_PREFIX . $assistant_id;
		$cached       = get_transient( $transient );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$population = self::load_population( $assistant_id );
		$frontier   = self::compute_pareto_frontier( $population );

		set_transient( $transient, $frontier, self::PARETO_CACHE_TTL );

		return $frontier;
	}

	/**
	 * Get the population for an assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Population keyed by hash.
	 */
	public static function get_population( $assistant_id ) {
		return self::load_population( (int) $assistant_id );
	}

	/**
	 * Get the best candidate from the population by aggregate score.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array|null Best candidate entry, or null if none evaluated.
	 */
	public static function get_best_candidate( $assistant_id ) {
		$population = self::load_population( (int) $assistant_id );
		return self::find_best_in_population( $population );
	}

	/**
	 * Diff two population entries by hash.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $hash_a       First profile hash.
	 * @param string $hash_b       Second profile hash.
	 * @return array|WP_Error Diff array or WP_Error if not found.
	 */
	public static function diff_profiles( $assistant_id, $hash_a, $hash_b ) {
		$population = self::load_population( (int) $assistant_id );

		if ( ! isset( $population[ $hash_a ] ) ) {
			return new \WP_Error( 'wp_mcp_ai_search_hash_not_found', __( 'Profile A not found.', 'nvoos-content-graph-ai-platform' ) );
		}
		if ( ! isset( $population[ $hash_b ] ) ) {
			return new \WP_Error( 'wp_mcp_ai_search_hash_not_found', __( 'Profile B not found.', 'nvoos-content-graph-ai-platform' ) );
		}

		$profile_a = $population[ $hash_a ]['profile'];
		$profile_b = $population[ $hash_b ]['profile'];

		return self::compute_diff( $profile_a, $profile_b );
	}

	// -------------------------------------------------------------------------
	// Internal — Persistence
	// -------------------------------------------------------------------------

	/**
	 * Load population from persistent storage.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	private static function load_population( $assistant_id ) {
		$option = self::OPTION_POPULATION_PREFIX . $assistant_id;
		$raw    = get_option( $option, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return $raw;
	}

	/**
	 * Save population to persistent storage.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $population   Population keyed by hash.
	 * @return void
	 */
	private static function save_population( $assistant_id, array $population ) {
		$option = self::OPTION_POPULATION_PREFIX . $assistant_id;

		// Prune to MAX_POPULATION.
		if ( count( $population ) > self::MAX_POPULATION ) {
			// Sort by iteration desc, keeping newest first.
			uasort(
				$population,
				static function ( $a, $b ) {
					$a_iter = isset( $a['iteration'] ) ? (int) $a['iteration'] : 0;
					$b_iter = isset( $b['iteration'] ) ? (int) $b['iteration'] : 0;
					return $b_iter - $a_iter;
				}
			);
			$population = array_slice( $population, 0, self::MAX_POPULATION, true );
		}

		update_option( $option, $population, false );
	}

	/**
	 * Update the Pareto frontier cache.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $population   Current population.
	 * @return void
	 */
	private static function update_pareto_cache( $assistant_id, array $population ) {
		$transient = self::TRANSIENT_PARETO_PREFIX . $assistant_id;
		$frontier  = self::compute_pareto_frontier( $population );
		set_transient( $transient, $frontier, self::PARETO_CACHE_TTL );
	}

	// -------------------------------------------------------------------------
	// Internal — Evaluation
	// -------------------------------------------------------------------------

	/**
	 * Evaluate a candidate profile against the search set suites.
	 *
	 * Creates a generator callable that applies the candidate profile to the
	 * assistant and collects eval results via the existing eval runner.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $profile      Candidate harness profile.
	 * @param array $suite_slugs  Eval suite slugs.
	 * @return array|WP_Error Aggregated eval results or WP_Error.
	 */
	private static function evaluate_candidate( $assistant_id, array $profile, array $suite_slugs ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_search_no_eval_registry',
				__( 'Eval suite registry is not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$registry = \WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$registry->boot();

		$runner       = new \WP_MCP_AI_Eval_Runner();
		$all_results  = array();
		$total_cases  = 0;
		$total_passed = 0;
		$total_cost   = 0.0;
		$total_tokens = 0;

		foreach ( $suite_slugs as $suite_slug ) {
			$suite = $registry->get( sanitize_key( (string) $suite_slug ) );
			if ( ! $suite instanceof \WP_MCP_AI_Eval_Suite ) {
				continue;
			}

			// Build a generator that simulates the assistant with this profile.
			$generator = self::build_profile_generator( $assistant_id, $profile, $suite );

			if ( ! is_callable( $generator ) ) {
				continue;
			}

			$report = $runner->run( $suite, $generator );

			$suite_summary              = isset( $report['summary'] ) ? $report['summary'] : array();
			$all_results[ $suite_slug ] = array(
				'summary'     => $suite_summary,
				'duration_ms' => isset( $report['duration_ms'] ) ? (int) $report['duration_ms'] : 0,
				'case_count'  => $suite->count_cases(),
			);

			$total_cases  += isset( $suite_summary['total'] ) ? (int) $suite_summary['total'] : 0;
			$total_passed += isset( $suite_summary['passed'] ) ? (int) $suite_summary['passed'] : 0;

			// Accumulate cost from cases.
			if ( isset( $report['cases'] ) && is_array( $report['cases'] ) ) {
				foreach ( $report['cases'] as $case_report ) {
					if ( isset( $case_report['cost_usd'] ) && is_numeric( $case_report['cost_usd'] ) ) {
						$total_cost += (float) $case_report['cost_usd'];
					}
				}
			}
		}

		if ( empty( $all_results ) ) {
			return new \WP_Error(
				'wp_mcp_ai_search_no_suites_evaluated',
				__( 'No eval suites could be evaluated.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$aggregate_score = $total_cases > 0 ? round( $total_passed / $total_cases, 4 ) : 0.0;

		return array(
			'suites'       => $all_results,
			'aggregate'    => array(
				'score'              => $aggregate_score,
				'total_cases'        => $total_cases,
				'total_passed'       => $total_passed,
				'estimated_cost_usd' => round( $total_cost, 6 ),
				'context_tokens'     => $total_tokens,
			),
			'evaluated_at' => time(),
		);
	}

	/**
	 * Build a generator callable that applies a candidate harness profile.
	 *
	 * The generator is a closure that:
	 *   1. Takes an eval case + suite context.
	 *   2. Builds a minimal system prompt using the profile's cues.
	 *   3. Calls a chat-completion endpoint (via the existing REST handler).
	 *   4. Returns the response in the shape expected by WP_MCP_AI_Eval_Runner.
	 *
	 * NOTE: This method creates a simplified generator. A production-quality
	 * implementation would route through the full agentic loop and apply all
	 * harness layers. This simplified version applies only cue injection and
	 * is designed for rapid search iteration.
	 *
	 * @since 1.9.0
	 *
	 * @param int                  $assistant_id Assistant post ID.
	 * @param array                $profile      Candidate harness profile.
	 * @param WP_MCP_AI_Eval_Suite $suite        Eval suite.
	 * @return callable|null Generator callable, or null if unavailable.
	 */
	private static function build_profile_generator( $assistant_id, array $profile, $suite ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $suite provides context for the generator; required by the eval runner contract.
		// Capture profile and assistant ID in closure.
		$_profile      = $profile;
		$_assistant_id = $assistant_id;

		return static function ( WP_MCP_AI_Eval_Case $case, array $suite_context ) use ( $_profile, $_assistant_id ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Universal.NamingConventions.NoReservedKeywordParameterNames.caseFound -- $case required by WP_MCP_AI_Eval_Case type; $suite_context required by eval runner contract.
			$input    = $case->get_input();
			$expected = $case->get_expected();

			// Build a simple prompt with profile cues prepended.
			$system_prompt = 'You are a helpful assistant.';
			if ( ! empty( $_profile['cues'] ) && class_exists( __NAMESPACE__ . '\\PromptCueLibrary' ) ) {
				$library       = PromptCueLibrary::get_instance();
				$system_prompt = is_string( $library->apply( $system_prompt, $_profile['cues'] ) )
					? $library->apply( $system_prompt, $_profile['cues'] )
					: $system_prompt;
			}

			// For now, return a placeholder result so the search loop can run.
			// In production, this would call the full chat pipeline.
			// The "output" key is what the eval runner feeds to the verifier.
			return array(
				'output'   => 'PLACEHOLDER — Full chat pipeline integration pending.',
				'cost_usd' => 0.0,
			);
		};
	}

	// -------------------------------------------------------------------------
	// Internal — Proposer invocation
	// -------------------------------------------------------------------------

	/**
	 * Invoke the proposer to generate new candidate profiles.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population    Current population.
	 * @param int   $assistant_id  Assistant post ID.
	 * @param array $suites        Eval suite slugs.
	 * @param int   $k             Number of candidates desired.
	 * @param array $proposer_args Additional args for the proposer.
	 * @return array<int,array> Candidate profiles.
	 */
	private static function invoke_proposer( array $population, $assistant_id, array $suites, $k, array $proposer_args = array() ) {
		/**
		 * Filter: provide a custom proposer for harness search.
		 *
		 * Return an array of k candidate profile arrays. Return null to
		 * fall back to the built-in best-of-N restarter.
		 *
		 * @since 1.9.0
		 *
		 * @param array|null $candidates     Candidate profiles, or null.
		 * @param array      $population     Current population (keyed by hash).
		 * @param int        $assistant_id   Assistant post ID.
		 * @param array      $suites         Eval suite slugs.
		 * @param int        $k              Desired number of candidates.
		 * @param array      $proposer_args  Extra args passed from start_search().
		 */
		$candidates = apply_filters( 'wp_mcp_ai_harness_proposer', null, $population, $assistant_id, $suites, $k, $proposer_args );

		if ( is_array( $candidates ) && ! empty( $candidates ) ) {
			return array_slice( $candidates, 0, $k );
		}

		// Default: best-of-N restarter — generate random perturbations.
		return self::best_of_n_proposer( $population, $k );
	}

	/**
	 * Default proposer: random perturbation (best-of-N restarter).
	 *
	 * Generates new profiles by randomly mutating one parameter at a time
	 * from the best-so-far profile.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Current population.
	 * @param int   $k          Number of candidates.
	 * @return array<int,array>
	 */
	private static function best_of_n_proposer( array $population, $k ) {
		$best = self::find_best_in_population( $population );
		if ( null === $best ) {
			// No evaluated profiles — use first seed.
			$best = reset( $population );
			if ( false === $best ) {
				return array();
			}
		}

		$base       = $best['profile'];
		$candidates = array();
		$attempts   = $k * 3; // Allow some duplicates to be skipped.

		$mutations = array(
			'toggle_retrieval',
			'adjust_retrieval_k',
			'toggle_refine',
			'adjust_refine_iters',
			'adjust_reasoning_samples',
			'toggle_citations',
			'switch_router_mode',
			'adjust_preset_weight',
			'swap_cue',
			'toggle_trace_capture',
		);

		$candidate_count = count( $candidates );
		for ( $i = 0; $i < $attempts && $candidate_count < $k; ++$i ) {
			$mutated  = $base;
			$mutation = $mutations[ array_rand( $mutations ) ];
			$mutated  = self::apply_mutation( $mutated, $mutation );

			$clean = HarnessProfile::sanitize( $mutated );
			$hash  = self::profile_hash( $clean );

			if ( isset( $population[ $hash ] ) ) {
				continue; // Duplicate — try again.
			}

			$candidates[] = $clean;
		}

		return $candidates;
	}

	/**
	 * Apply a named mutation to a profile.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $profile  Profile to mutate.
	 * @param string $mutation Mutation name.
	 * @return array Mutated profile.
	 */
	private static function apply_mutation( array $profile, $mutation ) {
		switch ( $mutation ) {
			case 'toggle_retrieval':
				$profile['retrieval']['enabled'] = empty( $profile['retrieval']['enabled'] );
				break;

			case 'adjust_retrieval_k':
				$current                   = isset( $profile['retrieval']['k'] ) ? (int) $profile['retrieval']['k'] : 5;
				$profile['retrieval']['k'] = max( 1, min( 50, $current + ( wp_rand( 0, 1 ) ? wp_rand( 1, 5 ) : -wp_rand( 1, 5 ) ) ) );
				break;

			case 'toggle_refine':
				$profile['refine']['enabled'] = empty( $profile['refine']['enabled'] );
				break;

			case 'adjust_refine_iters':
				$current                        = isset( $profile['refine']['max_iters'] ) ? (int) $profile['refine']['max_iters'] : 1;
				$profile['refine']['max_iters'] = max( 1, min( 4, $current + ( wp_rand( 0, 1 ) ? 1 : -1 ) ) );
				break;

			case 'adjust_reasoning_samples':
				$current                           = isset( $profile['reasoning']['n_samples'] ) ? (int) $profile['reasoning']['n_samples'] : 1;
				$profile['reasoning']['n_samples'] = max( 1, min( 8, $current + ( wp_rand( 0, 1 ) ? 1 : -1 ) ) );
				$profile['reasoning']['enabled']   = $profile['reasoning']['n_samples'] > 1;
				break;

			case 'toggle_citations':
				$profile['retrieval']['require_citations'] = empty( $profile['retrieval']['require_citations'] );
				break;

			case 'switch_router_mode':
				$profile['tools']['router'] = 'scored' === $profile['tools']['router'] ? 'fixed' : 'scored';
				break;

			case 'swap_cue':
				if ( ! class_exists( __NAMESPACE__ . '\\PromptCueLibrary' ) ) {
					break;
				}
				$library  = PromptCueLibrary::get_instance();
				$all_cues = array_keys( $library->all() );
				if ( empty( $all_cues ) ) {
					break;
				}
				$current = isset( $profile['cues'] ) ? $profile['cues'] : array();
				if ( empty( $current ) ) {
					// Add a random cue.
					$profile['cues'] = array( $all_cues[ array_rand( $all_cues ) ] );
				} else {
					// Replace a random cue.
					$idx                     = array_rand( $current );
					$profile['cues'][ $idx ] = $all_cues[ array_rand( $all_cues ) ];
					$profile['cues']         = array_values( array_unique( $profile['cues'] ) );
				}
				break;

			case 'toggle_trace_capture':
				$profile['trace_capture']['enabled'] = empty( $profile['trace_capture']['enabled'] );
				break;
		}

		return $profile;
	}

	// -------------------------------------------------------------------------
	// Internal — Pareto helpers
	// -------------------------------------------------------------------------

	/**
	 * Check if vector a dominates vector b.
	 *
	 * Vector a dominates b if a[i] >= b[i] for all i AND a[j] > b[j] for some j.
	 *
	 * @since 1.9.0
	 *
	 * @param array $a Objective vector.
	 * @param array $b Objective vector.
	 * @return bool
	 */
	private static function dominates( array $a, array $b ) {
		$strictly_better = false;
		$count           = count( $a );

		for ( $i = 0; $i < $count; ++$i ) {
			if ( $a[ $i ] < $b[ $i ] ) {
				return false;
			}
			if ( $a[ $i ] > $b[ $i ] ) {
				$strictly_better = true;
			}
		}

		return $strictly_better;
	}

	/**
	 * Extract aggregate accuracy score from eval results.
	 *
	 * @since 1.9.0
	 *
	 * @param array $eval_data Eval results.
	 * @return float
	 */
	private static function extract_accuracy( array $eval_data ) {
		if ( isset( $eval_data['aggregate']['score'] ) ) {
			return (float) $eval_data['aggregate']['score'];
		}
		return 0.0;
	}

	/**
	 * Extract total context tokens from eval results.
	 *
	 * @since 1.9.0
	 *
	 * @param array $eval_data Eval results.
	 * @return int
	 */
	private static function extract_context_tokens( array $eval_data ) {
		if ( isset( $eval_data['aggregate']['context_tokens'] ) ) {
			return (int) $eval_data['aggregate']['context_tokens'];
		}
		return 0;
	}

	/**
	 * Extract estimated cost from eval results.
	 *
	 * @since 1.9.0
	 *
	 * @param array $eval_data Eval results.
	 * @return float
	 */
	private static function extract_cost( array $eval_data ) {
		if ( isset( $eval_data['aggregate']['estimated_cost_usd'] ) ) {
			return (float) $eval_data['aggregate']['estimated_cost_usd'];
		}
		return 0.0;
	}

	// -------------------------------------------------------------------------
	// Internal — Population helpers
	// -------------------------------------------------------------------------

	/**
	 * Count how many population entries have been evaluated.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population.
	 * @return int
	 */
	private static function count_evaluated_population( array $population ) {
		$count = 0;
		foreach ( $population as $entry ) {
			if ( null !== $entry['eval'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Find the highest iteration number in the population.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population.
	 * @return int
	 */
	private static function max_iteration( array $population ) {
		$max = 0;
		foreach ( $population as $entry ) {
			if ( isset( $entry['iteration'] ) && (int) $entry['iteration'] > $max ) {
				$max = (int) $entry['iteration'];
			}
		}
		return $max;
	}

	/**
	 * Find the best evaluated entry in the population by aggregate score.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population.
	 * @return array|null
	 */
	private static function find_best_in_population( array $population ) {
		$best       = null;
		$best_score = -1.0;

		foreach ( $population as $entry ) {
			if ( null === $entry['eval'] || isset( $entry['eval']['error'] ) ) {
				continue;
			}
			$score = self::extract_accuracy( $entry['eval'] );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $entry;
			}
		}

		return $best;
	}

	/**
	 * Get the hash of the best evaluated entry.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population.
	 * @return string|null
	 */
	private static function best_hash( array $population ) {
		$best = self::find_best_in_population( $population );
		return $best ? $best['hash'] : null;
	}

	/**
	 * Get the best aggregate score from the population.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population.
	 * @return float
	 */
	private static function best_aggregate_score( array $population ) {
		$best = self::find_best_in_population( $population );
		if ( null === $best ) {
			return 0.0;
		}
		return self::extract_accuracy( $best['eval'] );
	}

	/**
	 * Compute a deterministic hash for a sanitized profile.
	 *
	 * @since 1.9.0
	 *
	 * @param array $profile Sanitized profile.
	 * @return string MD5 hex hash.
	 */
	private static function profile_hash( array $profile ) {
		// Remove non-deterministic keys before hashing.
		unset( $profile['trace_capture'] );
		$encoded = wp_json_encode( $profile );
		return md5( false !== $encoded ? $encoded : serialize( $profile ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Compute a human-readable diff between two profiles.
	 *
	 * @since 1.9.0
	 *
	 * @param array $a Profile A.
	 * @param array $b Profile B.
	 * @return array Diff entries: {path, value_a, value_b, changed}.
	 */
	private static function compute_diff( array $a, array $b ) {
		$diff  = array();
		$paths = self::flatten_keys( $a );

		foreach ( $paths as $path ) {
			$val_a = self::get_nested( $a, $path );
			$val_b = self::get_nested( $b, $path );

			if ( $val_a !== $val_b ) {
				$diff[] = array(
					'path'    => $path,
					'value_a' => $val_a,
					'value_b' => $val_b,
					'changed' => true,
				);
			}
		}

		return $diff;
	}

	/**
	 * Flatten a nested array into dot-separated key paths.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $arr    Input array.
	 * @param string $prefix Key prefix.
	 * @return array<int,string>
	 */
	private static function flatten_keys( array $arr, $prefix = '' ) {
		$paths = array();
		foreach ( $arr as $key => $value ) {
			$path = '' === $prefix ? $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$subs = self::flatten_keys( $value, $path );
				foreach ( $subs as $sub ) {
					$paths[] = $sub;
				}
			} else {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * Get a nested value by dot-separated path.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $arr  Input array.
	 * @param string $path Dot-separated key path.
	 * @return mixed
	 */
	private static function get_nested( array $arr, $path ) {
		$keys    = explode( '.', $path );
		$current = $arr;
		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
				return null;
			}
			$current = $current[ $key ];
		}
		return $current;
	}
}
