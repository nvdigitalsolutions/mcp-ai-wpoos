<?php
/**
 * Verification Cascade Service.
 *
 * Implements the TypeSafe "SDE cascade" pattern (extract → verify → escalate)
 * as a reusable base service: a structured record produced by a cheap/draft
 * model is verified against its source text with a battery of per-field Noul
 * questions (each framed "bad = true"), batched into one decision call per
 * chunk. Escalation to a stronger model happens exactly once, only when any
 * flag fires above the threshold — never on a verifier failure (fail-open).
 *
 * The decision model only ever supplies probabilities; every threshold and
 * the single-rung escalation bound live in this class's code.
 *
 * @package WP_MCP_AI
 * @since   2026.09
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify structured output against a source and escalate once when a
 * semantic flag fires.
 *
 * @since 2026.09
 */
class WP_MCP_AI_Verification_Cascade {

	/**
	 * Default probability threshold above which a noul question "fires"
	 * (escalate). Filterable via `wp_mcp_ai_cascade_escalate_threshold`.
	 *
	 * @var float
	 */
	const DEFAULT_ESCALATE_THRESHOLD = 0.7;

	/**
	 * Maximum questions per decision call. The TypeSafe client caps at 64;
	 * 50 leaves headroom for chunk edges and future client changes.
	 *
	 * @var int
	 */
	const MAX_QUESTIONS_PER_CALL = 50;

	/**
	 * Default verification metrics (each becomes one noul question per
	 * non-empty field). Filterable via `wp_mcp_ai_cascade_metrics`.
	 *
	 * @var string[]
	 */
	const DEFAULT_METRICS = array( 'hallucinated', 'off_target', 'incomplete', 'format_violation', 'unreasonable' );

	/**
	 * Decision client instance (lazy-resolved when null).
	 *
	 * @var Interface_WP_MCP_AI_Decision_Client|null
	 */
	private $client = null;

	/**
	 * Constructor.
	 *
	 * @param Interface_WP_MCP_AI_Decision_Client|null $client Optional injected
	 *        decision client. When null, the service lazily resolves the best
	 *        available transport (native TypeSafe, then the OpenRouter
	 *        decisions bridge) at first use.
	 */
	public function __construct( $client = null ) {
		if ( $client instanceof Interface_WP_MCP_AI_Decision_Client ) {
			$this->client = $client;
		}
	}

	/**
	 * Resolve the decision client to use.
	 *
	 * Prefers the native TypeSafe client when enabled and credentialed, then
	 * the OpenRouter decisions bridge (wrapped in an anonymous adapter that
	 * satisfies the decision-client contract).
	 *
	 * @return Interface_WP_MCP_AI_Decision_Client|WP_Error
	 */
	public function get_client() {
		if ( null !== $this->client ) {
			return $this->client;
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$resolver = class_exists( 'WP_MCP_AI_Credential_Resolver' );

		$typesafe_usable = ! empty( $settings['enable_typesafe'] )
			&& ( $resolver
				? WP_MCP_AI_Credential_Resolver::has_credentials( 'typesafe' )
				: ! empty( $settings['typesafe_api_key'] ) );

		if ( $typesafe_usable && class_exists( 'WP_MCP_AI_Typesafe_Client' ) ) {
			$this->client = new WP_MCP_AI_Typesafe_Client();

			return $this->client;
		}

		$openrouter_usable = $resolver && WP_MCP_AI_Credential_Resolver::has_credentials( 'openrouter' );

		if ( $openrouter_usable && class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
			$bridge       = new WP_MCP_AI_OpenRouter_Client();
			$this->client = new class( $bridge ) implements Interface_WP_MCP_AI_Decision_Client {
				/**
				 * Wrapped OpenRouter client.
				 *
				 * @var WP_MCP_AI_OpenRouter_Client
				 */
				private $inner;

				/**
				 * Constructor.
				 *
				 * @param WP_MCP_AI_OpenRouter_Client $inner OpenRouter client.
				 */
				public function __construct( $inner ) {
					$this->inner = $inner;
				}

				/**
				 * Delegate to the decisions bridge.
				 *
				 * @param mixed $state     Content to evaluate.
				 * @param array $questions Question map.
				 * @param array $options   Request options.
				 * @return array|WP_Error
				 */
				public function decide( $state, $questions, $options = array() ) {
					return $this->inner->create_decision( $state, $questions, $options );
				}

				/**
				 * Provider slug.
				 *
				 * @return string
				 */
				public function get_provider_slug() {
					return 'openrouter';
				}
			};

			return $this->client;
		}

		return new WP_Error(
			'wp_mcp_ai_cascade_unavailable',
			__( 'No decision client is configured for verification cascades.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Build the per-field Noul verification battery.
	 *
	 * Programmatically decomposes the record into one noul question per
	 * `field::metric` pair (the TypeSafe way: atomic questions, combined in
	 * code). Every question is framed so `true` = something is wrong
	 * (escalate), with explicit `criteria.true` / `criteria.false`
	 * descriptions. Empty fields get only the `absence_wrong` head.
	 *
	 * @param array  $record      Map of field name => extracted value.
	 * @param array  $field_specs Map of field name => spec
	 *                            (type, description, required).
	 * @param string $source_text Source text the record was extracted from.
	 * @param array  $metrics     Optional metric subset (defaults to all).
	 * @return array { state: array, questions: array } Battery ready for
	 *               {@see evaluate()}.
	 */
	public function build_battery( array $record, array $field_specs, $source_text, array $metrics = array() ) {
		$source_text = is_string( $source_text ) ? sanitize_text_field( $source_text ) : '';

		/**
		 * Filter the verification metrics used by the battery.
		 *
		 * @since 2026.09
		 *
		 * @param string[] $metrics Metric slugs (hallucinated, off_target,
		 *                          incomplete, format_violation,
		 *                          unreasonable).
		 */
		$metric_slugs = empty( $metrics ) ? apply_filters( 'wp_mcp_ai_cascade_metrics', self::DEFAULT_METRICS ) : $metrics;
		$metric_slugs = array_values( array_unique( array_map( 'sanitize_key', $metric_slugs ) ) );

		$questions = array();

		foreach ( $field_specs as $field => $raw_spec ) {
			$field = sanitize_key( (string) $field );
			if ( '' === $field ) {
				continue;
			}

			$spec  = $this->sanitize_field_spec( $raw_spec );
			$value = isset( $record[ $field ] ) ? $this->sanitize_value( $record[ $field ] ) : '';

			if ( $this->is_empty_value( $value ) ) {
				$questions[ $field . '__absence_wrong' ] = array(
					'type'         => 'noul',
					'instructions' => array(
						'field_spec'      => $spec,
						'extracted_field' => $value,
						'main_question'   => __( 'The extracted field is empty, null, or an empty collection. Does the source text contain the information the field spec describes, making the empty result wrong?', 'mcp-ai-wpoos' ),
					),
					'criteria'     => array(
						'true'  => __( 'a value was wrongly omitted', 'mcp-ai-wpoos' ),
						'false' => __( 'returning nothing is correct', 'mcp-ai-wpoos' ),
					),
				);
				continue;
			}

			foreach ( $metric_slugs as $metric ) {
				$definition = $this->get_metric_definition( $metric );
				if ( null === $definition ) {
					continue;
				}

				$questions[ $field . '__' . $metric ] = array(
					'type'         => 'noul',
					'instructions' => array(
						'field_spec'      => $spec,
						'extracted_field' => $value,
						'main_question'   => $definition['question'],
					),
					'criteria'     => array(
						'true'  => $definition['true'],
						'false' => $definition['false'],
					),
				);
			}
		}

		return array(
			'state'     => array( 'source_text' => $source_text ),
			'questions' => $questions,
		);
	}

	/**
	 * Evaluate a battery in one (chunked) batched decision call.
	 *
	 * Aggregates with `max`: any question probability above the threshold
	 * fires. One confident red flag escalates — it is never averaged into
	 * silence. Fail-open per chunk: a transport error skips the chunk and
	 * reports the error without firing anything.
	 *
	 * @param array $battery Battery from {@see build_battery()}.
	 * @param array $options Optional decision options (model, timeout).
	 * @return array { answers: array, fired: array, threshold: float,
	 *                error: string|null, used_jev: bool }.
	 */
	public function evaluate( array $battery, array $options = array() ) {
		$questions = isset( $battery['questions'] ) && is_array( $battery['questions'] ) ? $battery['questions'] : array();
		$state     = isset( $battery['state'] ) ? $battery['state'] : array();

		$result = array(
			'answers'   => array(),
			'fired'     => array(),
			'threshold' => $this->get_threshold(),
			'error'     => null,
			'used_jev'  => false,
		);

		if ( empty( $questions ) ) {
			return $result;
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			$result['error'] = $client->get_error_message();

			return $result;
		}

		foreach ( array_chunk( $questions, self::MAX_QUESTIONS_PER_CALL, true ) as $chunk ) {
			$decision = $client->decide( $state, $chunk, $options );

			if ( is_wp_error( $decision ) ) {
				// Fail-open: a verifier outage must never block the flow.
				if ( null === $result['error'] ) {
					$result['error'] = $decision->get_error_message();
				}
				continue;
			}

			$result['used_jev'] = true;
			$answers            = isset( $decision['answers'] ) && is_array( $decision['answers'] ) ? $decision['answers'] : array();

			foreach ( $chunk as $qid => $question ) {
				if ( ! isset( $answers[ $qid ] ) || ! is_array( $answers[ $qid ] ) || ! isset( $answers[ $qid ]['noul'] ) ) {
					continue;
				}

				$probability               = max( 0.0, min( 1.0, floatval( $answers[ $qid ]['noul'] ) ) );
				$result['answers'][ $qid ] = $probability;

				if ( $probability > $result['threshold'] ) {
					$result['fired'][ $qid ] = $probability;
				}
			}
		}

		return $result;
	}

	/**
	 * Run the full verify-then-escalate cascade.
	 *
	 * Builds the battery, evaluates it, and invokes the escalation callback
	 * exactly once — only when at least one flag fired. Verifier errors are
	 * fail-open: the draft result is accepted with no escalation.
	 *
	 * @param array    $record      Map of field name => extracted value.
	 * @param array    $field_specs Map of field name => spec.
	 * @param string   $source_text Source text the record came from.
	 * @param callable $escalate    Callback invoked as
	 *                              $escalate( $record, $fired ) once when a
	 *                              flag fires; its return value is stored in
	 *                              `escalated`.
	 * @param array    $options     Optional decision options (model, timeout).
	 * @return array { needs_escalation: bool, fired: array, answers: array,
	 *                escalated: mixed|null, error: string|null,
	 *                escalation_error: string|null, used_jev: bool }.
	 */
	public function run( array $record, array $field_specs, $source_text, $escalate, array $options = array() ) {
		$battery    = $this->build_battery( $record, $field_specs, $source_text );
		$evaluation = $this->evaluate( $battery, $options );

		$result = array(
			'needs_escalation' => false,
			'fired'            => $evaluation['fired'],
			'answers'          => $evaluation['answers'],
			'escalated'        => null,
			'error'            => $evaluation['error'],
			'escalation_error' => null,
			'used_jev'         => $evaluation['used_jev'],
		);

		if ( empty( $evaluation['fired'] ) || ! is_callable( $escalate ) ) {
			return $result;
		}

		$result['needs_escalation'] = true;

		try {
			$result['escalated'] = call_user_func( $escalate, $record, $evaluation['fired'] );
		} catch ( Throwable $throwable ) {
			// A crashing escalation callback is the same as an escalation
			// failure: keep the draft result.
			$result['escalation_error'] = $throwable->getMessage();
			$result['escalated']        = null;
		}

		return $result;
	}

	/**
	 * Resolve the escalation threshold.
	 *
	 * @return float Threshold in (0, 1].
	 */
	private function get_threshold() {
		/**
		 * Filter the verification-cascade escalation threshold.
		 *
		 * @since 2026.09
		 *
		 * @param float $threshold Probability above which a question fires.
		 */
		$threshold = apply_filters( 'wp_mcp_ai_cascade_escalate_threshold', self::DEFAULT_ESCALATE_THRESHOLD );

		return max( 0.05, min( 1.0, floatval( $threshold ) ) );
	}

	/**
	 * Metric question definitions (bad = true framing).
	 *
	 * @param string $metric Metric slug.
	 * @return array|null { question, true, false } or null when unknown.
	 */
	private function get_metric_definition( $metric ) {
		$definitions = array(
			'hallucinated'     => array(
				'question' => __( 'Is the extracted field unsupported by, or absent from, the source text?', 'mcp-ai-wpoos' ),
				'true'     => __( 'the extracted field is a hallucination — not supported by, or absent from, the source text', 'mcp-ai-wpoos' ),
				'false'    => __( 'the extracted field is supported by the source text', 'mcp-ai-wpoos' ),
			),
			'off_target'       => array(
				'question' => __( 'Does the source text fail to genuinely report the thing the field spec describes, so the value was pulled from incidental text?', 'mcp-ai-wpoos' ),
				'true'     => __( 'the source does not genuinely provide this field — the value was pulled from incidental text', 'mcp-ai-wpoos' ),
				'false'    => __( 'the source genuinely reports this field', 'mcp-ai-wpoos' ),
			),
			'incomplete'       => array(
				'question' => __( 'Does the extracted field fail to capture a value the source supports (noting whether the field is required)?', 'mcp-ai-wpoos' ),
				'true'     => __( 'the field is wrongly empty, null, or missing a value the source supports', 'mcp-ai-wpoos' ),
				'false'    => __( 'the field captures the value the source supports', 'mcp-ai-wpoos' ),
			),
			'format_violation' => array(
				'question' => __( 'Does the extracted field violate the format or constraints implied by the field description?', 'mcp-ai-wpoos' ),
				'true'     => __( 'the extracted field violates the implied format or constraints', 'mcp-ai-wpoos' ),
				'false'    => __( 'the extracted field satisfies the format and constraints', 'mcp-ai-wpoos' ),
			),
			'unreasonable'     => array(
				'question' => __( 'Is the extracted field one that a reasonable person would not have extracted for this field spec?', 'mcp-ai-wpoos' ),
				'true'     => __( 'a reasonable person would not have extracted this value', 'mcp-ai-wpoos' ),
				'false'    => __( 'the extraction is reasonable', 'mcp-ai-wpoos' ),
			),
		);

		return isset( $definitions[ $metric ] ) ? $definitions[ $metric ] : null;
	}

	/**
	 * Sanitise a field spec (two-gate rule, gate one).
	 *
	 * @param mixed $spec Raw field spec.
	 * @return array Sanitised spec (path, type, description, required).
	 */
	private function sanitize_field_spec( $spec ) {
		if ( ! is_array( $spec ) ) {
			$spec = array();
		}

		return array(
			'type'        => isset( $spec['type'] ) ? sanitize_key( (string) $spec['type'] ) : 'string',
			'description' => isset( $spec['description'] ) ? sanitize_text_field( (string) $spec['description'] ) : '',
			'required'    => ! empty( $spec['required'] ),
		);
	}

	/**
	 * Recursive sanitisation walk for extracted values.
	 *
	 * @param mixed $value Extracted value.
	 * @return mixed Sanitised value.
	 */
	private function sanitize_value( $value ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$key               = is_string( $key ) ? sanitize_key( $key ) : $key;
				$sanitized[ $key ] = $this->sanitize_value( $item );
			}

			return $sanitized;
		}

		return $value;
	}

	/**
	 * Whether an extracted value counts as empty for the absence head.
	 *
	 * @param mixed $value Extracted value.
	 * @return bool
	 */
	private function is_empty_value( $value ) {
		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			return true;
		}

		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}

		return false;
	}
}
