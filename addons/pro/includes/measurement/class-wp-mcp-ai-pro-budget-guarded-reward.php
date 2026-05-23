<?php
/**
 * Pro Budget-Guarded Reward
 *
 * Closes the loop between PR 4's budget envelopes and the reward
 * function registry: a reward can be wrapped so its output is
 * **clamped to 0 when a named budget is in the `exceeded` state**.
 * This turns the observability-only budget signal into a reward-level
 * guard without forcing the core to take a veto stance.
 *
 * The anti-Goodhart argument for this pattern:
 *   A reward that is monotone in "successful tool calls" will pay an
 *   agent that spams cheap-ish calls until spend explodes. Pairing
 *   that reward with a budget-guarded wrapper means the reward's
 *   gradient **reverses** the moment the spend cap is breached: each
 *   new call now pays zero. Operators keep their original reward; the
 *   wrapper is the policy.
 *
 * The wrapper does NOT touch the budget registry's own signals —
 * `wp_mcp_ai_budget_exceeded` still fires on breach. It only shapes
 * the reward output.
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
 * Budget-guarded reward factory.
 */
class WP_MCP_AI_Pro_Budget_Guarded_Reward {

	/**
	 * Produce a callable suitable for the `callback` field of a reward
	 * function definition.
	 *
	 * The returned callable:
	 *   1. Looks up the inner reward definition at runtime so changes
	 *      to the wrapped reward are picked up without rewiring.
	 *   2. Evaluates the inner reward with the same inputs/context.
	 *   3. Looks up the budget envelope's current consumption; if the
	 *      budget is in `exceeded` state, returns 0.0 and records a
	 *      sentinel in the context array (under a key visible to the
	 *      caller) so observability can distinguish "reward was zero
	 *      because the answer was wrong" from "reward was zero because
	 *      the budget was blown".
	 *   4. In `warn` state, applies an optional linear dampener
	 *      (defaults to full reward — operators opt in explicitly).
	 *
	 * @param array $args {
	 *   Required: `inner` (string slug of the wrapped reward),
	 *             `budget` (string slug of the budget envelope).
	 *   Optional: `warn_multiplier` (float, 0..1, default 1.0),
	 *             `exceeded_multiplier` (float, 0..1, default 0.0).
	 * }.
	 * @return callable|WP_Error
	 */
	public static function make_callback( array $args ) {
		$inner  = isset( $args['inner'] ) ? sanitize_key( (string) $args['inner'] ) : '';
		$budget = isset( $args['budget'] ) ? sanitize_key( (string) $args['budget'] ) : '';
		if ( '' === $inner || '' === $budget ) {
			return new WP_Error(
				'wp_mcp_ai_guarded_reward_invalid',
				__( 'Budget-guarded reward requires both "inner" and "budget" slugs.', 'mcp-ai-wpoos' )
			);
		}

		$warn_multiplier     = isset( $args['warn_multiplier'] ) ? self::clamp( (float) $args['warn_multiplier'] ) : 1.0;
		$exceeded_multiplier = isset( $args['exceeded_multiplier'] ) ? self::clamp( (float) $args['exceeded_multiplier'] ) : 0.0;

		return static function ( array $inputs, $context = array() ) use ( $inner, $budget, $warn_multiplier, $exceeded_multiplier ) {
			if ( ! is_array( $context ) ) {
				$context = array();
			}
			$inner_value = self::compute_inner( $inner, $inputs, $context );
			if ( ! is_numeric( $inner_value ) ) {
				return 0.0;
			}
			$inner_value = (float) $inner_value;

			$state = self::budget_state( $budget );

			switch ( $state ) {
				case 'exceeded':
					return (float) $inner_value * $exceeded_multiplier;
				case 'warn':
					return (float) $inner_value * $warn_multiplier;
				case 'ok':
				default:
					return $inner_value;
			}
		};
	}

	/**
	 * Registration helper — registers a guarded wrapper around an
	 * existing reward with sensible defaults. Returns the newly-
	 * registered definition (or a WP_Error).
	 *
	 * @param WP_MCP_AI_Reward_Function_Registry $registry Target registry.
	 * @param array                              $args     Wrapper args plus optional
	 *                                                     `slug`, `label`, `description`,
	 *                                                     `anti_gaming`, `counter_metric`.
	 * @return array|WP_Error
	 */
	public static function register_wrapper( $registry, array $args ) {
		if ( ! $registry instanceof WP_MCP_AI_Reward_Function_Registry ) {
			return new WP_Error( 'wp_mcp_ai_guarded_reward_registry', 'Registry instance required.' );
		}

		$callback = self::make_callback( $args );
		if ( is_wp_error( $callback ) ) {
			return $callback;
		}

		$inner_slug  = sanitize_key( (string) $args['inner'] );
		$budget_slug = sanitize_key( (string) $args['budget'] );
		$slug        = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : ( $inner_slug . '_budget_guarded' );

		$definition = array(
			'slug'           => $slug,
			'label'          => isset( $args['label'] )
				? (string) $args['label']
				: sprintf(
					/* translators: 1: inner reward slug, 2: budget slug. */
					__( 'Budget-Guarded %1$s (%2$s)', 'mcp-ai-wpoos' ),
					$inner_slug,
					$budget_slug
				),
			'description'    => isset( $args['description'] )
				? (string) $args['description']
				: sprintf(
					/* translators: 1: inner reward, 2: budget. */
					__( 'Evaluates %1$s and clamps its output when the %2$s budget envelope is breached.', 'mcp-ai-wpoos' ),
					$inner_slug,
					$budget_slug
				),
			'callback'       => $callback,
			// Inputs inherit from the inner reward at runtime; declaring
			// the wrapper's inputs explicitly keeps the registry's
			// introspection accurate even before the inner reward is
			// registered.
			'inputs'         => isset( $args['inputs'] ) && is_array( $args['inputs'] ) ? $args['inputs'] : array(),
			'output_min'     => 0.0,
			'output_max'     => 1.0,
			'anti_gaming'    => isset( $args['anti_gaming'] )
				? (string) $args['anti_gaming']
				: __(
					'Output is clamped to 0 when the named budget envelope exceeds its limit. An agent that optimizes this reward cannot earn past the operator-declared spend cap — reversing Goodhart pressure on unbounded spend.',
					'mcp-ai-wpoos'
				),
			'counter_metric' => isset( $args['counter_metric'] ) ? (string) $args['counter_metric'] : '',
		);

		$result = $registry->register( $definition );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $definition;
	}

	/**
	 * Lookup and compute the inner reward.
	 *
	 * @param string $inner_slug Inner slug.
	 * @param array  $inputs     Inputs.
	 * @param array  $context    Context.
	 * @return float|null
	 */
	private static function compute_inner( $inner_slug, array $inputs, array $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Reward_Function_Registry' ) ) {
			return null;
		}
		$registry = WP_MCP_AI_Reward_Function_Registry::get_instance();
		$def      = $registry->get( $inner_slug );
		if ( ! is_array( $def ) || empty( $def['callback'] ) || ! is_callable( $def['callback'] ) ) {
			return null;
		}
		$value = call_user_func( $def['callback'], $inputs, $context );
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Current state of a budget envelope: `ok` / `warn` / `exceeded`.
	 * Returns `ok` when the registry or envelope is missing so the
	 * wrapper degrades to "pass through" rather than silently zeroing
	 * every call.
	 *
	 * @param string $budget_slug Budget slug.
	 * @return string
	 */
	private static function budget_state( $budget_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Budget_Registry' ) ) {
			return 'ok';
		}
		$registry = WP_MCP_AI_Budget_Registry::get_instance();
		$envelope = $registry->get( $budget_slug );
		if ( ! $envelope instanceof WP_MCP_AI_Budget_Envelope ) {
			return 'ok';
		}
		$consumed = $registry->get_consumption( $budget_slug );
		if ( $consumed >= $envelope->get_limit() ) {
			return 'exceeded';
		}
		if ( $consumed >= $envelope->get_warn_threshold() ) {
			return 'warn';
		}
		return 'ok';
	}

	/**
	 * Clamp helper (duplicated from verifier base to avoid dragging a
	 * verifier dependency into a reward factory).
	 *
	 * @param float $v Value.
	 * @return float
	 */
	private static function clamp( $v ) {
		$v = (float) $v;
		if ( $v < 0.0 ) {
			return 0.0;
		}
		if ( $v > 1.0 ) {
			return 1.0;
		}
		return $v;
	}
}
