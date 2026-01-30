<?php
/**
 * Tool for validating logical reasoning chains.
 *
 * Part of Phase 3.3: Reasoning Tools implementation.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate Reasoning Chain Tool
 *
 * Validates logical consistency and completeness of step-by-step reasoning chains.
 * Helps ensure AI reasoning is coherent, follows from premises, and reaches
 * valid conclusions.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Tool_Validate_Reasoning_Chain implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_reasoning_chain';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Reasoning Chain', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates logical reasoning chains for coherence and consistency. Checks step-by-step progression, verifies premises, identifies logical gaps, and ensures conclusions follow from reasoning. Returns validation report with coherence score, consistency check, and identified issues.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'reasoning_steps' => array(
					'type'        => 'array',
					'description' => __( 'Array of reasoning steps to validate. Each step should be a string describing one logical step.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'premises'        => array(
					'type'        => 'array',
					'description' => __( 'Starting premises or assumptions for the reasoning chain.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'conclusion'      => array(
					'type'        => 'string',
					'description' => __( 'Expected or actual conclusion of the reasoning chain.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'reasoning_steps' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'experimentation' ),

			'profession_tags'       => array( 'ai_researcher' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'          => true,
			'modifies-wp'   => false,
			'deterministic' => true,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['reasoning_steps'] ) || ! is_array( $arguments['reasoning_steps'] ) ) {
			return new WP_Error(
				'missing_parameter',
				__( 'Reasoning steps parameter is required and must be an array.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$reasoning_steps = $arguments['reasoning_steps'];
		$premises        = $arguments['premises'] ?? array();
		$conclusion      = $arguments['conclusion'] ?? '';

		// Get reasoning controller instance.
		if ( ! class_exists( 'WP_MCP_AI_Reasoning_Controller' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Reasoning Controller service is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$reasoning_controller = new WP_MCP_AI_Reasoning_Controller();

		// Prepare reasoning output for validation.
		$reasoning_output = array(
			'steps'       => $reasoning_steps,
			'premises'    => $premises,
			'conclusion'  => $conclusion,
			'explanation' => implode( ' → ', $reasoning_steps ),
		);

		// Create a simple task description for tracking.
		$task = sprintf(
			/* translators: %d: number of steps */
			__( 'Validate reasoning chain with %d steps', 'mcp-ai-wpoos' ),
			count( $reasoning_steps )
		);

		// Track reasoning quality (assuming success for validation).
		$quality_metrics = $reasoning_controller->track_reasoning_quality(
			$task,
			$reasoning_output,
			array( 'validated' => true ),
			true
		);

		// Perform validation checks.
		$validation_result = $this->perform_validation_checks( $reasoning_steps, $premises, $conclusion );

		// Build result.
		$result = array(
			'valid'              => $validation_result['valid'],
			'step_count'         => count( $reasoning_steps ),
			'quality_metrics'    => $quality_metrics,
			'validation_details' => $validation_result,
			'timestamp'          => current_time( 'mysql' ),
		);

		// Generate message.
		if ( $validation_result['valid'] ) {
			$message = sprintf(
				/* translators: 1: coherence score, 2: consistency score */
				__( 'Reasoning chain is valid. Coherence: %1$.1f%%, Logical consistency: %2$.1f%%. No significant gaps identified.', 'mcp-ai-wpoos' ),
				$quality_metrics['coherence'] * 100,
				$quality_metrics['logical_consistency'] * 100
			);
		} else {
			$issue_count = count( $validation_result['issues'] ?? array() );
			$message     = sprintf(
				/* translators: %d: number of issues */
				__( 'Reasoning chain has %d validation issues. Review identified gaps and inconsistencies.', 'mcp-ai-wpoos' ),
				$issue_count
			);
		}

		$result['message'] = $message;

		return $this->success( $result, $message );
	}

	/**
	 * Perform validation checks on reasoning chain
	 *
	 * @param array  $reasoning_steps Reasoning steps.
	 * @param array  $premises Starting premises.
	 * @param string $conclusion Expected conclusion.
	 * @return array Validation result.
	 */
	protected function perform_validation_checks( $reasoning_steps, $premises, $conclusion ) {
		$issues = array();
		$valid  = true;

		// Check for empty steps.
		foreach ( $reasoning_steps as $index => $step ) {
			if ( empty( trim( $step ) ) ) {
				$issues[] = sprintf(
					/* translators: %d: step number */
					__( 'Step %d is empty or contains only whitespace.', 'mcp-ai-wpoos' ),
					$index + 1
				);
				$valid = false;
			}
		}

		// Check for very short chain (less than 2 steps).
		if ( count( $reasoning_steps ) < 2 && ! empty( $conclusion ) ) {
			$issues[] = __( 'Reasoning chain is very short. Complex conclusions typically require multiple steps.', 'mcp-ai-wpoos' );
		}

		// Check if premises are referenced in early steps.
		if ( ! empty( $premises ) ) {
			$premises_referenced = false;
			$first_steps         = array_slice( $reasoning_steps, 0, min( 3, count( $reasoning_steps ) ) );
			$first_steps_text    = implode( ' ', $first_steps );

			foreach ( $premises as $premise ) {
				if ( ! empty( $premise ) && false !== stripos( $first_steps_text, substr( $premise, 0, 20 ) ) ) {
					$premises_referenced = true;
					break;
				}
			}

			if ( ! $premises_referenced ) {
				$issues[] = __( 'Warning: Starting premises do not appear to be referenced in initial reasoning steps.', 'mcp-ai-wpoos' );
			}
		}

		// Check if conclusion is supported by final steps.
		if ( ! empty( $conclusion ) && count( $reasoning_steps ) > 0 ) {
			$final_steps      = array_slice( $reasoning_steps, -2 );
			$final_steps_text = implode( ' ', $final_steps );

			// Simple check: conclusion keywords should appear in final steps.
			$conclusion_words = explode( ' ', strtolower( $conclusion ) );
			$conclusion_words = array_filter(
				$conclusion_words,
				function ( $word ) {
					return strlen( $word ) > 4;
				}
			);

			$matches = 0;
			foreach ( $conclusion_words as $word ) {
				if ( false !== stripos( $final_steps_text, $word ) ) {
					++$matches;
				}
			}

			if ( $matches === 0 && count( $conclusion_words ) > 0 ) {
				$issues[] = __( 'Warning: Conclusion does not appear to be directly supported by final reasoning steps.', 'mcp-ai-wpoos' );
			}
		}

		// Check for logical connectors (therefore, thus, because, since, so).
		$connectors_found = 0;
		$connectors       = array( 'therefore', 'thus', 'because', 'since', 'so', 'hence', 'consequently' );
		$all_steps_text   = strtolower( implode( ' ', $reasoning_steps ) );

		foreach ( $connectors as $connector ) {
			if ( false !== strpos( $all_steps_text, $connector ) ) {
				++$connectors_found;
			}
		}

		if ( $connectors_found === 0 && count( $reasoning_steps ) > 2 ) {
			$issues[] = __( 'Notice: No logical connectors found. Reasoning may benefit from explicit causal language (therefore, because, thus, etc.).', 'mcp-ai-wpoos' );
		}

		return array(
			'valid'  => $valid && count( $issues ) === 0,
			'issues' => $issues,
			'checks' => array(
				'has_empty_steps'        => $valid,
				'sufficient_length'      => count( $reasoning_steps ) >= 2,
				'premises_referenced'    => empty( $premises ) || $premises_referenced ?? false,
				'conclusion_supported'   => empty( $conclusion ) || $matches > 0,
				'has_logical_connectors' => $connectors_found > 0,
			),
		);
	}
}
