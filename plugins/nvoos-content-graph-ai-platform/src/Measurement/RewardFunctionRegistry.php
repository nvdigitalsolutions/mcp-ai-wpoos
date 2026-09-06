<?php
/**
 * Reward Function Registry
 *
 * Composable reward functions for autonomous loops. Each reward function
 * declares inputs, output range, paired counter-metric, and an anti-gaming
 * safeguard note. The registry enforces that every registered reward function
 * has a documented anti-gaming safeguard — unsafeguarded reward functions are
 * rejected. This is a Goodhart guard: reward signals without counter-signals
 * are the single most common cause of reward hacking.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reward Function Registry singleton.
 */
class RewardFunctionRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var RewardFunctionRegistry|null
	 */
	private static $instance = null;

	/**
	 * Registered reward functions keyed by slug.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $functions = array();

	/**
	 * Whether the `wp_mcp_ai_register_reward_functions` action has fired.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Get the singleton.
	 *
	 * @return RewardFunctionRegistry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton. Intended for tests only.
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Fire the registration action exactly once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Fires when third parties should register reward functions.
		 *
		 * @since 1.3.0
		 *
		 * @param RewardFunctionRegistry $registry Registry.
		 */
		do_action( 'wp_mcp_ai_register_reward_functions', $this );
	}

	/**
	 * Register a reward function.
	 *
	 * Required keys:
	 *   - slug            string   Unique slug.
	 *   - label           string   Human-readable label.
	 *   - callback        callable (array $inputs, array $context) => float.
	 *   - output_min      float    Minimum output (inclusive).
	 *   - output_max      float    Maximum output (inclusive).
	 *   - inputs          array    List of required input keys.
	 *   - anti_gaming     string   Documented anti-gaming safeguard. MUST be non-empty.
	 *
	 * Optional keys:
	 *   - counter_metric  string  Paired counter-metric id (Goodhart guard).
	 *   - description     string
	 *
	 * Returns a WP_Error if validation fails so callers can surface a precise
	 * error to the admin UI.
	 *
	 * @param array $definition Definition array.
	 * @return true|WP_Error
	 */
	public function register( array $definition ) {
		$slug = isset( $definition['slug'] ) ? sanitize_key( (string) $definition['slug'] ) : '';
		if ( '' === $slug ) {
			return new \WP_Error( 'wp_mcp_ai_reward_invalid_slug', __( 'Reward function slug is required.', 'nvoos-content-graph-ai-platform' ) );
		}
		if ( isset( $this->functions[ $slug ] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_reward_already_registered',
				/* translators: %s: reward function slug. */
				sprintf( __( 'Reward function "%s" is already registered.', 'nvoos-content-graph-ai-platform' ), $slug )
			);
		}
		if ( empty( $definition['callback'] ) || ! is_callable( $definition['callback'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_reward_invalid_callback', __( 'Reward function callback must be callable.', 'nvoos-content-graph-ai-platform' ) );
		}
		if ( empty( $definition['label'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_reward_missing_label', __( 'Reward function label is required.', 'nvoos-content-graph-ai-platform' ) );
		}
		if ( ! isset( $definition['output_min'] ) || ! isset( $definition['output_max'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_reward_missing_range', __( 'Reward function output range is required.', 'nvoos-content-graph-ai-platform' ) );
		}
		$output_min = (float) $definition['output_min'];
		$output_max = (float) $definition['output_max'];
		if ( $output_max <= $output_min ) {
			return new \WP_Error( 'wp_mcp_ai_reward_invalid_range', __( 'Reward function output_max must be greater than output_min.', 'nvoos-content-graph-ai-platform' ) );
		}
		if ( empty( $definition['anti_gaming'] ) || ! is_string( $definition['anti_gaming'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_reward_missing_anti_gaming',
				__( 'Reward function must declare an anti-gaming safeguard. This is a Goodhart guard and cannot be bypassed.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$this->functions[ $slug ] = array(
			'slug'           => $slug,
			'label'          => sanitize_text_field( (string) $definition['label'] ),
			'description'    => isset( $definition['description'] ) ? wp_kses_post( (string) $definition['description'] ) : '',
			'callback'       => $definition['callback'],
			'output_min'     => $output_min,
			'output_max'     => $output_max,
			'inputs'         => isset( $definition['inputs'] ) && is_array( $definition['inputs'] )
				? array_values( array_map( 'sanitize_key', $definition['inputs'] ) )
				: array(),
			'anti_gaming'    => wp_kses_post( (string) $definition['anti_gaming'] ),
			'counter_metric' => isset( $definition['counter_metric'] ) ? sanitize_text_field( (string) $definition['counter_metric'] ) : '',
		);
		return true;
	}

	/**
	 * Unregister a reward function.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function unregister( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( isset( $this->functions[ $slug ] ) ) {
			unset( $this->functions[ $slug ] );
			return true;
		}
		return false;
	}

	/**
	 * Get a reward function definition.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public function get( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return isset( $this->functions[ $slug ] ) ? $this->functions[ $slug ] : null;
	}

	/**
	 * All registered reward functions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all() {
		return $this->functions;
	}

	/**
	 * Evaluate a reward function with input validation.
	 *
	 * @param string $slug    Reward function slug.
	 * @param array  $inputs  Input values.
	 * @param array  $context Context.
	 * @return float|WP_Error Clamped output value or WP_Error on failure.
	 */
	public function evaluate( $slug, array $inputs, array $context = array() ) {
		$def = $this->get( $slug );
		if ( null === $def ) {
			return new \WP_Error(
				'wp_mcp_ai_reward_not_found',
				/* translators: %s: reward function slug. */
				sprintf( __( 'Reward function "%s" is not registered.', 'nvoos-content-graph-ai-platform' ), $slug )
			);
		}

		foreach ( $def['inputs'] as $required ) {
			if ( ! array_key_exists( $required, $inputs ) ) {
				return new \WP_Error(
					'wp_mcp_ai_reward_missing_input',
					/* translators: 1: input key, 2: reward function slug. */
					sprintf( __( 'Missing required input "%1$s" for reward function "%2$s".', 'nvoos-content-graph-ai-platform' ), $required, $slug )
				);
			}
		}

		$value = call_user_func( $def['callback'], $inputs, $context );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		if ( ! is_numeric( $value ) ) {
			return new \WP_Error(
				'wp_mcp_ai_reward_non_numeric',
				/* translators: %s: reward function slug. */
				sprintf( __( 'Reward function "%s" returned a non-numeric value.', 'nvoos-content-graph-ai-platform' ), $slug )
			);
		}
		$value = (float) $value;

		// Clamp to declared range.
		if ( $value < $def['output_min'] ) {
			$value = $def['output_min'];
		} elseif ( $value > $def['output_max'] ) {
			$value = $def['output_max'];
		}

		return $value;
	}
}
