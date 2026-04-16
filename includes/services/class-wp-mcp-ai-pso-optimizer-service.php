<?php
/**
 * Particle Swarm Optimization (PSO) Optimizer Service
 *
 * Applies the classic PSO velocity update equation to optimize AI orchestration
 * parameters across four decision surfaces:
 *
 * 1. Model Selection — quality/cost trade-off per assistant
 * 2. Tool Execution — sync/async routing thresholds
 * 3. Iteration Control — agentic loop depth
 * 4. Cost-Quality — cross-cutting budget optimization
 *
 * PSO Formula:
 *   V_i^{t+1} = W · V_i^t + c₁U₁(lb_i − P_i^t) + c₂U₂(g_b − P_i^t)
 *
 * Where:
 *   W   = inertia weight (exploration vs exploitation)
 *   c₁  = cognitive/personal learning coefficient
 *   c₂  = social learning coefficient
 *   U₁, U₂ = random factors in [0, 1]
 *   lb_i = personal best position for particle i
 *   g_b  = global best position across all particles
 *   P_i  = current position of particle i
 *   V_i  = current velocity of particle i
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSO Optimizer Service class.
 *
 * Each assistant acts as a "particle" in the swarm, with its own position
 * (current configuration), velocity (rate of change), personal best, and
 * a shared global best across all assistants.
 *
 * State is persisted in WordPress options and post meta to survive across
 * HTTP requests.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_PSO_Optimizer_Service {

	/**
	 * Option key for storing the global best position and fitness.
	 *
	 * @var string
	 */
	const OPTION_GLOBAL_BEST = 'wp_mcp_ai_pso_global_best';

	/**
	 * Post meta key prefix for per-assistant (particle) state.
	 *
	 * Stored keys:
	 *   _wp_mcp_ai_pso_position  — current position vector (array of floats)
	 *   _wp_mcp_ai_pso_velocity  — current velocity vector (array of floats)
	 *   _wp_mcp_ai_pso_pbest     — personal best position (array of floats)
	 *   _wp_mcp_ai_pso_pbest_fit — personal best fitness (float)
	 *   _wp_mcp_ai_pso_samples   — conversation count since last update (int)
	 *
	 * @var string
	 */
	const META_PREFIX = '_wp_mcp_ai_pso_';

	// ------------------------------------------------------------------
	// PSO hyper-parameters (sensible defaults for production).
	// ------------------------------------------------------------------

	/**
	 * Initial inertia weight — high value favours exploration.
	 *
	 * @var float
	 */
	const INERTIA_WEIGHT_START = 0.9;

	/**
	 * Final inertia weight — low value favours exploitation.
	 *
	 * @var float
	 */
	const INERTIA_WEIGHT_END = 0.4;

	/**
	 * Number of conversations over which inertia decays linearly.
	 *
	 * @var int
	 */
	const INERTIA_DECAY_CONVERSATIONS = 100;

	/**
	 * Cognitive (personal) learning coefficient.
	 *
	 * @var float
	 */
	const C1 = 1.5;

	/**
	 * Social (global) learning coefficient.
	 *
	 * @var float
	 */
	const C2 = 2.0;

	/**
	 * Number of conversations to collect before running an update.
	 *
	 * @var int
	 */
	const UPDATE_FREQUENCY = 10;

	/**
	 * Maximum velocity magnitude (prevents overshooting).
	 *
	 * @var float
	 */
	const V_MAX = 0.5;

	// ------------------------------------------------------------------
	// Dimension definitions — the search space.
	// ------------------------------------------------------------------
	// Each dimension has: key, min, max, default.

	/**
	 * Get the search space dimensions.
	 *
	 * Position vector order:
	 *   0 — model_quality_weight     (0..1)  Preference for advanced model
	 *   1 — temperature_offset       (-0.3..0.3)  Temperature adjustment
	 *   2 — async_threshold          (0..1)  When to prefer async execution
	 *   3 — capacity_weight          (0..1)  How much capacity influences routing
	 *   4 — max_iterations_factor    (0.5..2.0)  Multiplier on base iteration count
	 *   5 — cache_aggressiveness     (0..1)  How aggressively to cache
	 *   6 — cost_sensitivity         (0..1)  How much cost influences decisions
	 *
	 * @return array Array of dimension definitions.
	 */
	public static function get_dimensions() {
		return array(
			array(
				'key'     => 'model_quality_weight',
				'min'     => 0.0,
				'max'     => 1.0,
				'default' => 0.5,
			),
			array(
				'key'     => 'temperature_offset',
				'min'     => -0.3,
				'max'     => 0.3,
				'default' => 0.0,
			),
			array(
				'key'     => 'async_threshold',
				'min'     => 0.0,
				'max'     => 1.0,
				'default' => 0.5,
			),
			array(
				'key'     => 'capacity_weight',
				'min'     => 0.0,
				'max'     => 1.0,
				'default' => 0.5,
			),
			array(
				'key'     => 'max_iterations_factor',
				'min'     => 0.5,
				'max'     => 2.0,
				'default' => 1.0,
			),
			array(
				'key'     => 'cache_aggressiveness',
				'min'     => 0.0,
				'max'     => 1.0,
				'default' => 0.5,
			),
			array(
				'key'     => 'cost_sensitivity',
				'min'     => 0.0,
				'max'     => 1.0,
				'default' => 0.5,
			),
		);
	}

	// ------------------------------------------------------------------
	// Instance properties.
	// ------------------------------------------------------------------

	/**
	 * Whether PSO optimisation is enabled.
	 *
	 * @var bool
	 */
	protected $enabled;

	/**
	 * Cached global best.
	 *
	 * @var array|null
	 */
	protected $global_best_cache = null;

	// ------------------------------------------------------------------
	// Lifecycle.
	// ------------------------------------------------------------------

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings      = get_option( 'wp_mcp_ai_settings', array() );
		$this->enabled = ! empty( $settings['enable_pso_optimizer'] );

		if ( $this->enabled ) {
			$this->init_hooks();
		}
	}

	/**
	 * Register WordPress hooks.
	 */
	protected function init_hooks() {
		// Listen for completed agentic workflows to collect fitness data.
		add_action( 'wp_mcp_ai_agentic_metrics', array( $this, 'on_agentic_metrics' ), 20 );

		// Provide PSO-informed iteration limits.
		add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'filter_max_iterations' ), 50, 2 );

		/**
		 * Fires when the PSO optimizer service is fully initialised.
		 *
		 * @since 1.2.0
		 *
		 * @param WP_MCP_AI_PSO_Optimizer_Service $service The PSO service instance.
		 */
		do_action( 'wp_mcp_ai_pso_optimizer_init', $this );
	}

	/**
	 * Check whether the optimizer is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->enabled;
	}

	// ------------------------------------------------------------------
	// Core PSO algorithm.
	// ------------------------------------------------------------------

	/**
	 * Update a particle's velocity and position using the PSO equation.
	 *
	 * V_i^{t+1} = W · V_i^t + c₁U₁(lb_i − P_i^t) + c₂U₂(g_b − P_i^t)
	 * P_i^{t+1} = P_i^t + V_i^{t+1}
	 *
	 * @param array $position     Current position vector.
	 * @param array $velocity     Current velocity vector.
	 * @param array $personal_best Personal best position vector.
	 * @param array $global_best  Global best position vector.
	 * @param float $inertia      Inertia weight (W).
	 * @return array {
	 *     @type array $position  New position vector.
	 *     @type array $velocity  New velocity vector.
	 * }
	 */
	public function update_particle( array $position, array $velocity, array $personal_best, array $global_best, $inertia ) {
		$dimensions = self::get_dimensions();
		$dim_count  = count( $dimensions );
		$new_vel    = array();
		$new_pos    = array();

		$c1 = self::C1;
		$c2 = self::C2;

		/**
		 * Filter PSO learning coefficients.
		 *
		 * @since 1.2.0
		 *
		 * @param array $coefficients {
		 *     @type float $c1 Cognitive coefficient.
		 *     @type float $c2 Social coefficient.
		 * }
		 */
		$coefficients = apply_filters(
			'wp_mcp_ai_pso_coefficients',
			array(
				'c1' => $c1,
				'c2' => $c2,
			)
		);
		$c1           = (float) $coefficients['c1'];
		$c2           = (float) $coefficients['c2'];

		for ( $d = 0; $d < $dim_count; $d++ ) {
			$p_i  = isset( $position[ $d ] ) ? (float) $position[ $d ] : (float) $dimensions[ $d ]['default'];
			$v_i  = isset( $velocity[ $d ] ) ? (float) $velocity[ $d ] : 0.0;
			$lb_i = isset( $personal_best[ $d ] ) ? (float) $personal_best[ $d ] : $p_i;
			$g_b  = isset( $global_best[ $d ] ) ? (float) $global_best[ $d ] : $p_i;

			// Random factors U₁, U₂ ∈ [0, 1].
			$u1 = $this->random_float();
			$u2 = $this->random_float();

			// PSO velocity equation.
			$inertia_component  = $inertia * $v_i;
			$personal_component = $c1 * $u1 * ( $lb_i - $p_i );
			$social_component   = $c2 * $u2 * ( $g_b - $p_i );

			$v_new = $inertia_component + $personal_component + $social_component;

			// Clamp velocity.
			$v_max = self::V_MAX * ( $dimensions[ $d ]['max'] - $dimensions[ $d ]['min'] );
			$v_new = max( -$v_max, min( $v_max, $v_new ) );

			// Update position.
			$p_new = $p_i + $v_new;

			// Clamp position to bounds.
			$p_new = max( $dimensions[ $d ]['min'], min( $dimensions[ $d ]['max'], $p_new ) );

			$new_vel[] = round( $v_new, 6 );
			$new_pos[] = round( $p_new, 6 );
		}

		return array(
			'position' => $new_pos,
			'velocity' => $new_vel,
		);
	}

	/**
	 * Calculate the inertia weight for the current conversation count.
	 *
	 * Linearly decays from INERTIA_WEIGHT_START to INERTIA_WEIGHT_END.
	 *
	 * @param int $conversation_count Total conversations observed.
	 * @return float Inertia weight.
	 */
	public function calculate_inertia( $conversation_count ) {
		$t       = min( (int) $conversation_count, self::INERTIA_DECAY_CONVERSATIONS );
		$t_max   = self::INERTIA_DECAY_CONVERSATIONS;
		$w_start = self::INERTIA_WEIGHT_START;
		$w_end   = self::INERTIA_WEIGHT_END;

		$inertia = $w_start - ( ( $w_start - $w_end ) * ( $t / $t_max ) );

		/**
		 * Filter the PSO inertia weight.
		 *
		 * @since 1.2.0
		 *
		 * @param float $inertia            Calculated inertia weight.
		 * @param int   $conversation_count Total conversations observed.
		 */
		return (float) apply_filters( 'wp_mcp_ai_pso_inertia_weight', $inertia, $conversation_count );
	}

	// ------------------------------------------------------------------
	// Fitness evaluation.
	// ------------------------------------------------------------------

	/**
	 * Compute a fitness score from agentic workflow metrics.
	 *
	 * Higher fitness = better. The function balances task completion speed,
	 * tool efficiency, and (when available) cost.
	 *
	 * Fitness = (1 / duration) × cache_efficiency × iteration_efficiency
	 *
	 * @param array $metrics Metrics from `wp_mcp_ai_agentic_metrics` action.
	 * @return float Fitness score (higher is better), or 0.0 on invalid input.
	 */
	public function evaluate_fitness( array $metrics ) {
		$duration        = isset( $metrics['duration'] ) ? (float) $metrics['duration'] : 0.0;
		$iterations      = isset( $metrics['iterations'] ) ? (int) $metrics['iterations'] : 1;
		$tool_executions = isset( $metrics['tool_executions'] ) ? (int) $metrics['tool_executions'] : 0;
		$cache_hits      = isset( $metrics['cache_hits'] ) ? (int) $metrics['cache_hits'] : 0;
		$cache_misses    = isset( $metrics['cache_misses'] ) ? (int) $metrics['cache_misses'] : 0;

		// Guard against division by zero.
		if ( $duration <= 0.0 ) {
			return 0.0;
		}

		// Speed component: inverse of duration (faster = better).
		$speed_score = 1.0 / $duration;

		// Cache efficiency: ratio of hits to total cache operations.
		$total_cache = $cache_hits + $cache_misses;
		$cache_score = ( $total_cache > 0 ) ? ( $cache_hits / $total_cache ) : 0.5;

		// Iteration efficiency: fewer iterations for more tool executions is better.
		$iteration_score = ( $iterations > 0 && $tool_executions > 0 )
			? ( $tool_executions / $iterations )
			: 1.0;

		// Normalise iteration score to [0, 1] range (cap at 5 tools/iteration).
		$iteration_score = min( 1.0, $iteration_score / 5.0 );

		$fitness = $speed_score * ( 0.4 + 0.3 * $cache_score + 0.3 * $iteration_score );

		/**
		 * Filter the PSO fitness score.
		 *
		 * @since 1.2.0
		 *
		 * @param float $fitness Computed fitness score.
		 * @param array $metrics Raw agentic workflow metrics.
		 */
		return (float) apply_filters( 'wp_mcp_ai_pso_fitness_score', $fitness, $metrics );
	}

	// ------------------------------------------------------------------
	// Hooks and integration.
	// ------------------------------------------------------------------

	/**
	 * Handle agentic workflow completion — record fitness and maybe update swarm.
	 *
	 * @param array $metrics Metrics from `wp_mcp_ai_agentic_metrics`.
	 */
	public function on_agentic_metrics( $metrics ) {
		if ( ! is_array( $metrics ) ) {
			return;
		}

		$assistant_id = isset( $metrics['assistant_id'] ) ? absint( $metrics['assistant_id'] ) : 0;
		if ( ! $assistant_id ) {
			return;
		}

		// Evaluate fitness.
		$fitness = $this->evaluate_fitness( $metrics );
		if ( $fitness <= 0.0 ) {
			return;
		}

		// Ensure particle is initialised.
		$this->maybe_init_particle( $assistant_id );

		// Update personal best if this fitness is better.
		$this->maybe_update_personal_best( $assistant_id, $fitness );

		// Update global best if this fitness is better.
		$this->maybe_update_global_best( $assistant_id, $fitness );

		// Increment sample counter.
		$samples = (int) get_post_meta( $assistant_id, self::META_PREFIX . 'samples', true );
		++$samples;
		update_post_meta( $assistant_id, self::META_PREFIX . 'samples', $samples );

		// Run PSO update when enough samples collected.
		if ( $samples >= self::UPDATE_FREQUENCY ) {
			$this->run_pso_update( $assistant_id );
			update_post_meta( $assistant_id, self::META_PREFIX . 'samples', 0 );
		}
	}

	/**
	 * Filter the max agentic iterations using PSO-informed values.
	 *
	 * @param int   $max_iterations   Current max iterations.
	 * @param array $assistant_config Assistant configuration.
	 * @return int Modified max iterations.
	 */
	public function filter_max_iterations( $max_iterations, $assistant_config ) {
		if ( ! is_array( $assistant_config ) ) {
			return $max_iterations;
		}

		$assistant_id = isset( $assistant_config['id'] ) ? absint( $assistant_config['id'] ) : 0;
		if ( ! $assistant_id ) {
			return $max_iterations;
		}

		$position = get_post_meta( $assistant_id, self::META_PREFIX . 'position', true );
		if ( ! is_array( $position ) || empty( $position ) ) {
			return $max_iterations;
		}

		// Dimension 4 = max_iterations_factor.
		$factor = isset( $position[4] ) ? (float) $position[4] : 1.0;

		// Apply factor to current limit.
		$adjusted = (int) round( $max_iterations * $factor );

		// Enforce bounds.
		return max( 1, min( 50, $adjusted ) );
	}

	// ------------------------------------------------------------------
	// Particle state management.
	// ------------------------------------------------------------------

	/**
	 * Initialise a particle with default position and zero velocity.
	 *
	 * @param int $assistant_id Assistant post ID.
	 */
	public function maybe_init_particle( $assistant_id ) {
		$position = get_post_meta( $assistant_id, self::META_PREFIX . 'position', true );
		if ( is_array( $position ) && ! empty( $position ) ) {
			return; // Already initialised.
		}

		$dimensions = self::get_dimensions();
		$position   = array();
		$velocity   = array();

		foreach ( $dimensions as $dim ) {
			$position[] = (float) $dim['default'];
			$velocity[] = 0.0;
		}

		update_post_meta( $assistant_id, self::META_PREFIX . 'position', $position );
		update_post_meta( $assistant_id, self::META_PREFIX . 'velocity', $velocity );
		update_post_meta( $assistant_id, self::META_PREFIX . 'pbest', $position );
		update_post_meta( $assistant_id, self::META_PREFIX . 'pbest_fit', 0.0 );
		update_post_meta( $assistant_id, self::META_PREFIX . 'samples', 0 );
	}

	/**
	 * Update personal best if the new fitness exceeds the stored best.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param float $fitness      New fitness score.
	 */
	protected function maybe_update_personal_best( $assistant_id, $fitness ) {
		$current_best_fitness = (float) get_post_meta( $assistant_id, self::META_PREFIX . 'pbest_fit', true );

		if ( $fitness > $current_best_fitness ) {
			$position = get_post_meta( $assistant_id, self::META_PREFIX . 'position', true );
			if ( is_array( $position ) ) {
				update_post_meta( $assistant_id, self::META_PREFIX . 'pbest', $position );
				update_post_meta( $assistant_id, self::META_PREFIX . 'pbest_fit', $fitness );
			}
		}
	}

	/**
	 * Update global best if the new fitness exceeds the stored global best.
	 *
	 * @param int   $assistant_id Assistant post ID (source of the new fitness).
	 * @param float $fitness      New fitness score.
	 */
	protected function maybe_update_global_best( $assistant_id, $fitness ) {
		$global = $this->get_global_best();

		if ( $fitness > $global['fitness'] ) {
			$position = get_post_meta( $assistant_id, self::META_PREFIX . 'position', true );
			if ( is_array( $position ) ) {
				$new_global = array(
					'position'     => $position,
					'fitness'      => $fitness,
					'assistant_id' => $assistant_id,
					'updated_at'   => time(),
				);
				update_option( self::OPTION_GLOBAL_BEST, $new_global, false );
				$this->global_best_cache = $new_global;
			}
		}
	}

	/**
	 * Retrieve the global best state.
	 *
	 * @return array {
	 *     @type array  $position     Global best position vector.
	 *     @type float  $fitness      Global best fitness.
	 *     @type int    $assistant_id Assistant that achieved it.
	 *     @type int    $updated_at   Unix timestamp.
	 * }
	 */
	public function get_global_best() {
		if ( null !== $this->global_best_cache ) {
			return $this->global_best_cache;
		}

		$global = get_option( self::OPTION_GLOBAL_BEST, false );

		if ( ! is_array( $global ) || ! isset( $global['position'] ) ) {
			$dimensions = self::get_dimensions();
			$defaults   = array();
			foreach ( $dimensions as $dim ) {
				$defaults[] = (float) $dim['default'];
			}

			$global = array(
				'position'     => $defaults,
				'fitness'      => 0.0,
				'assistant_id' => 0,
				'updated_at'   => 0,
			);
		}

		$this->global_best_cache = $global;
		return $global;
	}

	/**
	 * Get the current state of a particle (assistant).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array {
	 *     @type array  $position      Current position vector.
	 *     @type array  $velocity      Current velocity vector.
	 *     @type array  $personal_best Personal best position vector.
	 *     @type float  $pbest_fitness Personal best fitness.
	 *     @type int    $samples       Conversations since last update.
	 * }
	 */
	public function get_particle_state( $assistant_id ) {
		$this->maybe_init_particle( $assistant_id );

		return array(
			'position'      => (array) get_post_meta( $assistant_id, self::META_PREFIX . 'position', true ),
			'velocity'      => (array) get_post_meta( $assistant_id, self::META_PREFIX . 'velocity', true ),
			'personal_best' => (array) get_post_meta( $assistant_id, self::META_PREFIX . 'pbest', true ),
			'pbest_fitness' => (float) get_post_meta( $assistant_id, self::META_PREFIX . 'pbest_fit', true ),
			'samples'       => (int) get_post_meta( $assistant_id, self::META_PREFIX . 'samples', true ),
		);
	}

	// ------------------------------------------------------------------
	// PSO update cycle.
	// ------------------------------------------------------------------

	/**
	 * Run a full PSO velocity + position update for one particle.
	 *
	 * @param int $assistant_id Assistant post ID.
	 */
	public function run_pso_update( $assistant_id ) {
		$state  = $this->get_particle_state( $assistant_id );
		$global = $this->get_global_best();

		// Calculate inertia based on total conversations across all assistants.
		$total_conversations = $this->get_total_conversation_count();
		$inertia             = $this->calculate_inertia( $total_conversations );

		$result = $this->update_particle(
			$state['position'],
			$state['velocity'],
			$state['personal_best'],
			$global['position'],
			$inertia
		);

		update_post_meta( $assistant_id, self::META_PREFIX . 'position', $result['position'] );
		update_post_meta( $assistant_id, self::META_PREFIX . 'velocity', $result['velocity'] );

		/**
		 * Fires after a PSO velocity + position update for an assistant.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $assistant_id Assistant post ID.
		 * @param array $result       New position and velocity.
		 * @param float $inertia      Inertia weight used.
		 */
		do_action( 'wp_mcp_ai_pso_velocity_update', $assistant_id, $result, $inertia );

		WP_MCP_AI_Logger::log_event(
			'pso_update',
			sprintf( 'PSO update for assistant %d', $assistant_id ),
			array(
				'assistant_id' => $assistant_id,
				'position'     => $result['position'],
				'velocity'     => $result['velocity'],
				'inertia'      => $inertia,
			)
		);
	}

	// ------------------------------------------------------------------
	// Parameter accessors — used by other services to read PSO state.
	// ------------------------------------------------------------------

	/**
	 * Get a named PSO parameter for an assistant.
	 *
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $dimension_key Dimension key (e.g. 'model_quality_weight').
	 * @return float|null Parameter value, or null if not found.
	 */
	public function get_parameter( $assistant_id, $dimension_key ) {
		$position   = get_post_meta( $assistant_id, self::META_PREFIX . 'position', true );
		$dimensions = self::get_dimensions();

		if ( ! is_array( $position ) ) {
			return null;
		}

		foreach ( $dimensions as $index => $dim ) {
			if ( $dim['key'] === $dimension_key && isset( $position[ $index ] ) ) {
				return (float) $position[ $index ];
			}
		}

		return null;
	}

	/**
	 * Get all PSO parameters as a keyed associative array.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Associative array of dimension_key => value.
	 */
	public function get_all_parameters( $assistant_id ) {
		$position   = get_post_meta( $assistant_id, self::META_PREFIX . 'position', true );
		$dimensions = self::get_dimensions();
		$params     = array();

		foreach ( $dimensions as $index => $dim ) {
			$params[ $dim['key'] ] = ( is_array( $position ) && isset( $position[ $index ] ) )
				? (float) $position[ $index ]
				: (float) $dim['default'];
		}

		return $params;
	}

	// ------------------------------------------------------------------
	// Diagnostics and reset.
	// ------------------------------------------------------------------

	/**
	 * Get a swarm-level summary for diagnostics.
	 *
	 * @return array {
	 *     @type array $global_best          Global best state.
	 *     @type int   $total_conversations  Total observed conversations.
	 *     @type float $current_inertia      Current inertia weight.
	 *     @type int   $particle_count       Number of initialised particles.
	 *     @type array $dimensions           Dimension definitions.
	 * }
	 */
	public function get_swarm_summary() {
		$total   = $this->get_total_conversation_count();
		$global  = $this->get_global_best();
		$inertia = $this->calculate_inertia( $total );

		// Count particles (assistants with PSO state).
		$particles = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_PREFIX . 'position', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Diagnostic method, not called on every request.
				'meta_compare'   => 'EXISTS',
			)
		);

		return array(
			'global_best'         => $global,
			'total_conversations' => $total,
			'current_inertia'     => $inertia,
			'particle_count'      => count( $particles ),
			'dimensions'          => self::get_dimensions(),
		);
	}

	/**
	 * Reset all PSO state for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 */
	public function reset_particle( $assistant_id ) {
		$meta_keys = array( 'position', 'velocity', 'pbest', 'pbest_fit', 'samples' );
		foreach ( $meta_keys as $key ) {
			delete_post_meta( $assistant_id, self::META_PREFIX . $key );
		}
	}

	/**
	 * Reset the entire swarm — all particles and global best.
	 */
	public function reset_swarm() {
		// Delete global best.
		delete_option( self::OPTION_GLOBAL_BEST );
		$this->global_best_cache = null;

		// Delete per-particle meta.
		$meta_keys = array( 'position', 'velocity', 'pbest', 'pbest_fit', 'samples' );
		foreach ( $meta_keys as $key ) {
			delete_metadata( 'post', 0, self::META_PREFIX . $key, '', true );
		}
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Get total conversation count across all assistants.
	 *
	 * Uses the global best timestamp as a proxy when detailed counts
	 * are unavailable, or sums per-assistant sample counters.
	 *
	 * @return int Total conversation count.
	 */
	protected function get_total_conversation_count() {
		$cache_key = 'wp_mcp_ai_pso_total_conversations';
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_pso' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// Sum all sample counters across assistants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Aggregate SUM across postmeta; no WP API for this.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_PREFIX . 'samples'
			)
		);

		// Add estimate from previous update cycles.
		$global = $this->get_global_best();
		if ( ! empty( $global['updated_at'] ) ) {
			// Rough estimate: each UPDATE_FREQUENCY batch counts as one cycle.
			$cycles = (int) floor( ( time() - $global['updated_at'] ) / DAY_IN_SECONDS );
			$total += $cycles * self::UPDATE_FREQUENCY;
		}

		$total = max( 0, (int) $total );

		wp_cache_set( $cache_key, $total, 'wp_mcp_ai_pso', 300 );

		return $total;
	}

	/**
	 * Generate a random float in [0, 1].
	 *
	 * Uses wp_rand for WordPress compatibility.
	 *
	 * @return float Random value in [0, 1].
	 */
	protected function random_float() {
		return wp_rand( 0, 1000000 ) / 1000000;
	}
}
