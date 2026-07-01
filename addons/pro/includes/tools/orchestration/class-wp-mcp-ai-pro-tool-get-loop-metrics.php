<?php
/**
 * Tool: Get Loop Metrics
 *
 * Returns performance analytics for autonomous orchestration loops:
 * success rates, average iteration duration, tool-call frequency,
 * error distribution, and trend data.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Loop Metrics Tool
 */
class WP_MCP_AI_Pro_Tool_Get_Loop_Metrics {

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_loop_metrics';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'get_loop_metrics',
			'description'         => 'Get performance analytics for autonomous orchestration loops. Returns success rates, average iteration duration, tool-call frequency, error distribution, and session trend data. Use this to monitor autonomous session health and optimise loop configurations.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id' => array(
						'type'        => 'string',
						'description' => 'Session ID to get metrics for (required)',
					),
					'period'     => array(
						'type'        => 'string',
						'enum'        => array( 'session', '24h', '7d', '30d' ),
						'description' => 'Time period for aggregated metrics',
						'default'     => 'session',
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => 'read',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by tool interface.
		$session_id = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$period     = isset( $arguments['period'] ) ? sanitize_key( $arguments['period'] ) : 'session';

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'missing_session_id',
				__( 'Missing required argument: session_id', 'mcp-ai-wpoos' )
			);
		}

		// Get execution history for this session.
		$history = $this->get_history( $session_id );

		// Calculate metrics.
		$metrics = array(
			'session_id'         => $session_id,
			'period'             => $period,
			'total_calls'        => count( $history ),
			'successful_calls'   => 0,
			'failed_calls'       => 0,
			'success_rate'       => 0,
			'avg_duration_ms'    => 0,
			'total_tokens'       => 0,
			'most_used_tool'     => '',
			'error_tools'        => array(),
			'tool_frequency'     => array(),
			'error_distribution' => array(),
		);

		if ( empty( $history ) ) {
			$metrics['message'] = __( 'No execution history found for this session.', 'mcp-ai-wpoos' );
			return array(
				'success' => true,
				'metrics' => $metrics,
			);
		}

		$total_duration = 0;
		$tool_counts    = array();
		$error_counts   = array();

		foreach ( $history as $record ) {
			$tool_name = isset( $record['tool_name'] ) ? $record['tool_name'] : 'unknown';
			$success   = isset( $record['success'] ) ? (bool) $record['success'] : true;

			if ( $success ) {
				++$metrics['successful_calls'];
			} else {
				++$metrics['failed_calls'];
				$error_msg = isset( $record['error_message'] ) ? $record['error_message'] : 'Unknown error';
				if ( ! isset( $error_counts[ $tool_name ] ) ) {
					$error_counts[ $tool_name ] = array(
						'count'    => 0,
						'messages' => array(),
					);
				}
				++$error_counts[ $tool_name ]['count'];
				if ( count( $error_counts[ $tool_name ]['messages'] ) < 3 ) {
					$error_counts[ $tool_name ]['messages'][] = $error_msg;
				}
			}

			// Tool frequency.
			if ( ! isset( $tool_counts[ $tool_name ] ) ) {
				$tool_counts[ $tool_name ] = 0;
			}
			++$tool_counts[ $tool_name ];

			// Duration.
			if ( isset( $record['duration_ms'] ) && $record['duration_ms'] > 0 ) {
				$total_duration += (int) $record['duration_ms'];
			}

			// Tokens.
			if ( isset( $record['tokens_used'] ) && $record['tokens_used'] > 0 ) {
				$metrics['total_tokens'] += (int) $record['tokens_used'];
			}
		}

		// Calculate derived metrics.
		$total = $metrics['total_calls'];
		if ( $total > 0 ) {
			$metrics['success_rate']    = round( ( $metrics['successful_calls'] / $total ) * 100, 1 );
			$metrics['avg_duration_ms'] = round( $total_duration / $total );
		}

		// Most used tool.
		if ( ! empty( $tool_counts ) ) {
			arsort( $tool_counts );
			$metrics['most_used_tool'] = key( $tool_counts );
			$metrics['tool_frequency'] = array_slice( $tool_counts, 0, 10, true );
		}

		// Error distribution.
		if ( ! empty( $error_counts ) ) {
			uasort(
				$error_counts,
				function ( $a, $b ) {
					return $b['count'] - $a['count'];
				}
			);
			$metrics['error_distribution'] = $error_counts;
			$metrics['error_tools']        = array_keys( $error_counts );
		}

		// Session health recommendation.
		$metrics['health_recommendation'] = $this->get_recommendation( $metrics );

		return array(
			'success' => true,
			'metrics' => $metrics,
		);
	}

	/**
	 * Get execution history for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return array
	 */
	private function get_history( $session_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Execution_History_CCT' ) ) {
			return array();
		}

		return WP_MCP_AI_Execution_History_CCT::get_session_history(
			$session_id,
			array(
				'limit'   => 1000,
				'orderby' => 'executed_at',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Generate a health recommendation based on metrics.
	 *
	 * @param array $metrics Computed metrics.
	 * @return string
	 */
	private function get_recommendation( array $metrics ) {
		if ( 0 === $metrics['total_calls'] ) {
			return __( 'No data available yet. Start the loop to collect metrics.', 'mcp-ai-wpoos' );
		}

		if ( $metrics['success_rate'] >= 90 ) {
			return __( 'Excellent! Loop is performing well with high success rate. Consider increasing max_iterations for longer autonomous runs.', 'mcp-ai-wpoos' );
		}

		if ( $metrics['success_rate'] >= 70 ) {
			return __( 'Good. Some errors detected — review error distribution for patterns and consider adjusting tool configurations.', 'mcp-ai-wpoos' );
		}

		if ( $metrics['failed_calls'] > $metrics['successful_calls'] ) {
			return sprintf(
				/* translators: %s: comma-separated list of error-prone tools */
				__( 'Critical: More failures than successes. Failing tools: %s. Review circuit breaker status and consider pausing the session for investigation.', 'mcp-ai-wpoos' ),
				implode( ', ', $metrics['error_tools'] )
			);
		}

		return __( 'Moderate performance. Monitor closely and review tool configurations for optimisation.', 'mcp-ai-wpoos' );
	}
}
