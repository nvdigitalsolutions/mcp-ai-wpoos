<?php
/**
 * Pro Measurement Bootstrap
 *
 * Single entry point that wires Pro-only measurement artifacts into the
 * base plugin's measurement registries. Base measurement files are
 * *always* present on a Pro install (Pro depends on Base), but this
 * bootstrap still guards its wiring behind class-existence checks so
 * that load-order changes don't produce fatals.
 *
 * What this registers (on-hook, so Base installs stay untouched):
 *   - A stock "pro_content_rubric" rubric verifier composing the three
 *     reference verifiers (rule, schema, llm-judge). This is a template
 *     — deployments are expected to override/extend via filter.
 *   - A budget-guarded wrapper around the Base `verified_success`
 *     reward, tied to the `pro_request_cost_usd` envelope.
 *   - A `pro_request_cost_usd` request-scope budget envelope that
 *     demonstrates how Pro can ship policy defaults without forcing
 *     Base installs to adopt them.
 *
 * Hooks registered:
 *   - `wp_mcp_ai_register_verifiers`       (priority 20)
 *   - `wp_mcp_ai_register_budgets`         (priority 20)
 *   - `wp_mcp_ai_register_reward_functions` (priority 30)
 *
 * Registration order matters: budgets must exist before the guarded
 * reward resolves its budget handle on first use, and the guarded
 * reward is registered at priority 30 so Base reference rewards
 * (priority 10) register first.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Measurement Bootstrap singleton.
 */
class WP_MCP_AI_Pro_Measurement_Bootstrap {

	/**
	 * Whether boot() has run (idempotent).
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Boot: attach all pro measurement hooks. Safe to call multiple
	 * times; subsequent calls are no-ops.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Gate: base measurement infrastructure must be present. If
		// a Pro install somehow loads without Base measurement, skip
		// wiring rather than dropping a fatal.
		if (
			! class_exists( 'WP_MCP_AI_Verifier_Registry' ) ||
			! class_exists( 'WP_MCP_AI_Reward_Function_Registry' ) ||
			! class_exists( 'WP_MCP_AI_Budget_Registry' )
		) {
			return;
		}

		add_action( 'wp_mcp_ai_register_verifiers', array( __CLASS__, 'register_verifiers' ), 20 );
		add_action( 'wp_mcp_ai_register_verifiers', array( __CLASS__, 'register_preset_rubrics' ), 25 );
		add_action( 'wp_mcp_ai_register_budgets', array( __CLASS__, 'register_budgets' ), 20 );
		add_action( 'wp_mcp_ai_register_reward_functions', array( __CLASS__, 'register_rewards' ), 30 );

		// Boot the Pro Schedule OTel subscriber (registers metrics +
		// subscribes to wp_mcp_ai_pro_schedule_run_completed).
		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Otel_Subscriber' ) ) {
			WP_MCP_AI_Pro_Schedule_Otel_Subscriber::boot();
		}
	}

	/**
	 * Reset (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$booted = false;
		remove_action( 'wp_mcp_ai_register_verifiers', array( __CLASS__, 'register_verifiers' ), 20 );
		remove_action( 'wp_mcp_ai_register_verifiers', array( __CLASS__, 'register_preset_rubrics' ), 25 );
		remove_action( 'wp_mcp_ai_register_budgets', array( __CLASS__, 'register_budgets' ), 20 );
		remove_action( 'wp_mcp_ai_register_reward_functions', array( __CLASS__, 'register_rewards' ), 30 );

		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Otel_Subscriber' ) ) {
			WP_MCP_AI_Pro_Schedule_Otel_Subscriber::reset();
		}
	}

	/**
	 * Register Pro verifiers.
	 *
	 * @param WP_MCP_AI_Verifier_Registry $registry Registry.
	 * @return void
	 */
	public static function register_verifiers( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Verifier_Registry ) {
			return;
		}
		/**
		 * Filters the default criteria for the stock Pro rubric.
		 *
		 * Deployments that want a different default rubric shape should
		 * filter this rather than unregistering the verifier entirely —
		 * the slug (`pro_content_rubric`) is a stable contract for
		 * eval suites and dashboards.
		 *
		 * @since 1.3.0
		 *
		 * @param array $criteria Default criterion definitions.
		 */
		$criteria = apply_filters(
			'wp_mcp_ai_pro_rubric_default_criteria',
			array(
				array(
					'slug'        => 'schema_valid',
					'description' => 'Output matches the declared schema.',
					'verifier'    => 'schema',
					'weight'      => 2.0,
				),
				array(
					'slug'        => 'rule_checks',
					'description' => 'Output passes declared rule predicates.',
					'verifier'    => 'rule',
					'weight'      => 1.0,
				),
				array(
					'slug'        => 'judge_quality',
					'description' => 'LLM judge scores output quality.',
					'verifier'    => 'llm_judge',
					'weight'      => 1.0,
				),
			)
		);

		try {
			$verifier = new WP_MCP_AI_Pro_Rubric_Verifier(
				'pro_content_rubric',
				$criteria,
				__( 'Pro Content Rubric', 'mcp-ai-wpoos' ),
				0.7
			);
			$registry->register( $verifier );
		} catch ( InvalidArgumentException $e ) {
			// Can only happen if the default filter removed every
			// criterion — silently skip rather than fataling.
			return;
		}
	}

	/**
	 * Register Pro budgets.
	 *
	 * @param WP_MCP_AI_Budget_Registry $registry Registry.
	 * @return void
	 */
	public static function register_budgets( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Budget_Registry ) {
			return;
		}

		/**
		 * Filters the default limit for the Pro request-cost budget.
		 *
		 * Expressed in USD; 0.25 is a conservative default for a single
		 * assistant request. Operators running expensive multi-step
		 * agents should raise this; ops running high-volume cheap
		 * tools should lower it so the warn hook surfaces cost creep
		 * earlier.
		 *
		 * @since 1.3.0
		 *
		 * @param float $limit Default limit in USD.
		 */
		$limit = (float) apply_filters( 'wp_mcp_ai_pro_request_cost_budget_limit', 0.25 );
		if ( $limit <= 0.0 ) {
			return;
		}

		$registry->register(
			array(
				'slug'       => 'pro_request_cost_usd',
				'label'      => __( 'Pro request cost (USD)', 'mcp-ai-wpoos' ),
				'metric_ids' => array( 'model.cost_usd' ),
				'limit'      => $limit,
				'warn_ratio' => 0.8,
				'unit'       => 'usd',
				'scope'      => WP_MCP_AI_Budget_Envelope::SCOPE_REQUEST,
				'tags'       => array( 'tier' => 'pro' ),
			)
		);
	}

	/**
	 * Register Pro reward functions.
	 *
	 * @param WP_MCP_AI_Reward_Function_Registry $registry Registry.
	 * @return void
	 */
	public static function register_rewards( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Reward_Function_Registry ) {
			return;
		}

		WP_MCP_AI_Pro_Budget_Guarded_Reward::register_wrapper(
			$registry,
			array(
				'inner'               => 'verified_success',
				'budget'              => 'pro_request_cost_usd',
				'slug'                => 'verified_success_budget_guarded',
				'warn_multiplier'     => 1.0,
				'exceeded_multiplier' => 0.0,
				'inputs'              => array( 'verifier_passed', 'verifier_confidence' ),
				'counter_metric'      => 'agent.abstention.rate',
			)
		);
	}

	/**
	 * Register the three stock rubric presets (prompt adherence,
	 * JSON schema, citation presence). Runs at priority 25 so the
	 * composite `pro_content_rubric` registered at 20 is in place
	 * first — presets do not depend on it, but operators who have
	 * both registered get deterministic ordering this way.
	 *
	 * Each preset is wrapped in its own try/catch so a single broken
	 * filter cannot prevent the others from registering.
	 *
	 * @param WP_MCP_AI_Verifier_Registry $registry Registry.
	 * @return void
	 */
	public static function register_preset_rubrics( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Verifier_Registry ) {
			return;
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Rubric_Presets' ) ) {
			return;
		}

		$factories = array(
			WP_MCP_AI_Pro_Rubric_Presets::SLUG_PROMPT_ADHERENCE => array( 'WP_MCP_AI_Pro_Rubric_Presets', 'prompt_adherence' ),
			WP_MCP_AI_Pro_Rubric_Presets::SLUG_JSON_SCHEMA => array( 'WP_MCP_AI_Pro_Rubric_Presets', 'json_schema' ),
			WP_MCP_AI_Pro_Rubric_Presets::SLUG_CITATION_PRESENCE => array( 'WP_MCP_AI_Pro_Rubric_Presets', 'citation_presence' ),
		);

		foreach ( $factories as $slug => $factory ) {
			try {
				$verifier = call_user_func( $factory );
				if ( $verifier instanceof WP_MCP_AI_Pro_Rubric_Verifier ) {
					$registry->register( $verifier );
				}
			} catch ( InvalidArgumentException $e ) {
				// A filter author removed every criterion. Skip this
				// preset but keep trying the others.
				continue;
			}
		}
	}
}
