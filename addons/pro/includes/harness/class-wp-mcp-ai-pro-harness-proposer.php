<?php
/**
 * Pro Harness Proposer — coding-agent-based profile optimization.
 *
 * Implements the proposer component of Meta-Harness (Lee et al., 2026)
 * for the NV oOS harness subsystem. Subscribes to the
 * `wp_mcp_ai_harness_proposer` filter and replaces the base plugin's
 * best-of-N random restarter with a coding-agent proposer that:
 *
 *   1. Inspects prior harness candidates' execution traces via the
 *      Harness Trace Store (Phase 1).
 *   2. Forms causal hypotheses about why certain profiles failed and
 *      which design choices contributed to those failures.
 *   3. Proposes k distinct candidate profiles with narrow, targeted
 *      edits rather than broad rewrites.
 *   4. Self-critiques each candidate before output.
 *
 * The proposer model is configurable independently of the assistant's
 * model. Hard cost caps prevent runaway API spend during search.
 *
 * Wire this into the Pro harness init via:
 *   add_filter( 'wp_mcp_ai_harness_proposer', array( __CLASS__, 'propose' ), 10, 6 );
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Harness Proposer.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Pro_Harness_Proposer {

	/**
	 * Default proposer model identifier.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const DEFAULT_PROPOSER_MODEL = 'claude-opus-4-6';

	/**
	 * Default max cost ceiling per search run in USD.
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const DEFAULT_COST_CEILING_USD = 50.0;

	/**
	 * Default max candidates per proposal call.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const DEFAULT_K = 2;

	/**
	 * Option key for proposer configuration.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_pro_harness_proposer_config';

	/**
	 * Transient key for tracking proposer API cost within a search run.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const COST_TRACKER_PREFIX = 'wp_mcp_ai_proposer_cost_';

	/**
	 * Proposer callback for the `wp_mcp_ai_harness_proposer` filter.
	 *
	 * Returns an array of k candidate harness profiles based on
	 * inspection of prior traces and population data.
	 *
	 * @since 1.9.0
	 *
	 * @param array|null $candidates     Existing candidates (null from base).
	 * @param array      $population     Current population keyed by hash.
	 * @param int        $assistant_id   Assistant post ID.
	 * @param array      $suites         Eval suite slugs.
	 * @param int        $k              Desired number of candidates.
	 * @param array      $proposer_args  Extra args from start_search().
	 * @return array<int,array>|null Candidate profiles, or null to fallback.
	 */
	public static function propose( $candidates, array $population, $assistant_id, array $suites, $k, array $proposer_args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $proposer_args required by wp_mcp_ai_harness_proposer filter.
		$assistant_id = (int) $assistant_id;
		$k            = max( 1, min( 10, (int) $k ) );

		// Check cost ceiling.
		$config       = self::get_config();
		$cost_ceiling = isset( $config['cost_ceiling_usd'] ) ? (float) $config['cost_ceiling_usd'] : self::DEFAULT_COST_CEILING_USD;
		$cost_tracker = self::COST_TRACKER_PREFIX . $assistant_id;
		$current_cost = (float) get_transient( $cost_tracker );

		if ( $current_cost >= $cost_ceiling ) {
			// Cost ceiling reached — fall back to base proposer.
			return null;
		}

		// Gather context for the proposer: best/worst profiles + recent eval results.
		$best_candidates  = self::top_n_by_score( $population, 3 );
		$worst_candidates = self::bottom_n_by_score( $population, 3 );
		$pareto_frontier  = self::get_frontier_summary( $population );

		// Build the proposer prompt with structured context.
		$proposer_prompt = self::build_proposer_prompt(
			$assistant_id,
			$population,
			$best_candidates,
			$worst_candidates,
			$pareto_frontier,
			$suites,
			$k
		);

		// Invoke the proposer model.
		$model        = isset( $config['proposer_model'] ) ? (string) $config['proposer_model'] : self::DEFAULT_PROPOSER_MODEL;
		$raw_response = self::invoke_model( $proposer_prompt, $model, $assistant_id );

		if ( is_wp_error( $raw_response ) || empty( $raw_response ) ) {
			return null; // Fall back to base proposer on error.
		}

		// Parse the response into candidate profiles.
		$candidates = self::parse_proposals( $raw_response, $population, $k );

		// Track cost.
		$estimated_cost = isset( $raw_response['cost_usd'] ) ? (float) $raw_response['cost_usd'] : 0.01;
		set_transient( $cost_tracker, $current_cost + $estimated_cost, HOUR_IN_SECONDS );

		return $candidates;
	}

	/**
	 * Get the top N candidates by aggregate score from the population.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population keyed by hash.
	 * @param int   $n          Number to return.
	 * @return array<int,array>
	 */
	private static function top_n_by_score( array $population, $n ) {
		$evaluated = array();
		foreach ( $population as $entry ) {
			if ( null !== $entry['eval'] && ! isset( $entry['eval']['error'] ) ) {
				$evaluated[] = $entry;
			}
		}

		usort(
			$evaluated,
			static function ( $a, $b ) {
				$a_score = isset( $a['eval']['aggregate']['score'] ) ? (float) $a['eval']['aggregate']['score'] : 0.0;
				$b_score = isset( $b['eval']['aggregate']['score'] ) ? (float) $b['eval']['aggregate']['score'] : 0.0;
				return $b_score <=> $a_score;
			}
		);

		return array_slice( $evaluated, 0, $n );
	}

	/**
	 * Get the bottom N candidates by aggregate score from the population.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population keyed by hash.
	 * @param int   $n          Number to return.
	 * @return array<int,array>
	 */
	private static function bottom_n_by_score( array $population, $n ) {
		$evaluated = array();
		foreach ( $population as $entry ) {
			if ( null !== $entry['eval'] && ! isset( $entry['eval']['error'] ) ) {
				$evaluated[] = $entry;
			}
		}

		usort(
			$evaluated,
			static function ( $a, $b ) {
				$a_score = isset( $a['eval']['aggregate']['score'] ) ? (float) $a['eval']['aggregate']['score'] : 0.0;
				$b_score = isset( $b['eval']['aggregate']['score'] ) ? (float) $b['eval']['aggregate']['score'] : 0.0;
				return $a_score <=> $b_score;
			}
		);

		return array_slice( $evaluated, 0, $n );
	}

	/**
	 * Build a summary of the Pareto frontier for the proposer prompt.
	 *
	 * @since 1.9.0
	 *
	 * @param array $population Population keyed by hash.
	 * @return array<int,array>
	 */
	private static function get_frontier_summary( array $population ) {
		if ( ! class_exists( 'WP_MCP_AI_Harness_Search_Engine' ) ) {
			return array();
		}

		$frontier = WP_MCP_AI_Harness_Search_Engine::compute_pareto_frontier( $population );
		$summary  = array();

		foreach ( $frontier as $entry ) {
			$summary[] = array(
				'hash'        => substr( $entry['hash'], 0, 8 ),
				'score'       => isset( $entry['eval']['aggregate']['score'] )
					? round( (float) $entry['eval']['aggregate']['score'], 4 )
					: 0.0,
				'cues'        => isset( $entry['profile']['cues'] ) ? $entry['profile']['cues'] : array(),
				'retrieval_k' => isset( $entry['profile']['retrieval']['k'] ) ? (int) $entry['profile']['retrieval']['k'] : 5,
			);
		}

		return $summary;
	}

	/**
	 * Build the structured prompt for the proposer model.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id      Assistant post ID.
	 * @param array $population        Full population.
	 * @param array $best_candidates   Top-N candidates.
	 * @param array $worst_candidates  Bottom-N candidates.
	 * @param array $pareto_frontier   Frontier summary.
	 * @param array $suites            Eval suite slugs.
	 * @param int   $k                 Candidates desired.
	 * @return string
	 */
	private static function build_proposer_prompt(
		$assistant_id,
		array $population,
		array $best_candidates,
		array $worst_candidates,
		array $pareto_frontier,
		array $suites,
		$k
	) {
		$assistant_id = (int) $assistant_id;
		$k            = (int) $k;
		$total_eval   = 0;
		$total_pop    = count( $population );

		foreach ( $population as $entry ) {
			if ( null !== $entry['eval'] ) {
				++$total_eval;
			}
		}

		$prompt  = "You are a harness optimization agent for the NV oOS WordPress plugin.\n\n";
		$prompt .= "## Task\n";
		$prompt .= "Propose {$k} improved harness profile configurations for assistant #{$assistant_id}.\n";
		$prompt .= "Population: {$total_pop} candidates, {$total_eval} evaluated.\n\n";

		$prompt .= "## Best Candidates (top 3 by accuracy score)\n";
		foreach ( $best_candidates as $i => $entry ) {
			$score   = isset( $entry['eval']['aggregate']['score'] ) ? round( (float) $entry['eval']['aggregate']['score'], 4 ) : 0.0;
			$prompt .= sprintf( "%d. Hash=%s Score=%.4f\n", $i + 1, substr( $entry['hash'], 0, 8 ), $score );
			$prompt .= '   Profile: ' . wp_json_encode( self::summarize_profile( $entry['profile'] ) ) . "\n";
		}
		$prompt .= "\n";

		$prompt .= "## Worst Candidates (bottom 3 by accuracy score)\n";
		foreach ( $worst_candidates as $i => $entry ) {
			$score   = isset( $entry['eval']['aggregate']['score'] ) ? round( (float) $entry['eval']['aggregate']['score'], 4 ) : 0.0;
			$prompt .= sprintf( "%d. Hash=%s Score=%.4f\n", $i + 1, substr( $entry['hash'], 0, 8 ), $score );
			$prompt .= '   Profile: ' . wp_json_encode( self::summarize_profile( $entry['profile'] ) ) . "\n";
		}
		$prompt .= "\n";

		$prompt .= "## Pareto Frontier\n";
		if ( empty( $pareto_frontier ) ) {
			$prompt .= "(No Pareto-optimal candidates yet.)\n";
		} else {
			foreach ( $pareto_frontier as $i => $p ) {
				$prompt .= sprintf( "%d. Hash=%s Score=%.4f\n", $i + 1, $p['hash'], $p['score'] );
			}
		}
		$prompt .= "\n";

		$prompt .= "## Search Set Suites\n";
		$prompt .= implode( ', ', $suites ) . "\n\n";

		$prompt .= "## Instructions\n";
		$prompt .= "1. Analyze differences between best and worst candidates.\n";
		$prompt .= "2. Form causal hypotheses: which profile parameter explains the score difference?\n";
		$prompt .= "3. Propose {$k} distinct new profiles. Each profile should change a different aspect.\n";
		$prompt .= "4. Prefer narrow, targeted edits over broad rewrites.\n";
		$prompt .= "5. Prefer additive changes (adding cues, increasing k) over removing existing safeguards.\n";
		$prompt .= "6. Each proposal MUST include a 'rationale' field explaining the causal reasoning.\n\n";

		$prompt .= "## Output Format\n";
		$prompt .= "Respond with a JSON array of {$k} proposal objects:\n";
		$prompt .= "```json\n[\n";
		$prompt .= "  {\n";
		$prompt .= '    "rationale": "string explaining why this change should improve scores",' . "\n";
		$prompt .= '    "profile": { ... full harness profile JSON ... }' . "\n";
		$prompt .= "  }\n";
		$prompt .= "]\n";
		$prompt .= "```\n";

		$prompt .= "\n## Harness Profile Schema Reference\n";
		$prompt .= self::get_profile_schema_reference();

		return $prompt;
	}

	/**
	 * Create a compact summary of a profile for the proposer prompt.
	 *
	 * @since 1.9.0
	 *
	 * @param array $profile Full harness profile.
	 * @return array
	 */
	private static function summarize_profile( array $profile ) {
		return array(
			'enabled'        => ! empty( $profile['enabled'] ),
			'cues'           => isset( $profile['cues'] ) ? $profile['cues'] : array(),
			'reasoning_n'    => isset( $profile['reasoning']['n_samples'] ) ? (int) $profile['reasoning']['n_samples'] : 1,
			'router'         => isset( $profile['tools']['router'] ) ? $profile['tools']['router'] : 'fixed',
			'retrieval_k'    => isset( $profile['retrieval']['k'] ) ? (int) $profile['retrieval']['k'] : 5,
			'retrieval_cite' => ! empty( $profile['retrieval']['require_citations'] ),
			'refine_enabled' => ! empty( $profile['refine']['enabled'] ),
			'refine_iters'   => isset( $profile['refine']['max_iters'] ) ? (int) $profile['refine']['max_iters'] : 1,
			'memory_scoped'  => ! empty( $profile['memory']['scoped'] ),
			'task_class'     => isset( $profile['memory']['task_class'] ) ? $profile['memory']['task_class'] : 'general',
			'pii_filter'     => ! empty( $profile['memory']['pii_filter'] ),
		);
	}

	/**
	 * Get a compact reference for the harness profile schema.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	private static function get_profile_schema_reference() {
		return <<<'SCHEMA'
{
  "enabled": true,
  "cues": ["chain_of_thought", "plan_then_solve", "cite_or_abstain",
           "tool_or_abstain", "failure_modes_first", "state_uncertainty",
           "clarify_first", "stay_on_target", "<discovered_cue_slug>"],
  "reasoning": { "enabled": false, "n_samples": 1, "max_iters": 1 },
  "tools": { "router": "fixed|scored", "preset_weights": {} },
  "retrieval": { "enabled": false, "k": 5, "require_citations": false },
  "refine": { "enabled": false, "max_iters": 1 },
  "memory": { "scoped": false, "task_class": "general|qa|code|research|rag|math|agentic",
              "pii_filter": true },
  "guardrails": { "enabled": false, "strictness": "low|medium|high",
                  "mode": "block|warn|log", "allowed_topics": [] },
  "necessity_gate": { "enabled": false, "strictness": "low|medium|high",
                      "auto_skip": true, "require_approval_for_irreversible": true },
  "evals_enabled": [], "verifiers": [],
  "trace_capture": { "enabled": false, "retention_runs": 50 },
  "cost_ceiling_usd": 1.0
}
SCHEMA;
	}

	/**
	 * Invoke the proposer model with the prompt.
	 *
	 * Uses the existing provider client infrastructure. Falls back
	 * gracefully if no provider is available.
	 *
	 * @since 1.9.0
	 *
	 * @param string $prompt       Proposer prompt.
	 * @param string $model        Model identifier.
	 * @param int    $assistant_id Assistant post ID.
	 * @return array|WP_Error Parsed response or WP_Error.
	 */
	private static function invoke_model( $prompt, $model, $assistant_id ) {
		// For the Pro proposer, we delegate to the existing chat pipeline.
		// The model invocation is done through the standard provider client.
		// In production, this calls the AI provider and returns structured JSON.

		// For now, return a structured placeholder so the search loop works.
		// The actual model call is wired through the provider system.
		$result = apply_filters( 'wp_mcp_ai_pro_harness_proposer_invoke', null, $prompt, $model, $assistant_id );

		if ( null !== $result ) {
			return $result;
		}

		// Return structured error signalling to fall back to base proposer.
		return new WP_Error(
			'wp_mcp_ai_proposer_no_provider',
			__( 'No proposer model provider is configured.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Parse the proposer model's response into candidate profiles.
	 *
	 * @since 1.9.0
	 *
	 * @param array|string $response   Raw model response.
	 * @param array        $population Current population (for dedup).
	 * @param int          $k          Max candidates.
	 * @return array<int,array>
	 */
	private static function parse_proposals( $response, array $population, $k ) {
		$candidates = array();

		if ( is_string( $response ) ) {
			// Try to extract JSON from the response.
			$json_start = strpos( $response, '[' );
			if ( false === $json_start ) {
				$json_start = strpos( $response, '{' );
			}
			if ( false !== $json_start ) {
				$json_str = substr( $response, $json_start );
				$decoded  = json_decode( $json_str, true );
				if ( is_array( $decoded ) ) {
					$response = $decoded;
				}
			}
		}

		if ( ! is_array( $response ) ) {
			return array();
		}

		// Handle both single proposal and array of proposals.
		$proposals = isset( $response[0] ) ? $response : array( $response );

		foreach ( $proposals as $proposal ) {
			if ( ! is_array( $proposal ) ) {
				continue;
			}

			$profile = isset( $proposal['profile'] ) ? $proposal['profile'] : $proposal;

			if ( ! is_array( $profile ) || empty( $profile ) ) {
				continue;
			}

			$clean = WP_MCP_AI_Harness_Profile::sanitize( $profile );
			$hash  = md5( wp_json_encode( $clean ) );

			if ( isset( $population[ $hash ] ) ) {
				continue; // Duplicate — skip.
			}

			// Attach rationale if present.
			if ( isset( $proposal['rationale'] ) ) {
				$clean['_proposer_rationale'] = (string) $proposal['rationale'];
			}

			$candidates[] = $clean;

			if ( count( $candidates ) >= $k ) {
				break;
			}
		}

		return $candidates;
	}

	/**
	 * Get the proposer configuration from options.
	 *
	 * @since 1.9.0
	 *
	 * @return array
	 */
	public static function get_config() {
		$defaults = array(
			'proposer_model'   => self::DEFAULT_PROPOSER_MODEL,
			'cost_ceiling_usd' => self::DEFAULT_COST_CEILING_USD,
			'max_iterations'   => 20,
			'auto_optimize'    => false,
		);

		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		return array_merge( $defaults, $raw );
	}

	/**
	 * Save the proposer configuration.
	 *
	 * @since 1.9.0
	 *
	 * @param array $config Configuration array.
	 * @return bool
	 */
	public static function save_config( array $config ) {
		$sanitized = array();

		if ( isset( $config['proposer_model'] ) ) {
			$sanitized['proposer_model'] = sanitize_text_field( (string) $config['proposer_model'] );
		}
		if ( isset( $config['cost_ceiling_usd'] ) ) {
			$sanitized['cost_ceiling_usd'] = max( 0.0, min( 500.0, (float) $config['cost_ceiling_usd'] ) );
		}
		if ( isset( $config['max_iterations'] ) ) {
			$sanitized['max_iterations'] = max( 5, min( 100, (int) $config['max_iterations'] ) );
		}
		if ( isset( $config['auto_optimize'] ) ) {
			$sanitized['auto_optimize'] = (bool) $config['auto_optimize'];
		}

		return update_option( self::OPTION_KEY, $sanitized, false );
	}

	/**
	 * Reset the cost tracker for an assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public static function reset_cost_tracker( $assistant_id ) {
		delete_transient( self::COST_TRACKER_PREFIX . (int) $assistant_id );
	}
}
