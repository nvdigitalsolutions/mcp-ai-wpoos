<?php
/**
 * Agent Communication Service
 *
 * Manages structured message passing and coordination between agents.
 * Inspired by DeepSeek V4's multi-agent communication patterns.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Communication Service class
 *
 * Handles:
 * - Task delegation between agents
 * - Result aggregation from multiple agents
 * - Context sharing and propagation
 * - Message queue management
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Communication_Service {

	/**
	 * Cron hook for processing pending delegations.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_process_delegation';

	/**
	 * Whether the cron hook has been registered.
	 *
	 * @since 1.1.0
	 * @var bool
	 */
	private static $cron_registered = false;

	/**
	 * Bootstrap the delegation processing system.
	 *
	 * Registers the cron hook that processes pending delegations.
	 * Called once per request.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function init() {
		if ( self::$cron_registered ) {
			return;
		}
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_pending_delegation' ) );
		self::$cron_registered = true;
	}

	/**
	 * Delegate a task to another agent
	 *
	 * @param int|string $from_agent_id Source agent ID (integer post ID or string virtual ID).
	 * @param int|string $to_agent_id Target agent ID (integer post ID or string virtual ID).
	 * @param array      $task Task data to delegate.
	 * @param array      $context Shared context.
	 * @return array|WP_Error Delegation result or error.
	 */
	public function delegate_task( $from_agent_id, $to_agent_id, $task, $context = array() ) {
		// Validate target agent (required).
		if ( empty( $to_agent_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_agent_id',
				__( 'Valid agent IDs required for delegation.', 'mcp-ai-wpoos' )
			);
		}

		// Check if target is a virtual agent.
		$is_virtual_target = is_string( $to_agent_id ) && 0 === strpos( $to_agent_id, 'virtual_' );

		// For virtual agents, validate they exist in a team context.
		if ( $is_virtual_target ) {
			$virtual_agent = $this->get_virtual_agent_data( $to_agent_id, $context );
			if ( ! $virtual_agent ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_virtual_agent',
					__( 'Virtual agent not found. Ensure the agent was created via create_agent_team.', 'mcp-ai-wpoos' )
				);
			}
			$to_agent_name = $virtual_agent['name'];
			$to_agent_role = isset( $virtual_agent['role'] ) ? $virtual_agent['role'] : 'unknown';
		} else {
			// Real agent - validate as WordPress post.
			$to_agent_id = absint( $to_agent_id );
			if ( ! $to_agent_id ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_agent_id',
					__( 'Valid agent IDs required for delegation.', 'mcp-ai-wpoos' )
				);
			}

			$to_agent = get_post( $to_agent_id );
			if ( ! $to_agent || 'mcp_ai_assistant' !== $to_agent->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_target_agent',
					__( 'Target agent not found or invalid.', 'mcp-ai-wpoos' )
				);
			}
			$to_agent_name = get_the_title( $to_agent_id );
			$to_agent_role = wp_mcp_ai_get_assistant_role( $to_agent_id );
			$to_agent_role = $to_agent_role ? $to_agent_role->get_role_name() : 'assistant';
		}

		// Validate source agent (can be 0 for system-level delegation).
		$is_virtual_source = is_string( $from_agent_id ) && 0 === strpos( $from_agent_id, 'virtual_' );

		if ( ! $is_virtual_source ) {
			$from_agent_id = absint( $from_agent_id );

			// Source validation is optional - 0 means system/user delegation.
			if ( $from_agent_id > 0 ) {
				$from_agent = get_post( $from_agent_id );
				if ( ! $from_agent || 'mcp_ai_assistant' !== $from_agent->post_type ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_source_agent',
						__( 'Source agent not found or invalid.', 'mcp-ai-wpoos' )
					);
				}

				// Verify source agent can delegate.
				$source_role = wp_mcp_ai_get_assistant_role( $from_agent_id );
				if ( $source_role && ! $source_role->can_delegate() ) {
					return new WP_Error(
						'wp_mcp_ai_cannot_delegate',
						sprintf(
							/* translators: %s: role name */
							__( 'Agent with role %s cannot delegate tasks.', 'mcp-ai-wpoos' ),
							$source_role->get_role_name()
						)
					);
				}
			}
		}

		// Create delegation record.
		$delegation = array(
			'delegation_id' => $this->generate_delegation_id(),
			'from_agent_id' => $from_agent_id,
			'to_agent_id'   => $to_agent_id,
			'task'          => $task,
			'context'       => $context,
			'status'        => 'pending',
			'created_at'    => current_time( 'mysql' ),
			'ttl'           => $this->get_delegation_ttl(),
		);

		// Store delegation in transient.
		$this->store_delegation( $delegation );

		// Log delegation.
		$this->log_delegation( $delegation, 'created' );

		// Schedule cron processing for this delegation.
		$this->schedule_delegation_processing( $delegation );

		// Register with Cron Manager for visibility in the Tasks drawer.
		$this->register_delegation_with_cron_manager( $delegation );

			return array(
				'delegation_id' => $delegation['delegation_id'],
				'status'        => 'delegated',
				'agent_id'      => $to_agent_id,
				'agent_name'    => $to_agent_name,
				'agent_role'    => $to_agent_role,
				'delegated_at'  => $delegation['created_at'],
				'message'       => __( 'Task successfully delegated to target agent.', 'mcp-ai-wpoos' ),
			);
	}

	/**
	 * Aggregate results from multiple agents
	 *
	 * @param array  $agent_results Array of results from different agents.
	 * @param string $strategy Aggregation strategy (consensus, weighted, hierarchical).
	 * @return array|WP_Error Aggregated result or error.
	 */
	public function aggregate_results( $agent_results, $strategy = 'consensus' ) {
		if ( empty( $agent_results ) || ! is_array( $agent_results ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_results',
				__( 'Valid agent results array required for aggregation.', 'mcp-ai-wpoos' )
			);
		}

		$strategy           = sanitize_key( $strategy );
		$allowed_strategies = array( 'consensus', 'weighted', 'hierarchical', 'first', 'best' );

		if ( ! in_array( $strategy, $allowed_strategies, true ) ) {
			$strategy = 'consensus';
		}

		// Apply aggregation strategy.
		switch ( $strategy ) {
			case 'weighted':
				$aggregated = $this->aggregate_weighted( $agent_results );
				break;

			case 'hierarchical':
				$aggregated = $this->aggregate_hierarchical( $agent_results );
				break;

			case 'first':
				$aggregated = $this->aggregate_first( $agent_results );
				break;

			case 'best':
				$aggregated = $this->aggregate_best( $agent_results );
				break;

			case 'consensus':
			default:
				$aggregated = $this->aggregate_consensus( $agent_results );
				break;
		}

		// Wrap with metadata.
		return array(
			'aggregation_id' => uniqid( 'agg_', true ),
			'strategy'       => $strategy,
			'agent_count'    => count( $agent_results ),
			'result'         => $aggregated,
			'aggregated_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Share context between agents
	 *
	 * @param int   $source_agent_id Source agent ID.
	 * @param array $target_agent_ids Array of target agent IDs.
	 * @param array $context_items Context data to share.
	 * @return array|WP_Error Sharing result or error.
	 */
	public function share_context( $source_agent_id, $target_agent_ids, $context_items ) {
		$source_agent_id = absint( $source_agent_id );

		if ( ! $source_agent_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_source_agent',
				__( 'Valid source agent ID required.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $target_agent_ids ) || ! is_array( $target_agent_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_target_agents',
				__( 'Valid target agent IDs array required.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $context_items ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_context',
				__( 'Context items cannot be empty.', 'mcp-ai-wpoos' )
			);
		}

		$shared_count = 0;
		$errors       = array();

		foreach ( $target_agent_ids as $target_id ) {
			$target_id = absint( $target_id );
			if ( ! $target_id ) {
				continue;
			}

			// Store context for target agent.
			$context_key = sprintf( 'wp_mcp_ai_shared_context_%d_from_%d', $target_id, $source_agent_id );
			$stored      = set_transient( $context_key, $context_items, HOUR_IN_SECONDS );

			if ( $stored ) {
				++$shared_count;
			} else {
				$errors[] = $target_id;
			}
		}

		return array(
			'shared_count' => $shared_count,
			'total_count'  => count( $target_agent_ids ),
			'errors'       => $errors,
			'success'      => $shared_count > 0,
		);
	}

	/**
	 * Aggregate results using consensus strategy
	 *
	 * Simple majority or averaging approach.
	 *
	 * @param array $results Agent results.
	 * @return mixed Aggregated result.
	 */
	protected function aggregate_consensus( $results ) {
		// For now, concatenate all results.
		// In production, this would use more sophisticated consensus logic.
		$combined = array();

		foreach ( $results as $result ) {
			if ( isset( $result['result'] ) ) {
				$combined[] = $result['result'];
			}
		}

		return array(
			'type'    => 'consensus',
			'results' => $combined,
			'summary' => __( 'Combined results from all participating agents.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Aggregate results using weighted strategy
	 *
	 * Weight results based on agent reliability or scores.
	 *
	 * @param array $results Agent results.
	 * @return mixed Aggregated result.
	 */
	protected function aggregate_weighted( $results ) {
		$weighted = array();

		foreach ( $results as $result ) {
			$weight = isset( $result['weight'] ) ? floatval( $result['weight'] ) : 1.0;

			$weighted[] = array(
				'result' => isset( $result['result'] ) ? $result['result'] : $result,
				'weight' => $weight,
			);
		}

		return array(
			'type'    => 'weighted',
			'results' => $weighted,
			'summary' => __( 'Results weighted by agent reliability or confidence scores.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Aggregate results using hierarchical strategy
	 *
	 * Prioritize results from higher-level agents.
	 *
	 * @param array $results Agent results.
	 * @return mixed Aggregated result.
	 */
	protected function aggregate_hierarchical( $results ) {
		// Sort by priority if available.
		usort(
			$results,
			function ( $a, $b ) {
				$priority_a = isset( $a['priority'] ) ? intval( $a['priority'] ) : 0;
				$priority_b = isset( $b['priority'] ) ? intval( $b['priority'] ) : 0;
				return $priority_b - $priority_a; // Higher priority first.
			}
		);

		return array(
			'type'           => 'hierarchical',
			'primary_result' => isset( $results[0]['result'] ) ? $results[0]['result'] : null,
			'all_results'    => $results,
			'summary'        => __( 'Results prioritized by agent hierarchy.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Aggregate by taking the first valid result
	 *
	 * @param array $results Agent results.
	 * @return mixed Aggregated result.
	 */
	protected function aggregate_first( $results ) {
		$first_result = ! empty( $results[0] ) ? $results[0] : null;

		return array(
			'type'    => 'first',
			'result'  => isset( $first_result['result'] ) ? $first_result['result'] : $first_result,
			'summary' => __( 'First valid result from agents.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Aggregate by selecting the best result
	 *
	 * Based on quality score or validation.
	 *
	 * @param array $results Agent results.
	 * @return mixed Aggregated result.
	 */
	protected function aggregate_best( $results ) {
		$best_result = null;
		$best_score  = -1;

		foreach ( $results as $result ) {
			$score = isset( $result['score'] ) ? floatval( $result['score'] ) : 0.5;

			if ( $score > $best_score ) {
				$best_score  = $score;
				$best_result = $result;
			}
		}

		return array(
			'type'        => 'best',
			'result'      => isset( $best_result['result'] ) ? $best_result['result'] : $best_result,
			'best_score'  => $best_score,
			'total_count' => count( $results ),
			'summary'     => __( 'Highest quality result selected from all agents.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Store delegation record
	 *
	 * @param array $delegation Delegation data.
	 */
	protected function store_delegation( $delegation ) {
		$key = 'wp_mcp_ai_delegation_' . $delegation['delegation_id'];
		set_transient( $key, $delegation, $delegation['ttl'] );
	}

	/**
	 * Get delegation TTL (time to live)
	 *
	 * @return int TTL in seconds.
	 */
	protected function get_delegation_ttl() {
		/**
		 * Filters the TTL for delegation records.
		 *
		 * @param int $ttl TTL in seconds. Default 1 hour.
		 */
		return apply_filters( 'wp_mcp_ai_delegation_ttl', HOUR_IN_SECONDS );
	}

	/**
	 * Generate a unique delegation ID
	 *
	 * @return string Delegation ID.
	 */
	protected function generate_delegation_id() {
		return 'del_' . wp_generate_uuid4();
	}

	/**
	 * Schedule a cron event to process this delegation.
	 *
	 * Ensures the cron hook is registered, then schedules a one-off
	 * event to execute immediately (on the next WP-Cron cycle).
	 *
	 * @since 1.1.0
	 *
	 * @param array $delegation Delegation record.
	 * @return void
	 */
	protected function schedule_delegation_processing( $delegation ) {
		self::init();

		$args = array( $delegation['delegation_id'] );

		// Avoid duplicate scheduling.
		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK, $args );

		// Trigger WordPress cron immediately so the delegation starts right away.
		// Without this, the delegation sits in the cron queue until the next
		// HTTP request, which may never arrive (especially on SSE connections).
		// This matches the pattern used by the VEO video generation service's
		// queue_async_polling() which also calls spawn_cron() after scheduling.
		spawn_cron();
	}

	/**
	 * Register the delegation with the Cron Manager for UI visibility.
	 *
	 * @since 1.1.0
	 *
	 * @param array $delegation Delegation record.
	 * @return void
	 */
	protected function register_delegation_with_cron_manager( $delegation ) {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			return;
		}

		$user_id = isset( $delegation['task']['delegated_by'] )
			? absint( $delegation['task']['delegated_by'] )
			: 0;

		// If delegated_by is a virtual agent or 0, try to get the current user.
		if ( 0 === $user_id && function_exists( 'get_current_user_id' ) ) {
			$user_id = get_current_user_id();
		}

		WP_MCP_AI_Cron_Manager::record_job(
			self::CRON_HOOK,
			array( $delegation['delegation_id'] ),
			'single',
			time(),
			$user_id
		);
	}

	/**
	 * Process a pending delegation (cron callback).
	 *
	 * Reads the delegation from its transient, executes the task via
	 * the target agent's role, and updates the delegation status.
	 *
	 * @since 1.1.0
	 *
	 * @param string $delegation_id The delegation identifier.
	 * @return void
	 */
	public static function process_pending_delegation( $delegation_id ) {
		$key  = 'wp_mcp_ai_delegation_' . $delegation_id;
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['to_agent_id'] ) ) {
			// Delegation expired or invalid — nothing to do.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'delegation_cron_miss',
					'Delegation not found for cron processing',
					array( 'delegation_id' => $delegation_id )
				);
			}
			return;
		}

		// Skip if already completed or failed.
		if ( ! empty( $data['status'] ) && 'pending' !== $data['status'] ) {
			return;
		}

		$to_agent_id = $data['to_agent_id'];
		$task_data   = isset( $data['task'] ) ? $data['task'] : array();
		$context     = isset( $data['context'] ) ? $data['context'] : array();

		// Mark as in-progress.
		$data['status']     = 'running';
		$data['started_at'] = current_time( 'mysql' );
		set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );

		// Log delegation execution start.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'delegation_execution_started',
				'Processing delegated task',
				array(
					'delegation_id' => $delegation_id,
					'to_agent_id'   => $to_agent_id,
				)
			);
		}

		// Virtual agents cannot be dispatched to the chat endpoint — they
		// only exist in team context. The rest of this method is for real
		// assistant post IDs only.
		$is_virtual = is_string( $to_agent_id ) && 0 === strpos( $to_agent_id, 'virtual_' );
		if ( $is_virtual ) {
			$data['status']       = 'failed';
			$data['error']        = __( 'Cannot process delegation for virtual agents via cron. Virtual agents must be handled within their team context.', 'mcp-ai-wpoos' );
			$data['completed_at'] = current_time( 'mysql' );
			set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );
			return;
		}

		$to_agent_id = absint( $to_agent_id );

		// Verify the target assistant post exists.
		$assistant_post = get_post( $to_agent_id );
		if ( ! $assistant_post || 'mcp_ai_assistant' !== $assistant_post->post_type || 'publish' !== $assistant_post->post_status ) {
			$data['status']       = 'failed';
			$data['error']        = __( 'Target assistant not found or not published.', 'mcp-ai-wpoos' );
			$data['completed_at'] = current_time( 'mysql' );
			set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );
			return;
		}

		// Build the chat message from the delegated task.
		$task_description = isset( $task_data['description'] ) ? $task_data['description'] : '';
		if ( '' === $task_description ) {
			$data['status']       = 'failed';
			$data['error']        = __( 'Delegated task has no description.', 'mcp-ai-wpoos' );
			$data['completed_at'] = current_time( 'mysql' );
			set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );
			return;
		}

		// Dispatch the assistant run via the internal REST API, following the
		// same pattern as WP_MCP_AI_Pro_Schedule_Manager::dispatch_assistant_run()
		// so the full agentic pipeline (AI model, tool execution, response
		// generation) is exercised.
		if ( ! function_exists( 'rest_do_request' ) ) {
			$data['status']       = 'failed';
			$data['error']        = __( 'REST API is not available for delegation processing.', 'mcp-ai-wpoos' );
			$data['completed_at'] = current_time( 'mysql' );
			set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );
			return;
		}

		// Pre-flight: verify the REST server is initialised so that
		// rest_do_request() does not throw a fatal error on a null server.
		// Matches the pattern in dispatch_assistant_run().
		$rest_server = rest_get_server();
		if ( ! $rest_server ) {
			$data['status']       = 'failed';
			$data['error']        = __( 'REST API server is not available.', 'mcp-ai-wpoos' );
			$data['completed_at'] = current_time( 'mysql' );
			set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );
			return;
		}

		// Resolve the user context: use the user who delegated, or the
		// current user, so the REST request inherits proper capabilities.
		$delegated_by  = isset( $data['task']['delegated_by'] ) ? absint( $data['task']['delegated_by'] ) : 0;
		$previous_user = get_current_user_id();
		if ( $delegated_by > 0 && $delegated_by !== $previous_user ) {
			wp_set_current_user( $delegated_by );
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $task_description,
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'assistant_id' => $to_agent_id,
				'messages'     => $messages,
				'stream'       => false,
				'context'      => array(
					'source'        => 'agent_delegation',
					'delegation_id' => $delegation_id,
					'delegated_by'  => isset( $data['from_agent_id'] ) ? $data['from_agent_id'] : 0,
				),
			)
		);

		try {
			$response = rest_do_request( $request );

			// Restore the previous user context.
			if ( $delegated_by > 0 && $delegated_by !== $previous_user ) {
				wp_set_current_user( $previous_user );
			}

			if ( $response->is_error() ) {
				$error_data     = $response->get_data();
				$data['status'] = 'failed';
				$data['error']  = isset( $error_data['message'] )
					? $error_data['message']
					: __( 'Chat API request failed.', 'mcp-ai-wpoos' );
			} else {
				$response_data = $response->get_data();

				// Extract the assistant reply using the same two-pass
				// extraction as the pro schedule manager.
				$reply = '';
				if ( isset( $response_data['data']['choices'] ) && is_array( $response_data['data']['choices'] ) ) {
					foreach ( $response_data['data']['choices'] as $choice ) {
						if ( isset( $choice['finish_reason'] ) && 'stop' === $choice['finish_reason']
							&& isset( $choice['message']['content'] )
						) {
							$reply = $choice['message']['content'];
							break;
						}
					}

					// Fallback: use the last choice's content.
					if ( '' === $reply ) {
						$last = end( $response_data['data']['choices'] );
						if ( isset( $last['message']['content'] ) ) {
							$reply = $last['message']['content'];
						}
					}
				}

				// Fallback: check agentic_tool_messages for content.
				if ( '' === $reply && isset( $response_data['agentic_tool_messages'] ) && is_array( $response_data['agentic_tool_messages'] ) ) {
					foreach ( $response_data['agentic_tool_messages'] as $msg ) {
						if ( isset( $msg['role'] ) && 'assistant' === $msg['role'] && ! empty( $msg['content'] ) ) {
							$reply = $msg['content'];
							break;
						}
					}
				}

				$data['status'] = 'completed';
				$data['result'] = array(
					'assistant_id' => $to_agent_id,
					'message'      => $task_description,
					'response'     => $reply,
				);
			}
		} catch ( \Throwable $e ) {
			// Restore the previous user context on exception.
			if ( $delegated_by > 0 && $delegated_by !== $previous_user ) {
				wp_set_current_user( $previous_user );
			}

			$data['status'] = 'failed';
			$data['error']  = sprintf(
				'%s in %s:%d',
				$e->getMessage(),
				str_replace( ABSPATH, '', $e->getFile() ),
				$e->getLine()
			);
		}

		$data['completed_at'] = current_time( 'mysql' );
		set_transient( $key, $data, isset( $data['ttl'] ) ? $data['ttl'] : HOUR_IN_SECONDS );

		// Log result.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'delegation_execution_finished',
				'Delegated task processing complete',
				array(
					'delegation_id' => $delegation_id,
					'status'        => $data['status'],
				)
			);
		}
	}

	/**
	 * Log delegation activity
	 *
	 * @param array  $delegation Delegation data.
	 * @param string $action Action performed (created, completed, failed).
	 */
	protected function log_delegation( $delegation, $action ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				sprintf( 'Agent delegation %s', $action ),
				array(
					'delegation_id' => $delegation['delegation_id'],
					'from_agent'    => $delegation['from_agent_id'],
					'to_agent'      => $delegation['to_agent_id'],
					'action'        => $action,
				)
			);
		}
	}

	/**
	 * Get virtual agent data from team context
	 *
	 * @param string $virtual_agent_id Virtual agent ID.
	 * @param array  $context Task context that may contain team_id.
	 * @return array|null Virtual agent data or null if not found.
	 */
	protected function get_virtual_agent_data( $virtual_agent_id, $context = array() ) {
		// Try to find the team ID in context.
		$team_id = null;
		if ( isset( $context['team_id'] ) ) {
			$team_id = $context['team_id'];
		} elseif ( isset( $context['parent_task_id'] ) ) {
			// Try to extract team ID from parent task ID if it follows team_xxx format.
			if ( 0 === strpos( $context['parent_task_id'], 'team_' ) ) {
				$team_id = $context['parent_task_id'];
			}
		}

		// If no team ID in context, search all recent teams.
		if ( ! $team_id ) {
			$team_id = $this->find_team_with_virtual_agent( $virtual_agent_id );
		}

		if ( ! $team_id ) {
			return null;
		}

		// Get team data from transient.
		$team = get_transient( 'wp_mcp_ai_team_' . $team_id );
		if ( ! $team || ! isset( $team['members'] ) ) {
			return null;
		}

		// Find the virtual agent in team members.
		foreach ( $team['members'] as $member ) {
			if ( isset( $member['id'] ) && $member['id'] === $virtual_agent_id ) {
				return $member;
			}
		}

		return null;
	}

	/**
	 * Find team containing a specific virtual agent
	 *
	 * Searches recent teams to find one that contains the virtual agent.
	 *
	 * @param string $virtual_agent_id Virtual agent ID to search for.
	 * @return string|null Team ID or null if not found.
	 */
	protected function find_team_with_virtual_agent( $virtual_agent_id ) {
		global $wpdb;

		// Search transients for recent teams.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				ORDER BY option_id DESC 
				LIMIT 20",
				'_transient_wp_mcp_ai_team_%'
			)
		);

		if ( empty( $transients ) ) {
			return null;
		}

		foreach ( $transients as $transient ) {
			$team = maybe_unserialize( $transient->option_value );
			if ( ! is_array( $team ) || ! isset( $team['members'] ) ) {
				continue;
			}

			// Check if this team has the virtual agent.
			foreach ( $team['members'] as $member ) {
				if ( isset( $member['id'] ) && $member['id'] === $virtual_agent_id ) {
					// Extract team ID from transient name.
					$team_id = str_replace( '_transient_wp_mcp_ai_team_', '', $transient->option_name );
					return $team_id;
				}
			}
		}

		return null;
	}
}
