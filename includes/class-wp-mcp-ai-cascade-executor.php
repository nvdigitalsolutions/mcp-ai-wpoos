<?php
/**
 * Cascade Executor — legacy-layer cascade routing for the base plugin.
 *
 * The live base+pro chat path runs through
 * {@see WP_MCP_AI_Language_Model_Router::create_chat_completion()} — it does
 * NOT pass through the framework-agnostic `lib/core` engine. This class
 * brings the FrugalGPT / RouteLLM cascade pattern (Proposal 056, P1) to that
 * legacy path with the same contract shapes as
 * `Nvoos\Core\Application\Provider\CascadeRouter`:
 *
 *   classify → route cheap tier → validate the cheap answer → escalate to
 *   the primary provider when the validator or confidence demands it.
 *
 * The executor is inert by default:
 *   - `wp_mcp_ai_cascade_enabled` filter defaults to false (opt-in).
 *   - No tier-1 target configured → passthrough.
 *   - No classifier returns a verdict → passthrough (fail-closed).
 *   - Streaming requests never cascade (a streamed answer cannot be
 *     retracted) — same decision as the lib/core router.
 *   - Tier-1 errors → fail-open escalation to the primary provider.
 *
 * Seams:
 *   - `wp_mcp_ai_cascade_classifier` — returns
 *     `{ tier: 'simple'|'complex', confidence: 0..1, reason: string }` or
 *     null. The Pro addon wires the TypeSafe Jev classifier
 *     (`WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for()`, Proposal 045).
 *   - `wp_mcp_ai_cascade_validator` — judges the cheap-tier answer:
 *     `{ acceptable: bool, confidence: 0..1, reason: string }`. A
 *     deterministic default (error shapes, empty content) applies when no
 *     listener overrides it, so the base plugin works without Pro.
 *   - `wp_mcp_ai_cascade_decision` — action fired with the outcome for
 *     audit/cost-tracker subscribers.
 *
 * Configuration keys (options array, same names as lib/core):
 *   cascade_tier_1_model, cascade_tier_1_provider,
 *   cascade_confidence_threshold. Assistant-level configuration is read
 *   through `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` when
 *   `assistant_id` is present in options.
 *
 * @credit  Cascade-routing concept inspired by affaan-m/ECC cost-aware LLM
 *          pipeline skill (MIT) and the FrugalGPT/RouteLLM literature.
 * @package WP_MCP_AI
 * @since   1.1.97
 * @author  NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy-layer cascade executor.
 *
 * @since 1.1.97
 */
class WP_MCP_AI_Cascade_Executor {

	/**
	 * Default validator-confidence threshold.
	 *
	 * @var float
	 */
	const DEFAULT_CONFIDENCE_THRESHOLD = 0.85;

	/**
	 * Routing statistics.
	 *
	 * @var array
	 */
	private static $stats = array(
		'requests'      => 0,
		'routed_simple' => 0,
		'accepted'      => 0,
		'escalated'     => 0,
		'tier1_errors'  => 0,
		'passthrough'   => 0,
	);

	/**
	 * Attempt a cascade for a chat completion.
	 *
	 * Returns null when the cascade is inactive for this request — the
	 * caller then dispatches normally (zero behavior change). Any non-null
	 * return is the final result of the cascade (tier-1 answer, escalated
	 * primary answer, or error).
	 *
	 * @param WP_MCP_AI_Language_Model_Router $router  Legacy router.
	 * @param array                           $messages Chat messages.
	 * @param array                           $options  Request options.
	 * @return array|WP_Error|null Cascade result, or null to dispatch normally.
	 */
	public static function maybe_route( $router, $messages, $options ) {
		++self::$stats['requests'];

		if ( ! empty( $options['wp_mcp_ai_cascade_bypass'] ) ) {
			++self::$stats['passthrough'];
			return null;
		}

		// Streaming answers cannot be retracted — never cascade streams.
		if ( ! empty( $options['stream'] ) || ! empty( $options['stream_callback'] ) ) {
			++self::$stats['passthrough'];
			return null;
		}

		/**
		 * Filters whether legacy cascade routing is active.
		 *
		 * @since 1.1.97
		 *
		 * @param bool  $enabled  Whether cascading runs. Default false.
		 * @param array $messages Chat messages.
		 * @param array $options  Request options.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_cascade_enabled', false, $messages, $options ) ) {
			++self::$stats['passthrough'];
			return null;
		}

		$tier1_config = self::resolve_tier1_config( $options );
		if ( '' === $tier1_config['model'] && '' === $tier1_config['provider'] ) {
			++self::$stats['passthrough'];
			return null;
		}

		/**
		 * Filters the cascade classification.
		 *
		 * A listener returns
		 * `{ tier: 'simple'|'complex', confidence: 0..1, reason: string }`.
		 * Returning null (the default) leaves the cascade inactive for this
		 * request — the primary provider handles it.
		 *
		 * @since 1.1.97
		 *
		 * @param array|null $verdict  Classification verdict or null.
		 * @param array      $messages Chat messages.
		 * @param array      $options  Request options.
		 */
		$classification = apply_filters( 'wp_mcp_ai_cascade_classifier', null, $messages, $options );

		if ( ! is_array( $classification ) || 'simple' !== ( isset( $classification['tier'] ) ? $classification['tier'] : '' ) ) {
			++self::$stats['passthrough'];
			return null;
		}

		$threshold       = self::resolve_threshold( $options );
		$primary_options = $options;
		unset( $primary_options['wp_mcp_ai_cascade_bypass'] );

		$tier1_options = self::build_tier1_options( $options, $tier1_config );

		++self::$stats['routed_simple'];

		$tier1_result = $router->create_chat_completion( $messages, $tier1_options );

		if ( is_wp_error( $tier1_result ) || ( is_array( $tier1_result ) && isset( $tier1_result['error'] ) ) ) {
			++self::$stats['tier1_errors'];
			++self::$stats['escalated'];
			self::record_decision( 'tier1_error', $classification, $tier1_config );
			return $router->create_chat_completion( $messages, $primary_options );
		}

		$verdict = self::default_verdict( $tier1_result );

		/**
		 * Filters the cascade validation verdict.
		 *
		 * The deterministic default (error shapes, empty content) applies
		 * when listeners leave it unchanged; a semantic judge (Jev or an
		 * LLM validator) can override `acceptable` and `confidence`.
		 *
		 * @since 1.1.97
		 *
		 * @param array $verdict   { acceptable, confidence, reason }.
		 * @param mixed $result    Cheap-tier provider result.
		 * @param array $messages  Chat messages.
		 * @param array $options   Request options.
		 */
		$verdict = apply_filters( 'wp_mcp_ai_cascade_validator', $verdict, $tier1_result, $messages, $options );

		$acceptable = is_array( $verdict ) && ! empty( $verdict['acceptable'] );
		$confidence = is_array( $verdict ) && isset( $verdict['confidence'] ) ? (float) $verdict['confidence'] : 0.0;

		if ( ! $acceptable || $confidence < $threshold ) {
			++self::$stats['escalated'];
			self::record_decision( 'escalated', $classification, $tier1_config );
			return $router->create_chat_completion( $messages, $primary_options );
		}

		++self::$stats['accepted'];
		self::record_decision( 'accepted', $classification, $tier1_config );

		return $tier1_result;
	}

	/**
	 * Resolve the tier-1 model/provider configuration.
	 *
	 * Resolution order: request options → assistant configuration (when
	 * `assistant_id` is present) → `wp_mcp_ai_cascade_default_tier_1_model`
	 * site filter (empty by default).
	 *
	 * @param array $options Request options.
	 * @return array{model: string, provider: string}
	 */
	private static function resolve_tier1_config( $options ) {
		$model    = '';
		$provider = '';

		if ( isset( $options['cascade_tier_1_model'] ) && is_string( $options['cascade_tier_1_model'] ) ) {
			$model = sanitize_text_field( $options['cascade_tier_1_model'] );
		}
		if ( isset( $options['cascade_tier_1_provider'] ) && is_string( $options['cascade_tier_1_provider'] ) ) {
			$provider = sanitize_key( $options['cascade_tier_1_provider'] );
		}

		if ( '' === $model && '' === $provider && ! empty( $options['assistant_id'] ) && class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
			$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( absint( $options['assistant_id'] ) );
			if ( is_array( $config ) ) {
				if ( '' === $model && isset( $config['cascade_tier_1_model'] ) && is_string( $config['cascade_tier_1_model'] ) ) {
					$model = sanitize_text_field( $config['cascade_tier_1_model'] );
				}
				if ( '' === $provider && isset( $config['cascade_tier_1_provider'] ) && is_string( $config['cascade_tier_1_provider'] ) ) {
					$provider = sanitize_key( $config['cascade_tier_1_provider'] );
				}
			}
		}

		if ( '' === $model && '' === $provider ) {
			/**
			 * Filters the site-wide default tier-1 model.
			 *
			 * @since 1.1.97
			 *
			 * @param string $model Default tier-1 model identifier. Empty = no
			 *                      cascade by default.
			 */
			$model = sanitize_text_field( (string) apply_filters( 'wp_mcp_ai_cascade_default_tier_1_model', '' ) );
		}

		return array(
			'model'    => $model,
			'provider' => $provider,
		);
	}

	/**
	 * Resolve the validator confidence threshold (0..1).
	 *
	 * @param array $options Request options.
	 * @return float
	 */
	private static function resolve_threshold( $options ) {
		$raw = isset( $options['cascade_confidence_threshold'] ) ? $options['cascade_confidence_threshold'] : self::DEFAULT_CONFIDENCE_THRESHOLD;

		/**
		 * Filters the default cascade confidence threshold.
		 *
		 * @since 1.1.97
		 *
		 * @param float $threshold Threshold in 0..1. Default 0.85.
		 */
		$raw = apply_filters( 'wp_mcp_ai_cascade_confidence_threshold', $raw, $options );

		return max( 0.0, min( 1.0, (float) $raw ) );
	}

	/**
	 * Build tier-1 request options.
	 *
	 * A tier-1 model override wins when set; a tier-1 provider switch drops
	 * the primary model override so the tier-1 provider's default model is
	 * used. The bypass flag prevents the executor from re-entering itself.
	 *
	 * @param array $options      Original request options.
	 * @param array $tier1_config Resolved tier-1 configuration.
	 * @return array
	 */
	private static function build_tier1_options( $options, $tier1_config ) {
		$tier1_options = $options;

		if ( '' !== $tier1_config['model'] ) {
			$tier1_options['model'] = $tier1_config['model'];
		} elseif ( '' !== $tier1_config['provider'] ) {
			unset( $tier1_options['model'] );
		}

		if ( '' !== $tier1_config['provider'] ) {
			$tier1_options['provider'] = $tier1_config['provider'];
		}

		$tier1_options['wp_mcp_ai_cascade_bypass'] = true;

		return $tier1_options;
	}

	/**
	 * Deterministic default validator.
	 *
	 * Rejects error envelopes and empty content; otherwise accepts with a
	 * conservative default confidence. Semantic judges override this via the
	 * `wp_mcp_ai_cascade_validator` filter.
	 *
	 * @param mixed $result Cheap-tier provider result.
	 * @return array{acceptable: bool, confidence: float, reason: string}
	 */
	public static function default_verdict( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'acceptable' => false,
				'confidence' => 0.0,
				'reason'     => 'provider error',
			);
		}

		if ( is_array( $result ) && isset( $result['error'] ) ) {
			return array(
				'acceptable' => false,
				'confidence' => 0.0,
				'reason'     => 'error envelope',
			);
		}

		$content = self::extract_content( $result );

		if ( '' === $content ) {
			return array(
				'acceptable' => false,
				'confidence' => 0.0,
				'reason'     => 'empty content',
			);
		}

		return array(
			'acceptable' => true,
			'confidence' => 0.9,
			'reason'     => 'deterministic default',
		);
	}

	/**
	 * Extract the text content from a provider result.
	 *
	 * @param mixed $result Provider result.
	 * @return string
	 */
	private static function extract_content( $result ) {
		if ( is_array( $result ) && isset( $result['choices'][0]['message']['content'] ) ) {
			$content = $result['choices'][0]['message']['content'];
			return is_string( $content ) ? trim( $content ) : '';
		}

		if ( is_string( $result ) ) {
			return trim( $result );
		}

		return '';
	}

	/**
	 * Record a cascade decision for audit/cost-tracker subscribers.
	 *
	 * @param string $decision     accepted|escalated|tier1_error.
	 * @param array  $classification Classifier verdict.
	 * @param array  $tier1_config   Resolved tier-1 configuration.
	 * @return void
	 */
	private static function record_decision( $decision, $classification, $tier1_config ) {
		/**
		 * Fires when the legacy cascade makes a routing decision.
		 *
		 * @since 1.1.97
		 *
		 * @param array $outcome {
		 *     @type string $decision accepted|escalated|tier1_error.
		 *     @type string $reason   Classifier reason.
		 *     @type string $model    Tier-1 model (empty when provider-routed).
		 *     @type string $provider Tier-1 provider (empty when model-routed).
		 * }
		 */
		do_action(
			'wp_mcp_ai_cascade_decision',
			array(
				'decision' => $decision,
				'reason'   => isset( $classification['reason'] ) ? sanitize_text_field( (string) $classification['reason'] ) : '',
				'model'    => $tier1_config['model'],
				'provider' => $tier1_config['provider'],
			)
		);
	}

	/**
	 * Routing statistics.
	 *
	 * @return array
	 */
	public static function get_stats() {
		return self::$stats;
	}

	/**
	 * Reset routing statistics (test seam).
	 *
	 * @return void
	 */
	public static function reset_for_tests() {
		self::$stats = array(
			'requests'      => 0,
			'routed_simple' => 0,
			'accepted'      => 0,
			'escalated'     => 0,
			'tier1_errors'  => 0,
			'passthrough'   => 0,
		);
	}
}
