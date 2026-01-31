<?php
/**
 * Tool: Get Session Status
 *
 * Retrieves real-time status of autonomous orchestration session.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Session Status Tool
 */
class WP_MCP_AI_Tool_Get_Session_Status {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_session_status';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'get_session_status',
			'description'         => 'Get real-time status of autonomous orchestration session including health metrics, progress, and resource usage.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'   => array(
						'type'        => 'string',
						'description' => 'Session ID to retrieve',
					),
					'include_plan' => array(
						'type'        => 'boolean',
						'description' => 'Include task plan details (default: false)',
						'default'     => false,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => 'read',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_MCP_AI_Tool_Interface.
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		$session_id   = $arguments['session_id'];
		$include_plan = ! empty( $arguments['include_plan'] );

		// Get session.
		$session = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		// Calculate derived metrics.
		$metrics = $this->calculate_metrics( $session );

		// Build response.
		$response = array(
			'success'    => true,
			'session_id' => $session_id,
			'status'     => $session['status'],
			'health'     => array(
				'status'          => $session['health_status'],
				'circuit_breaker' => $session['circuit_breaker'],
				'success_rate'    => $session['success_rate'],
				'error_count'     => $session['error_count'],
			),
			'progress'   => array(
				'iteration_count'      => $session['iteration_count'],
				'max_iterations'       => $session['max_iterations'],
				'iterations_remaining' => $metrics['iterations_remaining'],
				'progress_percent'     => $metrics['iteration_progress'],
				'completion_score'     => $session['completion_score'],
			),
			'resources'  => array(
				'token_usage'      => $session['token_usage'],
				'token_budget'     => $session['token_budget'],
				'tokens_remaining' => $metrics['tokens_remaining'],
				'budget_percent'   => $metrics['token_progress'],
			),
			'timing'     => array(
				'started_at'     => $session['started_at'],
				'last_activity'  => $session['last_activity'],
				'expires_at'     => $session['expires_at'],
				'elapsed'        => $metrics['elapsed_time'],
				'time_remaining' => $metrics['time_remaining'],
			),
			'metadata'   => array(
				'plan_id'      => $session['plan_id'],
				'assistant_id' => $session['assistant_id'],
				'user_id'      => $session['user_id'],
				'last_tool'    => ! empty( $session['last_tool'] ) ? $session['last_tool'] : null,
				'last_error'   => ! empty( $session['last_error'] ) ? $session['last_error'] : null,
			),
			'indicators' => array(
				'exit_signal'     => $session['exit_signal'],
				'should_continue' => $this->should_continue( $session, $metrics ),
				'status_summary'  => $this->get_status_summary( $session, $metrics ),
			),
		);

		// Add task plan if requested.
		if ( $include_plan ) {
			$plan = $this->get_task_plan( $session['plan_id'] );
			if ( $plan ) {
				$response['task_plan'] = $plan;
			}
		}

		return $response;
	}

	/**
	 * Get session
	 *
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	private function get_session( $session_id ) {
		$transient_key = 'mcp_ai_session_' . $session_id;
		$session       = get_transient( $transient_key );
		return $session ? $session : null;
	}

	/**
	 * Calculate metrics
	 *
	 * @param array $session Session data.
	 * @return array
	 */
	private function calculate_metrics( $session ) {
		$iterations_remaining = max( 0, $session['max_iterations'] - $session['iteration_count'] );
		$tokens_remaining     = max( 0, $session['token_budget'] - $session['token_usage'] );

		$iteration_progress = $session['max_iterations'] > 0
			? ( $session['iteration_count'] / $session['max_iterations'] ) * 100
			: 0;

		$token_progress = $session['token_budget'] > 0
			? ( $session['token_usage'] / $session['token_budget'] ) * 100
			: 0;

		$started_time = strtotime( $session['started_at'] );
		$expires_time = strtotime( $session['expires_at'] );
		$current_time = time();

		$elapsed_time   = $current_time - $started_time;
		$time_remaining = max( 0, $expires_time - $current_time );

		return array(
			'iterations_remaining' => $iterations_remaining,
			'tokens_remaining'     => $tokens_remaining,
			'iteration_progress'   => round( $iteration_progress, 1 ),
			'token_progress'       => round( $token_progress, 1 ),
			'elapsed_time'         => $elapsed_time,
			'time_remaining'       => $time_remaining,
		);
	}

	/**
	 * Should continue session
	 *
	 * @param array $session Session data.
	 * @param array $metrics Calculated metrics.
	 * @return bool
	 */
	private function should_continue( $session, $metrics ) {
		// Don't continue if not active.
		if ( 'active' !== $session['status'] ) {
			return false;
		}

		// Don't continue if circuit breaker open.
		if ( 'open' === $session['circuit_breaker'] ) {
			return false;
		}

		// Don't continue if limits reached.
		if ( $metrics['iterations_remaining'] <= 0 || $metrics['tokens_remaining'] <= 0 ) {
			return false;
		}

		// Don't continue if expired.
		if ( $metrics['time_remaining'] <= 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Get status summary
	 *
	 * @param array $session Session data.
	 * @param array $metrics Calculated metrics.
	 * @return string
	 */
	private function get_status_summary( $session, $metrics ) {
		// Check status.
		if ( 'completed' === $session['status'] ) {
			return '✅ Session completed successfully';
		}

		if ( 'paused' === $session['status'] ) {
			return '⏸️ Session paused';
		}

		if ( 'failed' === $session['status'] ) {
			return '❌ Session failed';
		}

		// Check health.
		if ( 'critical' === $session['health_status'] ) {
			return '🚨 Critical health issues detected';
		}

		if ( 'open' === $session['circuit_breaker'] ) {
			return '🔴 Circuit breaker opened - session paused';
		}

		// Check resources.
		if ( $metrics['iterations_remaining'] <= 0 ) {
			return '⚠️ Maximum iterations reached';
		}

		if ( $metrics['tokens_remaining'] <= 0 ) {
			return '⚠️ Token budget exhausted';
		}

		if ( $metrics['time_remaining'] <= 0 ) {
			return '⏰ Session expired';
		}

		// Check warnings.
		if ( $metrics['iterations_remaining'] < 5 ) {
			return sprintf(
				'⚠️ Running low on iterations (%d remaining)',
				$metrics['iterations_remaining']
			);
		}

		if ( $metrics['token_progress'] > 80 ) {
			return sprintf(
				'⚠️ Token budget %.1f%% used',
				$metrics['token_progress']
			);
		}

		if ( 'warning' === $session['health_status'] ) {
			return '⚠️ Health warnings detected';
		}

		// All good.
		return sprintf(
			'✅ Active and healthy - %d iterations, %.1f%% tokens used',
			$session['iteration_count'],
			$metrics['token_progress']
		);
	}

	/**
	 * Get task plan
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function get_task_plan( $plan_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Get_Task_Plan' ) ) {
			return null;
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Task_Plan();
		$result = $tool->execute(
			array( 'plan_id' => $plan_id ),
			array()
		);

		if ( empty( $result['success'] ) ) {
			return null;
		}

		return array(
			'plan_id'         => $result['plan_id'],
			'plan_name'       => $result['plan_name'],
			'goal'            => $result['goal'],
			'task_count'      => $result['task_count'],
			'completed_count' => $result['completed_count'],
			'progress'        => $result['progress'],
			'status'          => $result['status'],
		);
	}
}
