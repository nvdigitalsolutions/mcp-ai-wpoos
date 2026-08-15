<?php
/**
 * Tool Attention Router — Attention-based tool selection for LLM payloads.
 *
 * Applies Transformer-inspired attention mechanics to the tool selection
 * problem: the user query is the "Query", each tool definition is a "Key",
 * and the tool's value is its capability. Multiple "attention heads" score
 * tools on different dimensions (semantic, capability, recency, dependency,
 * risk) and the scores are fused into a final ranking.
 *
 * Architecturally analogous to multi-head self-attention, but operating at
 * the plugin orchestration layer rather than inside the neural network:
 *
 *   Q = embedding(query + conversation context)
 *   K = embedding(tool.name + tool.description)    [pre-computed]
 *   Attention(Q, K, V) = softmax( scores ) · V
 *
 * where scores come from multiple independent "heads".
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attention-based tool selection router.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Tool_Attention_Router {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Tool_Attention_Router|null
	 */
	private static $instance = null;

	/**
	 * Cached score breakdown from the most recent select_tools() call.
	 *
	 * Stored so that the harness Tool_Router_Harness can read attention
	 * scores for RRF fusion without recomputing embeddings.
	 *
	 * Format: [ ['slug' => string, 'score' => float, 'heads' => array], ... ]
	 *
	 * @since 1.8.0
	 * @var array|null
	 */
	private static $last_scores = null;

	/**
	 * Default number of tools to select (top-K).
	 *
	 * @var int
	 */
	const DEFAULT_TOP_K = 40;

	/**
	 * Minimum tools to always include regardless of score (safety margin).
	 *
	 * Ensures critical system tools are never filtered out.
	 *
	 * @var int
	 */
	const MIN_TOOLS = 10;

	/**
	 * Attention-head weight defaults.
	 *
	 * Weights sum to 1.0. Each head contributes independently to the final
	 * score, analogous to how multi-head attention concatenates head outputs.
	 *
	 * @var array<string, float>
	 */
	const DEFAULT_HEAD_WEIGHTS = array(
		'semantic'   => 0.50, // Embedding similarity (the "attention" core).
		'capability' => 0.15, // User capability match (binary gate).
		'recency'    => 0.10, // Recent successful usage boost.
		'dependency' => 0.15, // Required plugins/APIs are available.
		'risk'       => 0.10, // Risk-tier alignment with approval gate.
	);

	/**
	 * Tool slugs that must always be included (safety margin).
	 *
	 * These are critical infrastructure tools that the LLM needs even if
	 * they don't score high on semantic similarity.
	 *
	 * @var string[]
	 */
	const MANDATORY_TOOLS = array(
		'finish_task',
		'exit_workflow',
		'check_exit_conditions',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Tool_Attention_Router
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		// Hook into tool registration to trigger embedding pre-computation.
		add_action( 'wp_mcp_ai_tool_registered', array( $this, 'on_tool_registered' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Select the top-K most relevant tools for a query using attention scoring.
	 *
	 * This is the main entry point. Returns a filtered tool list ordered by
	 * descending attention score.
	 *
	 * @since 1.8.0
	 *
	 * @param string $query_text     User query (and relevant conversation context).
	 * @param array  $tool_slugs     Array of available tool slugs.
	 * @param array  $options {
	 *     Optional overrides.
	 *
	 *     @type int   $top_k         Number of tools to select (default 40).
	 *     @type int   $user_id       Current user ID for capability checks.
	 *     @type array $head_weights  Per-head weight overrides.
	 *     @type bool  $force_full    Bypass attention filtering, return all tools.
	 * }
	 * @return array Top-K tool slugs ordered by descending attention score.
	 */
	public function select_tools( $query_text, array $tool_slugs, array $options = array() ) {
		$top_k        = isset( $options['top_k'] ) ? absint( $options['top_k'] ) : self::DEFAULT_TOP_K;
		$user_id      = isset( $options['user_id'] ) ? absint( $options['user_id'] ) : 0;
		$head_weights = isset( $options['head_weights'] ) && is_array( $options['head_weights'] )
			? $options['head_weights']
			: self::DEFAULT_HEAD_WEIGHTS;
		$force_full   = ! empty( $options['force_full'] );

		// If fewer tools available than K, no filtering needed.
		if ( count( $tool_slugs ) <= $top_k || $force_full ) {
			return $tool_slugs;
		}

		// Early exit if the vector service isn't available or there is no
		// query to score against — return all tools so no enabled tool is
		// lost. The count cap in build_tools_payload() remains the hard
		// guard for oversized lists.
		$vector_service_available = function_exists( 'wp_mcp_ai_get_vector_context_service' );
		if ( ! $vector_service_available || empty( $query_text ) ) {
			return $tool_slugs;
		}

		try {
			$scores = $this->score_tools( $query_text, $tool_slugs, $head_weights, $user_id );
		} catch ( Exception $e ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Attention router scoring failed, using all tools.',
					array( 'error' => $e->getMessage() )
				);
			}
			return $tool_slugs;
		}

		// Sort by descending score.
		usort(
			$scores,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Cache the full score breakdown so the harness Tool_Router_Harness
		// can read attention scores for RRF fusion without recomputing.
		self::$last_scores = $scores;

		// Select top-K.
		$selected = array();
		$seen     = array();

		// Always include mandatory tools first.
		foreach ( self::MANDATORY_TOOLS as $mandatory ) {
			if ( in_array( $mandatory, $tool_slugs, true ) && ! isset( $seen[ $mandatory ] ) ) {
				$selected[]         = $mandatory;
				$seen[ $mandatory ] = true;
			}
		}

		// Fill with attention-ranked tools.
		foreach ( $scores as $scored ) {
			if ( count( $selected ) >= $top_k ) {
				break;
			}
			if ( ! isset( $seen[ $scored['slug'] ] ) ) {
				$selected[]              = $scored['slug'];
				$seen[ $scored['slug'] ] = true;
			}
		}

		// Ensure minimum count — pad with remaining tools if needed.
		if ( count( $selected ) < self::MIN_TOOLS ) {
			foreach ( $tool_slugs as $slug ) {
				if ( count( $selected ) >= self::MIN_TOOLS ) {
					break;
				}
				if ( ! isset( $seen[ $slug ] ) ) {
					$selected[]    = $slug;
					$seen[ $slug ] = true;
				}
			}
		}

		return $selected;
	}

	/**
	 * Get detailed attention scores for diagnostics.
	 *
	 * @since 1.8.0
	 *
	 * @param string $query_text   User query.
	 * @param array  $tool_slugs   Tool slugs to score.
	 * @param int    $user_id      Current user ID.
	 * @return array<int, array{slug:string, score:float, heads:array}>
	 */
	public function get_attention_breakdown( $query_text, array $tool_slugs, $user_id = 0 ) {
		$head_weights = self::DEFAULT_HEAD_WEIGHTS;
		return $this->score_tools( $query_text, $tool_slugs, $head_weights, $user_id );
	}

	/**
	 * Retrieve cached attention scores from the most recent select_tools() call.
	 *
	 * Returns a slug => score map suitable for passing to
	 * {@see WP_MCP_AI_Tool_Router_Harness::rank()} as $attention_scores.
	 *
	 * Returns an empty array if select_tools() hasn't been called yet on this
	 * request.
	 *
	 * @since 1.8.0
	 *
	 * @return array<string, float> Slug => attention score (0–1).
	 */
	public static function get_cached_scores() {
		if ( null === self::$last_scores ) {
			return array();
		}

		$map = array();
		foreach ( self::$last_scores as $entry ) {
			if ( isset( $entry['slug'], $entry['score'] ) ) {
				$map[ $entry['slug'] ] = (float) $entry['score'];
			}
		}

		return $map;
	}

	/**
	 * Trigger pre-computation of a tool's embedding on registration.
	 *
	 * Hooked to `wp_mcp_ai_tool_registered`.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug Tool slug.
	 * @param object $tool Tool instance.
	 * @return void
	 */
	public function on_tool_registered( $slug, $tool ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		// Check if the embedding store is available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Embedding_Store' ) ) {
			return;
		}

		// If already fresh, skip.
		$source_text = $this->build_tool_source_text( $slug, $tool );
		$vector_svc  = function_exists( 'wp_mcp_ai_get_vector_context_service' )
			? wp_mcp_ai_get_vector_context_service()
			: null;

		if ( null === $vector_svc ) {
			return;
		}

		$provider = $vector_svc->get_embedding_provider();
		if ( is_wp_error( $provider ) ) {
			return;
		}

		if ( WP_MCP_AI_Tool_Embedding_Store::is_fresh( $slug, $provider->get_id(), $provider->get_model(), $source_text ) ) {
			return;
		}

		// Schedule async pre-computation via WP-Cron to avoid slowing down init.
		wp_schedule_single_event(
			time() + 5,
			'wp_mcp_ai_tool_embedding_compute',
			array( $slug )
		);
	}

	// -------------------------------------------------------------------------
	// Scoring — Multi-Head Attention
	// -------------------------------------------------------------------------

	/**
	 * Score all tools across all attention heads and fuse into final scores.
	 *
	 * Each "head" is an independent scoring function, analogous to how
	 * multi-head attention learns different projection matrices.
	 *
	 * @since 1.8.0
	 *
	 * @param string $query_text   User query.
	 * @param array  $tool_slugs   Tool slugs to score.
	 * @param array  $head_weights Head weight map.
	 * @param int    $user_id      Current user ID.
	 * @return array<int, array{slug:string, score:float, heads:array}>
	 */
	private function score_tools( $query_text, array $tool_slugs, array $head_weights, $user_id ) {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$vector_svc    = wp_mcp_ai_get_vector_context_service();
		$audit_trail   = class_exists( 'WP_MCP_AI_Agent_Audit_Trail' ) ? 'WP_MCP_AI_Agent_Audit_Trail' : null;
		$approval_gate = class_exists( 'WP_MCP_AI_Agent_Approval_Gate' ) ? 'WP_MCP_AI_Agent_Approval_Gate' : null;

		// --- Head 1: Semantic — embedding similarity (the "attention" core) ---
		$semantic_scores = $this->head_semantic( $query_text, $tool_slugs, $vector_svc, $tool_registry );

		// --- Head 2: Capability — does the user have the required capability? ---
		$capability_scores = $this->head_capability( $tool_slugs, $tool_registry, $user_id );

		// --- Head 3: Recency — was this tool used successfully recently? ---
		$recency_scores = $this->head_recency( $tool_slugs, $audit_trail );

		// --- Head 4: Dependency — are required plugins/APIs available? ---
		$dependency_scores = $this->head_dependency( $tool_slugs, $tool_registry );

		// --- Head 5: Risk — risk tier alignment ---
		$risk_scores = $this->head_risk( $tool_slugs, $tool_registry, $approval_gate );

		// Fuse heads with weighted sum (analogous to concatenating + projecting).
		$scores = array();
		foreach ( $tool_slugs as $slug ) {
			$sem = isset( $semantic_scores[ $slug ] ) ? $semantic_scores[ $slug ] : 0.0;
			$cap = isset( $capability_scores[ $slug ] ) ? $capability_scores[ $slug ] : 0.0;
			$rec = isset( $recency_scores[ $slug ] ) ? $recency_scores[ $slug ] : 0.0;
			$dep = isset( $dependency_scores[ $slug ] ) ? $dependency_scores[ $slug ] : 0.0;
			$rsk = isset( $risk_scores[ $slug ] ) ? $risk_scores[ $slug ] : 0.0;

			$final_score = ( $sem * $head_weights['semantic'] )
				+ ( $cap * $head_weights['capability'] )
				+ ( $rec * $head_weights['recency'] )
				+ ( $dep * $head_weights['dependency'] )
				+ ( $rsk * $head_weights['risk'] );

			$scores[] = array(
				'slug'  => $slug,
				'score' => round( $final_score, 4 ),
				'heads' => array(
					'semantic'   => round( $sem, 4 ),
					'capability' => round( $cap, 4 ),
					'recency'    => round( $rec, 4 ),
					'dependency' => round( $dep, 4 ),
					'risk'       => round( $rsk, 4 ),
				),
			);
		}

		return $scores;
	}

	// -------------------------------------------------------------------------
	// Attention Heads
	// -------------------------------------------------------------------------

	/**
	 * Head 1: Semantic similarity via embedding cosine distance.
	 *
	 * This is the direct Transformer analog: Query embedding vs Key embeddings
	 * (pre-computed tool definitions). Cosine similarity is analogous to the
	 * scaled dot-product attention score before softmax.
	 *
	 * @since 1.8.0
	 *
	 * @param string                           $query_text    User query.
	 * @param array                            $tool_slugs    Tool slugs.
	 * @param WP_MCP_AI_Vector_Context_Service $vector_svc    Vector service.
	 * @param WP_MCP_AI_Tool_Registry          $tool_registry Tool registry.
	 * @return array<string, float> Slug → semantic score (0–1).
	 */
	private function head_semantic( $query_text, array $tool_slugs, $vector_svc, $tool_registry ) {
		$scores = array();

		try {
			$query_embedding = $vector_svc->embed_context( $query_text );
		} catch ( Exception $e ) {
			return $scores;
		}

		if ( is_wp_error( $query_embedding ) || empty( $query_embedding ) ) {
			return $scores;
		}

		$provider = $vector_svc->get_embedding_provider();
		if ( is_wp_error( $provider ) ) {
			return $scores;
		}

		$provider_id = $provider->get_id();
		$model       = $provider->get_model();

		// Load pre-computed tool embeddings.
		$stored_embeddings = array();
		if ( class_exists( 'WP_MCP_AI_Tool_Embedding_Store' ) ) {
			$all_stored = WP_MCP_AI_Tool_Embedding_Store::get_all( $provider_id, $model );
			foreach ( $all_stored as $entry ) {
				$stored_embeddings[ $entry['tool_slug'] ] = $entry['vector'];
			}
		}

		$has_cosine = method_exists( $vector_svc, 'cosine_similarity' );

		foreach ( $tool_slugs as $slug ) {
			$tool_vector = null;

			// Try pre-computed embedding first.
			if ( isset( $stored_embeddings[ $slug ] ) ) {
				$tool_vector = $stored_embeddings[ $slug ];
			}

			// Fall back to on-the-fly embedding if no pre-computed vector exists.
			if ( null === $tool_vector ) {
				$tool = $tool_registry->get_tool( $slug );
				if ( ! $tool ) {
					continue;
				}
				$tool_text = $this->build_tool_source_text( $slug, $tool );
				$tool_vec  = $vector_svc->embed_context( $tool_text );
				if ( is_wp_error( $tool_vec ) || empty( $tool_vec ) ) {
					continue;
				}
				$tool_vector = $tool_vec;
			}

			// Compute cosine similarity.
			if ( $has_cosine ) {
				$scores[ $slug ] = $vector_svc->cosine_similarity( $query_embedding, $tool_vector );
			} else {
				$scores[ $slug ] = self::static_cosine( $query_embedding, $tool_vector );
			}
		}

		return $scores;
	}

	/**
	 * Head 2: Capability gate.
	 *
	 * Binary signal — tools the user cannot execute are heavily penalised.
	 *
	 * @since 1.8.0
	 *
	 * @param array                   $tool_slugs    Tool slugs.
	 * @param WP_MCP_AI_Tool_Registry $tool_registry Tool registry.
	 * @param int                     $user_id       Current user ID.
	 * @return array<string, float> Slug → capability score (0–1).
	 */
	private function head_capability( array $tool_slugs, $tool_registry, $user_id ) {
		$scores = array();

		foreach ( $tool_slugs as $slug ) {
			$cap = $tool_registry->get_tool_capability( $slug );

			// If no explicit capability required, the tool is available.
			if ( empty( $cap ) ) {
				$scores[ $slug ] = 1.0;
				continue;
			}

			if ( 0 === $user_id ) {
				// Unknown user — assume available but score lower.
				$scores[ $slug ] = 0.5;
				continue;
			}

			$scores[ $slug ] = user_can( $user_id, $cap ) ? 1.0 : 0.0;
		}

		return $scores;
	}

	/**
	 * Head 3: Recency boost.
	 *
	 * Tools used successfully in recent agent sessions get a small boost.
	 * Analogous to the recency bias in attention mechanisms.
	 *
	 * @since 1.8.0
	 *
	 * @param array       $tool_slugs  Tool slugs.
	 * @param string|null $audit_trail Audit trail class name (or null).
	 * @return array<string, float> Slug → recency score (0–1).
	 */
	private function head_recency( array $tool_slugs, $audit_trail ) {
		$scores = array();
		foreach ( $tool_slugs as $slug ) {
			// Neutral score when audit trail is unavailable.
			$scores[ $slug ] = 0.5;
		}

		if ( null === $audit_trail || ! method_exists( $audit_trail, 'get_recent_tool_success_rate' ) ) {
			return $scores;
		}

		try {
			$recent_rates = $audit_trail::get_recent_tool_success_rate( 50 );
			if ( ! is_array( $recent_rates ) ) {
				return $scores;
			}
			foreach ( $recent_rates as $slug => $rate ) {
				if ( isset( $scores[ $slug ] ) ) {
					// Clamp and scale: 0.5 = neutral, 0.0 = always fails, 1.0 = always succeeds.
					$scores[ $slug ] = max( 0.0, min( 1.0, (float) $rate ) );
				}
			}
		} catch ( Exception $e ) {
			// Gracefully degrade to neutral scores — recency data is optional.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Attention router: recency head failed, using neutral scores.',
					array( 'error' => $e->getMessage() )
				);
			}
		}

		return $scores;
	}

	/**
	 * Head 4: Dependency availability.
	 *
	 * Tools that require plugins not currently active or APIs not configured
	 * are scored lower so they don't waste LLM context space.
	 *
	 * @since 1.8.0
	 *
	 * @param array                   $tool_slugs    Tool slugs.
	 * @param WP_MCP_AI_Tool_Registry $tool_registry Tool registry.
	 * @return array<string, float> Slug → dependency score (0–1).
	 */
	private function head_dependency( array $tool_slugs, $tool_registry ) {
		$scores = array();

		foreach ( $tool_slugs as $slug ) {
			// Default: assumed available.
			$scores[ $slug ] = 1.0;

			if ( ! method_exists( $tool_registry, 'validate_dependencies' ) ) {
				continue;
			}

			// Resolve the tool slug to its dependency requirements.
			$tool     = $tool_registry->get_tool( $slug );
			$dep_reqs = array();
			if ( $tool instanceof WP_MCP_AI_Tool_Rules_Interface ) {
				$rules    = $tool->get_tool_rules();
				$dep_reqs = isset( $rules['dependencies'] ) && is_array( $rules['dependencies'] )
					? $rules['dependencies']
					: array();
			}

			// If the tool defines no dependencies, skip validation.
			if ( empty( $dep_reqs ) ) {
				continue;
			}

			$deps = $tool_registry->validate_dependencies( $dep_reqs );
			if ( is_wp_error( $deps ) ) {
				// Dependencies failed — score low but not zero (could be wrong).
				$scores[ $slug ] = 0.2;
			}
		}

		return $scores;
	}

	/**
	 * Head 5: Risk tier alignment.
	 *
	 * Matches tool risk level against the agent's approval gate configuration.
	 * High-risk tools get a slight penalty (they need explicit approval),
	 * low-risk tools get a slight boost.
	 *
	 * @since 1.8.0
	 *
	 * @param array                   $tool_slugs    Tool slugs.
	 * @param WP_MCP_AI_Tool_Registry $tool_registry Tool registry.
	 * @param string|null             $approval_gate Approval gate class name.
	 * @return array<string, float> Slug → risk score (0–1).
	 */
	private function head_risk( array $tool_slugs, $tool_registry, $approval_gate ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- reserved for future approval gate integration
		$risk_map = array(
			'low'      => 1.0,
			'medium'   => 0.8,
			'high'     => 0.6,
			'critical' => 0.4,
		);

		$scores = array();

		foreach ( $tool_slugs as $slug ) {
			$scores[ $slug ] = 0.8; // Neutral default.

			$tool = $tool_registry->get_tool( $slug );
			if ( ! $tool ) {
				continue;
			}

			// Check for risk-level constant or method.
			$risk = 'medium';
			if ( defined( get_class( $tool ) . '::RISK_LEVEL' ) ) {
				$risk = constant( get_class( $tool ) . '::RISK_LEVEL' );
			} elseif ( method_exists( $tool, 'get_risk_level' ) ) {
				$risk = $tool->get_risk_level();
			}

			if ( isset( $risk_map[ $risk ] ) ) {
				$scores[ $slug ] = $risk_map[ $risk ];
			}
		}

		return $scores;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the source text used to embed a tool definition.
	 *
	 * Combines the tool name, description, and key parameter names into
	 * a single text block that captures the tool's semantic identity.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug Tool slug.
	 * @param object $tool Tool instance.
	 * @return string Source text for embedding.
	 */
	public function build_tool_source_text( $slug, $tool ) {
		$name   = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug;
		$desc   = method_exists( $tool, 'get_description' ) ? $tool->get_description() : '';
		$params = method_exists( $tool, 'get_parameters_schema' ) ? $tool->get_parameters_schema() : array();

		$text = $name . ': ' . $desc;

		// Include top-level parameter names for richer semantic signal.
		if ( isset( $params['properties'] ) && is_array( $params['properties'] ) ) {
			$param_names = array_keys( $params['properties'] );
			if ( ! empty( $param_names ) ) {
				$text .= ' [Parameters: ' . implode( ', ', $param_names ) . ']';
			}
		}

		return $text;
	}

	/**
	 * Static cosine similarity (fallback when the vector service method
	 * is inaccessible).
	 *
	 * @since 1.8.0
	 *
	 * @param float[] $a First vector.
	 * @param float[] $b Second vector.
	 * @return float Cosine similarity.
	 */
	private static function static_cosine( array $a, array $b ) {
		$len = count( $a );
		if ( count( $b ) !== $len || 0 === $len ) {
			return 0.0;
		}

		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$ai      = (float) $a[ $i ];
			$bi      = (float) $b[ $i ];
			$dot    += $ai * $bi;
			$norm_a += $ai * $ai;
			$norm_b += $bi * $bi;
		}

		$denominator = sqrt( $norm_a ) * sqrt( $norm_b );
		if ( $denominator < 1.0e-10 ) {
			return 0.0;
		}

		return (float) ( $dot / $denominator );
	}

	/**
	 * Compute tool embeddings on registration (async-safe entry point).
	 *
	 * Called by the `wp_mcp_ai_tool_embedding_compute` cron hook.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug Tool slug.
	 * @return bool True on success.
	 */
	public static function compute_tool_embedding( $slug ) {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( $slug );
		if ( ! $tool ) {
			return false;
		}

		if ( ! function_exists( 'wp_mcp_ai_get_vector_context_service' ) ) {
			return false;
		}

		$vector_svc = wp_mcp_ai_get_vector_context_service();
		$provider   = $vector_svc->get_embedding_provider();
		if ( is_wp_error( $provider ) ) {
			return false;
		}

		$router      = self::get_instance();
		$source_text = $router->build_tool_source_text( $slug, $tool );

		$embedding = $vector_svc->embed_context( $source_text );
		if ( is_wp_error( $embedding ) || empty( $embedding ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Embedding_Store' ) ) {
			return false;
		}

		return WP_MCP_AI_Tool_Embedding_Store::store(
			$slug,
			$embedding,
			$provider->get_id(),
			$provider->get_model(),
			$source_text
		);
	}
}
