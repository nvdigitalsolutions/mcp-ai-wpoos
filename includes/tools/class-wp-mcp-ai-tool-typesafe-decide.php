<?php
/**
 * Tool: TypeSafe Decide (Jev).
 *
 * Exposes TypeSafe's Jev "System One" decision model to assistants. Jev does
 * not generate prose — it evaluates supplied state against typed questions
 * (choice | score | noul) and returns constrained, probabilistic decisions.
 *
 * Two transports are supported:
 *  - `typesafe`   (default): native POST https://api.typesafe.ai/v1/systemone
 *  - `openrouter`: OpenRouter's decisions route (alpha) for sites with an
 *    OpenRouter key but no TypeSafe early-access key.
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
 * TypeSafe Decide Tool.
 *
 * Implements the canonical envelope (success array or WP_Error) and the
 * two-gate sanitisation rule: sanitise every argument at entry, escape every
 * value at exit.
 */
class WP_MCP_AI_Tool_Typesafe_Decide implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'typesafe_decide';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'TypeSafe Decide (Jev)', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Ask the TypeSafe Jev decision model to answer typed questions (choice, score, or yes/no) about supplied text or structured state. Jev returns constrained, probabilistic decisions with confidence scores — it never generates prose. Use it for routing, triage, classification, and relevance judgments where a fast, cheap, typed answer matters more than generated text. NOTE: Jev only judges the state you send it; it cannot look anything up, and state containing user-controlled content should be treated as untrusted input. Consequential actions must stay gated in code.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Classifying or routing content against a fixed set of options, scoring against a rubric, or answering yes/no judgments where calibrated probabilities and confidence are useful.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Generating text, summaries, or code; arithmetic, counting, or date ordering (do those in code); any decision that must carry a written rationale.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'suggest_best_model', 'moderate_content', 'research_model' ),
			'notes'           => __( 'Requires a TypeSafe API key (Providers → TypeSafe) or an OpenRouter key (transport=openrouter). Input-only billing: $0.042 per million input tokens, output free. Batch several questions in one call — parallel questions cost almost no extra latency.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'The content to evaluate: plain text, a structured object, or an array of text. Text only — preprocess images, audio, or video before passing them as state.', 'mcp-ai-wpoos' ),
				),
				'questions' => array(
					'type'                 => 'object',
					'description'          => __( 'Map of named questions to evaluate in parallel against the state. Each question has a type (choice, score, or noul), an instructions string, and criteria (choice: map of option => description, up to 255 options; score: ordered list of 2 to 10 level descriptions; noul: omit criteria).', 'mcp-ai-wpoos' ),
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'type'         => array(
								'type' => 'string',
								'enum' => array( 'choice', 'score', 'noul' ),
							),
							'instructions' => array(
								'type' => 'string',
							),
							'criteria'     => array(
								'type' => array( 'object', 'array' ),
							),
						),
						'required'   => array( 'type', 'instructions' ),
					),
				),
				'model'     => array(
					'type'        => 'string',
					'description' => __( 'Optional model override. Defaults to the configured TypeSafe model (jev-latest). Pin a version such as jev-1.13.0 for reproducible thresholds.', 'mcp-ai-wpoos' ),
				),
				'transport' => array(
					'type'        => 'string',
					'enum'        => array( 'typesafe', 'openrouter' ),
					'default'     => 'typesafe',
					'description' => __( 'Which transport to use: the native TypeSafe API, or OpenRouter’s decisions route (uses your OpenRouter key).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'state', 'questions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // No local state changes.
			'external-api',         // Calls the TypeSafe / OpenRouter API.
			'requires-capability',  // Requires manage_options.
			'consumes-tokens',      // Billed input tokens.
			'network-dependent',    // Requires outbound network.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Recursively sanitise the supplied state (two-gate rule, gate one).
	 *
	 * Strings are sanitised with sanitize_text_field, arrays are walked, and
	 * scalar values are passed through unchanged. Keys are sanitised with
	 * sanitize_key.
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
	 * Sanitise the question map (two-gate rule, gate one).
	 *
	 * @param mixed $questions Raw questions argument.
	 * @return array|WP_Error Sanitised question map or WP_Error.
	 */
	private function sanitize_questions( $questions ) {
		if ( ! is_array( $questions ) || empty( $questions ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'At least one question is required.', 'mcp-ai-wpoos' )
			);
		}

		$sanitized = array();

		foreach ( $questions as $name => $question ) {
			$name = sanitize_key( (string) $name );

			if ( '' === $name || ! is_array( $question ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_question',
					__( 'Each question must be an object keyed by a valid question name.', 'mcp-ai-wpoos' )
				);
			}

			$type = isset( $question['type'] ) ? sanitize_key( $question['type'] ) : '';

			if ( ! in_array( $type, array( 'choice', 'score', 'noul' ), true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_question_type',
					__( 'Each question requires a type of choice, score, or noul.', 'mcp-ai-wpoos' )
				);
			}

			$instructions = isset( $question['instructions'] ) && is_string( $question['instructions'] )
				? sanitize_text_field( $question['instructions'] )
				: '';

			if ( '' === $instructions ) {
				return new WP_Error(
					'wp_mcp_ai_missing_instructions',
					sprintf(
						/* translators: %s: question name */
						__( 'Question "%s" is missing a non-empty instructions string.', 'mcp-ai-wpoos' ),
						$name
					)
				);
			}

			$entry = array(
				'type'         => $type,
				'instructions' => $instructions,
			);

			if ( 'noul' !== $type && isset( $question['criteria'] ) && ( is_array( $question['criteria'] ) || is_object( $question['criteria'] ) ) ) {
				$entry['criteria'] = $this->sanitize_state( $question['criteria'] );
			}

			$sanitized[ $name ] = $entry;
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
				__( 'You do not have permission to run decision requests. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! isset( $arguments['state'] ) || ! isset( $arguments['questions'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'Both state and questions are required.', 'mcp-ai-wpoos' )
			);
		}

		// Two-gate rule, gate one: sanitise at entry.
		$state     = $this->sanitize_state( $arguments['state'] );
		$questions = $this->sanitize_questions( $arguments['questions'] );

		if ( is_wp_error( $questions ) ) {
			return $questions;
		}

		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'typesafe';
		$transport = in_array( $transport, array( 'typesafe', 'openrouter' ), true ) ? $transport : 'typesafe';

		$options = array();
		if ( ! empty( $arguments['model'] ) && is_string( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
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

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'typesafe_decide_completed',
				'TypeSafe decision request completed.',
				array(
					'model'     => isset( $result['model'] ) ? $result['model'] : '',
					'transport' => $transport,
					'answers'   => count( $result['answers'] ),
				)
			);
		}

		// Canonical envelope: success array (never success => false) with
		// every echoed value escaped at exit (two-gate rule, gate two).
		return array(
			'success'  => true,
			'model'    => isset( $result['model'] ) ? esc_html( $result['model'] ) : '',
			'provider' => 'openrouter' === $transport ? 'openrouter' : 'typesafe',
			'answers'  => $result['answers'],
			'usage'    => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			),
			'message'  => sprintf(
				/* translators: 1: model id, 2: answer count */
				__( 'Model %1$s answered %2$d question(s).', 'mcp-ai-wpoos' ),
				isset( $result['model'] ) ? $result['model'] : __( 'unknown', 'mcp-ai-wpoos' ),
				count( $result['answers'] )
			),
		);
	}
}
