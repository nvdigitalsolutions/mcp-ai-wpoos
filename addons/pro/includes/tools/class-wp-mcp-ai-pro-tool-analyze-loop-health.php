<?php
/**
 * Tool: Analyze Loop Health
 *
 * Monitors autonomous loops for runaway behavior and circuit breaker triggers.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze Loop Health Tool
 */
class WP_MCP_AI_Pro_Tool_Analyze_Loop_Health {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'analyze_loop_health';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'analyze_loop_health',
			'description'         => 'Analyze autonomous loop health to detect runaway behaviors, repeated failures, and circuit breaker conditions. Returns health status and recommendations.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'    => array(
						'type'        => 'string',
						'description' => 'Session ID to analyze',
					),
					'last_actions'  => array(
						'type'        => 'array',
						'description' => 'Recent actions/tool calls for pattern detection',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'tool'    => array(
									'type'        => 'string',
									'description' => 'Tool name',
								),
								'success' => array(
									'type'        => 'boolean',
									'description' => 'Whether action succeeded',
								),
								'error'   => array(
									'type'        => 'string',
									'description' => 'Error message if failed',
								),
							),
						),
					),
					'current_error' => array(
						'type'        => 'string',
						'description' => 'Current error message to analyze',
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
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		$session_id    = $arguments['session_id'];
		$last_actions  = ! empty( $arguments['last_actions'] ) ? $arguments['last_actions'] : array();
		$current_error = ! empty( $arguments['current_error'] ) ? $arguments['current_error'] : '';

		// Get session.
		$session = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		// Analyze various health indicators.
		$analysis = array(
			'repeated_actions'  => $this->detect_repeated_actions( $last_actions ),
			'error_cascade'     => $this->detect_error_cascade( $last_actions ),
			'stuck_pattern'     => $this->detect_stuck_pattern( $last_actions ),
			'error_severity'    => $this->analyze_error_severity( $current_error ),
			'resource_pressure' => $this->check_resource_pressure( $session ),
			'velocity'          => $this->calculate_velocity( $session, $last_actions ),
		);

		// Determine health status.
		$health_status = $this->determine_health_status( $analysis );

		// Check if circuit breaker should open.
		$circuit_breaker_action = $this->check_circuit_breaker( $analysis, $session );

		// Update session health if needed.
		if ( $health_status !== $session['health_status'] || $circuit_breaker_action['should_open'] ) {
			$this->update_session_health( $session_id, $health_status, $circuit_breaker_action );
		}

		return array(
			'success'         => true,
			'session_id'      => $session_id,
			'health_status'   => $health_status,
			'circuit_breaker' => $circuit_breaker_action['status'],
			'analysis'        => $analysis,
			'warnings'        => $this->get_warnings( $analysis ),
			'recommendations' => $this->get_recommendations( $analysis, $health_status ),
			'should_pause'    => $circuit_breaker_action['should_open'],
		);
	}

	/**
	 * Detect repeated actions
	 *
	 * @param array $last_actions Recent actions.
	 * @return array
	 */
	private function detect_repeated_actions( $last_actions ) {
		if ( empty( $last_actions ) || count( $last_actions ) < 3 ) {
			return array(
				'detected' => false,
				'pattern'  => null,
				'count'    => 0,
			);
		}

		// Check last 3 actions.
		$recent = array_slice( $last_actions, -3 );
		$tools  = array_map(
			function ( $action ) {
				return $action['tool'];
			},
			$recent
		);

		$unique_tools = array_unique( $tools );

		// If only 1-2 unique tools in last 3 actions, it's repetitive.
		$is_repetitive = count( $unique_tools ) <= 2;

		if ( $is_repetitive ) {
			$tool_counts = array_count_values( $tools );
			arsort( $tool_counts );
			$most_repeated = key( $tool_counts );

			return array(
				'detected' => true,
				'pattern'  => $most_repeated,
				'count'    => $tool_counts[ $most_repeated ],
			);
		}

		return array(
			'detected' => false,
			'pattern'  => null,
			'count'    => 0,
		);
	}

	/**
	 * Detect error cascade
	 *
	 * @param array $last_actions Recent actions.
	 * @return array
	 */
	private function detect_error_cascade( $last_actions ) {
		if ( empty( $last_actions ) || count( $last_actions ) < 3 ) {
			return array(
				'detected'    => false,
				'error_count' => 0,
			);
		}

		// Check last 5 actions for errors.
		$recent = array_slice( $last_actions, -5 );
		$errors = array_filter(
			$recent,
			function ( $action ) {
				return empty( $action['success'] );
			}
		);

		$error_count = count( $errors );

		return array(
			'detected'    => $error_count >= 3,
			'error_count' => $error_count,
			'error_rate'  => count( $recent ) > 0 ? ( $error_count / count( $recent ) ) * 100 : 0,
		);
	}

	/**
	 * Detect stuck pattern
	 *
	 * @param array $last_actions Recent actions.
	 * @return array
	 */
	private function detect_stuck_pattern( $last_actions ) {
		if ( empty( $last_actions ) || count( $last_actions ) < 5 ) {
			return array(
				'detected' => false,
				'reason'   => null,
			);
		}

		// Check if same tool called multiple times with same failure.
		$recent = array_slice( $last_actions, -5 );
		$tools  = array();
		$errors = array();

		foreach ( $recent as $action ) {
			if ( ! empty( $action['tool'] ) ) {
				$tools[] = $action['tool'];
			}
			if ( ! empty( $action['error'] ) ) {
				$errors[] = $action['error'];
			}
		}

		// Same tool called 3+ times.
		$tool_counts = array_count_values( $tools );
		foreach ( $tool_counts as $tool => $count ) {
			if ( $count >= 3 ) {
				return array(
					'detected' => true,
					'reason'   => sprintf( 'Tool "%s" called %d times in last 5 actions', $tool, $count ),
				);
			}
		}

		// Same error message 2+ times.
		$error_counts = array_count_values( $errors );
		foreach ( $error_counts as $error => $count ) {
			if ( $count >= 2 ) {
				return array(
					'detected' => true,
					'reason'   => 'Same error repeated: ' . substr( $error, 0, 50 ),
				);
			}
		}

		return array(
			'detected' => false,
			'reason'   => null,
		);
	}

	/**
	 * Analyze error severity
	 *
	 * @param string $error Error message.
	 * @return array
	 */
	private function analyze_error_severity( $error ) {
		if ( empty( $error ) ) {
			return array(
				'severity' => 'none',
				'critical' => false,
			);
		}

		// Critical error patterns.
		$critical_patterns = array(
			'/fatal error/i',
			'/out of memory/i',
			'/segmentation fault/i',
			'/access denied/i',
			'/permission denied/i',
			'/authentication failed/i',
		);

		foreach ( $critical_patterns as $pattern ) {
			if ( preg_match( $pattern, $error ) ) {
				return array(
					'severity' => 'critical',
					'critical' => true,
					'message'  => 'Critical error detected',
				);
			}
		}

		return array(
			'severity' => 'normal',
			'critical' => false,
		);
	}

	/**
	 * Check resource pressure
	 *
	 * @param array $session Session data.
	 * @return array
	 */
	private function check_resource_pressure( $session ) {
		$iteration_usage = $session['iteration_count'] / $session['max_iterations'] * 100;
		$token_usage     = $session['token_usage'] / $session['token_budget'] * 100;

		return array(
			'iteration_pressure' => $iteration_usage,
			'token_pressure'     => $token_usage,
			'high_pressure'      => $iteration_usage > 80 || $token_usage > 80,
		);
	}

	/**
	 * Calculate velocity
	 *
	 * @param array $session      Session data.
	 * @param array $last_actions Recent actions.
	 * @return array
	 */
	private function calculate_velocity( $session, $last_actions ) {
		$success_count = 0;
		foreach ( $last_actions as $action ) {
			if ( ! empty( $action['success'] ) ) {
				++$success_count;
			}
		}

		$total        = count( $last_actions );
		$success_rate = $total > 0 ? ( $success_count / $total ) * 100 : 0;

		return array(
			'success_rate' => $success_rate,
			'velocity'     => $success_rate > 70 ? 'good' : ( $success_rate > 40 ? 'moderate' : 'poor' ),
		);
	}

	/**
	 * Determine health status
	 *
	 * @param array $analysis Analysis results.
	 * @return string
	 */
	private function determine_health_status( $analysis ) {
		// Critical issues.
		if ( $analysis['error_severity']['critical']
			|| $analysis['error_cascade']['error_count'] >= 4
			|| $analysis['stuck_pattern']['detected'] ) {
			return 'critical';
		}

		// Warning issues.
		if ( $analysis['error_cascade']['detected']
			|| $analysis['repeated_actions']['detected']
			|| $analysis['resource_pressure']['high_pressure']
			|| 'poor' === $analysis['velocity']['velocity'] ) {
			return 'warning';
		}

		return 'healthy';
	}

	/**
	 * Check circuit breaker
	 *
	 * @param array $analysis Analysis results.
	 * @param array $session  Session data.
	 * @return array
	 */
	private function check_circuit_breaker( $analysis, $session ) {
		$should_open = false;
		$reason      = '';

		// Open circuit breaker on critical health.
		if ( 'critical' === $this->determine_health_status( $analysis ) ) {
			$should_open = true;
			$reason      = 'Critical health status detected';
		}

		// Open on repeated stuck patterns.
		if ( $analysis['stuck_pattern']['detected'] && $session['error_count'] >= 5 ) {
			$should_open = true;
			$reason      = 'Stuck pattern with multiple errors';
		}

		return array(
			'should_open' => $should_open,
			'reason'      => $reason,
			'status'      => $should_open ? 'open' : $session['circuit_breaker'],
		);
	}

	/**
	 * Update session health
	 *
	 * @param string $session_id          Session ID.
	 * @param string $health_status       Health status.
	 * @param array  $circuit_breaker_action Circuit breaker action.
	 */
	private function update_session_health( $session_id, $health_status, $circuit_breaker_action ) {
		$transient_key = 'mcp_ai_session_' . $session_id;
		$session       = get_transient( $transient_key );

		if ( $session ) {
			$session['health_status']   = $health_status;
			$session['circuit_breaker'] = $circuit_breaker_action['status'];
			set_transient( $transient_key, $session, 86400 );
		}
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
	 * Get warnings
	 *
	 * @param array $analysis Analysis results.
	 * @return array
	 */
	private function get_warnings( $analysis ) {
		$warnings = array();

		if ( $analysis['repeated_actions']['detected'] ) {
			$warnings[] = sprintf(
				'Repetitive pattern: Tool "%s" called %d times',
				$analysis['repeated_actions']['pattern'],
				$analysis['repeated_actions']['count']
			);
		}

		if ( $analysis['error_cascade']['detected'] ) {
			$warnings[] = sprintf(
				'Error cascade: %d errors in last 5 actions (%.1f%% error rate)',
				$analysis['error_cascade']['error_count'],
				$analysis['error_cascade']['error_rate']
			);
		}

		if ( $analysis['stuck_pattern']['detected'] ) {
			$warnings[] = 'Stuck pattern: ' . $analysis['stuck_pattern']['reason'];
		}

		if ( $analysis['resource_pressure']['high_pressure'] ) {
			$warnings[] = sprintf(
				'Resource pressure: %.1f%% iterations, %.1f%% tokens used',
				$analysis['resource_pressure']['iteration_pressure'],
				$analysis['resource_pressure']['token_pressure']
			);
		}

		return $warnings;
	}

	/**
	 * Get recommendations
	 *
	 * @param array  $analysis     Analysis results.
	 * @param string $health_status Health status.
	 * @return array
	 */
	private function get_recommendations( $analysis, $health_status ) {
		$recommendations = array();

		if ( 'critical' === $health_status ) {
			$recommendations[] = 'STOP: Critical issues detected. Review errors and restart session.';
		}

		if ( $analysis['stuck_pattern']['detected'] ) {
			$recommendations[] = 'Try a different approach or tool to break the stuck pattern.';
		}

		if ( $analysis['error_cascade']['detected'] ) {
			$recommendations[] = 'Pause and investigate root cause of repeated errors.';
		}

		if ( $analysis['repeated_actions']['detected'] ) {
			$recommendations[] = 'Vary your approach to avoid repetitive loops.';
		}

		if ( $analysis['resource_pressure']['high_pressure'] ) {
			$recommendations[] = 'Consider wrapping up current iteration - resources running low.';
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = 'Loop health is good. Continue working.';
		}

		return $recommendations;
	}
}
