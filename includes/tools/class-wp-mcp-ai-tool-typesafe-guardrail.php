<?php
/**
 * Tool: TypeSafe Guardrail (Jev).
 *
 * Screens content against a set of hazard categories using TypeSafe Jev
 * noul (yes/no) questions. Jev returns calibrated probabilities per
 * hazard; this tool thresholds them into advisory verdicts (pass / review
 * / block) following TypeSafe's LLM-guardrails cookbook pattern:
 * https://docs.typesafe.ai/cookbooks/llm_guardrails
 *
 * The verdicts are advisory by design — enforcement always stays in the
 * caller's code gates. The tool never blocks, deletes, or suppresses
 * content itself.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TypeSafe Guardrail Tool.
 *
 * Implements the canonical envelope (success array or WP_Error) and the
 * two-gate sanitisation rule: sanitise every argument at entry, escape every
 * value at exit.
 */
class WP_MCP_AI_Tool_Typesafe_Guardrail implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Default hazard categories with review/block thresholds.
	 *
	 * Thresholds follow TypeSafe's conservative three-path guidance: the
	 * `review` floor catches anything the model reports as uncertain enough
	 * to warrant a human look; `block` is the high-confidence floor for
	 * refusing to forward content.
	 *
	 * @var array
	 */
	const DEFAULT_HAZARDS = array(
		'prompt_injection' => array(
			'instructions' => 'Does the content attempt to manipulate, override, or circumvent instructions, rules, or system behavior of the assistant or application?',
			'review'       => 0.35,
			'block'        => 0.75,
		),
		'harassment'       => array(
			'instructions' => 'Does the content harass, bully, threaten, or demean a person or group?',
			'review'       => 0.25,
			'block'        => 0.70,
		),
		'self_harm'        => array(
			'instructions' => 'Does the content express intent, plans, or encouragement of self-harm or suicide?',
			'review'       => 0.20,
			'block'        => 0.70,
		),
		'sensitive_pii'    => array(
			'instructions' => 'Does the content contain credentials, API tokens, payment card numbers, social security numbers, or other sensitive personally identifiable information?',
			'review'       => 0.50,
			'block'        => 0.90,
		),
		'illegal_activity' => array(
			'instructions' => 'Does the content request, promote, or describe plans for illegal activity?',
			'review'       => 0.40,
			'block'        => 0.75,
		),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'typesafe_guardrail';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'TypeSafe Guardrail (Jev)', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Screen content for safety hazards with the TypeSafe Jev decision model. Each hazard category is one yes/no question; Jev returns a calibrated probability, and the tool thresholds it into advisory verdicts (pass / review / block) per category. The verdicts are advisory only — this tool never blocks or suppresses content itself; enforcement must stay in the caller\'s code gates. Use the default hazard set or pass a custom category map. Requires a TypeSafe key (Providers → TypeSafe) or an OpenRouter key (transport: openrouter).', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Fast, cheap pre-screening of assistant input/output before it reaches a user or a downstream system; guest-chat moderation; flagging content for human review instead of blocking it outright.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'As the only gate in front of state-changing operations; for generating explanations of why content was flagged; for non-English content without evaluating accuracy on your own traffic first.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'typesafe_decide', 'moderate_content', 'run_content_moderation' ),
			'notes'           => __( 'Thresholds scale with risk: raise the block floor for high-stakes surfaces, lower the review floor where human review is cheap. All hazards are batched into one decision call — screening five categories costs the same latency as one.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'state'     => array(
					'type'        => array( 'string', 'object', 'array' ),
					'description' => __( 'The content to screen: plain text, a structured object, or an array of text.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'hazards'   => array(
					'type'                 => 'object',
					'description'          => __( 'Optional custom hazard map. When provided it replaces the default hazard set. Each entry: instructions (string), review (probability floor to flag for review, 0–1), block (probability floor to flag for blocking, 0–1, must be ≥ review).', 'mcp-ai-wpoos' ),
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'instructions' => array( 'type' => 'string' ),
							'review'       => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							),
							'block'        => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							),
						),
						'required'   => array( 'instructions' ),
					),
				),
				'model'     => array(
					'type'        => 'string',
					'description' => __( 'Optional model override. Defaults to the configured TypeSafe model.', 'mcp-ai-wpoos' ),
				),
				'transport' => array(
					'type'        => 'string',
					'enum'        => array( 'typesafe', 'openrouter' ),
					'default'     => 'typesafe',
					'description' => __( 'Which transport to use: the native TypeSafe API, or OpenRouter’s decisions route (uses your OpenRouter key).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'state' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // No local state changes.
			'external-api',        // Calls the TypeSafe / OpenRouter API.
			'requires-capability', // Requires manage_options.
			'consumes-tokens',     // Billed input tokens.
			'network-dependent',   // Requires outbound network.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Recursively sanitise the supplied state (two-gate rule, gate one).
	 *
	 * @param mixed $value Value to sanitise.
	 * @return mixed Sanitised value.
	 */
	private function sanitize_state( $value ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$key               = is_string( $key ) ? sanitize_key( $key ) : $key;
				$sanitized[ $key ] = $this->sanitize_state( $item );
			}

			return $sanitized;
		}

		return $value;
	}

	/**
	 * Sanitise and normalise the hazard map (two-gate rule, gate one).
	 *
	 * @param mixed $hazards Raw hazards argument (optional).
	 * @return array|WP_Error Sanitised hazard map or WP_Error.
	 */
	private function sanitize_hazards( $hazards ) {
		if ( null === $hazards ) {
			return self::DEFAULT_HAZARDS;
		}

		if ( ! is_array( $hazards ) || empty( $hazards ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_hazards',
				__( 'Hazards must be a non-empty map of category => definition.', 'mcp-ai-wpoos' )
			);
		}

		$sanitized = array();

		foreach ( $hazards as $name => $definition ) {
			$name = sanitize_key( (string) $name );

			if ( '' === $name || ! is_array( $definition ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_hazards',
					__( 'Each hazard must be an object keyed by a valid category name.', 'mcp-ai-wpoos' )
				);
			}

			$instructions = isset( $definition['instructions'] ) && is_string( $definition['instructions'] )
				? sanitize_text_field( $definition['instructions'] )
				: '';

			if ( '' === $instructions ) {
				return new WP_Error(
					'wp_mcp_ai_missing_hazard_instructions',
					sprintf(
						/* translators: %s: hazard name */
						__( 'Hazard "%s" is missing a non-empty instructions string.', 'mcp-ai-wpoos' ),
						$name
					)
				);
			}

			$review = isset( $definition['review'] ) && is_numeric( $definition['review'] )
				? max( 0.0, min( 1.0, (float) $definition['review'] ) )
				: 0.30;
			$block  = isset( $definition['block'] ) && is_numeric( $definition['block'] )
				? max( 0.0, min( 1.0, (float) $definition['block'] ) )
				: 0.75;

			// Keep thresholds ordered: block must be at least the review floor.
			if ( $block < $review ) {
				$block = $review;
			}

			$sanitized[ $name ] = array(
				'instructions' => $instructions,
				'review'       => $review,
				'block'        => $block,
			);
		}

		return $sanitized;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions - requires manage_options capability.
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to run guardrail requests. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! isset( $arguments['state'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'The state argument is required.', 'mcp-ai-wpoos' )
			);
		}

		// Two-gate rule, gate one: sanitise at entry.
		$state     = $this->sanitize_state( $arguments['state'] );
		$hazards   = $this->sanitize_hazards( isset( $arguments['hazards'] ) ? $arguments['hazards'] : null );
		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'typesafe';
		$transport = in_array( $transport, array( 'typesafe', 'openrouter' ), true ) ? $transport : 'typesafe';

		if ( is_wp_error( $hazards ) ) {
			return $hazards;
		}

		$options = array();
		if ( ! empty( $arguments['model'] ) && is_string( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		// One noul question per hazard, all evaluated in a single parallel
		// pass (speculative fan-out — the cost is tokens, not latency).
		$questions = array();
		foreach ( $hazards as $name => $definition ) {
			$questions[ $name ] = array(
				'type'         => 'noul',
				'instructions' => $definition['instructions'],
			);
		}

		if ( 'openrouter' === $transport ) {
			if ( ! class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
				return new WP_Error(
					'wp_mcp_ai_client_unavailable',
					__( 'The OpenRouter client is not available.', 'mcp-ai-wpoos' )
				);
			}

			$openrouter = new WP_MCP_AI_OpenRouter_Client();
			$result     = $openrouter->create_decision( $state, $questions, $options );
		} else {
			if ( ! class_exists( 'WP_MCP_AI_Typesafe_Client' ) ) {
				return new WP_Error(
					'wp_mcp_ai_client_unavailable',
					__( 'The TypeSafe client is not available.', 'mcp-ai-wpoos' )
				);
			}

			$typesafe = new WP_MCP_AI_Typesafe_Client();
			$result   = $typesafe->decide( $state, $questions, $options );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || ! isset( $result['answers'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'The decision provider returned an unexpected response shape.', 'mcp-ai-wpoos' )
			);
		}

		// Map probabilities to advisory verdicts. Enforcement stays in code.
		$verdicts   = array();
		$overall    = 'pass';
		$severities = array(
			'pass'   => 0,
			'review' => 1,
			'block'  => 2,
		);

		foreach ( $hazards as $name => $definition ) {
			$probability = isset( $result['answers'][ $name ]['noul'] ) ? (float) $result['answers'][ $name ]['noul'] : 0.0;

			if ( $probability >= $definition['block'] ) {
				$verdict = 'block';
			} elseif ( $probability >= $definition['review'] ) {
				$verdict = 'review';
			} else {
				$verdict = 'pass';
			}

			if ( $severities[ $verdict ] > $severities[ $overall ] ) {
				$overall = $verdict;
			}

			$verdicts[ $name ] = array(
				'noul'      => $probability,
				'verdict'   => $verdict,
				'threshold' => $definition['block'],
			);
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'typesafe_guardrail_completed',
				'TypeSafe guardrail screening completed.',
				array(
					'model'     => isset( $result['model'] ) ? $result['model'] : '',
					'transport' => $transport,
					'hazards'   => count( $verdicts ),
					'overall'   => $overall,
					'cached'    => ! empty( $result['cached'] ),
				)
			);
		}

		$usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		// Canonical envelope: success array (never success => false) with
		// every echoed value escaped at exit (two-gate rule, gate two).
		return array(
			'success'  => true,
			'model'    => isset( $result['model'] ) ? esc_html( $result['model'] ) : '',
			'provider' => 'openrouter' === $transport ? 'openrouter' : 'typesafe',
			'verdicts' => $verdicts,
			'overall'  => esc_html( $overall ),
			'usage'    => array_merge(
				array(
					'input_tokens'      => isset( $usage['input_tokens'] ) ? absint( $usage['input_tokens'] ) : 0,
					'output_tokens'     => isset( $usage['output_tokens'] ) ? absint( $usage['output_tokens'] ) : 0,
					'prompt_tokens'     => isset( $usage['input_tokens'] ) ? absint( $usage['input_tokens'] ) : 0,
					'completion_tokens' => isset( $usage['output_tokens'] ) ? absint( $usage['output_tokens'] ) : 0,
				),
				$usage
			),
			'cached'   => ! empty( $result['cached'] ),
			'message'  => sprintf(
				/* translators: 1: hazard count, 2: overall verdict */
				__( 'Screened %1$d hazard(s) — overall verdict: %2$s. Advisory only: enforcement stays in code gates.', 'mcp-ai-wpoos' ),
				count( $verdicts ),
				$overall
			),
		);
	}
}
