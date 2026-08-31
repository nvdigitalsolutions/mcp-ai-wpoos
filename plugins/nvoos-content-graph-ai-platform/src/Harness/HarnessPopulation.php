<?php
/**
 * Harness Population — shared profile archive across assistants.
 *
 * Enables discovered harness profiles from one assistant to transfer to
 * others. The population is the global archive of evaluated harness
 * profiles across all assistants on the site. Assistants can contribute
 * profiles from search runs, inherit profiles from the population, and
 * track lineage (which assistant discovered which profile).
 *
 * Storage: option `wp_mcp_ai_harness_population_global`.
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
 * Harness Population.
 *
 * @since 1.9.0
 */
class HarnessPopulation {

	/**
	 * Option key for the global population.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_harness_population_global';

	/**
	 * Maximum number of profiles in the global population.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_POPULATION = 500;

	/**
	 * Archive a harness profile into the global population.
	 *
	 * Records the profile, its eval results, source assistant, and
	 * lineage information. If the profile already exists (by hash),
	 * updates the shared count and metadata.
	 *
	 * @since 1.9.0
	 *
	 * @param array       $profile            Sanitized harness profile.
	 * @param array       $eval_results       Evaluation results.
	 * @param int         $source_assistant_id Assistant that discovered this profile.
	 * @param string|null $parent_hash  Hash of the parent profile, if any.
	 * @return string Profile hash (key in the population).
	 */
	public static function archive( array $profile, array $eval_results, $source_assistant_id, $parent_hash = null ) {
		$source_assistant_id = (int) $source_assistant_id;
		$hash                = md5( wp_json_encode( $profile ) );
		$population          = self::load();

		if ( isset( $population[ $hash ] ) ) {
			// Profile already exists — update metadata.
			$population[ $hash ]['shared_count'] = (int) ( $population[ $hash ]['shared_count'] ?? 0 ) + 1;
			$population[ $hash ]['last_seen_at'] = time();
			$population[ $hash ]['sources'][]    = $source_assistant_id;
			$population[ $hash ]['sources']      = array_unique( $population[ $hash ]['sources'] );
		} else {
			$population[ $hash ] = array(
				'hash'         => $hash,
				'profile'      => $profile,
				'eval'         => $eval_results,
				'source'       => $source_assistant_id,
				'sources'      => array( $source_assistant_id ),
				'parent'       => $parent_hash,
				'children'     => array(),
				'shared_count' => 1,
				'created_at'   => time(),
				'last_seen_at' => time(),
			);

			// Update parent's children list.
			if ( null !== $parent_hash && isset( $population[ $parent_hash ] ) ) {
				$population[ $parent_hash ]['children'][] = $hash;
				$population[ $parent_hash ]['children']   = array_unique( $population[ $parent_hash ]['children'] );
			}
		}

		// Enforce population cap.
		if ( count( $population ) > self::MAX_POPULATION ) {
			uasort(
				$population,
				static function ( $a, $b ) {
					$a_time = isset( $a['last_seen_at'] ) ? (int) $a['last_seen_at'] : 0;
					$b_time = isset( $b['last_seen_at'] ) ? (int) $b['last_seen_at'] : 0;
					return $b_time - $a_time;
				}
			);
			$population = array_slice( $population, 0, self::MAX_POPULATION, true );
		}

		self::save( $population );

		return $hash;
	}

	/**
	 * Get the entire global population.
	 *
	 * @since 1.9.0
	 *
	 * @param array $filters Optional. Filter by: task_class, min_score, source_assistant_id.
	 * @return array<int,array>
	 */
	public static function get_population( array $filters = array() ) {
		$population = array_values( self::load() );

		if ( ! empty( $filters['source_assistant_id'] ) ) {
			$source     = (int) $filters['source_assistant_id'];
			$population = array_filter(
				$population,
				static function ( $entry ) use ( $source ) {
					return in_array( $source, $entry['sources'] ?? array(), true );
				}
			);
		}

		if ( ! empty( $filters['min_score'] ) ) {
			$min_score  = (float) $filters['min_score'];
			$population = array_filter(
				$population,
				static function ( $entry ) use ( $min_score ) {
					$score = isset( $entry['eval']['aggregate']['score'] ) ? (float) $entry['eval']['aggregate']['score'] : 0.0;
					return $score >= $min_score;
				}
			);
		}

		if ( ! empty( $filters['task_class'] ) ) {
			$task_class = sanitize_key( (string) $filters['task_class'] );
			$population = array_filter(
				$population,
				static function ( $entry ) use ( $task_class ) {
					$profile_task = isset( $entry['profile']['memory']['task_class'] ) ? $entry['profile']['memory']['task_class'] : 'general';
					return $task_class === $profile_task;
				}
			);
		}

		return array_values( $population );
	}

	/**
	 * Transfer a population profile to a specific assistant.
	 *
	 * Applies the profile as the assistant's active harness profile.
	 *
	 * @since 1.9.0
	 *
	 * @param string $profile_hash       Profile hash in the population.
	 * @param int    $target_assistant_id Assistant to receive the profile.
	 * @return bool True on success.
	 */
	public static function transfer( $profile_hash, $target_assistant_id ) {
		$profile_hash        = (string) $profile_hash;
		$target_assistant_id = (int) $target_assistant_id;

		if ( $target_assistant_id <= 0 ) {
			return false;
		}

		$population = self::load();
		if ( ! isset( $population[ $profile_hash ] ) ) {
			return false;
		}

		$profile = $population[ $profile_hash ]['profile'];

		if ( ! current_user_can( 'edit_post', $target_assistant_id ) ) {
			return false;
		}

		$saved = HarnessProfile::save( $target_assistant_id, $profile );

		if ( $saved ) {
			// Update transfer count.
			$population[ $profile_hash ]['shared_count'] = (int) ( $population[ $profile_hash ]['shared_count'] ?? 1 ) + 1;
			self::save( $population );
		}

		return $saved;
	}

	/**
	 * Suggest Pareto-optimal profiles for an assistant based on task class.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $task_class   Task class to match.
	 * @param int    $limit        Maximum suggestions.
	 * @return array<int,array>
	 */
	public static function suggest_for_assistant( $assistant_id, $task_class = 'general', $limit = 5 ) {
		$assistant_id = (int) $assistant_id;
		$task_class   = sanitize_key( (string) $task_class );
		$limit        = max( 1, min( 20, (int) $limit ) );

		$population = self::get_population( array( 'task_class' => $task_class ) );

		// Sort by score descending.
		usort(
			$population,
			static function ( $a, $b ) {
				$a_score = isset( $a['eval']['aggregate']['score'] ) ? (float) $a['eval']['aggregate']['score'] : 0.0;
				$b_score = isset( $b['eval']['aggregate']['score'] ) ? (float) $b['eval']['aggregate']['score'] : 0.0;
				return $b_score <=> $a_score;
			}
		);

		// Exclude profiles already from this assistant.
		$filtered = array_filter(
			$population,
			static function ( $entry ) use ( $assistant_id ) {
				return ! in_array( $assistant_id, $entry['sources'] ?? array(), true );
			}
		);

		return array_slice( array_values( $filtered ), 0, $limit );
	}

	/**
	 * Get the lineage tree for a profile.
	 *
	 * Returns parent and children hashes for visualizing the
	 * evolution of profiles.
	 *
	 * @since 1.9.0
	 *
	 * @param string $profile_hash Profile hash.
	 * @return array{parent:string|null,children:array<int,string>,siblings:array<int,string>}|null
	 */
	public static function get_lineage( $profile_hash ) {
		$profile_hash = (string) $profile_hash;
		$population   = self::load();

		if ( ! isset( $population[ $profile_hash ] ) ) {
			return null;
		}

		$entry  = $population[ $profile_hash ];
		$parent = $entry['parent'] ?? null;

		// Find siblings (other children of the same parent).
		$siblings = array();
		if ( null !== $parent && isset( $population[ $parent ] ) ) {
			$all_children = $population[ $parent ]['children'] ?? array();
			foreach ( $all_children as $child_hash ) {
				if ( $child_hash !== $profile_hash ) {
					$siblings[] = $child_hash;
				}
			}
		}

		return array(
			'parent'   => $parent,
			'children' => $entry['children'] ?? array(),
			'siblings' => $siblings,
		);
	}

	/**
	 * Delete a profile from the global population.
	 *
	 * @since 1.9.0
	 *
	 * @param string $profile_hash Profile hash.
	 * @return bool True if deleted.
	 */
	public static function delete( $profile_hash ) {
		$profile_hash = (string) $profile_hash;
		$population   = self::load();

		if ( ! isset( $population[ $profile_hash ] ) ) {
			return false;
		}

		unset( $population[ $profile_hash ] );
		self::save( $population );

		return true;
	}

	/**
	 * Get the size of the global population.
	 *
	 * @since 1.9.0
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::load() );
	}

	// -------------------------------------------------------------------------
	// Internal — persistence
	// -------------------------------------------------------------------------

	/**
	 * Load the global population from persistent storage.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string,array>
	 */
	private static function load() {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Save the global population to persistent storage.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string,array> $population Population keyed by hash.
	 * @return void
	 */
	private static function save( array $population ) {
		update_option( self::OPTION_KEY, $population, false );
	}
}
