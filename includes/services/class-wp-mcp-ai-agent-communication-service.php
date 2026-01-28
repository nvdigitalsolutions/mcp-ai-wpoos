<?php
/**
 * Agent Communication Service
 *
 * Manages structured message passing and coordination between agents.
 * Inspired by DeepSeek V4's multi-agent communication patterns.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
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
	 * Delegate a task to another agent
	 *
	 * @param int   $from_agent_id Source agent ID.
	 * @param int   $to_agent_id Target agent ID.
	 * @param array $task Task data to delegate.
	 * @param array $context Shared context.
	 * @return array|WP_Error Delegation result or error.
	 */
	public function delegate_task( $from_agent_id, $to_agent_id, $task, $context = array() ) {
		// Validate inputs.
		$from_agent_id = absint( $from_agent_id );
		$to_agent_id   = absint( $to_agent_id );

		if ( ! $from_agent_id || ! $to_agent_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_agent_id',
				__( 'Valid agent IDs required for delegation.', 'mcp-ai-wpoos' )
			);
		}

		// Verify both agents exist.
		$from_agent = get_post( $from_agent_id );
		$to_agent   = get_post( $to_agent_id );

		if ( ! $from_agent || 'mcp_ai_assistant' !== $from_agent->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_source_agent',
				__( 'Source agent not found or invalid.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! $to_agent || 'mcp_ai_assistant' !== $to_agent->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_target_agent',
				__( 'Target agent not found or invalid.', 'mcp-ai-wpoos' )
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

		return array(
			'delegation_id' => $delegation['delegation_id'],
			'status'        => 'delegated',
			'to_agent'      => array(
				'id'    => $to_agent_id,
				'title' => get_the_title( $to_agent_id ),
			),
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
}
