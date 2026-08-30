<?php
/**
 * Tool: Manage Autonomous Session
 *
 * Manages lifecycle of autonomous orchestration sessions.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Autonomous Session Tool
 */
class WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session {

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
	 * @return array|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action     = $arguments['action'];
		$start_time = microtime( true );

		switch ( $action ) {
			case 'start':
				$result = $this->start_session( $arguments, $context );
				break;

			case 'pause':
				$result = $this->pause_session( $arguments );
				break;

			case 'resume':
				$result = $this->resume_session( $arguments );
				break;

			case 'stop':
				$result = $this->stop_session( $arguments );
				break;

			case 'update':
				$result = $this->update_session( $arguments );
				break;

			default:
				$result = new \WP_Error(
					'tool_error',
					sprintf( 'Unknown action: %s', $action )
				);
				break;
		}

		// Log tool execution to history CCT.
		$this->log_execution( $arguments, $result, $start_time );

		return $result;
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
			return new \WP_Error(
				'missing_plan_id',
				__( 'Missing required argument: plan_id', 'mcp-ai-wpoos' )
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
			return new \WP_Error(
				'session_store_failed',
				__( 'Failed to create session', 'mcp-ai-wpoos' )
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
			return new \WP_Error(
				'missing_session_id',
				__( 'Missing required argument: session_id', 'mcp-ai-wpoos' )
			);
		}

		$session_id = $arguments['session_id'];
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return new \WP_Error(
				'session_not_found',
				sprintf(
					/* translators: %s: session ID */
					__( 'Session %s not found', 'mcp-ai-wpoos' ),
					$session_id
				)
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
			return new \WP_Error(
				'session_pause_failed',
				__( 'Failed to pause session', 'mcp-ai-wpoos' )
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
	 * @return array|\WP_Error
	 */
	private function resume_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return new \WP_Error(
				'missing_session_id',
				__( 'Missing required argument: session_id', 'mcp-ai-wpoos' )
			);
		}

		$session_id = $arguments['session_id'];
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return new \WP_Error(
				'session_not_found',
				sprintf(
					/* translators: %s: session ID */
					__( 'Session %s not found', 'mcp-ai-wpoos' ),
					$session_id
				)
			);
		}

		// Check if expired.
		if ( ! empty( $session['expires_at'] ) && strtotime( $session['expires_at'] ) < time() ) {
			return new \WP_Error(
				'session_expired',
				__( 'Session has expired', 'mcp-ai-wpoos' )
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
			return new \WP_Error(
				'session_resume_failed',
				__( 'Failed to resume session', 'mcp-ai-wpoos' )
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
	 * @return array|\WP_Error
	 */
	private function stop_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return new \WP_Error(
				'missing_session_id',
				__( 'Missing required argument: session_id', 'mcp-ai-wpoos' )
			);
		}

		$session_id = $arguments['session_id'];
		$session    = $this->get_session( $session_id );

		if ( ! $session ) {
			return new \WP_Error(
				'session_not_found',
				sprintf(
					/* translators: %s: session ID */
					__( 'Session %s not found', 'mcp-ai-wpoos' ),
					$session_id
				)
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
			return new \WP_Error(
				'session_stop_failed',
				__( 'Failed to stop session', 'mcp-ai-wpoos' )
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
	 * @return array|\WP_Error
	 */
	private function update_session( $arguments ) {
		if ( empty( $arguments['session_id'] ) ) {
			return new \WP_Error(
				'missing_session_id',
				__( 'Missing required argument: session_id', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['metrics'] ) ) {
			return new \WP_Error(
				'missing_metrics',
				__( 'Missing required argument: metrics', 'mcp-ai-wpoos' )
			);
		}

		$session_id = $arguments['session_id'];
		$metrics    = $arguments['metrics'];

		$session = $this->get_session( $session_id );

		if ( ! $session ) {
			return new \WP_Error(
				'session_not_found',
				sprintf(
					/* translators: %s: session ID */
					__( 'Session %s not found', 'mcp-ai-wpoos' ),
					$session_id
				)
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
			return new \WP_Error(
				'session_update_failed',
				__( 'Failed to update session', 'mcp-ai-wpoos' )
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
	 * Store session — CCT-first with transient fallback.
	 *
	 * @param array $session_data Session data.
	 * @return bool
	 */
	private function store_session( $session_data ) {
		// Always write to transient as a fast hot-read cache.
		$transient_key = 'mcp_ai_session_' . $session_data['session_id'];
		$transient_ok  = set_transient( $transient_key, $session_data, 86400 );

		// Write to CCT for durable storage if available.
		if ( $this->is_cct_available() ) {
			WP_MCP_AI_Autonomous_Sessions_CCT::upsert_session( $session_data );
		}

		return $transient_ok;
	}

	/**
	 * Get session — CCT-first with transient fallback.
	 *
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	private function get_session( $session_id ) {
		// Try durable CCT first.
		if ( $this->is_cct_available() ) {
			$cct_session = WP_MCP_AI_Autonomous_Sessions_CCT::get_session_by_id( $session_id );
			if ( $cct_session ) {
				return self::map_cct_to_session_array( $cct_session );
			}
		}

		// Fallback to transient.
		$transient_key = 'mcp_ai_session_' . $session_id;
		$session       = get_transient( $transient_key );
		return $session ? $session : null;
	}

	/**
	 * Update session storage — CCT-first with transient fallback.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $data       Data to update.
	 * @return bool
	 */
	private function update_session_storage( $session_id, $data ) {
		// Update transient (always).
		$session = $this->get_session( $session_id );
		if ( ! $session ) {
			return false;
		}

		$session       = array_merge( $session, $data );
		$transient_key = 'mcp_ai_session_' . $session_id;
		$transient_ok  = set_transient( $transient_key, $session, 86400 );

		// Update CCT if available.
		if ( $this->is_cct_available() ) {
			WP_MCP_AI_Autonomous_Sessions_CCT::update_session( $session_id, $data );
		}

		return $transient_ok;
	}

	/**
	 * Check if the autonomous sessions CCT is available for read/write.
	 *
	 * @return bool
	 */
	private function is_cct_available() {
		return class_exists( 'WP_MCP_AI_Autonomous_Sessions_CCT' )
			&& WP_MCP_AI_Autonomous_Sessions_CCT::is_available();
	}

	/**
	 * Map a CCT flat record back to the transient-style session array
	 * expected by the rest of the tool code.
	 *
	 * @param array $cct_row Raw CCT row from get_session_by_id().
	 * @return array Session data with transient-compatible keys.
	 */
	private static function map_cct_to_session_array( array $cct_row ) {
		$session = array();

		// Direct 1:1 mappings.
		$direct = array(
			'session_id'   => 'session_id',
			'plan_id'      => 'plan_id',
			'status'       => 'status',
			'assistant_id' => 'assistant_id',
		);
		foreach ( $direct as $cct_key => $session_key ) {
			if ( isset( $cct_row[ $cct_key ] ) ) {
				$session[ $session_key ] = $cct_row[ $cct_key ];
			}
		}

		// Renamed keys.
		if ( isset( $cct_row['health'] ) ) {
			$session['health_status'] = $cct_row['health'];
		}
		if ( isset( $cct_row['iterations'] ) ) {
			$session['iteration_count'] = (int) $cct_row['iterations'];
		}
		if ( isset( $cct_row['tokens_used'] ) ) {
			$session['token_usage'] = (int) $cct_row['tokens_used'];
		}
		if ( isset( $cct_row['start_time'] ) ) {
			$session['started_at'] = $cct_row['start_time'];
		}
		if ( isset( $cct_row['max_iterations'] ) ) {
			$session['max_iterations'] = (int) $cct_row['max_iterations'];
		}
		if ( isset( $cct_row['token_budget'] ) ) {
			$session['token_budget'] = (int) $cct_row['token_budget'];
		}
		if ( isset( $cct_row['completion_score'] ) ) {
			$session['completion_score'] = (int) $cct_row['completion_score'];
		}
		if ( isset( $cct_row['circuit_breaker_open'] ) ) {
			$session['circuit_breaker'] = $cct_row['circuit_breaker_open'] ? 'open' : 'closed';
		}
		if ( isset( $cct_row['exit_signal'] ) ) {
			$session['exit_signal'] = (bool) $cct_row['exit_signal'];
		}
		if ( isset( $cct_row['last_activity'] ) ) {
			$session['last_activity'] = $cct_row['last_activity'];
		}
		if ( isset( $cct_row['expires_at'] ) ) {
			$session['expires_at'] = $cct_row['expires_at'];
		}
		if ( isset( $cct_row['stop_reason'] ) ) {
			$session['stop_reason'] = $cct_row['stop_reason'];
		}

		// Hydrate extra fields from the JSON metadata column.
		if ( ! empty( $cct_row['metadata'] ) ) {
			$meta = json_decode( $cct_row['metadata'], true );
			if ( is_array( $meta ) ) {
				$meta_keys = array( 'user_id', 'error_count', 'success_rate', 'last_tool', 'last_error', 'completed_at' );
				foreach ( $meta_keys as $key ) {
					if ( isset( $meta[ $key ] ) ) {
						$session[ $key ] = $meta[ $key ];
					}
				}
			}
		}

		return $session;
	}

	/**
	 * Log tool execution to the execution history CCT.
	 *
	 * @param array           $arguments  Tool arguments.
	 * @param array|\WP_Error $result     Execution result.
	 * @param float           $start_time Microtime when execution began.
	 */
	private function log_execution( array $arguments, $result, $start_time ) {
		if ( ! class_exists( 'WP_MCP_AI_Execution_Logger' ) ) {
			return;
		}

		$session_id = ! empty( $arguments['session_id'] ) ? $arguments['session_id'] : '';
		$duration   = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$success = false;
			$message = '';
			$error   = $result->get_error_message();
		} else {
			$success = ! empty( $result['success'] );
			$message = ! empty( $result['message'] ) ? $result['message'] : '';
			$error   = ! $success && ! empty( $result['error'] ) ? $result['error'] : '';
		}

		WP_MCP_AI_Execution_Logger::log_tool_call(
			array(
				'session_id'     => $session_id,
				'iteration'      => 0,
				'tool_name'      => 'manage_autonomous_session',
				'success'        => $success,
				'duration_ms'    => $duration,
				'input_summary'  => ! empty( $arguments['action'] ) ? 'Action: ' . sanitize_text_field( $arguments['action'] ) : '',
				'output_summary' => sanitize_text_field( $message ),
				'error_message'  => sanitize_text_field( $error ),
			)
		);
	}
}
