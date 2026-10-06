<?php
/**
 * Tool: Suggest Workflows From History.
 *
 * Mines the Meta-Harness trace store for recurring tool chains and returns
 * them as candidate workflows — the "instincts → workflows" pattern from
 * ECC's continuous learning, applied to the Pro Workflow Builder. Every
 * suggestion carries steps, occurrences, success rate, score, confidence,
 * and the assistants that exhibited the pattern; admins can import the
 * chain into the Workflow Builder in one step.
 *
 * Fully deterministic and read-only: no provider calls, no state changes.
 *
 * @credit  Pattern-extraction concept inspired by affaan-m/ECC
 *          continuous-learning instincts (MIT).
 * @package WP_MCP_AI_Pro
 * @since   1.1.97
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggest Workflows From History tool.
 *
 * Implements the canonical envelope (success array or WP_Error) and the
 * two-gate sanitisation rule.
 *
 * @since 1.1.97
 */
class WP_MCP_AI_Tool_Suggest_Workflows_From_History implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'suggest_workflows_from_history';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Suggest Workflows From History', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Mine recorded assistant execution history for recurring tool chains and return them as candidate workflows for the Pro Workflow Builder. Each suggestion includes the tool steps, occurrence count, success rate, a confidence score, and which assistants exhibited the pattern. Read-only and deterministic — no model calls.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Discovering repeated assistant behaviors worth automating as workflows; auditing how assistants actually combine tools; seeding the Workflow Builder after a period of organic usage.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Predicting future tool needs (no forecast — it only reports observed history); replacing manual workflow design where domain constraints matter more than frequency.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'execute_workflow', 'validate_workflow', 'check_workflow_health', 'run_assistant_eval' ),
			'notes'           => __( 'Scores combine frequency, recency, and success rate. Chains that only appear once are filtered out by default (raise min_occurrences to be stricter).', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Restrict mining to one assistant, or 0 to mine every assistant\'s history.', 'mcp-ai-wpoos' ),
					'default'     => 0,
				),
				'min_occurrences'  => array(
					'type'        => 'integer',
					'description' => __( 'Minimum times a chain must appear before it is suggested. Default 2.', 'mcp-ai-wpoos' ),
					'default'     => 2,
				),
				'max_results'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum suggestions to return. Default 10.', 'mcp-ai-wpoos' ),
					'default'     => 10,
				),
				'max_chain_length' => array(
					'type'        => 'integer',
					'description' => __( 'Longest tool chain to consider (2-5). Default 4.', 'mcp-ai-wpoos' ),
					'default'     => 4,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // No local state changes.
			'no-user-data-access',  // Trace-store aggregates only.
			'stateless',            // No persistence between calls.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
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
				__( 'You do not have permission to mine workflow suggestions. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Workflow_Suggestion_Miner' ) ) {
			$miner_file = WP_MCP_AI_PRO_PATH . 'includes/harness/class-wp-mcp-ai-workflow-suggestion-miner.php';
			if ( file_exists( $miner_file ) ) {
				require_once $miner_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Workflow_Suggestion_Miner' ) ) {
			return new WP_Error(
				'wp_mcp_ai_miner_unavailable',
				__( 'The workflow suggestion miner is not available.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			return new WP_Error(
				'wp_mcp_ai_trace_store_unavailable',
				__( 'The harness trace store is not available, so there is no execution history to mine.', 'mcp-ai-wpoos' )
			);
		}

		// Two-gate rule, gate one: sanitise at entry.
		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		$opts         = array();

		if ( isset( $arguments['min_occurrences'] ) ) {
			$opts['min_occurrences'] = max( 1, absint( $arguments['min_occurrences'] ) );
		}
		if ( isset( $arguments['max_results'] ) ) {
			$opts['max_results'] = max( 1, absint( $arguments['max_results'] ) );
		}
		if ( isset( $arguments['max_chain_length'] ) ) {
			$opts['max_chain'] = max( 2, min( 5, absint( $arguments['max_chain_length'] ) ) );
		}

		$result = WP_MCP_AI_Workflow_Suggestion_Miner::mine( $assistant_id, $opts );

		if ( empty( $result['suggestions'] ) ) {
			return $this->format_success_response(
				__( 'No recurring tool chains found in the recorded history yet. Keep using the assistants and re-run this tool after a few sessions.', 'mcp-ai-wpoos' ),
				array(
					'data' => $result,
				)
			);
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: suggestion count, 2: scanned run count. */
				__( 'Found %1$d workflow suggestion(s) across %2$d recorded run(s).', 'mcp-ai-wpoos' ),
				count( $result['suggestions'] ),
				$result['runs_scanned']
			),
			array(
				'data' => $result,
			)
		);
	}
}
