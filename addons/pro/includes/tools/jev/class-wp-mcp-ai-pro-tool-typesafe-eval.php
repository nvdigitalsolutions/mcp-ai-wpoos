<?php
/**
 * Pro tool: TypeSafe Eval (Jev).
 *
 * Calibration harness for the TypeSafe Jev decision provider: run labeled
 * examples through Jev and report overall accuracy, per-confidence-bucket
 * accuracy, and per-example grading. Implements the TypeSafe
 * self-consistency cookbook pattern so sites can tune confidence thresholds
 * on their own traffic. Nothing is trained or stored — examples are
 * supplied inline and the call is report-only.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TypeSafe Eval Pro Tool.
 */
class WP_MCP_AI_Pro_Tool_Typesafe_Eval implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'typesafe_eval';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'TypeSafe Eval (Jev)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Evaluate labeled examples against the TypeSafe Jev decision model and report calibration: overall accuracy, accuracy per confidence bucket, and per-example grading. Use it to tune confidence thresholds and rubric wording on your own traffic before relying on them. Examples are supplied inline (state, questions, expected) — nothing is stored or trained. Report-only.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before trusting Jev thresholds on a new question set; comparing rubric wording variants; measuring whether high-confidence answers are genuinely more accurate on your content.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Fine-tuning or training (Jev is not fine-tunable — it returns report-only results); evaluating more than 25 examples per call (chunk them); evaluating content with no ground-truth labels.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'typesafe_decide', 'typesafe_rerank', 'self_consistency_vote' ),
			'notes'           => __( 'Expected values: choice → the option string; score → the rubric position (tolerance 0.5); noul → 0/1 or true/false. Vendor benchmarks are self-run — this harness is how you verify on your own traffic.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'examples' => array(
					'type'        => 'array',
					'description' => __( 'Labeled examples (max 25). Each: { state, questions, expected } where expected maps question name => expected value.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'state'     => array( 'type' => array( 'string', 'object', 'array' ) ),
							'questions' => array( 'type' => 'object' ),
							'expected'  => array( 'type' => 'object' ),
						),
					),
				),
				'model'     => array(
					'type'        => 'string',
					'description' => __( 'Optional model override.', 'mcp-ai-wpoos-pro' ),
				),
				'transport' => array(
					'type'        => 'string',
					'enum'        => array( 'typesafe', 'openrouter' ),
					'default'     => 'typesafe',
					'description' => __( 'Which transport to use.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'examples' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'external-api',
			'requires-capability',
			'consumes-tokens',
			'network-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Recursively sanitise a value (two-gate rule, gate one).
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to run evaluation requests. This tool requires administrator privileges.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['examples'] ) || ! is_array( $arguments['examples'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'At least one labeled example is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Eval' ) ) {
			$eval_file = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-eval.php';
			if ( file_exists( $eval_file ) ) {
				require_once $eval_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Eval' ) ) {
			return new WP_Error(
				'wp_mcp_ai_client_unavailable',
				__( 'The Jev evaluation service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$options = array();
		if ( ! empty( $arguments['model'] ) && is_string( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}
		if ( ! empty( $arguments['transport'] ) ) {
			$transport = sanitize_key( $arguments['transport'] );
			if ( in_array( $transport, array( 'typesafe', 'openrouter' ), true ) ) {
				$options['transport'] = $transport;
			}
		}

		// Two-gate rule, gate one: sanitise every example at entry.
		$examples = array();
		foreach ( array_slice( $arguments['examples'], 0, WP_MCP_AI_Pro_Jev_Eval::MAX_EXAMPLES ) as $example ) {
			if ( ! is_array( $example ) ) {
				continue;
			}

			$sanitized = array();
			foreach ( array( 'state', 'questions', 'expected' ) as $key ) {
				if ( isset( $example[ $key ] ) ) {
					$sanitized[ $key ] = $this->sanitize_state( $example[ $key ] );
				}
			}

			if ( isset( $sanitized['state'], $sanitized['questions'], $sanitized['expected'] ) ) {
				$examples[] = $sanitized;
			}
		}

		$report = WP_MCP_AI_Pro_Jev_Eval::evaluate( $examples, $options );

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'typesafe_eval_completed',
				'TypeSafe evaluation completed.',
				array(
					'total'    => $report['total'],
					'correct'  => $report['correct'],
					'accuracy' => $report['accuracy'],
				)
			);
		}

		return array(
			'success'  => true,
			'provider' => 'typesafe',
			'report'   => $report,
			'message'  => sprintf(
				/* translators: 1: correct count, 2: total, 3: accuracy */
				__( 'Evaluation complete: %1$d/%2$d correct (%3$s accuracy).', 'mcp-ai-wpoos-pro' ),
				$report['correct'],
				$report['total'],
				$report['accuracy']
			),
		);
	}
}
