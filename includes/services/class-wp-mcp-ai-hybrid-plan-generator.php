<?php
/**
 * Hybrid Plan Generator
 *
 * Generates task plans using a semi-autoregressive hybrid approach —
 * parallel proposal from multiple agents followed by lightweight sequential
 * refinement. Inspired by DSpark's semi-autoregressive drafting pattern
 * (parallel backbone + cheap sequential head).
 *
 * Stage 1 — Fan-out: collect independent subtask proposals from all
 * available planning-capable agents in parallel.
 * Stage 2 — Merge: lightweight sequential deduplication, conflict
 * resolution, and ordering via heuristics (not LLM re-invocation).
 * Stage 3 — Graph: dependency inference and parallel-group discovery.
 *
 * @package WP_MCP_AI
 * @since   1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hybrid plan generator class.
 *
 * Produces a structured execution plan from a high-level task description
 * by combining parallel agent proposals with a cheap heuristic merge pass.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Hybrid_Plan_Generator {

	/**
	 * Maximum number of agent proposals to collect in the fan-out stage.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_PROPOSALS = 5;

	/**
	 * Minimum cosine-like similarity score for two subtasks to be considered
	 * the same during the merge stage.
	 *
	 * @since 1.5.0
	 * @var float
	 */
	const MERGE_CONFIDENCE_THRESHOLD = 0.7;

	/**
	 * Hard cap on the number of subtasks in the final merged plan.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_SUBTASKS = 15;

	/**
	 * Agent team orchestrator instance.
	 *
	 * @since 1.5.0
	 * @var WP_MCP_AI_Agent_Team_Orchestrator|null
	 */
	protected $orchestrator;

	/**
	 * Orchestration depth scheduler instance.
	 *
	 * @since 1.5.0
	 * @var WP_MCP_AI_Orchestration_Depth_Scheduler|null
	 */
	protected $depth_scheduler;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_MCP_AI_Agent_Team_Orchestrator|null       $orchestrator    Agent team orchestrator.
	 * @param WP_MCP_AI_Orchestration_Depth_Scheduler|null $depth_scheduler Depth scheduler.
	 */
	public function __construct( $orchestrator = null, $depth_scheduler = null ) {
		$this->orchestrator    = $orchestrator;
		$this->depth_scheduler = $depth_scheduler;
	}

	/**
	 * Generate a hybrid plan for a high-level task.
	 *
	 * Orchestrates the three-stage pipeline:
	 *   1. Parallel fan-out to collect agent proposals.
	 *   2. Lightweight sequential merge with conflict resolution.
	 *   3. Dependency-graph construction and parallel-group discovery.
	 *
	 * @since 1.5.0
	 *
	 * @param string $task    Natural-language task description.
	 * @param array  $context Optional contextual metadata (e.g. tool availability,
	 *                        resource constraints, prior plan fragments).
	 * @return array|WP_Error Structured plan array on success, or WP_Error on failure.
	 */
	public function generate_hybrid_plan( $task, $context = array() ) {
		$task = trim( sanitize_text_field( $task ) );

		if ( '' === $task ) {
			return new WP_Error(
				'wp_mcp_ai_empty_task',
				__( 'Task description must not be empty.', 'mcp-ai-wpoos' )
			);
		}

		// ---- Stage 1: parallel proposal collection ---------------------------------
		$planners = $this->get_available_planners();

		if ( empty( $planners ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_planners',
				__( 'No planning-capable agents are available.', 'mcp-ai-wpoos' )
			);
		}

		$proposals = $this->collect_parallel_proposals( $task, $planners, $context );

		if ( is_wp_error( $proposals ) ) {
			return $proposals;
		}

		// ---- Stage 2: lightweight sequential merge ---------------------------------
		$merged = $this->lightweight_merge( $proposals );

		if ( empty( $merged['subtasks'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_merge_failed',
				__( 'Plan merge produced no viable subtask list.', 'mcp-ai-wpoos' )
			);
		}

		// ---- Stage 3: dependency graph & parallel groups ---------------------------
		$dependencies    = $this->build_dependency_graph( $merged['subtasks'] );
		$parallel_groups = $this->identify_parallel_groups( $merged['subtasks'], $dependencies );

		$task_id = $this->generate_task_id();

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				sprintf(
					/* translators: %s: generated task ID */
					__( 'Hybrid plan generated for task %s', 'mcp-ai-wpoos' ),
					$task_id
				),
				array(
					'task_id'              => $task_id,
					'subtask_count'        => count( $merged['subtasks'] ),
					'proposal_count'       => count( $proposals ),
					'merge_confidence'     => $merged['merge_confidence'],
					'parallel_group_count' => count( $parallel_groups ),
				)
			);
		}

		/**
		 * Fires when a hybrid plan has been successfully generated.
		 *
		 * @since 1.6.0
		 *
		 * @param string $task_id         Generated task ID.
		 * @param float  $merge_confidence Merge confidence score (0-1).
		 * @param int    $subtask_count    Number of merged subtasks.
		 */
		do_action( 'wp_mcp_ai_hybrid_plan_generated', $task_id, (float) $merged['merge_confidence'], count( $merged['subtasks'] ) );

		return array(
			'task_id'          => esc_html( $task_id ),
			'subtasks'         => $this->escape_subtask_array( $merged['subtasks'] ),
			'dependencies'     => $dependencies,
			'parallel_groups'  => $parallel_groups,
			'plan_type'        => 'hybrid',
			'merge_confidence' => (float) $merged['merge_confidence'],
		);
	}

	/**
	 * Return a list of agents capable of plan generation.
	 *
	 * Queries the assistant registry (CPT `mcp_ai_assistant`) for published
	 * assistants whose `_wp_mcp_ai_agent_role` meta matches one of the
	 * planning roles. Falls back to a static default role list when no
	 * matching assistants are found.
	 *
	 * @since 1.5.0
	 *
	 * @return array Array of planner agent descriptors, each containing at
	 *               least `id`, `name`, and `role`.
	 */
	public function get_available_planners() {
		$planning_roles = array( 'planner', 'executor', 'reviewer' );
		$planners       = array();
		$cache_key      = 'wp_mcp_ai_hybrid_planners';
		$cached         = wp_cache_get( $cache_key, 'mcp-ai-wpoos' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		foreach ( $planning_roles as $role ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => self::MAX_PROPOSALS,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter assistant CPT by orchestration role; no alternative index-based query available.
						array(
							'key'     => '_wp_mcp_ai_agent_role',
							'value'   => $role,
							'compare' => '=',
						),
					),
				)
			);

			foreach ( $query->posts as $post_id ) {
				$planners[] = array(
					'id'   => absint( $post_id ),
					'name' => get_the_title( $post_id ),
					'role' => sanitize_text_field( $role ),
				);
			}
		}

		// Fallback: return static default roles when no assistants are found.
		if ( empty( $planners ) ) {
			foreach ( $planning_roles as $role ) {
				$planners[] = array(
					'id'   => 0,
					'name' => sprintf(
						/* translators: %s: role name */
						__( 'Default %s', 'mcp-ai-wpoos' ),
						ucfirst( $role )
					),
					'role' => sanitize_text_field( $role ),
				);
			}
		}

		wp_cache_set( $cache_key, $planners, 'mcp-ai-wpoos', MINUTE_IN_SECONDS * 5 );

		return $planners;
	}

	// ---------------------------------------------------------------------------
	// Protected helpers
	// ---------------------------------------------------------------------------

	/**
	 * Collect independent subtask proposals from every planner in parallel.
	 *
	 * Each planner receives the same task and context and returns its own
	 * ordered list of subtasks.  Proposals are capped at MAX_PROPOSALS.
	 *
	 * @since 1.5.0
	 *
	 * @param string $task     The high-level task description.
	 * @param array  $planners Planner agent descriptors.
	 * @param array  $context  Optional contextual metadata.
	 * @return array|WP_Error Array of proposal arrays, or WP_Error on failure.
	 */
	protected function collect_parallel_proposals( $task, $planners, $context ) {
		$proposals = array();
		$errors    = array();

		// Fan-out: request a proposal from each planner independently.
		foreach ( $planners as $index => $planner ) {
			if ( count( $proposals ) >= self::MAX_PROPOSALS ) {
				break;
			}

			$proposal = $this->request_planner_proposal( $planner, $task, $context );

			if ( is_wp_error( $proposal ) ) {
				$errors[] = $proposal;
				continue;
			}

			$proposals[] = array(
				'planner_id'   => absint( $planner['id'] ),
				'planner_name' => sanitize_text_field( $planner['name'] ),
				'planner_role' => sanitize_text_field( $planner['role'] ),
				'subtasks'     => $proposal,
			);
		}

		if ( empty( $proposals ) && ! empty( $errors ) ) {
			return $errors[0];
		}

		if ( empty( $proposals ) ) {
			return new WP_Error(
				'wp_mcp_ai_proposal_collection_failed',
				__( 'All planner proposals failed.', 'mcp-ai-wpoos' )
			);
		}

		return $proposals;
	}

	/**
	 * Merge, deduplicate, and order subtasks from parallel proposals.
	 *
	 * Implements the "cheap sequential head" — uses heuristics (word overlap,
	 * positional consensus, length penalties) rather than an LLM call.
	 *
	 * @since 1.5.0
	 *
	 * @param array $proposals Array of proposal arrays from collect_parallel_proposals().
	 * @return array Associative array with keys `subtasks` (normalised list)
	 *               and `merge_confidence` (float 0-1).
	 */
	protected function lightweight_merge( $proposals ) {
		// Flatten all subtasks with source metadata.
		$all_subtasks = array();
		foreach ( $proposals as $prop ) {
			if ( empty( $prop['subtasks'] ) || ! is_array( $prop['subtasks'] ) ) {
				continue;
			}
			foreach ( $prop['subtasks'] as $pos => $subtask ) {
				$all_subtasks[] = array(
					'description' => $this->normalise_subtask( $subtask ),
					'source'      => $prop['planner_id'],
					'source_name' => $prop['planner_name'],
					'source_role' => $prop['planner_role'],
					'position'    => (int) $pos,
				);
			}
		}

		if ( empty( $all_subtasks ) ) {
			return array(
				'subtasks'         => array(),
				'merge_confidence' => 0.0,
			);
		}

		// ---- Deduplicate -------------------------------------------------------
		$unique  = array();
		$matched = 0;
		$total   = count( $all_subtasks );

		foreach ( $all_subtasks as $candidate ) {
			$found = false;

			foreach ( $unique as &$existing ) {
				$similarity = $this->subtask_similarity( $existing['description'], $candidate['description'] );

				if ( $similarity >= self::MERGE_CONFIDENCE_THRESHOLD ) {
					// Merge: keep the higher-quality entry (prefer shorter text as
					// it tends to be more focused).
					if ( strlen( $candidate['description'] ) < strlen( $existing['description'] ) ) {
						$existing['description'] = $candidate['description'];
						$existing['source']      = $candidate['source'];
						$existing['source_name'] = $candidate['source_name'];
						$existing['source_role'] = $candidate['source_role'];
					}
					$existing['agreement_count'] = ( $existing['agreement_count'] ?? 1 ) + 1;
					$found                       = true;
					++$matched;
					break;
				}
			}
			unset( $existing );

			if ( ! $found ) {
				$unique[] = array_merge( $candidate, array( 'agreement_count' => 1 ) );
			}
		}

		// ---- Order -------------------------------------------------------------
		// Compute a consensus position for each unique subtask.
		$position_map    = array();
		$position_counts = array();

		foreach ( $all_subtasks as $item ) {
			foreach ( $unique as $u ) {
				if ( $this->subtask_similarity( $u['description'], $item['description'] ) >= self::MERGE_CONFIDENCE_THRESHOLD ) {
					$key = $this->subtask_fingerprint( $u['description'] );
					if ( ! isset( $position_map[ $key ] ) ) {
						$position_map[ $key ]    = 0;
						$position_counts[ $key ] = 0;
					}
					$position_map[ $key ]    += $item['position'];
					$position_counts[ $key ] += 1;
					break;
				}
			}
		}

		// Assign average position as sort key.
		$ordered = array();
		foreach ( $unique as $u ) {
			$key                     = $this->subtask_fingerprint( $u['description'] );
			$u['consensus_position'] = isset( $position_map[ $key ] ) && $position_counts[ $key ] > 0
				? $position_map[ $key ] / $position_counts[ $key ]
				: PHP_INT_MAX;
			$ordered[]               = $u;
		}

		usort(
			$ordered,
			function ( $a, $b ) {
				if ( $a['consensus_position'] === $b['consensus_position'] ) {
					return $b['agreement_count'] - $a['agreement_count'];
				}
				return $a['consensus_position'] <=> $b['consensus_position'];
			}
		);

		// Enforce MAX_SUBTASKS cap.
		$ordered = array_slice( $ordered, 0, self::MAX_SUBTASKS );

		// ---- Merge confidence --------------------------------------------------
		// Higher confidence when many proposals agree and few subtasks are unique.
		$merge_confidence = 1.0;
		if ( $total > 0 ) {
			$agreement_ratio    = $matched > 0 ? $matched / $total : 0;
			$uniqueness_penalty = count( $ordered ) / min( max( count( $proposals ), 1 ), self::MAX_PROPOSALS );
			$merge_confidence   = ( $agreement_ratio * 0.6 ) + ( ( 1 - $uniqueness_penalty ) * 0.4 );
			$merge_confidence   = max( 0.0, min( 1.0, $merge_confidence ) );
		}

		// Strip internal metadata from the output subtask list.
		$subtasks = array_map(
			function ( $item ) {
				return $item['description'];
			},
			$ordered
		);

		return array(
			'subtasks'         => $subtasks,
			'merge_confidence' => $merge_confidence,
		);
	}

	/**
	 * Build a dependency graph from the merged subtask list.
	 *
	 * Determines which subtasks depend on the outputs of which other subtasks
	 * using keyword-based heuristic rules (e.g. "using the results", "after
	 * X", "based on Y").
	 *
	 * @since 1.5.0
	 *
	 * @param array $subtasks Ordered array of subtask description strings.
	 * @return array Adjacency map: subtask index => array of prerequisite indices.
	 */
	protected function build_dependency_graph( $subtasks ) {
		$dependencies = array();
		$count        = count( $subtasks );

		for ( $i = 0; $i < $count; $i++ ) {
			$dependencies[ $i ] = array();

			// Heuristic: earlier subtasks are potential prerequisites.
			for ( $j = 0; $j < $i; $j++ ) {
				if ( $this->has_dependency_signal( $subtasks[ $i ], $j, $subtasks[ $j ] ) ) {
					$dependencies[ $i ][] = $j;
				}
			}
		}

		return $dependencies;
	}

	/**
	 * Identify groups of subtasks that can execute in parallel.
	 *
	 * Two subtasks belong to the same parallel group when neither depends on
	 * the other (they share no transitive dependency edge).
	 *
	 * @since 1.5.0
	 *
	 * @param array $subtasks     Ordered array of subtask description strings.
	 * @param array $dependencies Adjacency map from build_dependency_graph().
	 * @return array Array of parallel groups, each group being an array of
	 *               subtask indices.
	 */
	protected function identify_parallel_groups( $subtasks, $dependencies ) {
		$groups   = array();
		$assigned = array_fill( 0, count( $subtasks ), false );

		for ( $i = 0, $count = count( $subtasks ); $i < $count; $i++ ) {
			if ( $assigned[ $i ] ) {
				continue;
			}

			$group   = array( $i );
			$blocked = $this->transitive_dependency_set( $i, $dependencies );

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( $assigned[ $j ] ) {
					continue;
				}

				// Can run in parallel if no dependency edge connects i→j or j→i.
				if ( ! in_array( $j, $blocked, true ) && ! in_array( $i, $this->transitive_dependency_set( $j, $dependencies ), true ) ) {
					$group[]        = $j;
					$assigned[ $j ] = true;
				}
			}

			$assigned[ $i ] = true;
			$groups[]       = $group;
		}

		return $groups;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Request a subtask list from a single planner agent.
	 *
	 * Delegates to the team orchestrator when available; otherwise returns a
	 * simple keyword-split fallback proposal.
	 *
	 * @since 1.5.0
	 *
	 * @param array  $planner Planner descriptor (id, name, role).
	 * @param string $task    Task description.
	 * @param array  $context Contextual metadata.
	 * @return array|WP_Error Array of subtask descriptions, or WP_Error.
	 */
	private function request_planner_proposal( $planner, $task, $context ) {
		if ( $this->orchestrator && method_exists( $this->orchestrator, 'request_agent_plan' ) ) {
			$result = $this->orchestrator->request_agent_plan( $planner, $task, $context );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_array( $result ) ) {
				return $result;
			}
		}

		// Fallback: generate a simple keyword-split proposal.
		return $this->fallback_proposal( $task );
	}

	/**
	 * Generate a simple keyword-split fallback subtask list.
	 *
	 * @since 1.5.0
	 *
	 * @param string $task Task description.
	 * @return array Array of subtask description strings.
	 */
	private function fallback_proposal( $task ) {
		// Split by common delimiters and conjunction words.
		$delimiters = array( ',', ';', ' then ', ' and ', ' after ', ' before ' );
		$parts      = array( $task );

		foreach ( $delimiters as $delim ) {
			$new_parts = array();
			foreach ( $parts as $part ) {
				$split = explode( $delim, $part );
				foreach ( $split as $s ) {
					$trimmed = trim( $s );
					if ( '' !== $trimmed ) {
						$new_parts[] = $trimmed;
					}
				}
			}
			$parts = $new_parts;
		}

		// Deduplicate while preserving order.
		$seen   = array();
		$result = array();
		foreach ( $parts as $part ) {
			$fingerprint = $this->subtask_fingerprint( $part );
			if ( ! isset( $seen[ $fingerprint ] ) ) {
				$seen[ $fingerprint ] = true;
				$result[]             = $part;
			}
		}

		return array_slice( $result, 0, self::MAX_SUBTASKS );
	}

	/**
	 * Normalise a subtask description — a string or an array with a
	 * `description` / `title` key — to a plain string.
	 *
	 * @since 1.5.0
	 *
	 * @param string|array $subtask Raw subtask from a proposal.
	 * @return string Normalised description.
	 */
	private function normalise_subtask( $subtask ) {
		if ( is_array( $subtask ) ) {
			return trim(
				sanitize_text_field(
					$subtask['description'] ?? $subtask['title'] ?? $subtask['name'] ?? ''
				)
			);
		}

		return trim( sanitize_text_field( (string) $subtask ) );
	}

	/**
	 * Compute a simple word-overlap similarity score between two strings.
	 *
	 * Normalised Jaccard-like coefficient on word-level tokens.  Fast enough
	 * for the "cheap sequential head" without calling an LLM or vector store.
	 *
	 * @since 1.5.0
	 *
	 * @param string $a First description.
	 * @param string $b Second description.
	 * @return float Similarity score in [0, 1].
	 */
	private function subtask_similarity( $a, $b ) {
		$a = strtolower( $a );
		$b = strtolower( $b );

		if ( $a === $b ) {
			return 1.0;
		}

		$tokens_a = $this->tokenize( $a );
		$tokens_b = $this->tokenize( $b );

		if ( empty( $tokens_a ) || empty( $tokens_b ) ) {
			return 0.0;
		}

		$intersection = array_intersect( $tokens_a, $tokens_b );
		$union        = array_unique( array_merge( $tokens_a, $tokens_b ) );

		return count( $intersection ) / count( $union );
	}

	/**
	 * Tokenize a string into lower-case word tokens, stripping common stop
	 * words and punctuation.
	 *
	 * @since 1.5.0
	 *
	 * @param string $text Input text.
	 * @return array Array of word tokens.
	 */
	private function tokenize( $text ) {
		$stop_words = array(
			'a',
			'an',
			'the',
			'is',
			'are',
			'be',
			'to',
			'of',
			'in',
			'for',
			'on',
			'with',
			'and',
			'or',
			'by',
			'at',
			'from',
			'this',
			'that',
			'it',
			'as',
			'we',
			'our',
			'will',
			'can',
			'should',
			'must',
			'need',
		);

		// Strip non-alpha characters and collapse whitespace.
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		$words = explode( ' ', $text );
		$words = array_filter(
			$words,
			function ( $w ) use ( $stop_words ) {
				return strlen( $w ) > 1 && ! in_array( $w, $stop_words, true );
			}
		);

		return array_values( $words );
	}

	/**
	 * Build a stable fingerprint for a subtask string so it can be used as
	 * an array key during merge.
	 *
	 * @since 1.5.0
	 *
	 * @param string $subtask Subtask description.
	 * @return string Lower-case MD5 hash of the normalised text.
	 */
	private function subtask_fingerprint( $subtask ) {
		return md5( strtolower( trim( $subtask ) ) );
	}

	/**
	 * Determine whether subtask B (description) signals a dependency on the
	 * output of the subtask at index A.
	 *
	 * Uses keyword-based heuristics for cheap dependency detection without an
	 * LLM round-trip.
	 *
	 * @since 1.5.0
	 *
	 * @param string $current_desc  Description of the dependent subtask.
	 * @param int    $prereq_index  Index of the potential prerequisite.
	 * @param string $prereq_desc   Description of the potential prerequisite.
	 * @return bool True when a dependency signal is detected.
	 */
	private function has_dependency_signal( $current_desc, $prereq_index, $prereq_desc ) {
		$dep_patterns = array(
			'/using\s+the\s+result/i',
			'/after\s+step\s+\d+/i',
			'/based\s+on\s+the\s+(output|result)/i',
			'/from\s+the\s+(previous|prior)\s+step/i',
			'/once\s+(the\s+)?\w+\s+is\s+(complete|done|finished)/i',
		);

		foreach ( $dep_patterns as $pattern ) {
			if ( preg_match( $pattern, $current_desc ) ) {
				return true;
			}
		}

		// Cross-reference: the current description contains words from the
		// prerequisite's description (weak signal).
		$tokens_a = $this->tokenize( $prereq_desc );
		$tokens_b = $this->tokenize( $current_desc );

		if ( empty( $tokens_a ) || empty( $tokens_b ) ) {
			return false;
		}

		$overlap = count( array_intersect( $tokens_a, $tokens_b ) );
		$ratio   = $overlap / max( count( $tokens_a ), 1 );

		return $ratio >= 0.4;
	}

	/**
	 * Compute the full set of transitive dependencies for a given subtask
	 * index (all direct and indirect prerequisites).
	 *
	 * @since 1.5.0
	 *
	 * @param int   $index        Subtask index.
	 * @param array $dependencies Adjacency map.
	 * @return array Flat array of all prerequisite indices.
	 */
	private function transitive_dependency_set( $index, $dependencies ) {
		$visited = array();
		$stack   = isset( $dependencies[ $index ] ) ? $dependencies[ $index ] : array();

		while ( ! empty( $stack ) ) {
			$current = array_shift( $stack );

			if ( in_array( $current, $visited, true ) ) {
				continue;
			}

			$visited[] = $current;

			if ( isset( $dependencies[ $current ] ) ) {
				foreach ( $dependencies[ $current ] as $dep ) {
					if ( ! in_array( $dep, $visited, true ) ) {
						$stack[] = $dep;
					}
				}
			}
		}

		return $visited;
	}

	/**
	 * Escape every value in the final subtask array for safe output.
	 *
	 * @since 1.5.0
	 *
	 * @param array $subtasks Array of subtask descriptions.
	 * @return array Escaped subtask array.
	 */
	private function escape_subtask_array( $subtasks ) {
		return array_map( 'esc_html', $subtasks );
	}

	/**
	 * Generate a unique task identifier.
	 *
	 * @since 1.5.0
	 *
	 * @return string Unique task ID.
	 */
	private function generate_task_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'task_' . wp_generate_uuid4();
		}

		return 'task_' . wp_hash( uniqid( 'hybrid_plan_', true ) );
	}
}
