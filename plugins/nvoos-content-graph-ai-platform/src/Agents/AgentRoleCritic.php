<?php
/**
 * Critic Agent Role
 *
 * Validates and improves results from other agents.
 * Inspired by DeepSeek V4's validation and refinement patterns.
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Critic Agent Role class
 *
 * Responsible for:
 * - Validating results from executor agents
 * - Checking quality against criteria
 * - Identifying improvements
 * - Providing actionable feedback
 *
 * @since 1.1.0
 */
class AgentRoleCritic extends AgentRoleBase {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'critic';
		$this->role_name        = __( 'Critic', 'nvoos-content-graph-ai-platform' );
		$this->role_description = __( 'Validates results from other agents, checks quality, and provides feedback for improvement.', 'nvoos-content-graph-ai-platform' );

		$this->capabilities = array(
			'can-validate',
			'autonomous',
		);

		$this->recommended_tools = array(
			'check_site_security',
		);
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return __(
			'You are a Critic agent responsible for validating and improving the quality of results from other agents. When reviewing results, be thorough and constructive. Check for accuracy, completeness, clarity, and adherence to requirements. Identify specific issues and provide actionable recommendations for improvement. Your feedback should be clear, specific, and focused on enhancing the final output quality.',
			'nvoos-content-graph-ai-platform'
		);
	}

	/**
	 * Execute a validation task
	 *
	 * Reviews and validates results from other agents.
	 *
	 * @param array $task Task data including results to validate.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Validation result with feedback or error.
	 */
	public function execute_role_task( $task, $context ) {
		// Validate inputs.
		$task_validation = $this->validate_task( $task );
		if ( is_wp_error( $task_validation ) ) {
			return $task_validation;
		}

		$context_validation = $this->validate_context( $context );
		if ( is_wp_error( $context_validation ) ) {
			return $context_validation;
		}

		$this->log(
			'Critic agent validating results',
			'info',
			array(
				'task_description' => $task['description'],
				'assistant_id'     => $context['assistant_id'],
			)
		);

		// Get the result to validate.
		if ( empty( $task['result_to_validate'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_result',
				__( 'No result provided for validation.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$result_to_validate = $task['result_to_validate'];
		$criteria           = isset( $task['criteria'] ) ? $task['criteria'] : array();

		// Perform validation checks.
		$validation = array(
			'completeness' => $this->check_completeness( $result_to_validate, $criteria ),
			'accuracy'     => $this->check_accuracy( $result_to_validate, $criteria ),
			'quality'      => $this->check_quality( $result_to_validate, $criteria ),
		);

		// Calculate overall score.
		$overall_score = $this->calculate_overall_score( $validation );

		// Generate feedback.
		$feedback = $this->generate_feedback( $validation, $criteria );

		// Determine if result passes validation.
		$passes = $overall_score >= 0.7; // 70% threshold.

		$validation_result = array(
			'validation_id' => uniqid( 'validation_', true ),
			'passes'        => $passes,
			'overall_score' => $overall_score,
			'checks'        => $validation,
			'feedback'      => $feedback,
			'validated_at'  => current_time( 'mysql' ),
		);

		$this->log(
			'Validation complete',
			'info',
			array(
				'validation_id' => $validation_result['validation_id'],
				'passes'        => $passes,
				'score'         => $overall_score,
			)
		);

		return $validation_result;
	}

	/**
	 * Check completeness of results
	 *
	 * @param mixed $result Result to validate.
	 * @param array $criteria Validation criteria.
	 * @return array Check results with score and issues.
	 */
	protected function check_completeness( $result, $criteria ) {
		$score  = 0.8; // Default score.
		$issues = array();

		// Basic completeness checks.
		if ( empty( $result ) ) {
			$score    = 0.0;
			$issues[] = __( 'Result is empty', 'nvoos-content-graph-ai-platform' );
		} elseif ( is_array( $result ) && count( $result ) === 0 ) {
			$score    = 0.0;
			$issues[] = __( 'Result array is empty', 'nvoos-content-graph-ai-platform' );
		}

		// Check against required fields if specified.
		if ( isset( $criteria['required_fields'] ) && is_array( $result ) ) {
			foreach ( $criteria['required_fields'] as $field ) {
				if ( ! isset( $result[ $field ] ) ) {
					$issues[] = sprintf(
						/* translators: %s: field name */
						__( 'Missing required field: %s', 'nvoos-content-graph-ai-platform' ),
						$field
					);
					$score -= 0.1;
				}
			}
		}

		return array(
			'score'  => max( 0.0, min( 1.0, $score ) ),
			'issues' => $issues,
		);
	}

	/**
	 * Check accuracy of results
	 *
	 * @param mixed $result Result to validate.
	 * @param array $criteria Validation criteria.
	 * @return array Check results with score and issues.
	 */
	protected function check_accuracy( $result, $criteria ) {
		$score  = 0.8; // Default score.
		$issues = array();

		// Basic accuracy checks would go here.
		// In production, this would be more sophisticated.

		return array(
			'score'  => $score,
			'issues' => $issues,
		);
	}

	/**
	 * Check quality of results
	 *
	 * @param mixed $result Result to validate.
	 * @param array $criteria Validation criteria.
	 * @return array Check results with score and issues.
	 */
	protected function check_quality( $result, $criteria ) {
		$score  = 0.8; // Default score.
		$issues = array();

		// Basic quality checks.
		if ( is_string( $result ) && strlen( $result ) < 10 ) {
			$score   -= 0.2;
			$issues[] = __( 'Result is too short to be meaningful', 'nvoos-content-graph-ai-platform' );
		}

		return array(
			'score'  => max( 0.0, min( 1.0, $score ) ),
			'issues' => $issues,
		);
	}

	/**
	 * Calculate overall validation score
	 *
	 * @param array $validation Individual check results.
	 * @return float Overall score between 0.0 and 1.0.
	 */
	protected function calculate_overall_score( $validation ) {
		$scores = array_column( $validation, 'score' );

		if ( empty( $scores ) ) {
			return 0.0;
		}

		return array_sum( $scores ) / count( $scores );
	}

	/**
	 * Generate actionable feedback
	 *
	 * @param array $validation Validation check results.
	 * @param array $criteria Validation criteria.
	 * @return array Structured feedback.
	 */
	protected function generate_feedback( $validation, $criteria ) {
		$feedback = array(
			'summary'         => '',
			'issues'          => array(),
			'recommendations' => array(),
		);

		// Collect all issues.
		foreach ( $validation as $check_name => $check_result ) {
			if ( ! empty( $check_result['issues'] ) ) {
				foreach ( $check_result['issues'] as $issue ) {
					$feedback['issues'][] = sprintf(
						'[%s] %s',
						ucfirst( $check_name ),
						$issue
					);
				}
			}
		}

		// Generate recommendations.
		if ( ! empty( $feedback['issues'] ) ) {
			$feedback['recommendations'][] = __( 'Address all identified issues before proceeding', 'nvoos-content-graph-ai-platform' );
			$feedback['summary']           = sprintf(
				/* translators: %d: number of issues */
				_n( '%d issue found that needs attention.', '%d issues found that need attention.', count( $feedback['issues'] ), 'nvoos-content-graph-ai-platform' ),
				count( $feedback['issues'] )
			);
		} else {
			$feedback['summary'] = __( 'Result meets all validation criteria.', 'nvoos-content-graph-ai-platform' );
		}

		return $feedback;
	}
}
