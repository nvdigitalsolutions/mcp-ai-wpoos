<?php
/**
 * Harness Evolver — Continual Refinement of Agent Harnesses Mid-Session
 *
 * WordPress adaptation of the Continual Harness paper (Karten et al., 2026,
 * arXiv:2605.09998). Reads recent trajectories from the existing Audit Trail,
 * detects failure signatures, and applies CRUD edits to all four harness
 * components (system prompt, agent roles, skills, memory) mid-session via the
 * assistant's own provider. Each evolution is logged as an immutable CoSAI
 * audit trail event with before/after snapshots.
 *
 * @link    https://arxiv.org/abs/2605.09998
 * @credit  Continual Harness by Karten et al. (2026)
 * @package NvoosContentGraphAiPlatform\Agents
 * @subpackage NvoosContentGraphAiPlatform/src/Agents
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * CoSAI Alignment:
 *   - Principle 3 (Transparent & Verifiable): Every evolution is logged as
 *     an immutable Audit Trail event with before/after snapshots.
 *   - Principle 1 (Human Oversight): Evolutions are non-destructive
 *     (originals preserved); evolved artefacts are opt-in.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness Evolver class.
 *
 * Monitors agent sessions and proposes CRUD edits to system prompts, roles,
 * skills, and memory when failure signatures are detected. All evolutions are
 * logged immutably via the Audit Trail and are non-destructive by default.
 *
 * @since 1.2.0
 */
class AgentHarnessEvolver {

	/**
	 * Minimum iterations before the first evolution is triggered.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const MIN_WARMUP_ITERATIONS = 5;

	/**
	 * Iterations at or below this count are in the "early phase" with
	 * more frequent evolution checks.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const EARLY_PHASE_CUTOFF = 50;

	/**
	 * Frequency of evolution checks during the early phase (every N iterations).
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const EARLY_FREQUENCY = 5;

	/**
	 * Frequency of evolution checks during the stable phase (every N iterations).
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const STABLE_FREQUENCY = 20;

	/**
	 * Maximum number of audit trail events to read in a single window.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const MAX_TRAJECTORY_WINDOW = 100;

	/**
	 * Options prefix for evolved roles storage.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const EVOLVED_ROLE_OPTION_PREFIX = 'wp_mcp_ai_evolved_role_';

	/**
	 * Post meta key for the evolved (refiner-suggested) system prompt.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const EVOLVED_PROMPT_META_KEY = '_wp_mcp_ai_evolved_system_prompt';

	/**
	 * Options key for evolution log storage.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const EVOLUTION_LOG_OPTION = 'wp_mcp_ai_harness_evolution_log';

	/**
	 * Valid harness components that can be evolved or analysed.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const VALID_COMPONENTS = array( 'all', 'prompt', 'roles', 'skills', 'memory' );

	/**
	 * Transient prefix for the per-assistant evolution budget tracker.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const BUDGET_TRANSIENT_PREFIX = 'wp_mcp_ai_evolution_budget_';

	/**
	 * Default hourly evolution budget in USD.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_BUDGET_USD = 5.0;

	/**
	 * Flat cost estimate (USD) for a Refiner call when the provider response
	 * carries no usage or cost data.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_REFINER_CALL_COST_USD = 0.01;

	/**
	 * Session ID this evolver is bound to.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	private $session_id;

	/**
	 * Assistant post ID.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	private $assistant_id;

	/**
	 * Audit trail ID for this session.
	 *
	 * @since 1.2.0
	 * @var   string|null
	 */
	private $trail_id;

	/**
	 * Evolution history for this session (in-memory).
	 *
	 * @since 1.2.0
	 * @var   array
	 */
	private $evolution_log = array();

	/**
	 * Cached frequency config to avoid repeated filter calls.
	 *
	 * @since 1.2.0
	 * @var   array|null
	 */
	private $frequency_config = null;

	/**
	 * Whether the current evolution run is a dry run (no writes).
	 *
	 * @since 1.9.0
	 * @var   bool
	 */
	private $dry_run = false;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id   Unique session identifier.
	 * @param int    $assistant_id Assistant post ID.
	 */
	public function __construct( $session_id, $assistant_id ) {
		// Defensive normalization: some historical callers passed the arguments
		// in reverse order (assistant ID first, session second). Detect that
		// signature and swap so both orders behave identically.
		if ( is_numeric( $session_id ) && ! is_numeric( $assistant_id ) ) {
			$swap         = $session_id;
			$session_id   = $assistant_id;
			$assistant_id = $swap;
		}

		$this->session_id   = sanitize_key( (string) $session_id );
		$this->assistant_id = absint( $assistant_id );

		// Resolve the audit trail ID for this session if one exists.
		$trails = AgentAuditTrail::get_trails_by_session( $this->session_id );
		if ( ! is_wp_error( $trails ) && ! empty( $trails ) ) {
			$last_trail     = end( $trails );
			$this->trail_id = isset( $last_trail[0]['trail_id'] ) ? $last_trail[0]['trail_id'] : null;
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Determine whether evolution should be attempted at the given iteration.
	 *
	 * Returns false when the feature is disabled, before warmup, or when the
	 * iteration doesn't land on a frequency multiple.
	 *
	 * @since 1.2.0
	 *
	 * @param int $iteration Current iteration count (1-based).
	 * @return bool True if evolution should be attempted.
	 */
	public function should_evolve( $iteration ) {
		$iteration = absint( $iteration );

		/**
		 * Filters whether harness evolution is enabled.
		 *
		 * When false (default), all evolution is disabled.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Whether harness evolution is enabled. Default false.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_harness_evolution_enabled', false );
		if ( ! $enabled ) {
			return false;
		}

		/**
		 * Filters the minimum number of warmup iterations before the
		 * first evolution is triggered.
		 *
		 * @since 1.2.0
		 *
		 * @param int $warmup Minimum warmup iterations. Default MIN_WARMUP_ITERATIONS (5).
		 */
		$warmup = (int) apply_filters( 'wp_mcp_ai_harness_evolution_warmup', self::MIN_WARMUP_ITERATIONS );
		$warmup = max( 1, $warmup );

		if ( $iteration < $warmup ) {
			return false;
		}

		$config = $this->get_frequency_config();

		if ( $iteration <= (int) $config['cutoff'] ) {
			$frequency = (int) $config['early_frequency'];
		} else {
			$frequency = (int) $config['stable_frequency'];
		}

		$frequency = max( 1, $frequency );

		return ( 0 === $iteration % $frequency );
	}

	/**
	 * Analyse recent trajectory events for failure patterns without modifying
	 * any assistant state.
	 *
	 * Read-only counterpart to {@see evolve()}. When no audit trail data is
	 * available for the session, a graceful empty analysis is returned rather
	 * than an error, so callers can always render a meaningful response.
	 *
	 * @since 1.9.0
	 *
	 * @param string $component     Component to analyse: 'all', 'prompt', 'roles', 'skills', or 'memory'. Default 'all'.
	 * @param int    $window_length Number of recent trajectory events to analyse (10-500). Default 50.
	 * @return array|WP_Error Analysis array or WP_Error for an invalid component.
	 */
	public function analyze_failures( $component = 'all', $window_length = 50 ) {
		$component = sanitize_key( (string) $component );
		if ( ! in_array( $component, self::VALID_COMPONENTS, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolution_invalid_component',
				sprintf(
					/* translators: %s: invalid component slug */
					__( 'Invalid component "%s". Valid components: all, prompt, roles, skills, memory.', 'nvoos-content-graph-ai-platform' ),
					$component
				),
				array( 'status' => 400 )
			);
		}

		$window_length = absint( $window_length );
		$window_length = max( 10, min( $window_length, 500 ) );

		$trajectory = $this->read_trajectory_window( $window_length );
		if ( is_wp_error( $trajectory ) ) {
			return array(
				'failures_detected' => 0,
				'signatures'        => array(),
				'trajectory_count'  => 0,
				'window_length'     => $window_length,
				'component'         => $component,
				'trail_available'   => false,
				'note'              => __( 'No audit trail data is available for this session yet. Run some agent interactions before analysing performance.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$signatures = $this->detect_failure_signatures( $trajectory );

		$failures_detected  = 0;
		$failures_detected += count( isset( $signatures['tool_failures'] ) ? $signatures['tool_failures'] : array() );
		$failures_detected += count( isset( $signatures['stuck_loops'] ) ? $signatures['stuck_loops'] : array() );
		$failures_detected += ! empty( $signatures['budget_exhausted'] ) ? 1 : 0;
		$failures_detected += ! empty( $signatures['low_success_rate'] ) ? 1 : 0;

		return array(
			'failures_detected' => $failures_detected,
			'signatures'        => $signatures,
			'trajectory_count'  => count( $trajectory ),
			'window_length'     => $window_length,
			'component'         => $component,
			'trail_available'   => true,
		);
	}

	/**
	 * Normalize the evolved skills option into the Skill Registry shape.
	 *
	 * Evolved skills are stored as raw Refiner output (name/description/code).
	 * This normalizes them to the registry contract (name/description/
	 * instructions) so they can be merged into {@see WP_MCP_AI_Skill_Registry}
	 * when the site opts in. Skill code remains inert instructional text — it
	 * is PII-scrubbed but never executed.
	 *
	 * @since 1.9.0
	 *
	 * @return array<int,array> Evolved skills keyed by sanitized name.
	 */
	public static function get_evolved_skills() {
		$skills  = array();
		$evolved = get_option( 'wp_mcp_ai_evolved_skills', array() );

		if ( ! is_array( $evolved ) ) {
			return $skills;
		}

		foreach ( $evolved as $skill ) {
			if ( ! is_array( $skill ) || empty( $skill['name'] ) ) {
				continue;
			}

			$name = sanitize_key( $skill['name'] );
			if ( '' === $name ) {
				continue;
			}

			$skills[ $name ] = array(
				'name'         => $name,
				'description'  => sanitize_textarea_field( self::scrub_evolved_text( isset( $skill['description'] ) ? $skill['description'] : '' ) ),
				'instructions' => wp_kses_post( self::scrub_evolved_text( isset( $skill['code'] ) ? $skill['code'] : '' ) ),
				'evolved'      => true,
			);
		}

		return $skills;
	}

	/**
	 * Scrub PII and secrets from Refiner-generated text before persisting.
	 *
	 * Degrades gracefully to the input string when the PII filter is not
	 * loaded (it is an optional harness dependency).
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $text Raw text from the Refiner.
	 * @return string Scrubbed text.
	 */
	private static function scrub_evolved_text( $text ) {
		$text = (string) $text;

		if ( '' === $text || ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Pii_Filter' ) ) ) {
			return $text;
		}

		$scrubbed = \WP_MCP_AI_Pii_Filter::scrub( $text );
		if ( is_array( $scrubbed ) && isset( $scrubbed['text'] ) && is_string( $scrubbed['text'] ) ) {
			return $scrubbed['text'];
		}

		return $text;
	}

	/**
	 * Run harness evolution passes.
	 *
	 * Each pass is independent — a failure in one does not block the others.
	 * Returns a summary with per-pass results, a total change count, and a
	 * human-readable summary line. Optional parameters allow callers to scope
	 * the run to a single component, bound the trajectory window, and preview
	 * suggestions without persisting anything.
	 *
	 * @since 1.2.0
	 *
	 * @param string $component     Component to evolve: 'all', 'prompt', 'roles', 'skills', or 'memory'. Default 'all'.
	 * @param int    $window_length Trajectory window override (10-500). 0 uses the configured default.
	 * @param bool   $dry_run       When true, returns suggestions without persisting anything.
	 * @return array|WP_Error Summary array with per-pass results, or WP_Error for invalid input or budget exhaustion.
	 */
	public function evolve( $component = 'all', $window_length = 0, $dry_run = false ) {
		$component = sanitize_key( (string) $component );
		if ( ! in_array( $component, self::VALID_COMPONENTS, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolution_invalid_component',
				sprintf(
					/* translators: %s: invalid component slug */
					__( 'Invalid component "%s". Valid components: all, prompt, roles, skills, memory.', 'nvoos-content-graph-ai-platform' ),
					$component
				),
				array( 'status' => 400 )
			);
		}

		$this->dry_run    = (bool) $dry_run;
		$requested_window = absint( $window_length );

		/**
		 * Filters whether harness evolution is enabled.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Whether harness evolution is enabled. Default false.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_harness_evolution_enabled', false );
		if ( ! $enabled ) {
			return array(
				'evolved'   => false,
				'reason'    => __( 'Harness evolution is disabled.', 'nvoos-content-graph-ai-platform' ),
				'component' => $component,
				'dry_run'   => $this->dry_run,
			);
		}

		if ( empty( $this->session_id ) || empty( $this->assistant_id ) ) {
			return array(
				'evolved'   => false,
				'reason'    => __( 'Invalid session or assistant.', 'nvoos-content-graph-ai-platform' ),
				'component' => $component,
				'dry_run'   => $this->dry_run,
			);
		}

		// Budget gate — checked before any trajectory read or provider call.
		// Phase G: delegated to the unified evolution governor (same budget
		// transient and limit filter as Phase A, plus per-path rate limits).
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Evolution_Governor' ) ) ) {
			$gate = \WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'evolver' );
			if ( ! $gate['allowed'] ) {
				if ( 'budget_exhausted' === $gate['reason'] ) {
					return new \WP_Error(
						'wp_mcp_ai_evolution_budget_exceeded',
						__( 'The hourly harness evolution budget has been exhausted. Retry later or raise the wp_mcp_ai_harness_evolution_budget_usd filter.', 'nvoos-content-graph-ai-platform' ),
						array( 'status' => 429 )
					);
				}

				return new \WP_Error(
					'wp_mcp_ai_evolution_rate_limited',
					__( 'The harness evolution rate limit has been reached. Retry later or raise the wp_mcp_ai_evolution_governor_rate_limit filter.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 429 )
				);
			}

			if ( ! $this->dry_run ) {
				\WP_MCP_AI_Evolution_Governor::record_mutation( $this->assistant_id, 'evolver' );
			}
		}

		// Read the recent trajectory window.
		$trajectory = $this->read_trajectory_window( $requested_window );
		if ( is_wp_error( $trajectory ) ) {
			return array(
				'evolved'   => false,
				'reason'    => $trajectory->get_error_message(),
				'component' => $component,
				'dry_run'   => $this->dry_run,
			);
		}

		// Detect failure signatures.
		$signatures = $this->detect_failure_signatures( $trajectory );
		if ( empty( $signatures ) ) {
			return array(
				'evolved'          => false,
				'reason'           => __( 'No failure signatures detected in the trajectory window.', 'nvoos-content-graph-ai-platform' ),
				'trajectory_count' => count( $trajectory ),
				'component'        => $component,
				'dry_run'          => $this->dry_run,
			);
		}

		// Build the trajectory summary for the Refiner LLM.
		$summary = $this->build_trajectory_summary( $trajectory, $signatures );

		// Run the requested passes independently.
		$pass_map = array(
			'prompt' => 'evolve_prompt',
			'roles'  => 'evolve_roles',
			'skills' => 'evolve_skills',
			'memory' => 'evolve_memory',
		);

		$results = array(
			'evolved'          => true,
			'timestamp'        => time(),
			'session_id'       => $this->session_id,
			'assistant_id'     => $this->assistant_id,
			'component'        => $component,
			'dry_run'          => $this->dry_run,
			'trajectory_count' => count( $trajectory ),
			'signatures'       => $signatures,
		);

		foreach ( $pass_map as $pass_key => $pass_method ) {
			if ( 'all' !== $component && $pass_key !== $component ) {
				$results[ $pass_key ] = array(
					'pass'   => $pass_key,
					'status' => 'skipped',
					'reason' => __( 'Component not requested.', 'nvoos-content-graph-ai-platform' ),
					'error'  => false,
				);
				continue;
			}

			$results[ $pass_key ] = $this->safe_evolve_pass( $pass_method, array( $summary ) );
		}

		// Aggregate change counts and build the summary line.
		$changes_applied            = $this->count_changes( $results );
		$results['changes_applied'] = $changes_applied;
		$results['summary']         = $this->build_evolution_summary( $changes_applied, $component, $this->dry_run );

		// Determine if all requested passes failed.
		$all_failed = true;
		foreach ( array_keys( $pass_map ) as $pass_key ) {
			$pass = $results[ $pass_key ];
			if ( ! is_array( $pass ) || empty( $pass['error'] ) ) {
				$all_failed = false;
				break;
			}
		}

		// Dry runs preview only — no audit-trail events, no persisted log.
		if ( ! $this->dry_run ) {
			// Log the evolution event to the audit trail and local log.
			$this->record_evolution_event( $results );

			// Store in session log.
			$this->evolution_log[] = $results;
			$this->persist_evolution_log();
		}

		/**
		 * Fires after all four harness evolution passes have been attempted.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $results     Evolution results summary.
		 * @param string $session_id  Session identifier.
		 * @param int    $assistant_id Assistant post ID.
		 */
		do_action( 'wp_mcp_ai_harness_evolution_completed', $results, $this->session_id, $this->assistant_id );

		if ( $all_failed ) {
			/**
			 * Fires if all four evolution passes failed.
			 *
			 * @since 1.2.0
			 *
			 * @param array  $results     Evolution results (all with error flags).
			 * @param string $session_id  Session identifier.
			 * @param int    $assistant_id Assistant post ID.
			 */
			do_action( 'wp_mcp_ai_harness_evolution_failed', $results, $this->session_id, $this->assistant_id );
		}

		return $results;
	}

	/**
	 * Get the full evolution history for this session.
	 *
	 * Returns the in-memory log merged with any previously persisted entries.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of evolution result arrays ordered by timestamp.
	 */
	public function get_evolution_log() {
		$persisted = get_option( self::EVOLUTION_LOG_OPTION . '_' . $this->session_id, array() );
		if ( ! is_array( $persisted ) ) {
			$persisted = array();
		}

		return array_merge( $persisted, $this->evolution_log );
	}

	// -------------------------------------------------------------------------
	// Failure Signature Detection
	// -------------------------------------------------------------------------

	/**
	 * Detect failure signatures from a trajectory window.
	 *
	 * @since 1.2.0
	 *
	 * @param array $trajectory Array of audit trail events.
	 * @return array Associative array of detected signature types and their instances.
	 */
	public function detect_failure_signatures( $trajectory ) {
		$trajectory = is_array( $trajectory ) ? $trajectory : array();

		$signatures = array(
			'tool_failures'    => array(),
			'stuck_loops'      => array(),
			'budget_exhausted' => false,
			'low_success_rate' => false,
		);

		$total_tool_calls  = 0;
		$successful_calls  = 0;
		$consecutive_calls = array(); // Track consecutive calls with same tool+args.
		$max_iteration     = 0;
		$session_ended     = false;

		foreach ( $trajectory as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$step_type = isset( $event['step_type'] ) ? $event['step_type'] : '';

			// Track iteration from session_start or decision events.
			if ( isset( $event['data'] ) ) {
				$data = $event['data'];
				if ( is_string( $data ) ) {
					$decoded = json_decode( $data, true );
					if ( is_array( $decoded ) ) {
						$data = $decoded;
					}
				}
				if ( is_array( $data ) && isset( $data['iteration'] ) ) {
					$max_iteration = max( $max_iteration, (int) $data['iteration'] );
				}
			}

			// Detect tool failures.
			if ( 'tool_call' === $step_type ) {
				++$total_tool_calls;

				$tool_slug  = isset( $event['tool_slug'] ) ? $event['tool_slug'] : '';
				$event_data = isset( $event['data'] ) ? $event['data'] : '';
				if ( is_string( $event_data ) ) {
					$event_data = json_decode( $event_data, true );
				}

				$is_error   = false;
				$error_info = '';
				$arguments  = isset( $event_data['arguments'] ) ? $event_data['arguments'] : array();

				if ( is_array( $event_data ) && isset( $event_data['result'] ) ) {
					$result = $event_data['result'];
					if ( is_array( $result ) && ! empty( $result['is_error'] ) ) {
						$is_error   = true;
						$error_info = isset( $result['error_message'] ) ? $result['error_message'] : '';
					}
				}

				if ( $is_error ) {
					$signatures['tool_failures'][] = array(
						'tool'      => $tool_slug,
						'error'     => $error_info,
						'arguments' => $arguments,
						'timestamp' => isset( $event['timestamp'] ) ? $event['timestamp'] : 0,
					);
				} else {
					++$successful_calls;
				}

				// Detect stuck loops: same tool called 3+ times consecutively with same arguments.
				$arg_hash = md5( wp_json_encode( $arguments ) );
				$call_key = $tool_slug . '|' . $arg_hash;

				if ( ! empty( $consecutive_calls ) ) {
					$last = end( $consecutive_calls );
					if ( $last['key'] === $call_key ) {
						++$consecutive_calls[ count( $consecutive_calls ) - 1 ]['count'];
					} else {
						$consecutive_calls[] = array(
							'key'   => $call_key,
							'tool'  => $tool_slug,
							'args'  => $arguments,
							'count' => 1,
						);
					}
				} else {
					$consecutive_calls[] = array(
						'key'   => $call_key,
						'tool'  => $tool_slug,
						'args'  => $arguments,
						'count' => 1,
					);
				}
			}

			// Detect session_end with non-success status (budget exhaustion).
			if ( 'session_end' === $step_type ) {
				$session_ended = true;
				$status        = isset( $event['status'] ) ? $event['status'] : '';
				if ( 'timeout' === $status || 'failure' === $status ) {
					$signatures['budget_exhausted'] = true;
				}
			}
		}

		// Extract stuck loops (3+ consecutive identical calls).
		foreach ( $consecutive_calls as $call ) {
			if ( $call['count'] >= 3 ) {
				$signatures['stuck_loops'][] = array(
					'tool'        => $call['tool'],
					'arguments'   => $call['args'],
					'repetitions' => $call['count'],
				);
			}
		}

		// Detect low success rate (<50%).
		if ( $total_tool_calls > 0 ) {
			$rate = $successful_calls / $total_tool_calls;
			if ( $rate < 0.5 ) {
				$signatures['low_success_rate'] = array(
					'total'      => $total_tool_calls,
					'successful' => $successful_calls,
					'rate'       => round( $rate * 100, 1 ),
				);
			}
		}

		// Filter out empty/zero-value signatures.
		$active_signatures = array();
		if ( ! empty( $signatures['tool_failures'] ) ) {
			$active_signatures['tool_failures'] = $signatures['tool_failures'];
		}
		if ( ! empty( $signatures['stuck_loops'] ) ) {
			$active_signatures['stuck_loops'] = $signatures['stuck_loops'];
		}
		if ( $signatures['budget_exhausted'] ) {
			$active_signatures['budget_exhausted'] = true;
		}
		if ( ! empty( $signatures['low_success_rate'] ) ) {
			$active_signatures['low_success_rate'] = $signatures['low_success_rate'];
		}

		return $active_signatures;
	}

	// -------------------------------------------------------------------------
	// Evolution Passes
	// -------------------------------------------------------------------------

	/**
	 * Evolve the system prompt.
	 *
	 * Sends the current system prompt + trajectory summary + failure signatures
	 * to the Refiner LLM. Returns the LLM's suggested improved prompt. The
	 * evolved prompt is stored in post meta but NOT auto-applied.
	 *
	 * @since 1.2.0
	 *
	 * @param array $summary Trajectory summary with failure signatures.
	 * @return array Result with 'before', 'after', and 'stored' keys.
	 */
	public function evolve_prompt( $summary ) {
		$current_prompt = get_post_meta( $this->assistant_id, '_wp_mcp_ai_system_prompt', true );
		if ( ! is_string( $current_prompt ) ) {
			$current_prompt = '';
		}

		if ( '' === trim( $current_prompt ) ) {
			return array(
				'pass'   => 'prompt',
				'status' => 'skipped',
				'reason' => __( 'No current system prompt to evolve.', 'nvoos-content-graph-ai-platform' ),
				'before' => $current_prompt,
				'after'  => null,
				'stored' => false,
				'error'  => false,
			);
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->get_refiner_system_prompt( 'prompt' ),
			),
			array(
				'role'    => 'user',
				'content' => $this->build_prompt_evolution_query( $current_prompt, $summary ),
			),
		);

		$response = $this->call_refiner( $messages );
		if ( is_wp_error( $response ) ) {
			return array(
				'pass'   => 'prompt',
				'status' => 'error',
				'error'  => true,
				'reason' => $response->get_error_message(),
				'before' => $current_prompt,
				'after'  => null,
				'stored' => false,
			);
		}

		$evolved_prompt = $this->extract_content_from_response( $response );

		// Scrub PII/secrets before persisting.
		$evolved_prompt = self::scrub_evolved_text( $evolved_prompt );

		// Post-mutation verification (opt-in, Phase B): the candidate must
		// improve on the failure cases replayed from harness traces before
		// it may be stored. Dry runs and disabled verification skip this.
		$verification = $this->maybe_verify_prompt( $current_prompt, $evolved_prompt );
		if ( $this->is_verification_rejection( $verification ) ) {
			return array(
				'pass'         => 'prompt',
				'status'       => 'verification_failed',
				'error'        => false,
				'before'       => $current_prompt,
				'after'        => $evolved_prompt,
				'stored'       => false,
				'dry_run'      => $this->dry_run,
				'verification' => $verification,
			);
		}

		// Store in post meta (not auto-applied); dry runs skip the write.
		// Phase G: sites may route verified candidates through the human
		// approval queue instead of storing them directly.
		$stored   = false;
		$queued   = false;
		$queue_id = '';

		/**
		 * Filters whether verified prompt candidates are queued for human
		 * approval instead of being stored directly.
		 *
		 * Default false — evolved prompts are stored, never auto-applied, and
		 * never auto-deployed.
		 *
		 * @since 1.9.0
		 *
		 * @param bool        $queue_for_approval Whether to queue for approval.
		 * @param int         $assistant_id       Assistant post ID.
		 * @param array|null  $verification       Verification payload (may be null).
		 */
		$queue_for_approval = (bool) apply_filters( 'wp_mcp_ai_artifact_queue_for_approval', false, $this->assistant_id, $verification );

		if ( ! $this->dry_run ) {
			if ( $queue_for_approval && ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Approval_Queue' ) ) ) {
				$queue_id = \WP_MCP_AI_Artifact_Approval_Queue::enqueue(
					$this->assistant_id,
					'promote',
					'prompt',
					$evolved_prompt,
					array(
						'verification' => is_array( $verification ) ? $verification : array(),
						'reason'       => __( 'Evolved prompt candidate awaiting human approval.', 'nvoos-content-graph-ai-platform' ),
						'requester_id' => get_current_user_id(),
					)
				);
				$queued   = ! is_wp_error( $queue_id );
			} else {
				$stored = update_post_meta( $this->assistant_id, self::EVOLVED_PROMPT_META_KEY, wp_kses_post( $evolved_prompt ) );
			}
		}

		$result = array(
			'pass'    => 'prompt',
			'status'  => 'completed',
			'error'   => false,
			'before'  => $current_prompt,
			'after'   => $evolved_prompt,
			'stored'  => (bool) $stored,
			'dry_run' => $this->dry_run,
		);

		if ( $queued ) {
			$result['queued']   = true;
			$result['queue_id'] = (string) $queue_id;
		}

		if ( null !== $verification ) {
			$result['verification'] = $verification;
		}

		return $result;
	}

	/**
	 * Evolve agent roles.
	 *
	 * Sends current agent roles + trajectory summary to the Refiner. The Refiner
	 * returns JSON with create/update/retire operations. Roles are stored as
	 * options with prefix wp_mcp_ai_evolved_role_.
	 *
	 * @since 1.2.0
	 *
	 * @param array $summary Trajectory summary with failure signatures.
	 * @return array Result with created/updated/retired counts.
	 */
	public function evolve_roles( $summary ) {
		$current_roles = $this->get_current_roles_summary();

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->get_refiner_system_prompt( 'roles' ),
			),
			array(
				'role'    => 'user',
				'content' => $this->build_roles_evolution_query( $current_roles, $summary ),
			),
		);

		$response = $this->call_refiner( $messages );
		if ( is_wp_error( $response ) ) {
			return array(
				'pass'    => 'roles',
				'status'  => 'error',
				'error'   => true,
				'reason'  => $response->get_error_message(),
				'created' => 0,
				'updated' => 0,
				'retired' => 0,
			);
		}

		$content = $this->extract_content_from_response( $response );
		$parsed  = $this->parse_json_from_content( $content );

		if ( is_wp_error( $parsed ) ) {
			return array(
				'pass'    => 'roles',
				'status'  => 'error',
				'error'   => true,
				'reason'  => $parsed->get_error_message(),
				'created' => 0,
				'updated' => 0,
				'retired' => 0,
			);
		}

		$created = 0;
		$updated = 0;
		$retired = 0;

		// Process creates.
		if ( isset( $parsed['create'] ) && is_array( $parsed['create'] ) ) {
			foreach ( $parsed['create'] as $role_def ) {
				if ( ! is_array( $role_def ) || empty( $role_def['type'] ) ) {
					continue;
				}
				$type         = sanitize_key( $role_def['type'] );
				$instructions = isset( $role_def['system_instructions'] ) ? sanitize_textarea_field( self::scrub_evolved_text( $role_def['system_instructions'] ) ) : '';

				$role_data = array(
					'type'                => $type,
					'system_instructions' => $instructions,
					'created_at'          => time(),
					'evolved'             => true,
				);

				$stored = $this->dry_run ? true : update_option( self::EVOLVED_ROLE_OPTION_PREFIX . $type, $role_data, false );
				if ( $stored ) {
					++$created;
				}
			}
		}

		// Process updates.
		if ( isset( $parsed['update'] ) && is_array( $parsed['update'] ) ) {
			foreach ( $parsed['update'] as $update_def ) {
				if ( ! is_array( $update_def ) || empty( $update_def['type'] ) ) {
					continue;
				}
				$type     = sanitize_key( $update_def['type'] );
				$existing = get_option( self::EVOLVED_ROLE_OPTION_PREFIX . $type, array() );

				if ( ! is_array( $existing ) ) {
					continue;
				}

				if ( isset( $update_def['field_changes'] ) && is_array( $update_def['field_changes'] ) ) {
					foreach ( $update_def['field_changes'] as $field => $value ) {
						$existing[ sanitize_key( $field ) ] = sanitize_textarea_field( self::scrub_evolved_text( (string) $value ) );
					}
				}

				$existing['updated_at'] = time();
				$stored                 = $this->dry_run ? true : update_option( self::EVOLVED_ROLE_OPTION_PREFIX . $type, $existing, false );
				if ( $stored ) {
					++$updated;
				}
			}
		}

		// Process retirements.
		if ( isset( $parsed['retire'] ) && is_array( $parsed['retire'] ) ) {
			foreach ( $parsed['retire'] as $type_to_retire ) {
				$type = sanitize_key( (string) $type_to_retire );
				if ( '' === $type ) {
					continue;
				}
				$existing = get_option( self::EVOLVED_ROLE_OPTION_PREFIX . $type, null );
				if ( null !== $existing ) {
					$existing['retired']    = true;
					$existing['retired_at'] = time();
					if ( $this->dry_run ) {
						++$retired;
						continue;
					}
					update_option( self::EVOLVED_ROLE_OPTION_PREFIX . $type, $existing, false );
					++$retired;
				}
			}
		}

		// Register evolved roles via the wp_mcp_ai_agent_roles filter.
		if ( ! $this->dry_run ) {
			$this->register_evolved_roles();
		}

		return array(
			'pass'    => 'roles',
			'status'  => 'completed',
			'error'   => false,
			'created' => $created,
			'updated' => $updated,
			'retired' => $retired,
			'dry_run' => $this->dry_run,
		);
	}

	/**
	 * Evolve bundled skills.
	 *
	 * Sends current bundled skills + trajectory summary to the Refiner. The
	 * Refiner returns JSON with create/update operations. Skills are stored
	 * using option-based storage compatible with the existing skill system.
	 *
	 * @since 1.2.0
	 *
	 * @param array $summary Trajectory summary with failure signatures.
	 * @return array Result with created/updated counts.
	 */
	public function evolve_skills( $summary ) {
		$current_skills = $this->get_current_skills_summary();

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->get_refiner_system_prompt( 'skills' ),
			),
			array(
				'role'    => 'user',
				'content' => $this->build_skills_evolution_query( $current_skills, $summary ),
			),
		);

		$response = $this->call_refiner( $messages );
		if ( is_wp_error( $response ) ) {
			return array(
				'pass'    => 'skills',
				'status'  => 'error',
				'error'   => true,
				'reason'  => $response->get_error_message(),
				'created' => 0,
				'updated' => 0,
			);
		}

		$content = $this->extract_content_from_response( $response );
		$parsed  = $this->parse_json_from_content( $content );

		if ( is_wp_error( $parsed ) ) {
			return array(
				'pass'    => 'skills',
				'status'  => 'error',
				'error'   => true,
				'reason'  => $parsed->get_error_message(),
				'created' => 0,
				'updated' => 0,
			);
		}

		$created = 0;
		$updated = 0;

		$evolved_skills = get_option( 'wp_mcp_ai_evolved_skills', array() );
		if ( ! is_array( $evolved_skills ) ) {
			$evolved_skills = array();
		}

		// Process creates.
		if ( isset( $parsed['create'] ) && is_array( $parsed['create'] ) ) {
			foreach ( $parsed['create'] as $skill_def ) {
				if ( ! is_array( $skill_def ) || empty( $skill_def['name'] ) ) {
					continue;
				}

				$skill_id = sanitize_key( $skill_def['name'] );
				// The Refiner's `code` is inert instructional text: PII-scrubbed
				// but deliberately not HTML-stripped so code examples survive.
				// It is never executed.
				$evolved_skills[ $skill_id ] = array(
					'id'          => $skill_id,
					'name'        => sanitize_text_field( $skill_def['name'] ),
					'path'        => isset( $skill_def['path'] ) ? sanitize_text_field( $skill_def['path'] ) : '',
					'description' => isset( $skill_def['description'] ) ? sanitize_textarea_field( self::scrub_evolved_text( $skill_def['description'] ) ) : '',
					'code'        => isset( $skill_def['code'] ) ? self::scrub_evolved_text( $skill_def['code'] ) : '',
					'evolved'     => true,
					'created_at'  => time(),
				);
				++$created;
			}
		}

		// Process updates.
		if ( isset( $parsed['update'] ) && is_array( $parsed['update'] ) ) {
			foreach ( $parsed['update'] as $update_def ) {
				if ( ! is_array( $update_def ) || empty( $update_def['id'] ) ) {
					continue;
				}
				$skill_id = sanitize_key( $update_def['id'] );
				if ( ! isset( $evolved_skills[ $skill_id ] ) ) {
					continue;
				}

				if ( isset( $update_def['effectiveness'] ) ) {
					$evolved_skills[ $skill_id ]['effectiveness'] = (float) $update_def['effectiveness'];
				}
				if ( isset( $update_def['code'] ) ) {
					$evolved_skills[ $skill_id ]['code'] = self::scrub_evolved_text( $update_def['code'] );
				}
				$evolved_skills[ $skill_id ]['updated_at'] = time();
				++$updated;
			}
		}

		if ( ! $this->dry_run ) {
			update_option( 'wp_mcp_ai_evolved_skills', $evolved_skills, false );
		}

		return array(
			'pass'    => 'skills',
			'status'  => 'completed',
			'error'   => false,
			'created' => $created,
			'updated' => $updated,
			'dry_run' => $this->dry_run,
		);
	}

	/**
	 * Evolve agent memory.
	 *
	 * Sends current memory store overview + trajectory summary to the Refiner.
	 * The Refiner returns JSON with add/update operations. Memory is stored
	 * using the existing store_agent_context tool when available, falling back
	 * to direct option writes.
	 *
	 * @since 1.2.0
	 *
	 * @param array $summary Trajectory summary with failure signatures.
	 * @return array Result with added/updated counts.
	 */
	public function evolve_memory( $summary ) {
		$memory_overview = $this->get_current_memory_overview();

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->get_refiner_system_prompt( 'memory' ),
			),
			array(
				'role'    => 'user',
				'content' => $this->build_memory_evolution_query( $memory_overview, $summary ),
			),
		);

		$response = $this->call_refiner( $messages );
		if ( is_wp_error( $response ) ) {
			return array(
				'pass'    => 'memory',
				'status'  => 'error',
				'error'   => true,
				'reason'  => $response->get_error_message(),
				'added'   => 0,
				'updated' => 0,
			);
		}

		$content = $this->extract_content_from_response( $response );
		$parsed  = $this->parse_json_from_content( $content );

		if ( is_wp_error( $parsed ) ) {
			return array(
				'pass'    => 'memory',
				'status'  => 'error',
				'error'   => true,
				'reason'  => $parsed->get_error_message(),
				'added'   => 0,
				'updated' => 0,
			);
		}

		$added   = 0;
		$updated = 0;

		// Try to use the store_agent_context tool if available.
		$tool_available = $this->is_store_agent_context_available();

		$context = array(
			'assistant_id' => $this->assistant_id,
			'session_id'   => $this->session_id,
		);

		// Process additions.
		if ( isset( $parsed['add'] ) && is_array( $parsed['add'] ) ) {
			foreach ( $parsed['add'] as $mem_def ) {
				if ( ! is_array( $mem_def ) ) {
					continue;
				}

				$title       = isset( $mem_def['title'] ) ? sanitize_text_field( $mem_def['title'] ) : __( 'Evolved Memory', 'nvoos-content-graph-ai-platform' );
				$content_mem = isset( $mem_def['content'] ) ? sanitize_textarea_field( self::scrub_evolved_text( $mem_def['content'] ) ) : '';
				$importance  = isset( $mem_def['importance'] ) ? (float) $mem_def['importance'] : 0.5;
				$path        = isset( $mem_def['path'] ) ? sanitize_text_field( $mem_def['path'] ) : '';

				if ( '' === trim( $content_mem ) ) {
					continue;
				}

				if ( $this->dry_run ) {
					++$added;
					continue;
				}

				if ( $tool_available ) {
					$result = $this->store_memory_via_tool( $title, $content_mem, $importance, $path, $context );
					if ( ! is_wp_error( $result ) ) {
						++$added;
					}
				} else {
					// Fallback: store in options.
					$stored = $this->store_memory_in_options( $title, $content_mem, $importance, $path );
					if ( $stored ) {
						++$added;
					}
				}
			}
		}

		// Process updates.
		if ( isset( $parsed['update'] ) && is_array( $parsed['update'] ) ) {
			foreach ( $parsed['update'] as $update_def ) {
				if ( ! is_array( $update_def ) || empty( $update_def['id'] ) ) {
					continue;
				}

				$memory_id        = sanitize_key( $update_def['id'] );
				$evolved_memories = get_option( 'wp_mcp_ai_evolved_memories', array() );
				if ( ! is_array( $evolved_memories ) ) {
					continue;
				}

				if ( ! isset( $evolved_memories[ $memory_id ] ) ) {
					continue;
				}

				if ( isset( $update_def['content'] ) ) {
					$evolved_memories[ $memory_id ]['content'] = sanitize_textarea_field( self::scrub_evolved_text( $update_def['content'] ) );
				}
				if ( isset( $update_def['importance'] ) ) {
					$evolved_memories[ $memory_id ]['importance'] = (float) $update_def['importance'];
				}
				$evolved_memories[ $memory_id ]['updated_at'] = time();

				if ( ! $this->dry_run ) {
					update_option( 'wp_mcp_ai_evolved_memories', $evolved_memories, false );
				}
				++$updated;
			}
		}

		return array(
			'pass'    => 'memory',
			'status'  => 'completed',
			'error'   => false,
			'added'   => $added,
			'updated' => $updated,
			'dry_run' => $this->dry_run,
		);
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Trajectory
	// -------------------------------------------------------------------------

	/**
	 * Read the recent trajectory window from the audit trail.
	 *
	 * @since 1.2.0
	 *
	 * @param int $window_length Optional override for the maximum number of
	 *                           events (10-500). 0 uses the configured default.
	 * @return array|WP_Error Array of audit trail events or WP_Error.
	 */
	private function read_trajectory_window( $window_length = 0 ) {
		if ( empty( $this->trail_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_no_trail',
				__( 'No audit trail found for this session.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail = AgentAuditTrail::get_trail( $this->trail_id );
		if ( is_wp_error( $trail ) ) {
			return $trail;
		}

		/**
		 * Filters the maximum number of trajectory events to read.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_window Maximum events. Default MAX_TRAJECTORY_WINDOW (100).
		 */
		$max_window = (int) apply_filters( 'wp_mcp_ai_harness_evolution_max_window', self::MAX_TRAJECTORY_WINDOW );
		$max_window = max( 10, min( $max_window, 500 ) );

		// Per-call override (e.g. from the evolve_harness tool).
		$window_length = absint( $window_length );
		if ( $window_length > 0 ) {
			$max_window = max( 10, min( $window_length, 500 ) );
		}

		// Take the most recent events.
		$trail = array_slice( $trail, -$max_window );

		return $trail;
	}

	/**
	 * Build a human-readable trajectory summary for the Refiner.
	 *
	 * @since 1.2.0
	 *
	 * @param array $trajectory Trajectory events.
	 * @param array $signatures Failure signatures.
	 * @return string Formatted summary text.
	 */
	private function build_trajectory_summary( $trajectory, $signatures ) {
		$parts = array();

		$parts[] = sprintf(
			/* translators: %d: number of trajectory events */
			__( 'Trajectory Window: %d events', 'nvoos-content-graph-ai-platform' ),
			count( $trajectory )
		);

		// Summarize tool calls.
		$tool_counts    = array();
		$total_failures = 0;
		foreach ( $trajectory as $event ) {
			if ( ! is_array( $event ) || 'tool_call' !== ( isset( $event['step_type'] ) ? $event['step_type'] : '' ) ) {
				continue;
			}
			$slug = isset( $event['tool_slug'] ) ? $event['tool_slug'] : 'unknown';
			if ( ! isset( $tool_counts[ $slug ] ) ) {
				$tool_counts[ $slug ] = array(
					'total'    => 0,
					'failures' => 0,
				);
			}
			++$tool_counts[ $slug ]['total'];

			$event_data = isset( $event['data'] ) ? $event['data'] : '';
			if ( is_string( $event_data ) ) {
				$event_data = json_decode( $event_data, true );
			}
			if ( is_array( $event_data ) && isset( $event_data['result']['is_error'] ) && $event_data['result']['is_error'] ) {
				++$tool_counts[ $slug ]['failures'];
				++$total_failures;
			}
		}

		$parts[] = sprintf(
			/* translators: %d: total tool failures */
			__( 'Tool Failures: %d', 'nvoos-content-graph-ai-platform' ),
			$total_failures
		);

		foreach ( $tool_counts as $slug => $counts ) {
			$parts[] = sprintf(
				'  %s: %d/%d failures',
				$slug,
				$counts['failures'],
				$counts['total']
			);
		}

		// Append failure signatures.
		$parts[] = sprintf(
			/* translators: %s: JSON-encoded failure signatures */
			__( 'Failure Signatures: %s', 'nvoos-content-graph-ai-platform' ),
			wp_json_encode( $signatures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		return implode( "\n", $parts );
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Refiner Communication
	// -------------------------------------------------------------------------

	/**
	 * Get the Refiner LLM's system prompt for a given component.
	 *
	 * @since 1.2.0
	 *
	 * @param string $component One of 'prompt', 'roles', 'skills', 'memory'.
	 * @return string System prompt for the Refiner.
	 */
	private function get_refiner_system_prompt( $component ) {
		switch ( $component ) {
			case 'prompt':
				return __( 'You are a Harness Refiner specializing in system prompt optimization. Given the current system prompt, a trajectory summary of recent agent actions, and detected failure signatures, propose an improved system prompt that addresses the failures. Return ONLY the improved prompt text — no commentary, no JSON wrapper, no markdown code fences.', 'nvoos-content-graph-ai-platform' );

			case 'roles':
				return __( 'You are a Harness Refiner specializing in agent role definitions. Given current agent roles, a trajectory summary, and failure signatures, return a JSON object with create/update/retire arrays. Format: {"create": [{"type": "role_type", "system_instructions": "..."}], "update": [{"type": "role_type", "field_changes": {"system_instructions": "..."}}], "retire": ["role_type"]}. Return ONLY valid JSON — no commentary, no markdown code fences.', 'nvoos-content-graph-ai-platform' );

			case 'skills':
				return __( 'You are a Harness Refiner specializing in agent skill definitions. Given current bundled skills, a trajectory summary, and failure signatures, return a JSON object with create/update arrays. Format: {"create": [{"name": "skill_name", "path": "path/to/skill", "description": "...", "code": "..."}], "update": [{"id": "existing_skill_id", "effectiveness": 0.8, "code": "..."}]}. Return ONLY valid JSON.', 'nvoos-content-graph-ai-platform' );

			case 'memory':
				return __( 'You are a Harness Refiner specializing in agent memory management. Given the current memory store overview, a trajectory summary, and failure signatures, return a JSON object with add/update arrays. Format: {"add": [{"title": "...", "content": "...", "importance": 0.7, "path": "category/subcategory"}], "update": [{"id": "existing_memory_id", "content": "...", "importance": 0.9}]}. Return ONLY valid JSON.', 'nvoos-content-graph-ai-platform' );

			default:
				return __( 'You are a Harness Refiner. Analyze the provided data and suggest improvements.', 'nvoos-content-graph-ai-platform' );
		}
	}

	/**
	 * Call the Refiner LLM via the assistant's configured provider.
	 *
	 * @since 1.2.0
	 *
	 * @param array $messages Conversation messages.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	private function call_refiner( $messages ) {
		$provider_slug = get_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', true );
		if ( empty( $provider_slug ) ) {
			$provider_slug = 'openai';
		}

		$model = get_post_meta( $this->assistant_id, '_wp_mcp_ai_model', true );
		if ( empty( $model ) ) {
			$model = 'gpt-4o-mini';
		}

		// Try to get the provider client via the DI container.
		$client = $this->resolve_provider_client( $provider_slug );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Per-call budget enforcement.
		if ( $this->budget_remaining() <= 0.0 ) {
			return new \WP_Error(
				'wp_mcp_ai_evolution_budget_exceeded',
				__( 'The hourly harness evolution budget has been exhausted.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 429 )
			);
		}

		$options = array(
			'model'       => $model,
			'temperature' => 0.3, // Low temperature for deterministic refinement.
			'tools'       => array(), // No tools — text-only refinement.
		);

		$response = $client->chat( $messages, $options );

		if ( ! is_wp_error( $response ) ) {
			$this->record_budget_spend( $this->estimate_refiner_cost( $response ) );
		}

		return $response;
	}

	/**
	 * Resolve the per-assistant hourly evolution budget in USD.
	 *
	 * @since 1.9.0
	 *
	 * @return float Budget limit.
	 */
	private function get_budget_limit() {
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Evolution_Governor' ) ) ) {
			return \WP_MCP_AI_Evolution_Governor::budget_limit_usd( $this->assistant_id );
		}

		/**
		 * Filters the hourly harness evolution budget per assistant.
		 *
		 * @since 1.9.0
		 *
		 * @param float $budget_usd Budget in USD. Default DEFAULT_BUDGET_USD (5.0).
		 */
		$limit = apply_filters( 'wp_mcp_ai_harness_evolution_budget_usd', self::DEFAULT_BUDGET_USD );

		return max( 0.0, (float) $limit );
	}

	/**
	 * Get the budget already spent this hour for this assistant.
	 *
	 * @since 1.9.0
	 *
	 * @return float Spent amount in USD.
	 */
	private function get_budget_spent() {
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Evolution_Governor' ) ) ) {
			return \WP_MCP_AI_Evolution_Governor::budget_spent( $this->assistant_id );
		}

		$spent = get_transient( self::BUDGET_TRANSIENT_PREFIX . $this->assistant_id );

		return max( 0.0, (float) $spent );
	}

	/**
	 * Get the remaining evolution budget in USD.
	 *
	 * @since 1.9.0
	 *
	 * @return float Remaining budget.
	 */
	private function budget_remaining() {
		return $this->get_budget_limit() - $this->get_budget_spent();
	}

	/**
	 * Record a spend against the assistant's evolution budget.
	 *
	 * Uses a transient with an hourly TTL so the budget window resets
	 * automatically without cron cleanup.
	 *
	 * @since 1.9.0
	 *
	 * @param float $usd Amount in USD to record.
	 * @return void
	 */
	private function record_budget_spend( $usd ) {
		$usd = max( 0.0, (float) $usd );

		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Evolution_Governor' ) ) ) {
			\WP_MCP_AI_Evolution_Governor::record_spend( $this->assistant_id, $usd, 'evolver' );
			return;
		}

		$spent = $this->get_budget_spent() + $usd;

		set_transient( self::BUDGET_TRANSIENT_PREFIX . $this->assistant_id, $spent, HOUR_IN_SECONDS );
	}

	/**
	 * Estimate the USD cost of a Refiner call.
	 *
	 * Prefers explicit cost data from the provider response, falls back to a
	 * token-usage estimate (~$2.50 per 1M tokens), and finally to a flat
	 * per-call estimate when neither is present.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $response Provider response payload.
	 * @return float Estimated cost in USD.
	 */
	private function estimate_refiner_cost( $response ) {
		if ( is_array( $response ) && isset( $response['cost_usd'] ) ) {
			return max( 0.0, (float) $response['cost_usd'] );
		}

		$total_tokens = 0;
		if ( is_array( $response ) && isset( $response['usage']['total_tokens'] ) ) {
			$total_tokens = (int) $response['usage']['total_tokens'];
		} elseif ( is_array( $response ) && isset( $response['usage']['totalTokens'] ) ) {
			$total_tokens = (int) $response['usage']['totalTokens'];
		}

		if ( $total_tokens > 0 ) {
			return max( 0.0001, ( $total_tokens / 1000000.0 ) * 2.50 );
		}

		return self::DEFAULT_REFINER_CALL_COST_USD;
	}

	/**
	 * Count the number of applied (or, in a dry run, suggested) changes
	 * across pass results.
	 *
	 * @since 1.9.0
	 *
	 * @param array $results Evolution results keyed by component.
	 * @return int Total change count.
	 */
	private function count_changes( $results ) {
		$total = 0;

		$counters = array(
			'prompt' => array( 'stored' ),
			'roles'  => array( 'created', 'updated', 'retired' ),
			'skills' => array( 'created', 'updated' ),
			'memory' => array( 'added', 'updated' ),
		);

		foreach ( $counters as $component => $keys ) {
			if ( ! isset( $results[ $component ] ) || ! is_array( $results[ $component ] ) || ! empty( $results[ $component ]['error'] ) ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( isset( $results[ $component ][ $key ] ) ) {
					$total += absint( $results[ $component ][ $key ] );
				}
			}
		}

		return $total;
	}

	/**
	 * Build a human-readable evolution summary line.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $changes   Change count.
	 * @param string $component Evolved component slug.
	 * @param bool   $dry_run   Whether this was a dry run.
	 * @return string Summary line.
	 */
	private function build_evolution_summary( $changes, $component, $dry_run ) {
		if ( $dry_run ) {
			return sprintf(
				/* translators: 1: number of suggested changes, 2: component name */
				_n( '%1$d improvement suggested for %2$s.', '%1$d improvements suggested for %2$s.', $changes, 'nvoos-content-graph-ai-platform' ),
				$changes,
				$component
			);
		}

		return sprintf(
			/* translators: 1: number of applied changes, 2: component name */
			_n( '%1$d improvement applied to %2$s.', '%1$d improvements applied to %2$s.', $changes, 'nvoos-content-graph-ai-platform' ),
			$changes,
			$component
		);
	}

	/**
	 * Run post-mutation verification on a prompt candidate (opt-in).
	 *
	 * Returns null when verification is disabled or this is a dry run;
	 * otherwise returns the verification decision payload from
	 * {@see WP_MCP_AI_Artifact_Verification_Gate} (or a skip payload).
	 *
	 * @since 1.9.0
	 *
	 * @param string $incumbent_prompt Current system prompt.
	 * @param string $candidate_prompt Refiner-suggested prompt.
	 * @return array|null Verification payload, or null when not applicable.
	 */
	private function maybe_verify_prompt( $incumbent_prompt, $candidate_prompt ) {
		if ( $this->dry_run ) {
			return null;
		}

		/**
		 * Filters whether post-mutation verification is enabled.
		 *
		 * Default false — candidates are stored unverified unless the site
		 * opts in. When enabled, a candidate that does not improve on the
		 * replayed failure cases is rejected.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $enabled      Whether verification is enabled. Default false.
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $component    Component being evolved.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_harness_verification_enabled', false, $this->assistant_id, 'prompt' );
		if ( ! $enabled ) {
			return null;
		}

		return $this->verify_prompt_candidate( $incumbent_prompt, $candidate_prompt );
	}

	/**
	 * Whether a verification payload constitutes a rejection.
	 *
	 * Considers both the Phase B verification verdict and the Phase E
	 * admission verdict. Skip payloads and null are not rejections.
	 *
	 * @since 1.9.0
	 *
	 * @param array|null $verification Verification payload.
	 * @return bool True when the candidate must be rejected.
	 */
	private function is_verification_rejection( $verification ) {
		if ( ! is_array( $verification ) ) {
			return false;
		}

		if ( 'reject' === ( isset( $verification['decision'] ) ? $verification['decision'] : '' ) ) {
			return true;
		}

		return isset( $verification['admission']['decision'] ) && 'reject' === $verification['admission']['decision'];
	}

	/**
	 * Verify a prompt candidate against the failure-replay suite.
	 *
	 * Public so callers (and tests) can exercise the skip paths without a
	 * live provider. Skip payloads (`decision => 'skip'`) mean the candidate
	 * is allowed to proceed — there was no signal to judge it against.
	 *
	 * @since 1.9.0
	 *
	 * @param string $incumbent_prompt Current system prompt.
	 * @param string $candidate_prompt Refiner-suggested prompt.
	 * @return array Verification decision payload.
	 */
	public function verify_prompt_candidate( $incumbent_prompt, $candidate_prompt ) {
		if ( ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) || ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Artifact_Verification_Gate' ) ) ) {
			return array(
				'decision' => 'skip',
				'reason'   => __( 'Verification modules are not available.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$suite = \WP_MCP_AI_Artifact_Failure_Replay::build_suite( $this->assistant_id, array( 'artifact_type' => 'prompt' ) );
		if ( is_wp_error( $suite ) ) {
			/**
			 * Filters the behavior when verification is enabled but no
			 * replay cases exist. 'skip' (default) allows the candidate;
			 * 'reject' fails closed.
			 *
			 * @since 1.9.0
			 *
			 * @param string $behavior     Either 'skip' or 'reject'.
			 * @param int    $assistant_id Assistant post ID.
			 */
			$on_no_cases = (string) apply_filters( 'wp_mcp_ai_harness_verification_on_no_cases', 'skip', $this->assistant_id );
			if ( 'reject' === $on_no_cases ) {
				return array(
					'decision' => 'reject',
					'reason'   => $suite->get_error_message(),
				);
			}
			return array(
				'decision' => 'skip',
				'reason'   => $suite->get_error_message(),
			);
		}

		// Verification runs are provider calls — respect the same budget.
		if ( $this->budget_remaining() <= 0.0 ) {
			return array(
				'decision' => 'skip',
				'reason'   => __( 'Evolution budget exhausted before verification.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$incumbent_generator = $this->build_prompt_generator( $incumbent_prompt );
		$candidate_generator = $this->build_prompt_generator( $candidate_prompt );
		if ( is_wp_error( $incumbent_generator ) || is_wp_error( $candidate_generator ) ) {
			return array(
				'decision' => 'skip',
				'reason'   => __( 'Provider client unavailable for verification.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$result = \WP_MCP_AI_Artifact_Verification_Gate::evaluate( $incumbent_generator, $candidate_generator, $suite );
		if ( is_wp_error( $result ) ) {
			return array(
				'decision' => 'skip',
				'reason'   => $result->get_error_message(),
			);
		}

		// Phase E: run the pre-commit admission gate (VaG) on the candidate.
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Admission_Gate' ) ) ) {
			$result['admission'] = \WP_MCP_AI_Artifact_Admission_Gate::evaluate(
				'prompt',
				array( 'prompt' => $candidate_prompt ),
				array( 'prompt' => $incumbent_prompt ),
				$result,
				$this->assistant_id
			);
		}

		// Archive incumbent + candidate into the artifact population (Phase C).
		// Rejected candidates are archived too — the learning log needs the
		// failures to avoid re-proposing them.
		$incumbent_hash = null;
		$candidate_hash = null;
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Population' ) ) ) {
			$incumbent_hash = \WP_MCP_AI_Artifact_Population::archive(
				'prompt',
				(string) $this->assistant_id,
				array( 'prompt' => $incumbent_prompt ),
				isset( $result['incumbent_pass_rate'] ) ? (float) $result['incumbent_pass_rate'] : 0.0,
				isset( $result['incumbent_summary'] ) && is_array( $result['incumbent_summary'] ) ? $result['incumbent_summary'] : array(),
				null,
				$this->assistant_id
			);
			if ( ! is_wp_error( $incumbent_hash ) ) {
				$result['incumbent_hash'] = $incumbent_hash;
			}

			$candidate_hash = \WP_MCP_AI_Artifact_Population::archive(
				'prompt',
				(string) $this->assistant_id,
				array( 'prompt' => $candidate_prompt ),
				isset( $result['candidate_pass_rate'] ) ? (float) $result['candidate_pass_rate'] : 0.0,
				isset( $result['candidate_summary'] ) && is_array( $result['candidate_summary'] ) ? $result['candidate_summary'] : array(),
				is_wp_error( $incumbent_hash ) ? null : $incumbent_hash,
				$this->assistant_id
			);
			if ( ! is_wp_error( $candidate_hash ) ) {
				$result['candidate_hash'] = $candidate_hash;
			}
		}

		// Record the mutation in the learning log (Phase D): the diff between
		// incumbent and candidate plus the observed score delta is the
		// differential signal future mutators learn from.
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) && ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) && is_string( $candidate_hash ) && '' !== $candidate_hash ) {
			$incumbent_rate = isset( $result['incumbent_pass_rate'] ) ? (float) $result['incumbent_pass_rate'] : 0.0;
			$candidate_rate = isset( $result['candidate_pass_rate'] ) ? (float) $result['candidate_pass_rate'] : 0.0;

			$log_id = \WP_MCP_AI_Artifact_Learning_Log::record(
				array(
					'artifact_type'  => 'prompt',
					'artifact_id'    => (string) $this->assistant_id,
					'parent_hash'    => is_string( $incumbent_hash ) ? $incumbent_hash : '',
					'child_hash'     => $candidate_hash,
					'kind'           => 'continual_harness',
					'diff'           => \WP_MCP_AI_Artifact_Mutator::diff_artifacts(
						array( 'prompt' => $incumbent_prompt ),
						array( 'prompt' => $candidate_prompt )
					),
					'change_summary' => '',
					'score_delta'    => $candidate_rate - $incumbent_rate,
					'assistant_id'   => $this->assistant_id,
				)
			);
			if ( ! is_wp_error( $log_id ) ) {
				$result['learning_log_id'] = $log_id;
			}
		}

		// Phase E: enforce the per-assistant population cap (score-ordered
		// eviction keeps the pool bounded; VaG pool-size discipline).
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Population' ) ) && method_exists( 'WP_MCP_AI_Artifact_Population', 'enforce_per_assistant_cap' ) ) {
			$result['cap_evicted'] = \WP_MCP_AI_Artifact_Population::enforce_per_assistant_cap( $this->assistant_id );
		}

		// Record the verification run per artifact for regression tracking.
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Eval_Run_Store' ) ) && ! empty( $result['candidate_summary'] ) && is_array( $result['candidate_summary'] ) ) {
			$record_summary                 = $result['candidate_summary'];
			$record_summary['verification'] = array(
				'decision'        => $result['decision'],
				'mode'            => $result['mode'],
				'improved_cases'  => $result['improved_cases'],
				'regressed_cases' => $result['regressed_cases'],
			);
			\WP_MCP_AI_Eval_Run_Store::get_instance()->record(
				$suite->get_slug(),
				$record_summary,
				null,
				array(
					'artifact_type' => 'prompt',
					'artifact_id'   => (string) $this->assistant_id,
				)
			);
		}

		return $result;
	}

	/**
	 * Build a generator callable that answers eval cases with a fixed
	 * system prompt via the assistant's provider.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prompt System prompt to evaluate.
	 * @return callable|WP_Error Generator callable, or WP_Error when the
	 *                            provider client cannot be resolved.
	 */
	private function build_prompt_generator( $prompt ) {
		$provider_slug = get_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', true );
		if ( empty( $provider_slug ) ) {
			$provider_slug = 'openai';
		}

		$model = get_post_meta( $this->assistant_id, '_wp_mcp_ai_model', true );
		if ( empty( $model ) ) {
			$model = 'gpt-4o-mini';
		}

		$client = $this->resolve_provider_client( $provider_slug );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Non-static closure: bound to $this so private budget helpers stay
		// accessible inside the runner loop.
		return function ( $eval_case, $suite_context ) use ( $client, $model, $prompt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $suite_context is part of the eval-runner generator contract.
			if ( $this->budget_remaining() <= 0.0 ) {
				return new \WP_Error(
					'wp_mcp_ai_evolution_budget_exceeded',
					__( 'Evolution budget exhausted during verification.', 'nvoos-content-graph-ai-platform' )
				);
			}

			$input        = $eval_case->get_input();
			$user_message = is_array( $input ) && isset( $input['prompt'] ) ? (string) $input['prompt'] : wp_json_encode( $input );

			$response = $client->chat(
				array(
					array(
						'role'    => 'system',
						'content' => $prompt,
					),
					array(
						'role'    => 'user',
						'content' => $user_message,
					),
				),
				array(
					'model'       => $model,
					'temperature' => 0.0, // Deterministic verification.
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->record_budget_spend( $this->estimate_refiner_cost( $response ) );

			return array(
				'output'   => $this->extract_content_from_response( $response ),
				'cost_usd' => $this->estimate_refiner_cost( $response ),
			);
		};
	}

	/**
	 * Resolve a provider client from the DI container.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider_slug Provider identifier.
	 * @return Interface_WP_MCP_AI_Provider_Client|WP_Error Provider client or error.
	 */
	private function resolve_provider_client( $provider_slug ) {
		$provider_slug = sanitize_key( $provider_slug );

		if ( ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Container' ) ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_no_container',
				__( 'DI container not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$container = \WP_MCP_AI_Container::get_instance();

		$service_key = 'provider.' . $provider_slug;
		if ( $container->has( $service_key ) ) {
			$client = $container->get( $service_key );
			if ( $client instanceof \Interface_WP_MCP_AI_Provider_Client ) {
				return $client;
			}
		}

		// Fallback: try to construct known provider clients directly.
		return $this->resolve_provider_client_fallback( $provider_slug );
	}

	/**
	 * Fallback provider client resolution when DI container is unavailable.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider_slug Provider identifier.
	 * @return Interface_WP_MCP_AI_Provider_Client|WP_Error Provider client or error.
	 */
	private function resolve_provider_client_fallback( $provider_slug ) {
		$provider_slug = sanitize_key( $provider_slug );

		// Map provider slugs to known adapter classes.
		$provider_map = array(
			'openai'       => 'WP_MCP_AI_OpenAI_Provider_Client',
			'gemini'       => 'WP_MCP_AI_Gemini_Provider_Client',
			'anthropic'    => 'WP_MCP_AI_Anthropic_Provider_Client',
			'ollama'       => 'WP_MCP_AI_Ollama_Provider_Client',
			'cloudflare'   => 'WP_MCP_AI_Cloudflare_Provider_Client',
			'digitalocean' => 'WP_MCP_AI_DigitalOcean_Provider_Client',
			'baseten'      => 'WP_MCP_AI_Baseten_Provider_Client',
		);

		if ( ! isset( $provider_map[ $provider_slug ] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_unknown_provider',
				sprintf(
					/* translators: %s: provider slug */
					__( 'Unknown provider: %s', 'nvoos-content-graph-ai-platform' ),
					$provider_slug
				)
			);
		}

		$class_name = $provider_map[ $provider_slug ];
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( $class_name ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_provider_class_missing',
				sprintf(
					/* translators: %s: class name */
					__( 'Provider class not found: %s', 'nvoos-content-graph-ai-platform' ),
					$class_name
				)
			);
		}

		try {
			$client = new $class_name();
			if ( $client instanceof \Interface_WP_MCP_AI_Provider_Client ) {
				return $client;
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_provider_instantiation_failed',
				$e->getMessage()
			);
		}

		return new \WP_Error(
			'wp_mcp_ai_evolver_invalid_provider',
			__( 'Provider client does not implement the required interface.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Extract the plain-text content from a provider response.
	 *
	 * @since 1.2.0
	 *
	 * @param array $response Provider response array.
	 * @return string Extracted content.
	 */
	private function extract_content_from_response( $response ) {
		if ( isset( $response['content'] ) ) {
			return (string) $response['content'];
		}

		// Handle nested OpenAI-style response.
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			return (string) $response['choices'][0]['message']['content'];
		}

		return '';
	}

	/**
	 * Parse JSON from LLM content, handling markdown code fences.
	 *
	 * @since 1.2.0
	 *
	 * @param string $content Raw LLM response text.
	 * @return array|WP_Error Parsed array or WP_Error on failure.
	 */
	private function parse_json_from_content( $content ) {
		$content = trim( (string) $content );

		if ( '' === $content ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_empty_response',
				__( 'Refiner returned empty content.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Strip markdown code fences if present.
		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $matches ) ) {
			$content = $matches[1];
		}

		$parsed = json_decode( $content, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_invalid_json',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse Refiner JSON: %s', 'nvoos-content-graph-ai-platform' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! is_array( $parsed ) ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_json_not_array',
				__( 'Refiner JSON is not an object.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $parsed;
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Evolution Query Builders
	// -------------------------------------------------------------------------

	/**
	 * Build the prompt evolution query.
	 *
	 * @since 1.2.0
	 *
	 * @param string $current_prompt Current system prompt.
	 * @param array  $summary        Trajectory summary with signatures.
	 * @return string User message for the Refiner.
	 */
	private function build_prompt_evolution_query( $current_prompt, $summary ) {
		$parts = array();

		$parts[] = __( 'Current System Prompt:', 'nvoos-content-graph-ai-platform' );
		$parts[] = '---';
		$parts[] = $current_prompt;
		$parts[] = '---';
		$parts[] = '';
		$parts[] = __( 'Trajectory Analysis:', 'nvoos-content-graph-ai-platform' );
		$parts[] = $summary;
		$parts[] = '';
		$parts[] = __( 'Based on the failures detected in the trajectory, propose an improved system prompt that addresses these issues. Return ONLY the improved prompt text.', 'nvoos-content-graph-ai-platform' );

		return implode( "\n", $parts );
	}

	/**
	 * Build the roles evolution query.
	 *
	 * @since 1.2.0
	 *
	 * @param array $current_roles Current roles summary.
	 * @param array $summary       Trajectory summary with signatures.
	 * @return string User message for the Refiner.
	 */
	private function build_roles_evolution_query( $current_roles, $summary ) {
		$parts = array();

		$parts[] = __( 'Current Agent Roles:', 'nvoos-content-graph-ai-platform' );
		$parts[] = wp_json_encode( $current_roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$parts[] = '';
		$parts[] = __( 'Trajectory Analysis:', 'nvoos-content-graph-ai-platform' );
		$parts[] = $summary;
		$parts[] = '';
		$parts[] = __( 'Based on the failures, propose CRUD operations on roles. Return JSON with create/update/retire arrays.', 'nvoos-content-graph-ai-platform' );

		return implode( "\n", $parts );
	}

	/**
	 * Build the skills evolution query.
	 *
	 * @since 1.2.0
	 *
	 * @param array $current_skills Current skills summary.
	 * @param array $summary        Trajectory summary with signatures.
	 * @return string User message for the Refiner.
	 */
	private function build_skills_evolution_query( $current_skills, $summary ) {
		$parts = array();

		$parts[] = __( 'Current Bundled Skills:', 'nvoos-content-graph-ai-platform' );
		$parts[] = wp_json_encode( $current_skills, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$parts[] = '';
		$parts[] = __( 'Trajectory Analysis:', 'nvoos-content-graph-ai-platform' );
		$parts[] = $summary;
		$parts[] = '';
		$parts[] = __( 'Based on the failures, propose skill create/update operations. Return JSON with create/update arrays.', 'nvoos-content-graph-ai-platform' );

		return implode( "\n", $parts );
	}

	/**
	 * Build the memory evolution query.
	 *
	 * @since 1.2.0
	 *
	 * @param array $memory_overview Current memory overview.
	 * @param array $summary         Trajectory summary with signatures.
	 * @return string User message for the Refiner.
	 */
	private function build_memory_evolution_query( $memory_overview, $summary ) {
		$parts = array();

		$parts[] = __( 'Current Memory Store Overview:', 'nvoos-content-graph-ai-platform' );
		$parts[] = wp_json_encode( $memory_overview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$parts[] = '';
		$parts[] = __( 'Trajectory Analysis:', 'nvoos-content-graph-ai-platform' );
		$parts[] = $summary;
		$parts[] = '';
		$parts[] = __( 'Based on the failures, propose memory add/update operations. Return JSON with add/update arrays.', 'nvoos-content-graph-ai-platform' );

		return implode( "\n", $parts );
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Current State Readers
	// -------------------------------------------------------------------------

	/**
	 * Get a summary of the currently configured agent roles.
	 *
	 * @since 1.2.0
	 *
	 * @return array Roles summary.
	 */
	private function get_current_roles_summary() {
		$roles = array();

		if ( function_exists( 'wp_mcp_ai_get_agent_roles' ) ) {
			$registered = wp_mcp_ai_get_agent_roles();
			foreach ( $registered as $type => $role ) {
				if ( $role instanceof AgentRoleInterface ) {
					$roles[ $type ] = array(
						'type'         => $type,
						'name'         => $role->get_role_name(),
						'description'  => $role->get_role_description(),
						'capabilities' => $role->get_capabilities(),
					);
				}
			}
		}

		return $roles;
	}

	/**
	 * Get a summary of currently bundled skills.
	 *
	 * @since 1.2.0
	 *
	 * @return array Skills summary.
	 */
	private function get_current_skills_summary() {
		$skills = array();

		// Base skills from the skill registry.
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Skill_Registry' ) ) ) {
			$registry = \WP_MCP_AI_Skill_Registry::get_instance();
			if ( method_exists( $registry, 'get_all_skills' ) ) {
				$all_skills = $registry->get_all_skills();
				if ( is_array( $all_skills ) ) {
					foreach ( $all_skills as $skill ) {
						if ( is_array( $skill ) && isset( $skill['id'] ) ) {
							$skills[ $skill['id'] ] = array(
								'id'          => $skill['id'],
								'name'        => isset( $skill['name'] ) ? $skill['name'] : '',
								'description' => isset( $skill['description'] ) ? $skill['description'] : '',
							);
						}
					}
				}
			}
		}

		// Merge evolved skills.
		$evolved = get_option( 'wp_mcp_ai_evolved_skills', array() );
		if ( is_array( $evolved ) ) {
			$skills = array_merge( $skills, $evolved );
		}

		return $skills;
	}

	/**
	 * Get an overview of the current memory store.
	 *
	 * @since 1.2.0
	 *
	 * @return array Memory overview.
	 */
	private function get_current_memory_overview() {
		$overview = array(
			'total_entries' => 0,
			'recent'        => array(),
		);

		// Read from options-based memory store.
		$evolved_memories = get_option( 'wp_mcp_ai_evolved_memories', array() );
		if ( is_array( $evolved_memories ) ) {
			$overview['total_entries'] = count( $evolved_memories );
			$overview['recent']        = array_slice( $evolved_memories, -10, 10, true );
		}

		return $overview;
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Memory Storage
	// -------------------------------------------------------------------------

	/**
	 * Check if the store_agent_context tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if the tool is available.
	 */
	private function is_store_agent_context_available() {
		if ( ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) ) {
			return false;
		}

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		if ( method_exists( $registry, 'get_tool' ) ) {
			$tool = $registry->get_tool( 'store_agent_context' );
			return $tool instanceof WP_MCP_AI_Tool_Interface;
		}

		return false;
	}

	/**
	 * Store memory via the store_agent_context tool.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title      Memory title.
	 * @param string $content    Memory content.
	 * @param float  $importance Importance score (0-1).
	 * @param string $path       Category path.
	 * @param array  $context    Execution context.
	 * @return array|WP_Error Result or error.
	 */
	private function store_memory_via_tool( $title, $content, $importance, $path, $context ) {
		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'store_agent_context' );

		if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
			return new \WP_Error(
				'wp_mcp_ai_evolver_no_tool',
				__( 'store_agent_context tool not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$args = array(
			'title'      => $title,
			'content'    => $content,
			'importance' => max( 0.0, min( 1.0, (float) $importance ) ),
			'kind'       => 'evolved_memory',
		);

		if ( '' !== trim( $path ) ) {
			$args['path'] = sanitize_text_field( $path );
		}

		try {
			return $tool->execute( $args, $context );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wp_mcp_ai_evolver_store_failed', $e->getMessage() );
		}
	}

	/**
	 * Store memory in options (fallback when tool is unavailable).
	 *
	 * @since 1.2.0
	 *
	 * @param string $title      Memory title.
	 * @param string $content    Memory content.
	 * @param float  $importance Importance score (0-1).
	 * @param string $path       Category path.
	 * @return bool True on success.
	 */
	private function store_memory_in_options( $title, $content, $importance, $path ) {
		$evolved_memories = get_option( 'wp_mcp_ai_evolved_memories', array() );
		if ( ! is_array( $evolved_memories ) ) {
			$evolved_memories = array();
		}

		$memory_id                      = sanitize_key( $title ) . '_' . time();
		$evolved_memories[ $memory_id ] = array(
			'id'         => $memory_id,
			'title'      => sanitize_text_field( $title ),
			'content'    => sanitize_textarea_field( $content ),
			'importance' => max( 0.0, min( 1.0, (float) $importance ) ),
			'path'       => sanitize_text_field( $path ),
			'created_at' => time(),
			'evolved'    => true,
		);

		return update_option( 'wp_mcp_ai_evolved_memories', $evolved_memories, false );
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Audit Trail Logging
	// -------------------------------------------------------------------------

	/**
	 * Record an evolution event in the audit trail and local log.
	 *
	 * @since 1.2.0
	 *
	 * @param array $results Evolution results to log.
	 * @return void
	 */
	private function record_evolution_event( $results ) {
		// Log as an audit trail decision event if we have a trail.
		if ( ! empty( $this->trail_id ) ) {
			AgentAuditTrail::log_decision(
				$this->trail_id,
				'harness_evolution',
				array(
					'session_id'   => $this->session_id,
					'assistant_id' => $this->assistant_id,
					'timestamp'    => time(),
					'results'      => $results,
				)
			);
		}
	}

	/**
	 * Persist the in-memory evolution log to options storage.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function persist_evolution_log() {
		update_option(
			self::EVOLUTION_LOG_OPTION . '_' . $this->session_id,
			$this->evolution_log,
			false
		);
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Evolved Role Registration
	// -------------------------------------------------------------------------

	/**
	 * Register evolved roles via the wp_mcp_ai_agent_roles filter.
	 *
	 * Reads all options with the evolved role prefix and makes them available
	 * through the standard agent roles filter.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function register_evolved_roles() {
		add_filter(
			'wp_mcp_ai_agent_roles',
			function ( $roles ) {
				if ( ! is_array( $roles ) ) {
					$roles = array();
				}

				// Discover all evolved role options.
				global $wpdb;
				$prefix = self::EVOLVED_ROLE_OPTION_PREFIX;

				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( $prefix ) . '%'
					)
				);

				if ( ! is_array( $results ) ) {
					return $roles;
				}

				foreach ( $results as $row ) {
					$role_data = maybe_unserialize( $row->option_value );
					if ( ! is_array( $role_data ) || empty( $role_data['type'] ) ) {
						continue;
					}

					// Skip retired roles.
					if ( ! empty( $role_data['retired'] ) ) {
						continue;
					}

					$type = sanitize_key( $role_data['type'] );

					// Don't overwrite built-in roles.
					if ( isset( $roles[ $type ] ) ) {
						continue;
					}

					// Create a lightweight role representation.
					$roles[ $type ] = new AgentHarnessEvolverRoleAdapter(
						$type,
						isset( $role_data['system_instructions'] ) ? $role_data['system_instructions'] : ''
					);
				}

				return $roles;
			},
			20
		);
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Frequency Config
	// -------------------------------------------------------------------------

	/**
	 * Get the evolution frequency configuration.
	 *
	 * @since 1.2.0
	 *
	 * @return array Config with early_frequency, stable_frequency, and cutoff keys.
	 */
	private function get_frequency_config() {
		if ( null !== $this->frequency_config ) {
			return $this->frequency_config;
		}

		$defaults = array(
			'early_frequency'  => self::EARLY_FREQUENCY,
			'stable_frequency' => self::STABLE_FREQUENCY,
			'cutoff'           => self::EARLY_PHASE_CUTOFF,
		);

		/**
		 * Filters the evolution frequency configuration.
		 *
		 * @since 1.2.0
		 *
		 * @param array $config {
		 *     Frequency configuration.
		 *
		 *     @type int $early_frequency  Check every N iterations during early phase.
		 *     @type int $stable_frequency Check every N iterations during stable phase.
		 *     @type int $cutoff           Iteration count that separates early from stable phase.
		 * }
		 */
		$config = apply_filters( 'wp_mcp_ai_harness_evolution_frequency', $defaults );

		if ( ! is_array( $config ) ) {
			$config = $defaults;
		}

		$this->frequency_config = array_merge( $defaults, $config );

		return $this->frequency_config;
	}

	// -------------------------------------------------------------------------
	// Private Helpers — Safe Pass Execution
	// -------------------------------------------------------------------------

	/**
	 * Safely execute a single evolution pass, catching any exceptions.
	 *
	 * @since 1.2.0
	 *
	 * @param string $method Method name on this class.
	 * @param array  $args   Arguments to pass.
	 * @return array Result array, always with 'error' key.
	 */
	private function safe_evolve_pass( $method, $args ) {
		try {
			if ( ! method_exists( $this, $method ) ) {
				return array(
					'pass'   => str_replace( 'evolve_', '', $method ),
					'status' => 'error',
					'error'  => true,
					'reason' => sprintf(
						/* translators: %s: method name */
						__( 'Method %s does not exist.', 'nvoos-content-graph-ai-platform' ),
						$method
					),
				);
			}

			$result = call_user_func_array( array( $this, $method ), $args );

			if ( is_wp_error( $result ) ) {
				return array(
					'pass'   => str_replace( 'evolve_', '', $method ),
					'status' => 'error',
					'error'  => true,
					'reason' => $result->get_error_message(),
				);
			}

			if ( ! is_array( $result ) ) {
				return array(
					'pass'   => str_replace( 'evolve_', '', $method ),
					'status' => 'error',
					'error'  => true,
					'reason' => __( 'Unexpected return type from evolution pass.', 'nvoos-content-graph-ai-platform' ),
				);
			}

			return $result;
		} catch ( \Throwable $e ) {
			return array(
				'pass'   => str_replace( 'evolve_', '', $method ),
				'status' => 'error',
				'error'  => true,
				'reason' => $e->getMessage(),
			);
		}
	}
}


// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Lightweight adapter class.

/**
 * Lightweight adapter to expose an evolved role through the
 * AgentRoleInterface contract.
 *
 * @since 1.2.0
 */
class AgentHarnessEvolverRoleAdapter implements AgentRoleInterface {

	/**
	 * Role type identifier.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	private $type;

	/**
	 * System instructions for this evolved role.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	private $system_instructions;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param string $type                Role type.
	 * @param string $system_instructions Refiner-provided system instructions.
	 */
	public function __construct( $type, $system_instructions = '' ) {
		$this->type                = sanitize_key( $type );
		$this->system_instructions = sanitize_textarea_field( $system_instructions );
	}

	/**
	 * Get the role type identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_role_type() {
		return $this->type;
	}

	/**
	 * Get the human-readable role name.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_role_name() {
		return sprintf(
			/* translators: %s: role type */
			__( 'Evolved Role: %s', 'nvoos-content-graph-ai-platform' ),
			$this->type
		);
	}

	/**
	 * Get the role description.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_role_description() {
		return sprintf(
			/* translators: %s: role type */
			__( 'An AI-evolved agent role (%s) created by the Harness Evolver.', 'nvoos-content-graph-ai-platform' ),
			$this->type
		);
	}

	/**
	 * Get capabilities specific to this role.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string>
	 */
	public function get_capabilities() {
		return array( 'can-delegate' );
	}

	/**
	 * Get recommended tools for this role.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string>
	 */
	public function get_recommended_tools() {
		/**
		 * Filters recommended tools for an evolved role.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $tools Empty by default — fill via filter.
		 * @param string $type  Evolved role type.
		 */
		return apply_filters( 'wp_mcp_ai_evolved_role_recommended_tools', array(), $this->type );
	}

	/**
	 * Get recommended system prompt additions for this role.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_system_prompt_additions() {
		return $this->system_instructions;
	}

	/**
	 * Execute a role-specific task.
	 *
	 * @since 1.2.0
	 *
	 * @param array $task    Task data.
	 * @param array $context Execution context.
	 * @return array|WP_Error Task result or error.
	 */
	public function execute_role_task( $task, $context ) {
		return new \WP_Error(
			'wp_mcp_ai_evolved_role_not_executable',
			__( 'Evolved roles are advisory only and do not support direct task execution.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Check if this role can delegate tasks.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function can_delegate() {
		return in_array( 'can-delegate', $this->get_capabilities(), true );
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile
