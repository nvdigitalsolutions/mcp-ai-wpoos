<?php
/**
 * Tool for testing workflow rules without execution.
 *
 * Allows AI assistants to perform dry-run validation of workflow rules
 * to ensure they work correctly before activation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tests workflow rules in dry-run mode.
 */
class WP_MCP_AI_Tool_Test_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'test_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Test Workflow Rule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Performs dry-run validation of workflow rule to test trigger conditions and actions without executing them.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'rule_id'         => array(
					'type'        => 'string',
					'description' => __( 'Rule ID to test (required)', 'mcp-ai-wpoos-pro' ),
				),
				'test_data'       => array(
					'type'        => 'object',
					'description' => __( 'Test data to simulate (optional)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'registration_id' => array( 'type' => 'integer' ),
						'event_type'      => array( 'type' => 'string' ),
					),
				),
				'validate_actions' => array(
					'type'        => 'boolean',
					'description' => __( 'Validate actions configuration (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'rule_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to test workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['rule_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Rule ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$rule_id          = sanitize_text_field( $arguments['rule_id'] );
		$test_data        = ! empty( $arguments['test_data'] ) && is_array( $arguments['test_data'] ) ? $arguments['test_data'] : array();
		$validate_actions = isset( $arguments['validate_actions'] ) ? (bool) $arguments['validate_actions'] : true;

		// Get workflow rules.
		$workflow_rules = get_option( 'wp_mcp_ai_workflow_rules', array() );

		// Verify rule exists.
		if ( ! isset( $workflow_rules[ $rule_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Workflow rule not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$rule = $workflow_rules[ $rule_id ];

		$test_results = array(
			'valid'    => true,
			'errors'   => array(),
			'warnings' => array(),
		);

		// Validate trigger.
		if ( empty( $rule['trigger']['event'] ) ) {
			$test_results['valid'] = false;
			$test_results['errors'][] = __( 'Trigger event is not configured.', 'mcp-ai-wpoos-pro' );
		}

		// Test trigger conditions.
		if ( ! empty( $test_data ) ) {
			$trigger_matched = true;

			// Simulate trigger evaluation.
			if ( isset( $test_data['registration_id'] ) ) {
				$registration = get_post( absint( $test_data['registration_id'] ) );
				if ( ! $registration ) {
					$test_results['warnings'][] = __( 'Test registration not found.', 'mcp-ai-wpoos-pro' );
					$trigger_matched = false;
				}
			}

			$test_results['trigger_matched'] = $trigger_matched;
		}

		// Validate actions.
		if ( $validate_actions ) {
			if ( empty( $rule['actions'] ) || ! is_array( $rule['actions'] ) ) {
				$test_results['valid'] = false;
				$test_results['errors'][] = __( 'No actions configured.', 'mcp-ai-wpoos-pro' );
			} else {
				foreach ( $rule['actions'] as $index => $action ) {
					if ( empty( $action['type'] ) ) {
						$test_results['valid'] = false;
						$test_results['errors'][] = sprintf(
							/* translators: %d: action index */
							__( 'Action %d: Type is not specified.', 'mcp-ai-wpoos-pro' ),
							$index + 1
						);
					}

					// Validate action-specific configuration.
					if ( 'send_email' === $action['type'] && empty( $action['params']['recipients'] ) ) {
						$test_results['warnings'][] = sprintf(
							/* translators: %d: action index */
							__( 'Action %d: No email recipients configured.', 'mcp-ai-wpoos-pro' ),
							$index + 1
						);
					}
				}
			}
		}

		// Generate test report.
		$report = array(
			'rule_id'          => $rule_id,
			'rule_name'        => $rule['name'],
			'trigger_event'    => $rule['trigger']['event'],
			'action_count'     => count( $rule['actions'] ),
			'validation_valid' => $test_results['valid'],
			'errors'           => $test_results['errors'],
			'warnings'         => $test_results['warnings'],
		);

		if ( isset( $test_results['trigger_matched'] ) ) {
			$report['trigger_matched'] = $test_results['trigger_matched'];
		}

		$summary = '';
		if ( $test_results['valid'] ) {
			$summary = __( 'Workflow rule validation passed.', 'mcp-ai-wpoos-pro' );
			if ( ! empty( $test_results['warnings'] ) ) {
				$summary .= ' ' . sprintf(
					/* translators: %d: warning count */
					__( '%d warnings found.', 'mcp-ai-wpoos-pro' ),
					count( $test_results['warnings'] )
				);
			}
		} else {
			$summary = sprintf(
				/* translators: %d: error count */
				__( 'Validation failed: %d errors found.', 'mcp-ai-wpoos-pro' ),
				count( $test_results['errors'] )
			);
		}

		return array(
			'success'   => true,
			'summary'   => $summary,
			'report'    => $report,
			'tested_at' => current_time( 'mysql' ),
		);
	}
}
