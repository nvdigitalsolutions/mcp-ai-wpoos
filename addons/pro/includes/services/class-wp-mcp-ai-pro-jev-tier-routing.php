<?php
/**
 * Pro service: Jev tier-routing signal (proposal 045, G1).
 *
 * Wires the Jev decision-model classification into the base routing and
 * orchestration layers behind the opt-in `enable_jev_tier_routing`
 * setting. The decision model only supplies a signal; every threshold and
 * gate stays in code:
 *
 *  - `wp_mcp_ai_tiered_model_selection` — attaches an informational
 *    `decision_model` metadata block to tier-selection results.
 *  - `wp_mcp_ai_execution_depth_confidence` — replaces a zero (unset)
 *    confidence with the Jev-derived signal so the depth scheduler sees
 *    semantic information instead of a neutral default.
 *
 * Fail-open by design: when the setting is off, Jev is unavailable, the
 * context carries no prompt, or any decision call errors, the original
 * values pass through untouched. Caller-supplied non-zero confidence and
 * explicit provider/model/tier selections always win.
 *
 * @package NV_oOS_Pro
 * @since   1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jev tier-routing wiring helper for Pro surfaces.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Pro_Jev_Tier_Routing {

	/**
	 * Neutral confidence used by the classifier when Jev cannot steer.
	 *
	 * @var float
	 */
	const NEUTRAL_CONFIDENCE = 0.65;

	/**
	 * Register the routing-signal filters.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_mcp_ai_tiered_model_selection', array( __CLASS__, 'enrich_selection' ), 20, 3 );
		add_filter( 'wp_mcp_ai_execution_depth_confidence', array( __CLASS__, 'execution_depth_confidence' ), 20, 3 );
		add_filter( 'wp_mcp_ai_cascade_classifier', array( __CLASS__, 'cascade_classifier' ), 20, 3 );
	}

	/**
	 * Supply the legacy cascade (Proposal 056, P1) with a Jev classification.
	 *
	 * Runs on the base `wp_mcp_ai_cascade_classifier` filter. The mapping
	 * stays in code (proposal 045 discipline): the decision model supplies
	 * complexity / frontier-read signals and {@see map_signal_to_tier()}
	 * owns the tier translation. Returning the incoming verdict unchanged
	 * (null) leaves the cascade inactive for the request — fail-closed by
	 * design.
	 *
	 * @since 1.1.97
	 *
	 * @param array|null $verdict  Incoming classification or null.
	 * @param array      $messages Chat messages.
	 * @param array      $options  Request options.
	 * @return array|null Classification verdict or null.
	 */
	public static function cascade_classifier( $verdict, $messages, $options ) {
		// The third argument is part of the base filter contract; the signal
		// is derived from the messages, not the options.
		unset( $options );

		if ( null !== $verdict ) {
			// An earlier (higher-priority) classifier already answered.
			return $verdict;
		}

		if ( ! self::is_enabled() ) {
			return null;
		}

		$signal = self::get_signal( $messages );
		if ( null === $signal ) {
			return null;
		}

		$tier = self::map_signal_to_tier( $signal );

		return array(
			'tier'       => $tier,
			'confidence' => max( 0.0, min( 1.0, isset( $signal['confidence'] ) ? (float) $signal['confidence'] : 0.0 ) ),
			'reason'     => sprintf(
				/* translators: 1: complexity score, 2: frontier-read score. */
				__( 'jev-tier-routing (complexity %1$s, frontier %2$s)', 'mcp-ai-wpoos' ),
				number_format_i18n( isset( $signal['complexity'] ) ? (float) $signal['complexity'] : 0.0, 2 ),
				number_format_i18n( isset( $signal['needs_frontier'] ) ? (float) $signal['needs_frontier'] : 0.0, 2 )
			),
		);
	}

	/**
	 * Map a Jev routing signal to a cascade tier.
	 *
	 * Code-owned thresholds (proposal 045 discipline):
	 *  - frontier-read ≥ 0.7 → complex (skip the cheap tier);
	 *  - otherwise cheap-tier confidence ≥ 0.5 → simple;
	 *  - anything weaker → complex (fail closed).
	 *
	 * @since 1.1.97
	 *
	 * @param array $signal Routing signal from
	 *                      `WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for()`.
	 * @return string 'simple' or 'complex'.
	 */
	public static function map_signal_to_tier( $signal ) {
		$needs_frontier = isset( $signal['needs_frontier'] ) ? (float) $signal['needs_frontier'] : 0.0;
		$confidence     = isset( $signal['confidence'] ) ? (float) $signal['confidence'] : 0.0;

		if ( $needs_frontier >= 0.7 ) {
			return 'complex';
		}

		return $confidence >= 0.5 ? 'simple' : 'complex';
	}

	/**
	 * Whether the tier-routing signal is enabled on this site.
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );

		return ! empty( $settings['enable_jev_tier_routing'] );
	}

	/**
	 * Enrich a tiered model selection with an informational decision block.
	 *
	 * Runs on the base `wp_mcp_ai_tiered_model_selection` filter. Only adds
	 * metadata — it never changes the selected model, tier, or confidence.
	 * Fail-open: any error returns the config unchanged.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $config    Selection result.
	 * @param string $task_type Task category.
	 * @param array  $options   Original routing options.
	 * @return array Possibly-enriched selection result.
	 */
	public static function enrich_selection( $config, $task_type, $options ) {
		if ( ! self::is_enabled() || ! is_array( $config ) ) {
			return $config;
		}

		$messages = isset( $options['messages'] ) && is_array( $options['messages'] ) ? $options['messages'] : array();
		if ( empty( $messages ) ) {
			return $config;
		}

		$signal = self::get_signal( $messages );
		if ( null === $signal ) {
			return $config;
		}

		$config['decision_model'] = $signal;

		return $config;
	}

	/**
	 * Replace an unset confidence with the Jev-derived signal.
	 *
	 * Runs on the base `wp_mcp_ai_execution_depth_confidence` filter. A
	 * caller-supplied non-zero confidence is authoritative and untouched.
	 * The signal is only produced when the execution context carries a chat
	 * prompt (messages, user_message, or prompt).
	 *
	 * @since 1.9.0
	 *
	 * @param float  $confidence Confidence signal (0.0 when unset).
	 * @param array  $context    Execution context.
	 * @param string $tool_slug  Tool being executed.
	 * @return float Confidence signal to feed the depth scheduler.
	 */
	public static function execution_depth_confidence( $confidence, $context, $tool_slug ) {
		// The third argument is part of the base filter contract; the signal
		// is derived from the context prompt, not the slug.
		unset( $tool_slug );

		if ( ! self::is_enabled() ) {
			return $confidence;
		}

		$confidence = (float) $confidence;

		// A caller-supplied signal always wins — Jev only fills the gap.
		if ( $confidence > 0.0 ) {
			return $confidence;
		}

		$messages = self::messages_from_context( $context );
		if ( empty( $messages ) ) {
			return $confidence;
		}

		$signal = self::get_signal( $messages );

		return null === $signal ? $confidence : (float) $signal['confidence'];
	}

	/**
	 * Compute the routing signal for a message batch.
	 *
	 * @since 1.9.0
	 *
	 * @param array $messages Chat messages.
	 * @return array|null Routing signal, or null when unavailable/errored.
	 */
	private static function get_signal( $messages ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Classifier' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Classifier' ) ) {
			return null;
		}

		$signal = WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for( $messages );

		if ( is_wp_error( $signal ) || ! is_array( $signal ) || empty( $signal['used_jev'] ) ) {
			// Neutral fallback (unavailable / low-confidence read) must not
			// steer the router — treat it as "no signal".
			return null;
		}

		return $signal;
	}

	/**
	 * Extract a chat-message batch from a tool-execution context.
	 *
	 * @since 1.9.0
	 *
	 * @param array $context Execution context.
	 * @return array Chat messages (empty when the context carries no prompt).
	 */
	private static function messages_from_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		if ( isset( $context['messages'] ) && is_array( $context['messages'] ) && ! empty( $context['messages'] ) ) {
			return $context['messages'];
		}

		if ( isset( $context['user_message'] ) && is_string( $context['user_message'] ) && '' !== trim( $context['user_message'] ) ) {
			return array(
				array(
					'role'    => 'user',
					'content' => $context['user_message'],
				),
			);
		}

		if ( isset( $context['prompt'] ) && is_string( $context['prompt'] ) && '' !== trim( $context['prompt'] ) ) {
			return array(
				array(
					'role'    => 'user',
					'content' => $context['prompt'],
				),
			);
		}

		return array();
	}
}
