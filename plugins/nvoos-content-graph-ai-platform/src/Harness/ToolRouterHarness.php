<?php
/**
 * Tool Router Harness — Layer C scoring façade.
 *
 * Score and rank candidate tools for a given task class. Extends — does not
 * replace — the existing `Tool_Chain_Predictor`. The score is a transparent
 * weighted sum over capability flags, recent reliability (when the
 * measurement subsystem has data for the slug), and the assistant's declared
 * preferences.
 *
 * The score is exposed through the `wp_mcp_ai_harness_tool_score` filter so
 * the Pro addon can swap in a learned model (e.g. logistic regression over
 * eval-harness outcomes) without touching the base implementation.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
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
 * Tool Router Harness.
 *
 * @since 1.4.0
 */
class ToolRouterHarness {

	/**
	 * RRF constant — the smoothing parameter that prevents division by
	 * near-zero for top-ranked items. Industry standard is 60 (Elasticsearch,
	 * OpenSearch, MongoDB all use this default).
	 *
	 * @since 1.8.0
	 * @var int
	 */
	const RRF_K = 60;

	/**
	 * Per-stage weights for Reciprocal Rank Fusion.
	 *
	 * Different task classes benefit differently from semantic vs. structural
	 * scoring. Research/RAG tasks need semantic matching (which tool
	 * understands the domain?), while code tasks need structural matching
	 * (which tool is idempotent/safe?).
	 *
	 * Keys: 'attention' = semantic embedding similarity (Stage 1)
	 *       'harness'   = capability flags + preferences (Stage 2)
	 *
	 * @since 1.8.0
	 * @return array<string,array<string,float>>
	 */
	private static function stage_weights() {
		return array(
			'research' => array(
				'attention' => 1.5,
				'harness'   => 0.5,
			),
			'rag'      => array(
				'attention' => 1.5,
				'harness'   => 0.5,
			),
			'qa'       => array(
				'attention' => 1.2,
				'harness'   => 0.8,
			),
			'math'     => array(
				'attention' => 0.5,
				'harness'   => 1.5,
			),
			'code'     => array(
				'attention' => 0.8,
				'harness'   => 1.2,
			),
			'agentic'  => array(
				'attention' => 1.0,
				'harness'   => 1.0,
			),
			'general'  => array(
				'attention' => 1.0,
				'harness'   => 1.0,
			),
		);
	}

	/**
	 * Coarse mapping from task class to the capability flags that tend to
	 * help. Intentionally conservative — better to miss a useful tool than to
	 * recommend a write tool for a read-only task.
	 *
	 * @return array<string,array<string,float>>
	 */
	private static function task_flag_weights() {
		return array(
			'general'  => array(
				'read-only' => 1.0,
			),
			'qa'       => array(
				'read-only' => 1.5,
				'cacheable' => 0.5,
			),
			'research' => array(
				'read-only'    => 1.5,
				'external-api' => 1.0,
				'cacheable'    => 0.5,
			),
			'rag'      => array(
				'read-only' => 2.0,
			),
			'math'     => array(
				'local-only' => 1.5,
				'idempotent' => 1.0,
			),
			'code'     => array(
				'read-only'  => 1.0,
				'idempotent' => 1.0,
			),
			'agentic'  => array(
				'read-only'  => 1.0,
				'reversible' => 0.8,
				'idempotent' => 0.5,
			),
		);
	}

	/**
	 * Score a single tool for a task class.
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool            Tool instance.
	 * @param string                   $task_class      Task class slug.
	 * @param array                    $assistant_prefs Per-assistant slug-level preferences (slug → weight).
	 * @param array                    $preset_weights  Per-assistant preset-family weights (preset_slug → weight),
	 *                                                  resolved against `\WP_MCP_AI_Tool_Presets_Helper::get_presets()`.
	 * @param float|null               $attention_score  Semantic attention score for this tool (0–1), or null if unavailable.
	 * @return float Score; higher is better.
	 */
	public static function score_tool( $tool, $task_class, array $assistant_prefs = array(), array $preset_weights = array(), $attention_score = null ) {
		$task_class = sanitize_key( (string) $task_class );
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$score = 1.0;

		$flags = array();
		if ( $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = (array) $tool->get_capability_flags();
		}

		$weights_table = self::task_flag_weights();
		$weights       = isset( $weights_table[ $task_class ] ) ? $weights_table[ $task_class ] : $weights_table['general'];
		foreach ( $weights as $flag => $weight ) {
			if ( in_array( $flag, $flags, true ) ) {
				$score += (float) $weight;
			}
		}

		// Penalize state-changing or write tools for read-leaning task classes.
		if ( in_array( $task_class, array( 'qa', 'research', 'rag', 'math' ), true ) ) {
			if ( in_array( 'state-changing', $flags, true ) || in_array( 'write', $flags, true ) ) {
				$score -= 1.5;
			}
		}

		$slug = method_exists( $tool, 'get_slug' ) ? sanitize_key( $tool->get_slug() ) : '';

		// Apply explicit assistant preference (highest single signal).
		if ( '' !== $slug && isset( $assistant_prefs[ $slug ] ) ) {
			$score += (float) $assistant_prefs[ $slug ];
		}

		// Apply preset-family weights. A tool that appears in multiple weighted
		// presets accumulates the sum of those weights — this is intentional so
		// admins can layer broad family preferences with narrower overrides.
		if ( '' !== $slug && ! empty( $preset_weights ) ) {
			$family_index = self::tool_to_presets_index();
			if ( isset( $family_index[ $slug ] ) ) {
				foreach ( $family_index[ $slug ] as $preset_slug ) {
					if ( isset( $preset_weights[ $preset_slug ] ) ) {
						$score += (float) $preset_weights[ $preset_slug ];
					}
				}
			}
		}

		/**
		 * Filter the harness score for a tool.
		 *
		 * @since 1.4.0
		 *
		 * @param float                    $score             Default score from the base scoring rules.
		 * @param WP_MCP_AI_Tool_Interface $tool              Tool instance.
		 * @param string                   $task_class        Task class slug.
		 * @param array                    $assistant_prefs   Per-assistant slug-level preferences.
		 * @param array                    $preset_weights    Per-assistant preset-family weights.
		 * @param float|null               $attention_score   Semantic attention score for this tool (0–1), or null if unavailable.
		 */
		$score = (float) apply_filters( 'wp_mcp_ai_harness_tool_score', $score, $tool, $task_class, $assistant_prefs, $preset_weights, $attention_score );

		return $score;
	}

	/**
	 * Rank an iterable of tools for a task class. Returns slug => score
	 * sorted descending.
	 *
	 * When $attention_scores is provided (slug => cosine_similarity), the
	 * harness fuses its own structural scores with the attention router's
	 * semantic scores via Weighted Reciprocal Rank Fusion (RRF) — the
	 * industry-standard method for combining heterogeneous ranking signals.
	 *
	 * @since 1.4.0
	 *
	 * @param iterable            $tools            Tool instances.
	 * @param string              $task_class       Task class.
	 * @param array               $assistant_prefs  Optional per-assistant slug-level preferences.
	 * @param array               $preset_weights   Optional per-assistant preset-family weights.
	 * @param array<string,float> $attention_scores Optional semantic attention scores (slug => 0–1).
	 * @return array<string,float>
	 */
	public static function rank( $tools, $task_class, array $assistant_prefs = array(), array $preset_weights = array(), array $attention_scores = array() ) {
		$scored = array();
		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof \WP_MCP_AI_Tool_Interface ) {
				continue;
			}
			$slug = sanitize_key( $tool->get_slug() );
			if ( '' === $slug ) {
				continue;
			}

			// Resolve per-tool attention score (null if unavailable).
			$attn = isset( $attention_scores[ $slug ] ) ? (float) $attention_scores[ $slug ] : null;

			$scored[ $slug ] = self::score_tool( $tool, $task_class, $assistant_prefs, $preset_weights, $attn );
		}

		// If attention scores are available, fuse via RRF instead of pure harness ranking.
		if ( ! empty( $attention_scores ) ) {
			return self::fuse_with_rrf( $scored, $attention_scores, $task_class );
		}

		arsort( $scored, SORT_NUMERIC );
		return $scored;
	}

	// -------------------------------------------------------------------------
	// Reciprocal Rank Fusion (RRF)
	// -------------------------------------------------------------------------

	/**
	 * Fuse harness structural scores with attention-router semantic scores
	 * using Weighted Reciprocal Rank Fusion.
	 *
	 * RRF is the industry-standard method for combining heterogeneous ranking
	 * signals (used by Elasticsearch, OpenSearch, MongoDB). It operates on
	 * ranks rather than raw scores, making it immune to scale differences
	 * between the two scoring systems.
	 *
	 * Formula:
	 *   RRF(d) = Σ  w_stage × 1 / (k + rank_stage(d))
	 *
	 * where k = 60 (standard smoothing constant).
	 *
	 * @since 1.8.0
	 *
	 * @param array<string,float> $harness_scores   Harness structural scores (slug => score).
	 * @param array<string,float> $attention_scores Attention semantic scores (slug => 0–1).
	 * @param string              $task_class       Task class for stage-weight selection.
	 * @return array<string,float> Fused scores, sorted descending.
	 */
	public static function fuse_with_rrf( array $harness_scores, array $attention_scores, $task_class ) {
		$k = self::RRF_K;

		// Rank by each scorer independently (descending — rank 0 = highest score).
		arsort( $harness_scores, SORT_NUMERIC );
		arsort( $attention_scores, SORT_NUMERIC );

		// Build rank maps: slug => 0-based rank.
		$harness_ranks   = array_flip( array_keys( $harness_scores ) );
		$attention_ranks = array_flip( array_keys( $attention_scores ) );

		// Resolve per-stage weights for this task class.
		$sw          = self::stage_weights();
		$tc          = isset( $sw[ $task_class ] ) ? $task_class : 'general';
		$w_harness   = $sw[ $tc ]['harness'];
		$w_attention = $sw[ $tc ]['attention'];

		/**
		 * Filter the RRF stage weights for a task class.
		 *
		 * Allows per-assistant or global tuning of how much weight the
		 * semantic (attention) stage gets vs. the structural (harness) stage.
		 *
		 * @since 1.8.0
		 *
		 * @param float  $w_harness   Weight for harness structural scoring.
		 * @param float  $w_attention Weight for attention semantic scoring.
		 * @param string $task_class  Task class slug.
		 */
		$w_harness   = (float) apply_filters( 'wp_mcp_ai_harness_rrf_weight_harness', $w_harness, $task_class );
		$w_attention = (float) apply_filters( 'wp_mcp_ai_harness_rrf_weight_attention', $w_attention, $task_class );

		// Fuse: every slug that appears in either rank list.
		$all_slugs = array_unique( array_merge( array_keys( $harness_scores ), array_keys( $attention_scores ) ) );
		$max_rank  = max( count( $harness_scores ), count( $attention_scores ), 1 );

		$fused = array();
		foreach ( $all_slugs as $slug ) {
			$hr = isset( $harness_ranks[ $slug ] ) ? $harness_ranks[ $slug ] : $max_rank;
			$ar = isset( $attention_ranks[ $slug ] ) ? $attention_ranks[ $slug ] : $max_rank;

			$fused[ $slug ] = ( $w_harness * ( 1.0 / ( $k + $hr ) ) )
				+ ( $w_attention * ( 1.0 / ( $k + $ar ) ) );
		}

		arsort( $fused, SORT_NUMERIC );
		return $fused;
	}

	/**
	 * Resolve the per-assistant routing inputs from a stored harness profile.
	 *
	 * Returns a tuple `[ slug_preferences, preset_weights ]` so callers can pass
	 * them straight into {@see self::rank()} or {@see self::score_tool()}
	 * without reaching into the profile structure.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array{0:array<string,float>,1:array<string,float>}
	 */
	public static function resolve_assistant_inputs( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		$profile      = HarnessProfile::get( $assistant_id );

		$preset_weights = array();
		if ( isset( $profile['tools']['preset_weights'] ) && is_array( $profile['tools']['preset_weights'] ) ) {
			$preset_weights = $profile['tools']['preset_weights'];
		}

		// Slug-level prefs are not yet surfaced in the metabox UI, but the
		// shape is reserved on the profile for forward compatibility and Pro
		// addons that wish to inject explicit slug weights.
		$slug_prefs = array();
		if ( isset( $profile['tools']['preferences'] ) && is_array( $profile['tools']['preferences'] ) ) {
			foreach ( $profile['tools']['preferences'] as $slug => $weight ) {
				$slug_key = sanitize_key( (string) $slug );
				if ( '' !== $slug_key ) {
					$slug_prefs[ $slug_key ] = (float) $weight;
				}
			}
		}

		return array( $slug_prefs, $preset_weights );
	}

	/**
	 * Build (and cache for the request) a reverse index mapping every tool
	 * slug declared by the preset library to the list of preset slugs that
	 * contain it.
	 *
	 * The presets list is essentially static during a request (it's a hard-coded
	 * array in `WP_MCP_AI_Tool_Presets_Helper`), so a process-local cache is
	 * sufficient — no transients required.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function tool_to_presets_index() {
		static $index = null;
		if ( null !== $index ) {
			return $index;
		}

		$index = array();
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Tool_Presets_Helper' ) ) {
			return $index;
		}

		$presets = \WP_MCP_AI_Tool_Presets_Helper::get_presets();
		if ( ! is_array( $presets ) ) {
			return $index;
		}

		foreach ( $presets as $preset_slug => $preset ) {
			$preset_key = sanitize_key( (string) $preset_slug );
			if ( '' === $preset_key || empty( $preset['tools'] ) || ! is_array( $preset['tools'] ) ) {
				continue;
			}
			foreach ( $preset['tools'] as $tool_slug ) {
				$tool_key = sanitize_key( (string) $tool_slug );
				if ( '' === $tool_key ) {
					continue;
				}
				if ( ! isset( $index[ $tool_key ] ) ) {
					$index[ $tool_key ] = array();
				}
				$index[ $tool_key ][] = $preset_key;
			}
		}

		// De-duplicate per tool.
		foreach ( $index as $tool_key => $slugs ) {
			$index[ $tool_key ] = array_values( array_unique( $slugs ) );
		}

		return $index;
	}
}
