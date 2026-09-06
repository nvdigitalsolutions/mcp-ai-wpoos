<?php
/**
 * Artifact Population — site-global archive of scored artifact variants.
 *
 * The population is the archive of evaluated artifact variants (prompts,
 * roles, skills, memory) across all assistants on the site, together with
 * their lineage. It is the "organism pool" of the Darwinian loop: variants
 * compete, parents are selected by fitness-weighted sampling, and children
 * record their ancestry so discoveries in one lineage can be tracked (and,
 * in Phase D, transferred via crossover).
 *
 * Storage: option `wp_mcp_ai_artifact_population_global`, capped at
 * MAX_POPULATION entries (most recently seen kept, FIFO drop otherwise),
 * mirroring the harness-profile population pattern.
 *
 * Selection follows Imbue's Darwinian Evolver sampling weight:
 *   weight = max( min_weight,
 *                 sigmoid( sharpness × ( score − midpoint ) )
 *                 × ( 1 + novelty_weight / ( 1 + children_count ) ) )
 * where the sigmoid midpoint is recalculated every sampling call as the Nth
 * percentile of the current population's scores. The dynamic midpoint keeps
 * selection pressure in the sigmoid's high-gradient range as the population
 * improves, and the strictly positive minimum weight lets low scorers get
 * sampled occasionally (escape from local maxima).
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Imbue (2026). "LLM-based Evolution as a Universal Optimizer."
 *   https://imbue.com/blog/2026-02-27-darwinian-evolver
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Population class.
 *
 * @since 1.9.0
 */
class ArtifactPopulation {

	/**
	 * Option key for the global artifact population.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const OPTION_KEY = 'wp_mcp_ai_artifact_population_global';

	/**
	 * Maximum number of artifacts in the global population.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_POPULATION = 500;

	/**
	 * Allowed artifact types.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const ALLOWED_TYPES = array( 'prompt', 'role', 'skill', 'memory', 'profile' );

	/**
	 * Default sigmoid sharpness (5–20 typical; higher = more exploitation).
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_SHARPNESS = 10.0;

	/**
	 * Default percentile used for the dynamic sigmoid midpoint.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_PERCENTILE = 50.0;

	/**
	 * Default novelty weight (higher = stronger preference for un-branched
	 * organisms; 0 disables the bonus).
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_NOVELTY_WEIGHT = 2.0;

	/**
	 * Default minimum sampling weight — strictly positive so even the worst
	 * organism is sampled occasionally.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_MIN_WEIGHT = 0.01;

	/**
	 * Maximum number of score samples retained per artifact for aggregation.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_SCORE_HISTORY = 10;

	/**
	 * Default maximum number of population entries sourced from any single
	 * assistant (VaG: bounded pools resist skill contamination).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_PER_ASSISTANT_MAX = 25;

	/**
	 * Archive a scored artifact variant into the global population.
	 *
	 * Content-addressed: the hash derives from the artifact type + payload,
	 * so identical content merges into one entry (with merged sources and
	 * aggregated scores) regardless of which assistant produced it. New
	 * entries record their parent linkage.
	 *
	 * @since 1.9.0
	 *
	 * @param string      $artifact_type       Artifact type (prompt|role|skill|memory|profile).
	 * @param string      $artifact_id         Artifact identifier (e.g. assistant ID as string).
	 * @param mixed       $artifact            Artifact payload (any JSON-serializable value).
	 * @param float       $score               Fitness score for this evaluation.
	 * @param array       $eval_payload        Optional eval/verification summary payload.
	 * @param string|null $parent_hash         Parent artifact hash, or null for a seed.
	 * @param int         $source_assistant_id Assistant that produced this evaluation (0 = none).
	 * @param int         $timestamp           Optional recency timestamp (0 = now). Useful for
	 *                                         imports/replays and deterministic tests.
	 * @return string|WP_Error Entry hash on success, WP_Error for invalid input.
	 */
	public static function archive( $artifact_type, $artifact_id, $artifact, $score, array $eval_payload = array(), $parent_hash = null, $source_assistant_id = 0, $timestamp = 0 ) {
		$artifact_type = sanitize_key( (string) $artifact_type );
		if ( ! in_array( $artifact_type, self::ALLOWED_TYPES, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_population_invalid_type',
				sprintf(
					/* translators: %s: invalid artifact type */
					__( 'Invalid artifact type "%s".', 'nvoos-content-graph-ai-platform' ),
					$artifact_type
				)
			);
		}

		$artifact_id         = sanitize_key( (string) $artifact_id );
		$score               = (float) $score;
		$source_assistant_id = absint( $source_assistant_id );
		$hash                = md5(
			wp_json_encode(
				array(
					'artifact_type' => $artifact_type,
					'artifact'      => $artifact,
				)
			)
		);

		$population = self::load();
		$now        = (int) $timestamp > 0 ? (int) $timestamp : time();

		if ( isset( $population[ $hash ] ) ) {
			// Re-seen content: aggregate scores, merge sources, refresh recency.
			$entry = $population[ $hash ];

			$history   = isset( $entry['score_history'] ) && is_array( $entry['score_history'] ) ? $entry['score_history'] : array();
			$history[] = $score;
			if ( count( $history ) > self::MAX_SCORE_HISTORY ) {
				$history = array_slice( $history, -1 * self::MAX_SCORE_HISTORY );
			}

			$entry['score_history'] = $history;
			$entry['score']         = self::mean( $history );
			$entry['eval']          = $eval_payload;
			$entry['last_seen_at']  = $now;

			// Keep the first non-empty artifact ID.
			if ( '' === ( isset( $entry['artifact_id'] ) ? (string) $entry['artifact_id'] : '' ) && '' !== $artifact_id ) {
				$entry['artifact_id'] = $artifact_id;
			}

			if ( $source_assistant_id > 0 ) {
				$entry['sources'][] = $source_assistant_id;
				$entry['sources']   = array_values( array_unique( $entry['sources'] ) );
			}

			$population[ $hash ] = $entry;
		} else {
			$entry = array(
				'hash'           => $hash,
				'artifact_type'  => $artifact_type,
				'artifact_id'    => $artifact_id,
				'artifact'       => $artifact,
				'score'          => $score,
				'score_history'  => array( $score ),
				'eval'           => $eval_payload,
				'parent'         => null,
				'children'       => array(),
				'children_count' => 0,
				'sources'        => $source_assistant_id > 0 ? array( $source_assistant_id ) : array(),
				'created_at'     => $now,
				'last_seen_at'   => $now,
			);

			// Parent linkage for new entries only.
			if ( null !== $parent_hash ) {
				$parent_hash = sanitize_key( (string) $parent_hash );
				if ( '' !== $parent_hash && $parent_hash !== $hash && isset( $population[ $parent_hash ] ) ) {
					$entry['parent'] = $parent_hash;
					if ( ! in_array( $hash, $population[ $parent_hash ]['children'], true ) ) {
						$population[ $parent_hash ]['children'][] = $hash;
					}
					$population[ $parent_hash ]['children_count'] = count( $population[ $parent_hash ]['children'] );
				}
			}

			$population[ $hash ] = $entry;
		}

		self::prune( $population );
		self::save( $population );

		return $hash;
	}

	/**
	 * Get the entire global population, optionally filtered.
	 *
	 * @since 1.9.0
	 *
	 * @param array $filters Optional filters: `artifact_type`, `artifact_id`,
	 *                       `min_score`, `source_assistant_id`.
	 * @return array<int,array> Population entries keyed by hash.
	 */
	public static function get_population( array $filters = array() ) {
		$population = self::load();

		$type   = isset( $filters['artifact_type'] ) ? sanitize_key( (string) $filters['artifact_type'] ) : '';
		$id     = isset( $filters['artifact_id'] ) ? sanitize_key( (string) $filters['artifact_id'] ) : '';
		$min    = isset( $filters['min_score'] ) ? (float) $filters['min_score'] : null;
		$source = isset( $filters['source_assistant_id'] ) ? absint( $filters['source_assistant_id'] ) : 0;

		$out = array();
		foreach ( $population as $hash => $entry ) {
			if ( '' !== $type && ( isset( $entry['artifact_type'] ) ? $entry['artifact_type'] : '' ) !== $type ) {
				continue;
			}
			if ( '' !== $id && ( isset( $entry['artifact_id'] ) ? (string) $entry['artifact_id'] : '' ) !== $id ) {
				continue;
			}
			if ( null !== $min && (float) ( isset( $entry['score'] ) ? $entry['score'] : 0.0 ) < $min ) {
				continue;
			}
			if ( $source > 0 && ( empty( $entry['sources'] ) || ! in_array( $source, $entry['sources'], true ) ) ) {
				continue;
			}
			$out[ $hash ] = $entry;
		}

		return $out;
	}

	/**
	 * Get a single population entry by hash.
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash Entry hash.
	 * @return array|null Entry array, or null when absent.
	 */
	public static function get_entry( $hash ) {
		$hash       = sanitize_key( (string) $hash );
		$population = self::load();

		return isset( $population[ $hash ] ) ? $population[ $hash ] : null;
	}

	/**
	 * Remove a single entry by hash.
	 *
	 * @since 1.9.0
	 *
	 * @param string $hash Entry hash.
	 * @return bool True when an entry was removed.
	 */
	public static function remove( $hash ) {
		$hash       = sanitize_key( (string) $hash );
		$population = self::load();

		if ( ! isset( $population[ $hash ] ) ) {
			return false;
		}

		unset( $population[ $hash ] );
		self::save( $population );

		return true;
	}

	/**
	 * Drop the whole population (tests / uninstall).
	 *
	 * @since 1.9.0
	 * @return bool True when the option was deleted.
	 */
	public static function clear() {
		return (bool) delete_option( self::OPTION_KEY );
	}

	/**
	 * Get summary statistics for a population scope.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param string $artifact_id   Optional artifact identifier.
	 * @return array Stats: count, mean_score, max_score, min_score, midpoint.
	 */
	public static function get_stats( $artifact_type, $artifact_id = '' ) {
		$filters = array( 'artifact_type' => $artifact_type );
		if ( '' !== $artifact_id ) {
			$filters['artifact_id'] = $artifact_id;
		}

		$entries = self::get_population( $filters );
		$scores  = array();
		foreach ( $entries as $entry ) {
			$scores[] = isset( $entry['score'] ) ? (float) $entry['score'] : 0.0;
		}

		$count = count( $scores );

		return array(
			'count'      => $count,
			'mean_score' => $count > 0 ? self::mean( $scores ) : 0.0,
			'max_score'  => $count > 0 ? max( $scores ) : 0.0,
			'min_score'  => $count > 0 ? min( $scores ) : 0.0,
			'midpoint'   => self::get_dynamic_midpoint( $entries ),
		);
	}

	/**
	 * Enforce the per-assistant population cap by evicting the lowest-scored
	 * entries sourced from that assistant.
	 *
	 * Pre-commit pool bounding (VaG): a bounded pool resists the
	 * capability-contamination phase transition. Eviction is deterministic —
	 * sorted by score ascending (ties broken by oldest `created_at` first) —
	 * and only ever touches entries sourced from the given assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Source assistant ID.
	 * @return int Number of entries evicted (0 when under the cap).
	 */
	public static function enforce_per_assistant_cap( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( $assistant_id <= 0 ) {
			return 0;
		}

		/**
		 * Filters the maximum population entries per source assistant.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max Maximum entries per assistant. Default 25.
		 */
		$max = (int) apply_filters( 'wp_mcp_ai_artifact_population_per_assistant_max', self::DEFAULT_PER_ASSISTANT_MAX );
		$max = max( 1, $max );

		$population = self::load();

		$owned = array();
		foreach ( $population as $hash => $entry ) {
			if ( ! empty( $entry['sources'] ) && in_array( $assistant_id, $entry['sources'], true ) ) {
				$owned[ $hash ] = $entry;
			}
		}

		$excess = count( $owned ) - $max;
		if ( $excess <= 0 ) {
			return 0;
		}

		// Lowest score first; ties → oldest first.
		uasort(
			$owned,
			static function ( $a, $b ) {
				$a_score = isset( $a['score'] ) ? (float) $a['score'] : 0.0;
				$b_score = isset( $b['score'] ) ? (float) $b['score'] : 0.0;
				if ( $a_score !== $b_score ) {
					return $a_score <=> $b_score;
				}
				$a_created = isset( $a['created_at'] ) ? (int) $a['created_at'] : 0;
				$b_created = isset( $b['created_at'] ) ? (int) $b['created_at'] : 0;
				return $a_created <=> $b_created;
			}
		);

		$evicted = 0;
		foreach ( array_keys( $owned ) as $hash ) {
			if ( $evicted >= $excess ) {
				break;
			}
			unset( $population[ $hash ] );
			++$evicted;
		}

		self::save( $population );

		return $evicted;
	}

	// -------------------------------------------------------------------------
	// Selection
	// -------------------------------------------------------------------------

	/**
	 * Compute Imbue-style sampling weights for a set of entries.
	 *
	 * Public so callers (and tests) can inspect the weight properties without
	 * running a stochastic sample.
	 *
	 * @since 1.9.0
	 *
	 * @param array $entries Population entries keyed by hash.
	 * @return array<string,float> Weights keyed by hash.
	 */
	public static function compute_weights( $entries ) {
		$sharpness      = (float) apply_filters( 'wp_mcp_ai_artifact_population_sharpness', self::DEFAULT_SHARPNESS );
		$percentile     = (float) apply_filters( 'wp_mcp_ai_artifact_population_percentile', self::DEFAULT_PERCENTILE );
		$novelty_weight = (float) apply_filters( 'wp_mcp_ai_artifact_population_novelty_weight', self::DEFAULT_NOVELTY_WEIGHT );
		$min_weight     = (float) apply_filters( 'wp_mcp_ai_artifact_population_min_weight', self::DEFAULT_MIN_WEIGHT );

		$sharpness      = max( 0.1, $sharpness );
		$percentile     = max( 0.0, min( 100.0, $percentile ) );
		$novelty_weight = max( 0.0, $novelty_weight );
		$min_weight     = max( 0.0001, $min_weight );

		$midpoint = self::get_dynamic_midpoint( $entries, $percentile );
		$weights  = array();

		foreach ( $entries as $hash => $entry ) {
			$score    = isset( $entry['score'] ) ? (float) $entry['score'] : 0.0;
			$children = isset( $entry['children_count'] ) ? (int) $entry['children_count'] : 0;

			// Sigmoid-scaled fitness around the dynamic midpoint.
			$fitness = 1.0 / ( 1.0 + exp( -1.0 * $sharpness * ( $score - $midpoint ) ) );
			// Novelty bonus: wears off as the entry gains children.
			$novelty          = 1.0 + ( $novelty_weight / ( 1.0 + $children ) );
			$weights[ $hash ] = max( $min_weight, $fitness * $novelty );
		}

		return $weights;
	}

	/**
	 * Sample up to `$k` parents via weighted sampling without replacement.
	 *
	 * The RNG is injectable (`options.rng` callable returning floats in
	 * [0,1)) so tests can be deterministic; the default uses wp_rand().
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type to sample from.
	 * @param string $artifact_id   Optional artifact identifier scope.
	 * @param int    $k             Number of parents to sample (1-20).
	 * @param array  $options       Options: `rng` (callable).
	 * @return array<int,array> Sampled entries (with their `weight` attached).
	 */
	public static function sample_parents( $artifact_type, $artifact_id = '', $k = 1, array $options = array() ) {
		$entries = self::get_population(
			array(
				'artifact_type' => $artifact_type,
				'artifact_id'   => $artifact_id,
			)
		);

		if ( empty( $entries ) ) {
			return array();
		}

		$weights = self::compute_weights( $entries );

		/**
		 * Filters the sampling weights before parent selection.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string,float> $weights       Weights keyed by hash.
		 * @param array               $entries       Population entries keyed by hash.
		 * @param string              $artifact_type Artifact type.
		 * @param string              $artifact_id   Artifact identifier scope.
		 */
		$weights = (array) apply_filters( 'wp_mcp_ai_artifact_population_weights', $weights, $entries, $artifact_type, $artifact_id );

		// Align the filtered weight map with the sampled entries.
		$aligned = array();
		foreach ( $entries as $hash => $entry ) {
			$aligned[ $hash ] = isset( $weights[ $hash ] ) && is_numeric( $weights[ $hash ] )
				? max( 0.0001, (float) $weights[ $hash ] )
				: self::DEFAULT_MIN_WEIGHT;
		}

		$k = max( 1, min( 20, (int) $k ) );
		$k = min( $k, count( $entries ) );

		$rng = isset( $options['rng'] ) && is_callable( $options['rng'] ) ? $options['rng'] : null;

		$picked = array();
		for ( $i = 0; $i < $k; $i++ ) {
			$total = array_sum( $aligned );
			if ( $total <= 0.0 ) {
				break;
			}

			$roll   = $rng ? max( 0.0, min( 1.0, (float) call_user_func( $rng ) ) ) : ( wp_rand( 0, 1000000 ) / 1000000 );
			$target = $roll * $total;

			$cumulative = 0.0;
			$chosen     = null;
			foreach ( $aligned as $hash => $weight ) {
				$cumulative += $weight;
				if ( $cumulative >= $target ) {
					$chosen = $hash;
					break;
				}
			}

			// Float rounding safety: fall back to the last candidate.
			if ( null === $chosen ) {
				$keys   = array_keys( $aligned );
				$chosen = end( $keys );
			}

			$entry           = $entries[ $chosen ];
			$entry['weight'] = $aligned[ $chosen ];
			$picked[]        = $entry;

			unset( $aligned[ $chosen ], $entries[ $chosen ] );
		}

		return $picked;
	}

	/**
	 * Compute the Nth-percentile score of a set of entries (nearest-rank).
	 *
	 * @since 1.9.0
	 *
	 * @param array $entries    Population entries.
	 * @param float $percentile Percentile (0-100). Null uses the filter default.
	 * @return float Percentile score (0.0 for empty populations).
	 */
	public static function get_dynamic_midpoint( $entries, $percentile = null ) {
		if ( null === $percentile ) {
			$percentile = (float) apply_filters( 'wp_mcp_ai_artifact_population_percentile', self::DEFAULT_PERCENTILE );
		}
		$percentile = max( 0.0, min( 100.0, (float) $percentile ) );

		$scores = array();
		foreach ( $entries as $entry ) {
			$scores[] = isset( $entry['score'] ) ? (float) $entry['score'] : 0.0;
		}

		if ( empty( $scores ) ) {
			return 0.0;
		}

		sort( $scores );

		$index = max( 0, (int) ceil( $percentile / 100.0 * count( $scores ) ) - 1 );

		return $scores[ $index ];
	}

	// -------------------------------------------------------------------------
	// Storage helpers
	// -------------------------------------------------------------------------

	/**
	 * Load the population option.
	 *
	 * @since 1.9.0
	 * @return array Population entries keyed by hash.
	 */
	private static function load() {
		$population = get_option( self::OPTION_KEY, array() );

		return is_array( $population ) ? $population : array();
	}

	/**
	 * Persist the population option.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population entries.
	 * @return void
	 */
	private static function save( $population ) {
		update_option( self::OPTION_KEY, $population, false );
	}

	/**
	 * Enforce the population cap, keeping the most recently seen entries.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population entries (by reference).
	 * @return void
	 */
	private static function prune( &$population ) {
		/**
		 * Filters the maximum artifact population size.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max Maximum entries. Default MAX_POPULATION (500).
		 */
		$max = (int) apply_filters( 'wp_mcp_ai_artifact_population_max', self::MAX_POPULATION );
		$max = max( 1, $max );

		if ( count( $population ) <= $max ) {
			return;
		}

		uasort(
			$population,
			static function ( $a, $b ) {
				$a_time = isset( $a['last_seen_at'] ) ? (int) $a['last_seen_at'] : 0;
				$b_time = isset( $b['last_seen_at'] ) ? (int) $b['last_seen_at'] : 0;
				return $b_time - $a_time;
			}
		);

		$population = array_slice( $population, 0, $max, true );
	}

	/**
	 * Arithmetic mean of numeric values.
	 *
	 * @since 1.9.0
	 *
	 * @param array $values Numeric values.
	 * @return float Mean (0.0 for empty input).
	 */
	private static function mean( $values ) {
		$values = array_values( array_filter( $values, 'is_numeric' ) );
		if ( empty( $values ) ) {
			return 0.0;
		}

		return (float) ( array_sum( $values ) / count( $values ) );
	}
}
