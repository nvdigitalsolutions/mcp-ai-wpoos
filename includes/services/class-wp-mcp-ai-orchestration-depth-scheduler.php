<?php
/**
 * Orchestration Depth Scheduler
 *
 * Graduated orchestration depth based on system load and prediction confidence.
 * Inspired by DSpark's confidence-scheduled verification — deeper validation
 * when confidence is low or system has spare capacity, shallower paths when
 * the system is under load and predictions are trusted.
 *
 * The scheduler exposes four tiers (DEEP → MINIMAL) that downstream
 * orchestrators query to decide how much verification, debate rounds, and
 * model power to apply per task.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Graduated orchestration depth scheduler.
 *
 * Determines the appropriate verification depth for each orchestration step
 * by weighing current system capacity against prediction confidence.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Orchestration_Depth_Scheduler {

	/**
	 * Depth tier constants.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const TIER_DEEP     = 'deep';
	const TIER_STANDARD = 'standard';
	const TIER_SHALLOW  = 'shallow';
	const TIER_MINIMAL  = 'minimal';

	/**
	 * Capacity utilisation thresholds (percent). When the system is below the
	 * threshold the tier is eligible. Higher tiers require more spare capacity.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const CAPACITY_DEEP     = 70;
	const CAPACITY_STANDARD = 40;
	const CAPACITY_SHALLOW  = 15;

	/**
	 * Confidence score thresholds.
	 *
	 * @since 1.3.0
	 * @var float
	 */
	const CONFIDENCE_HIGH   = 0.8;
	const CONFIDENCE_MEDIUM = 0.6;
	const CONFIDENCE_LOW    = 0.4;

	/**
	 * Default block sizes per tier. Smaller blocks mean more granular
	 * verification; larger blocks trade safety for throughput.
	 *
	 * @since 1.3.0
	 * @var array<string,int>
	 */
	const DEFAULT_BLOCK_SIZE = array(
		'deep'     => 5,
		'standard' => 10,
		'shallow'  => 20,
		'minimal'  => 50,
	);

	/**
	 * The load monitor used to query current system capacity.
	 *
	 * @since 1.3.0
	 * @var WP_MCP_AI_Tool_Load_Monitor|null
	 */
	protected $load_monitor;

	/**
	 * Cached capacity score for the current request, so repeated calls to
	 * determine_tier() within the same request do not re-query the monitor.
	 *
	 * @since 1.3.0
	 * @var float|null
	 */
	private $cached_capacity = null;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 *
	 * @param WP_MCP_AI_Tool_Load_Monitor|null $load_monitor Optional load monitor.
	 *        If null, the scheduler will lazy-load it on first use.
	 */
	public function __construct( $load_monitor = null ) {
		if ( $load_monitor instanceof WP_MCP_AI_Tool_Load_Monitor ) {
			$this->load_monitor = $load_monitor;
		}
	}

	/**
	 * Determine the appropriate orchestration depth tier.
	 *
	 * Weighs current system capacity against prediction confidence to pick the
	 * right depth. High capacity + high confidence = minimal tier (fast path);
	 * low capacity or low confidence = deep tier (thorough verification).
	 *
	 * @since 1.3.0
	 *
	 * @param float|null $capacity_score Optional current capacity utilisation
	 *        (0–100, where lower is less busy). If null, queried from the load
	 *        monitor via get_current_capacity().
	 * @param float|null $confidence Optional prediction confidence (0.0–1.0).
	 *        If null, defaults to a neutral value that pushes toward standard.
	 * @return string One of the TIER_* constants. Returns TIER_STANDARD on
	 *                error or when insufficient data is available.
	 */
	public function determine_tier( $capacity_score = null, $confidence = null ) {
		// Sanitise inputs.
		if ( null !== $capacity_score ) {
			$capacity_score = (float) $capacity_score;
			$capacity_score = max( 0.0, min( 100.0, $capacity_score ) );
		} else {
			$capacity_score = $this->get_current_capacity();
			if ( is_wp_error( $capacity_score ) ) {
				// Fall back to standard when we cannot read capacity.
				return self::TIER_STANDARD;
			}
		}

		if ( null !== $confidence ) {
			$confidence = (float) $confidence;
			$confidence = max( 0.0, min( 1.0, $confidence ) );
		} else {
			// Neutral default — slightly above medium so we bias toward
			// standard rather than deep when no confidence data exists.
			$confidence = 0.65;
		}

		// Core depth-selection logic.
		$available = 100.0 - $capacity_score;

		if ( $available >= self::CAPACITY_DEEP && $confidence < self::CONFIDENCE_HIGH ) {
			$tier = self::TIER_DEEP;
		} elseif ( $available >= self::CAPACITY_STANDARD && $confidence < self::CONFIDENCE_MEDIUM ) {
			$tier = self::TIER_DEEP;
		} elseif ( $available >= self::CAPACITY_STANDARD ) {
			$tier = self::TIER_STANDARD;
		} elseif ( $available >= self::CAPACITY_SHALLOW ) {
			$tier = $confidence < self::CONFIDENCE_LOW ? self::TIER_STANDARD : self::TIER_SHALLOW;
		} else {
			$tier = $confidence < self::CONFIDENCE_LOW ? self::TIER_SHALLOW : self::TIER_MINIMAL;
		}

		/**
		 * Filter the determined orchestration depth tier.
		 *
		 * Allows plugins to override the tier selection based on custom
		 * heuristics, business rules, or A/B testing.
		 *
		 * @since 1.3.0
		 *
		 * @param string $tier           The calculated tier constant.
		 * @param float  $capacity_score Current capacity utilisation (0–100).
		 * @param float  $confidence     Prediction confidence (0.0–1.0).
		 */
		$tier = apply_filters(
			'wp_mcp_ai_orchestration_depth_tier',
			$tier,
			$capacity_score,
			$confidence
		);

		// Guard against invalid filter return values.
		$valid_tiers = array(
			self::TIER_DEEP,
			self::TIER_STANDARD,
			self::TIER_SHALLOW,
			self::TIER_MINIMAL,
		);

		if ( ! in_array( $tier, $valid_tiers, true ) ) {
			$tier = self::TIER_STANDARD;
		}

		return $tier;
	}

	/**
	 * Get the configuration for a given depth tier.
	 *
	 * Returns an associative array with the operational parameters that
	 * downstream orchestrators should apply for the requested tier.
	 *
	 * @since 1.3.0
	 *
	 * @param string $tier One of the TIER_* constants.
	 * @return array{
	 *     'block_size': int,
	 *     'verification_enabled': bool,
	 *     'debate_enabled': bool,
	 *     'model_tier': string
	 * } Tier configuration. Falls back to 'standard' config for unknown tiers.
	 */
	public function get_tier_config( $tier ) {
		$tier = sanitize_key( (string) $tier );

		$configs = array(
			self::TIER_DEEP     => array(
				'block_size'           => self::DEFAULT_BLOCK_SIZE[ self::TIER_DEEP ],
				'verification_enabled' => true,
				'debate_enabled'       => true,
				'model_tier'           => 'best',
			),
			self::TIER_STANDARD => array(
				'block_size'           => self::DEFAULT_BLOCK_SIZE[ self::TIER_STANDARD ],
				'verification_enabled' => true,
				'debate_enabled'       => false,
				'model_tier'           => 'balanced',
			),
			self::TIER_SHALLOW  => array(
				'block_size'           => self::DEFAULT_BLOCK_SIZE[ self::TIER_SHALLOW ],
				'verification_enabled' => false,
				'debate_enabled'       => false,
				'model_tier'           => 'fast',
			),
			self::TIER_MINIMAL  => array(
				'block_size'           => self::DEFAULT_BLOCK_SIZE[ self::TIER_MINIMAL ],
				'verification_enabled' => false,
				'debate_enabled'       => false,
				'model_tier'           => 'cheapest',
			),
		);

		if ( isset( $configs[ $tier ] ) ) {
			$config = $configs[ $tier ];
		} else {
			$config = $configs[ self::TIER_STANDARD ];
		}

		/**
		 * Filter the tier configuration before returning.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $config The tier configuration.
		 * @param string $tier   The requested tier.
		 */
		return apply_filters( 'wp_mcp_ai_orchestration_depth_tier_config', $config, $tier );
	}

	/**
	 * Get the current system capacity utilisation from the load monitor.
	 *
	 * Returns a value between 0 (fully idle) and 100 (fully saturated).
	 * Caches the result for the lifetime of the request.
	 *
	 * @since 1.3.0
	 *
	 * @return float|WP_Error Current capacity utilisation, or WP_Error if the
	 *                        load monitor is unavailable.
	 */
	public function get_current_capacity() {
		if ( null !== $this->cached_capacity ) {
			return $this->cached_capacity;
		}

		$monitor = $this->get_load_monitor();

		if ( ! $monitor instanceof WP_MCP_AI_Tool_Load_Monitor ) {
			return new WP_Error(
				'wp_mcp_ai_load_monitor_unavailable',
				esc_html__( 'The tool load monitor is not available.', 'mcp-ai-wpoos' )
			);
		}

		// Query the monitor for active execution count and derive capacity.
		if ( method_exists( $monitor, 'get_active_executions' ) ) {
			$active = (int) $monitor->get_active_executions();
		} elseif ( method_exists( $monitor, 'get_active_executions_count' ) ) {
			$active = (int) $monitor->get_active_executions_count();
		} else {
			$active = 0;
		}

		// Attempt to get the maximum concurrency from the monitor if available.
		if ( method_exists( $monitor, 'get_max_concurrency' ) ) {
			$max = max( 1, (int) $monitor->get_max_concurrency() );
		} else {
			// Sensible default when the monitor does not expose concurrency.
			$max = 10;
		}

		$capacity = min( 100.0, ( $active / $max ) * 100.0 );

		$this->cached_capacity = $capacity;

		return $capacity;
	}

	/**
	 * Whether verification should be skipped for the given tier.
	 *
	 * Convenience wrapper so callers do not need to call get_tier_config()
	 * and inspect the array when they only care about verification.
	 *
	 * @since 1.3.0
	 *
	 * @param string $tier One of the TIER_* constants.
	 * @return bool True if verification is disabled for this tier.
	 */
	public function should_skip_verification( $tier ) {
		$tier   = sanitize_key( (string) $tier );
		$config = $this->get_tier_config( $tier );

		return empty( $config['verification_enabled'] );
	}

	/**
	 * Lazy-load and return the tool load monitor.
	 *
	 * If a monitor was passed to the constructor it is returned directly;
	 * otherwise the method attempts to locate one from the global state.
	 *
	 * @since 1.3.0
	 *
	 * @return WP_MCP_AI_Tool_Load_Monitor|null The monitor instance, or null if
	 *                                           none could be resolved.
	 */
	protected function get_load_monitor() {
		if ( $this->load_monitor instanceof WP_MCP_AI_Tool_Load_Monitor ) {
			return $this->load_monitor;
		}

		if ( class_exists( 'WP_MCP_AI_Tool_Load_Monitor' ) ) {
			// Attempt to retrieve an already-constructed instance via a common
			// accessor pattern used elsewhere in the plugin.
			if ( method_exists( 'WP_MCP_AI_Tool_Load_Monitor', 'get_instance' ) ) {
				$this->load_monitor = WP_MCP_AI_Tool_Load_Monitor::get_instance();
			} else {
				$this->load_monitor = new WP_MCP_AI_Tool_Load_Monitor();
			}
		}

		return $this->load_monitor;
	}
}
