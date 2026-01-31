<?php
/**
 * Tool: Manage Autonomous Session
 *
 * Manages lifecycle of autonomous orchestration sessions.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Autonomous Session Tool
 */
class WP_MCP_AI_Tool_Manage_Autonomous_Session {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'manage_autonomous_session';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'manage_autonomous_session',
			'description'         => 'Manage autonomous orchestration session lifecycle. Use this to start, pause, resume, or stop autonomous workflows with session state tracking.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'action'     => array(
						'type'        => 'string',
						'enum'        => array( 'start', 'pause', 'resume', 'stop', 'update' ),
						'description' => 'Action to perform on the session',
					),
					'plan_id'    => array(
						'type'        => 'integer',
						'description' => 'Task plan ID to start session for (required for "start" action)',
					),
					'session_id' => array(
						'type'        => 'string',
						'description' => 'Session ID (required for pause/resume/stop/update actions)',
					),
					'config'     => array(
						'type'        => 'object',
						'description' => 'Session configuration (for "start" action)',
						'properties'  => array(
							'max_iterations' => array(
								'type'        => 'integer',
								'description' => 'Maximum iterations allowed (default: 25)',
								'default'     => 25,
							),
							'token_budget'   => array(
								'type'        => 'integer',
								'description' => 'Token budget for session (default: 10000)',
								'default'     => 10000,
							),
							'assistant_id'   => array(
								'type'        => 'integer',
								'description' => 'Assistant ID to use',
							),
						),
					),
					'metrics'    => array(
						'type'        => 'object',
						'description' => 'Session metrics to update (for "update" action)',
						'properties'  => array(
							'iteration_count' => array(
								'type'        => 'integer',
								'description' => 'Current iteration number',
							),
							'token_usage'     => array(
								'type'        => 'integer',
								'description' => 'Total tokens used',
							),
							'success_rate'    => array(
								'type'        => 'number',
								'description' => 'Success rate percentage (0-100)',
							),
							'error_count'     => array(
								'type'        => 'integer',
								'description' => 'Number of errors encountered',
							),
							'last_tool'       => array(
								'type'        => 'string',
								'description' => 'Last tool executed',
							),
							'last_error'      => array(
								'type'        => 'string',
								'description' => 'Last error message',
							),
						),
					),
				),
				'required'   => array( 'action' ),
			),
			'required_capability' => 'edit_posts',
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
		$action = $arguments['action'];

		switch ( $action ) {
			case 'start':
				return $this->start_session( $arguments, $context );

			case 'pause':
				return $this->pause_session( $arguments );

			case 'resume':
				return $this->resume_session( $arguments );

			case 'stop':
				return $this->stop_session( $arguments );

			case 'update':
				return $this->update_session( $arguments );

			default:
				return array(
					'success' => false,
					'error'   => sprintf( 'Unknown action: %s', $action ),
				);
		}
	}

	/**
	 * Start new session
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	private function start_session( $arguments, $context ) {
		if ( empty( $arguments['plan_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: plan_id',
			);
		}

		$plan_id = intval( $arguments['plan_id'] );
		$config  = ! empty( $arguments['config'] ) ? $arguments['config'] : array();

		// Generate session ID.
		$session_id = wp_generate_uuid4();

		// Default configuration.
		$max_iterations = ! empty( $config['max_iterations'] ) ? intval( $config['max_iterations'] ) : 25;
		$token_budget   = ! empty( $config['token_budget'] ) ? intval( $config['token_budget'] ) : 10000;
		$assistant_id   = ! empty( $config['assistant_id'] ) ? intval( $config['assistant_id'] ) : 0;

		// Session data.
		$session_data = array(
			'session_id'       => $session_id,
			'plan_id'          => $plan_id,
			'assistant_id'     => $assistant_id,
			'user_id'          => get_current_user_id(),
			'status'           => 'active',
			'iteration_count'  => 0,
			'max_iterations'   => $max_iterations,
			'health_status'    => 'healthy',
			'circuit_breaker'  => 'closed',
			'token_usage'      => 0,
			'token_budget'     => $token_budget,
			'success_rate'     => 100,
			'error_count'      => 0,
			'completion_score' => 0,
			'exit_signal'      => false,
			'started_at'       => current_time( 'mysql' ),
			'last_activity'    => current_time( 'mysql' ),
			'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + 86400 ), // 24h expiration.
		);

		// Store session.
		$stored = $this->store_session( $session_data );

		if ( ! $stored ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create session',
			);
		}

		return array(
			'success'        => true,
			'session_id'     => $session_id,
			'plan_id'        => $plan_id,
			'status'         => 'active',
			'max_iterations' => $max_iterations,
			'token_budget'   => $token_budget,
			'expires_at'     => $session_data['expires_at'],
			'message'        => sprintf(
				'Started autonomous session %s for task plan #%d',
				substr( $session_id, 0, 8 ),
				$plan_id
			),
		);
	}

	/**
	 * Pause session
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function pause_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		$session_id = $arguments['session_id'];
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		$updated = $this->update_session_storage(
			$session_id,
			array(
				'status'        => 'paused',
				'last_activity' => current_time( 'mysql' ),
			)
		);

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to pause session',
			);
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
			'status'     => 'paused',
			'message'    => sprintf( 'Paused session %s', substr( $session_id, 0, 8 ) ),
		);
	}

	/**
	 * Resume session
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function resume_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		$session_id = $arguments['session_id'];
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		// Check if expired.
		if ( ! empty( $session['expires_at'] ) && strtotime( $session['expires_at'] ) < time() ) {
			return array(
				'success' => false,
				'error'   => 'Session has expired',
			);
		}

		$updated = $this->update_session_storage(
			$session_id,
			array(
				'status'        => 'active',
				'last_activity' => current_time( 'mysql' ),
			)
		);

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to resume session',
			);
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
			'status'     => 'active',
			'message'    => sprintf( 'Resumed session %s', substr( $session_id, 0, 8 ) ),
		);
	}

	/**
	 * Stop session
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function stop_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		$session_id = $arguments['session_id'];
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		$updated = $this->update_session_storage(
			$session_id,
			array(
				'status'        => 'completed',
				'last_activity' => current_time( 'mysql' ),
				'completed_at'  => current_time( 'mysql' ),
			)
		);

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to stop session',
			);
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
			'status'     => 'completed',
			'message'    => sprintf( 'Stopped session %s', substr( $session_id, 0, 8 ) ),
		);
	}

	/**
	 * Update session metrics
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function update_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		if ( empty( $arguments['metrics'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: metrics',
			);
		}

		$session_id = $arguments['session_id'];
		$metrics    = $arguments['metrics'];

		$session = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		// Prepare update data.
		$update_data = array( 'last_activity' => current_time( 'mysql' ) );

		if ( isset( $metrics['iteration_count'] ) ) {
			$update_data['iteration_count'] = intval( $metrics['iteration_count'] );
		}
		if ( isset( $metrics['token_usage'] ) ) {
			$update_data['token_usage'] = intval( $metrics['token_usage'] );
		}
		if ( isset( $metrics['success_rate'] ) ) {
			$update_data['success_rate'] = floatval( $metrics['success_rate'] );
		}
		if ( isset( $metrics['error_count'] ) ) {
			$update_data['error_count'] = intval( $metrics['error_count'] );
		}
		if ( isset( $metrics['last_tool'] ) ) {
			$update_data['last_tool'] = sanitize_text_field( $metrics['last_tool'] );
		}
		if ( isset( $metrics['last_error'] ) ) {
			$update_data['last_error'] = sanitize_textarea_field( $metrics['last_error'] );
		}

		$updated = $this->update_session_storage( $session_id, $update_data );

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update session',
			);
		}

		return array(
			'success'    => true,
			'session_id' => $session_id,
			'updated'    => array_keys( $update_data ),
			'message'    => sprintf( 'Updated session %s metrics', substr( $session_id, 0, 8 ) ),
		);
	}

	/**
	 * Store session
	 *
	 * @param array $session_data Session data.
	 * @return bool
	 */
	private function store_session( $session_data ) {
		// For now, store in transients (24h TTL).
		// In Phase 2, we'll use CCT or custom table.
		$transient_key = 'mcp_ai_session_' . $session_data['session_id'];
		return set_transient( $transient_key, $session_data, 86400 );
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
	 * Update session storage
	 *
	 * @param string $session_id Session ID.
	 * @param array  $data       Data to update.
	 * @return bool
	 */
	private function update_session_storage( $session_id, $data ) {
		$session = $this->get_session( $session_id );
		if ( ! $session ) {
			return false;
		}

		$session = array_merge( $session, $data );
		return $this->store_session( $session );
	}
}
