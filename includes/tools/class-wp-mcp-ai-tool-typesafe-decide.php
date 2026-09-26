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
				'state'          => array(
					'type'        => array( 'string', 'object', 'array' ),
					'description' => __( 'The content to evaluate: plain text, a structured object, or an array of text. Text only — preprocess images, audio, or video before passing them as state.', 'mcp-ai-wpoos' ),
					// When state is supplied as an array, it is a list of text chunks.
					'items'       => array( 'type' => 'string' ),
				),
				'questions'      => array(
					'type'                 => 'object',
					'description'          => __( 'Map of named questions to evaluate in parallel against the state. Each question has a type (choice, score, or noul), an instructions string (or structured object per the TypeSafe EntryType), and criteria (choice: map of option => description, up to 255 options; score: ordered list of 2 to 10 level descriptions; noul: optional map of true/false boundary descriptions).', 'mcp-ai-wpoos' ),
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'type'         => array(
								'type' => 'string',
								'enum' => array( 'choice', 'score', 'noul' ),
							),
							'instructions' => array(
								'type'  => array( 'string', 'object', 'array' ),
								// Array form: a list of text chunks or structured EntryType objects.
								'items' => array( 'type' => array( 'string', 'object' ) ),
							),
							'criteria'     => array(
								'type'  => array( 'object', 'array' ),
								// Score questions use an ordered array of level descriptions
								// (strings or structured objects).
								'items' => array( 'type' => array( 'string', 'object' ) ),
							),
						),
						'required'   => array( 'type', 'instructions' ),
					),
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Optional model override. Defaults to the configured TypeSafe model (jev-latest). Pin a version such as jev-1.13.0 for reproducible thresholds.', 'mcp-ai-wpoos' ),
				),
				'transport'      => array(
					'type'        => 'string',
					'enum'        => array( 'typesafe', 'openrouter' ),
					'default'     => 'typesafe',
					'description' => __( 'Which transport to use: the native TypeSafe API, or OpenRouter’s decisions route (uses your OpenRouter key).', 'mcp-ai-wpoos' ),
				),
				'min_confidence' => array(
					'type'                 => 'object',
					'description'          => __( 'Optional per-question confidence floors (0–1). Answers below their floor are still returned, flagged below_threshold: true — the values stay visible so the caller can implement the three-path pattern (act / confirm / human). Noul answers carry no confidence field; gate them on the probability itself.', 'mcp-ai-wpoos' ),
					'additionalProperties' => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 1,
					),
				),
				'weights'        => array(
					'type'                 => 'object',
					'description'          => __( 'Optional composite-scoring weights for score questions: map of question name => positive weight. When provided, the tool computes a weighted-average composite of the named score answers locally (no extra API call) and returns it as composite. TypeSafe pattern: keep atomic scores, combine them in code.', 'mcp-ai-wpoos' ),
					'additionalProperties' => array(
						'type'             => 'number',
						'exclusiveMinimum' => 0,
					),
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
		return 'manage_options';
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

			$instructions = isset( $question['instructions'] ) ? $question['instructions'] : '';

			if ( is_string( $instructions ) ) {
				$instructions = sanitize_text_field( $instructions );
			} elseif ( is_array( $instructions ) ) {
				// Structured instructions (TypeSafe EntryType) — walk and
				// sanitise recursively; plain JSON only.
				$instructions = $this->sanitize_state( $instructions );
			} else {
				$instructions = '';
			}

			if ( '' === $instructions ) {
				return new WP_Error(
					'wp_mcp_ai_missing_instructions',
					sprintf(
						/* translators: %s: question name */
						__( 'Question "%s" is missing a non-empty instructions string or structure.', 'mcp-ai-wpoos' ),
						$name
					)
				);
			}

			$entry = array(
				'type'         => $type,
				'instructions' => $instructions,
			);

			// Noul criteria (true/false descriptions) are optional but must
			// survive when supplied (TypeSafe Advanced: structure).
			if ( isset( $question['criteria'] ) && ( is_array( $question['criteria'] ) || is_object( $question['criteria'] ) ) ) {
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

		// Per-question confidence floors (0–1), clamped and restricted to
		// questions that exist in the request.
		$min_confidence = array();
		if ( isset( $arguments['min_confidence'] ) && is_array( $arguments['min_confidence'] ) ) {
			foreach ( $arguments['min_confidence'] as $name => $floor ) {
				$name = sanitize_key( (string) $name );
				if ( '' === $name || ! isset( $questions[ $name ] ) || ! is_numeric( $floor ) ) {
					continue;
				}
				$min_confidence[ $name ] = max( 0.0, min( 1.0, (float) $floor ) );
			}
		}

		// Composite-scoring weights (positive floats) for score questions.
		$weights = array();
		if ( isset( $arguments['weights'] ) && is_array( $arguments['weights'] ) ) {
			foreach ( $arguments['weights'] as $name => $weight ) {
				$name = sanitize_key( (string) $name );
				if ( '' === $name || ! isset( $questions[ $name ] ) || ! is_numeric( $weight ) || (float) $weight <= 0 ) {
					continue;
				}
				$weights[ $name ] = (float) $weight;
			}
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

		$answers = $result['answers'];

		// Flag answers under their confidence floor (three-path pattern:
		// act / confirm / human). Values stay visible — nothing is dropped.
		if ( ! empty( $min_confidence ) && is_array( $answers ) ) {
			foreach ( $answers as $name => $answer ) {
				if ( isset( $min_confidence[ $name ], $answer['confidence'] ) && is_numeric( $answer['confidence'] ) ) {
					$answers[ $name ]['below_threshold'] = ( (float) $answer['confidence'] < $min_confidence[ $name ] );
				}
			}
		}

		// Composite scoring: weighted average of the named score answers,
		// computed locally (TypeSafe pattern: combine atomic scores in code).
		$composite = null;
		if ( ! empty( $weights ) ) {
			$weighted_sum = 0.0;
			$weight_total = 0.0;
			foreach ( $weights as $name => $weight ) {
				if ( isset( $answers[ $name ]['score'] ) && is_numeric( $answers[ $name ]['score'] ) ) {
					$weighted_sum += $weight * (float) $answers[ $name ]['score'];
					$weight_total += $weight;
				}
			}
			if ( $weight_total > 0 ) {
				$composite = $weighted_sum / $weight_total;
			}
		}

		// Advisory pre-flight warning: input-only billing means cost scales
		// with state size; warn when the estimate crosses the threshold.
		$warnings       = array();
		$estimated_toks = $this->estimate_input_tokens( $state, $questions );
		$warn_tokens    = apply_filters( 'wp_mcp_ai_typesafe_warn_tokens', 24000 );
		$warn_tokens    = max( 1, absint( $warn_tokens ) );

		if ( $estimated_toks > $warn_tokens ) {
			$warnings[] = sprintf(
				/* translators: 1: estimated tokens, 2: threshold */
				__( 'Estimated input of ~%1$d tokens exceeds the advisory threshold of %2$d tokens. Billing is input-only — trim state to the fields the questions need.', 'mcp-ai-wpoos' ),
				$estimated_toks,
				$warn_tokens
			);
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'typesafe_decide_completed',
				'TypeSafe decision request completed.',
				array(
					'model'     => isset( $result['model'] ) ? $result['model'] : '',
					'transport' => $transport,
					'answers'   => count( $answers ),
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
		// prompt_tokens/completion_tokens aliases keep the usage tracker's
		// result-envelope contract flowing for decision calls.
		$envelope = array(
			'success'  => true,
			'model'    => isset( $result['model'] ) ? esc_html( $result['model'] ) : '',
			'provider' => 'openrouter' === $transport ? 'openrouter' : 'typesafe',
			'answers'  => $answers,
			'usage'    => array_merge(
				array(
					'input_tokens'      => 0,
					'output_tokens'     => 0,
					'prompt_tokens'     => isset( $usage['input_tokens'] ) ? absint( $usage['input_tokens'] ) : 0,
					'completion_tokens' => isset( $usage['output_tokens'] ) ? absint( $usage['output_tokens'] ) : 0,
				),
				$usage
			),
			'cached'   => ! empty( $result['cached'] ),
			'message'  => sprintf(
				/* translators: 1: model id, 2: answer count */
				__( 'Model %1$s answered %2$d question(s).', 'mcp-ai-wpoos' ),
				isset( $result['model'] ) ? $result['model'] : __( 'unknown', 'mcp-ai-wpoos' ),
				count( $answers )
			),
		);

		if ( null !== $composite ) {
			$envelope['composite'] = $composite;
		}

		if ( ! empty( $warnings ) ) {
			$envelope['warnings'] = $warnings;
		}

		return $envelope;
	}

	/**
	 * Estimate the input token count for a decision request.
	 *
	 * Rough heuristic (~4 chars per token) over the serialized state and
	 * questions. Advisory only — the provider's count is authoritative.
	 *
	 * @param mixed $state     Sanitised state.
	 * @param array $questions Sanitised question map.
	 * @return int Estimated token count.
	 */
	private function estimate_input_tokens( $state, array $questions ) {
		$json = wp_json_encode(
			array(
				'state'     => $state,
				'questions' => $questions,
			)
		);

		if ( false === $json ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Benign fallback: used only to estimate input token count (strlen/4); never stored, transmitted, or unserialized.
			$json = serialize(
				array(
					'state'     => $state,
					'questions' => $questions,
				)
			);
		}

		return (int) ceil( strlen( $json ) / 4 );
	}
}
