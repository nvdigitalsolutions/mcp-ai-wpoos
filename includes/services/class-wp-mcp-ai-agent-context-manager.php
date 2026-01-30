<?php
/**
 * Agent Context Manager Service.
 *
 * Centralized service for managing agent context and memory.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 5).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages agent context and memory operations.
 *
 * Provides centralized context management including storage, retrieval,
 * prioritization, session recovery, and automatic pruning of expired contexts.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Context_Manager {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Agent_Context_Manager|null
	 */
	private static $instance = null;

	/**
	 * Context prefix for transient keys.
	 *
	 * @var string
	 */
	const CONTEXT_PREFIX = 'mcp_ai_ctx_';

	/**
	 * Context index prefix for transient keys.
	 *
	 * @var string
	 */
	const INDEX_PREFIX = 'mcp_ai_ctx_index_';

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Agent_Context_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		// Hook into WP cron for automatic context pruning.
		add_action( 'wp_mcp_ai_prune_expired_contexts', array( $this, 'prune_expired_contexts' ) );
		
		// Schedule daily pruning if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_prune_expired_contexts' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_prune_expired_contexts' );
		}
	}

	/**
	 * Store agent context.
	 *
	 * @param int|string $agent_id      Agent identifier.
	 * @param string     $context_type  Type of context.
	 * @param array      $context_data  Context data.
	 * @param int        $ttl           Time to live in seconds.
	 * @return array Storage result with context ID.
	 */
	public function store_context( $agent_id, $context_type, $context_data, $ttl = DAY_IN_SECONDS ) {
		// Validate inputs.
		if ( empty( $agent_id ) || empty( $context_type ) || empty( $context_data ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent ID, context type, and context data are required.', 'mcp-ai-wpoos' ),
			);
		}

		// Validate TTL bounds (1 hour to 1 year).
		$ttl = max( 3600, min( 31536000, absint( $ttl ) ) );

		// Generate unique context ID.
		$context_id = 'ctx_' . wp_generate_password( 12, false );

		// Prepare context record.
		$context_record = array(
			'context_id'   => $context_id,
			'agent_id'     => $agent_id,
			'context_type' => sanitize_key( $context_type ),
			'data'         => $context_data,
			'stored_at'    => current_time( 'mysql' ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'ttl'          => $ttl,
		);

		// Store context.
		$transient_key = self::CONTEXT_PREFIX . md5( $agent_id . '_' . $context_id );
		set_transient( $transient_key, $context_record, $ttl );

		// Update context index.
		$this->update_context_index( $agent_id, $context_id, $context_record, $ttl );

		return array(
			'success'    => true,
			'context_id' => $context_id,
			'stored_at'  => $context_record['stored_at'],
			'expires_at' => $context_record['expires_at'],
		);
	}

	/**
	 * Retrieve agent context by ID.
	 *
	 * @param int|string $agent_id         Agent identifier.
	 * @param string     $context_id       Context ID.
	 * @param bool       $include_expired  Whether to include expired contexts.
	 * @return array|null Context record or null if not found.
	 */
	public function retrieve_context( $agent_id, $context_id, $include_expired = false ) {
		$transient_key  = self::CONTEXT_PREFIX . md5( $agent_id . '_' . $context_id );
		$context_record = get_transient( $transient_key );

		if ( ! $context_record ) {
			return null;
		}

		// Check expiration.
		if ( ! $include_expired && isset( $context_record['expires_at'] ) ) {
			$expires_timestamp = strtotime( $context_record['expires_at'] );
			if ( $expires_timestamp && time() > $expires_timestamp ) {
				return null;
			}
		}

		return $context_record;
	}

	/**
	 * Search contexts for an agent.
	 *
	 * @param int|string $agent_id         Agent identifier.
	 * @param array      $filters          Search filters.
	 * @param int        $limit            Maximum results.
	 * @param bool       $include_expired  Whether to include expired contexts.
	 * @return array Array of context records.
	 */
	public function search_contexts( $agent_id, $filters = array(), $limit = 10, $include_expired = false ) {
		$index_key     = self::INDEX_PREFIX . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) || empty( $context_index ) ) {
			return array();
		}

		$results = array();
		foreach ( $context_index as $ctx_id => $index_entry ) {
			// Check expiration.
			if ( ! $include_expired && isset( $index_entry['expires_at'] ) ) {
				$expires_timestamp = strtotime( $index_entry['expires_at'] );
				if ( $expires_timestamp && time() > $expires_timestamp ) {
					continue;
				}
			}

			// Get full context record.
			$context_record = $this->retrieve_context( $agent_id, $ctx_id, $include_expired );
			if ( $context_record ) {
				$results[] = $context_record;
			}

			// Limit results.
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Recover session state for an agent.
	 *
	 * @param int|string $agent_id    Agent identifier.
	 * @param string     $session_id  Optional session identifier.
	 * @return array Session data including context and history.
	 */
	public function recover_session( $agent_id, $session_id = null ) {
		// Get all contexts for the agent.
		$contexts = $this->search_contexts( $agent_id, array(), 50 );

		// Group contexts by type.
		$grouped = array();
		foreach ( $contexts as $context ) {
			$type = $context['context_type'];
			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = array();
			}
			$grouped[ $type ][] = $context;
		}

		// Build session data.
		$session_data = array(
			'agent_id'        => $agent_id,
			'session_id'      => $session_id ? $session_id : 'session_' . time(),
			'context_count'   => count( $contexts ),
			'contexts'        => $contexts,
			'contexts_by_type' => $grouped,
			'recovered_at'    => current_time( 'mysql' ),
		);

		return $session_data;
	}

	/**
	 * Prioritize context items for token budget.
	 *
	 * @param array $context_items  Array of context items.
	 * @param int   $token_budget   Token budget.
	 * @param array $current_task   Current task description.
	 * @param array $weights        Scoring weights.
	 * @return array Prioritized context items.
	 */
	public function prioritize_context( $context_items, $token_budget, $current_task = array(), $weights = array() ) {
		// Use the prioritize_context tool if available.
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool = $tool_registry->get_tool( 'prioritize_context' );

		if ( $tool ) {
			$result = $tool->execute(
				array(
					'context_items' => $context_items,
					'token_budget'  => $token_budget,
					'current_task'  => $current_task,
					'weights'       => $weights,
				)
			);

			if ( isset( $result['prioritized'] ) ) {
				return $result['prioritized'];
			}
		}

		// Fallback: return all items if tool not available.
		return $context_items;
	}

	/**
	 * Prune expired contexts.
	 *
	 * Removes expired contexts from storage to free up space.
	 * Runs automatically via WP Cron.
	 *
	 * @return array Pruning statistics.
	 */
	public function prune_expired_contexts() {
		global $wpdb;

		$pruned_count = 0;
		$current_time = time();

		// Find all context transients.
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_name NOT LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CONTEXT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%'
			)
		);

		foreach ( $transients as $transient ) {
			$transient_key = str_replace( '_transient_', '', $transient->option_name );
			$context_data = maybe_unserialize( $transient->option_value );

			// Check if expired.
			if ( isset( $context_data['expires_at'] ) ) {
				$expires_timestamp = strtotime( $context_data['expires_at'] );
				if ( $expires_timestamp && $current_time > $expires_timestamp ) {
					// Delete expired context.
					delete_transient( $transient_key );
					$pruned_count++;
				}
			}
		}

		// Also clean up context indexes.
		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::INDEX_PREFIX ) . '%'
			)
		);

		foreach ( $indexes as $index ) {
			$index_key = str_replace( '_transient_', '', $index->option_name );
			$index_data = maybe_unserialize( $index->option_value );

			if ( is_array( $index_data ) ) {
				$updated = false;
				foreach ( $index_data as $ctx_id => $entry ) {
					if ( isset( $entry['expires_at'] ) ) {
						$expires_timestamp = strtotime( $entry['expires_at'] );
						if ( $expires_timestamp && $current_time > $expires_timestamp ) {
							unset( $index_data[ $ctx_id ] );
							$updated = true;
						}
					}
				}

				// Update index if modified.
				if ( $updated ) {
					if ( empty( $index_data ) ) {
						delete_transient( $index_key );
					} else {
						set_transient( $index_key, $index_data, MONTH_IN_SECONDS );
					}
				}
			}
		}

		return array(
			'pruned_count' => $pruned_count,
			'pruned_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Update context index for an agent.
	 *
	 * @param int|string $agent_id        Agent identifier.
	 * @param string     $context_id      Context ID.
	 * @param array      $context_record  Context record.
	 * @param int        $ttl              Time to live in seconds.
	 */
	private function update_context_index( $agent_id, $context_id, $context_record, $ttl ) {
		$index_key     = self::INDEX_PREFIX . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) ) {
			$context_index = array();
		}

		// Add to index with expiry time.
		$context_index[ $context_id ] = array(
			'type'       => $context_record['context_type'],
			'title'      => isset( $context_record['data']['title'] ) ? $context_record['data']['title'] : '',
			'stored_at'  => $context_record['stored_at'],
			'expires_at' => $context_record['expires_at'],
			'importance' => isset( $context_record['data']['importance'] ) ? $context_record['data']['importance'] : 'medium',
			'tags'       => isset( $context_record['data']['tags'] ) ? $context_record['data']['tags'] : array(),
		);

		// Store index with same TTL.
		set_transient( $index_key, $context_index, $ttl );
	}

	/**
	 * Get context statistics for an agent.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @return array Context statistics.
	 */
	public function get_context_stats( $agent_id ) {
		$index_key     = self::INDEX_PREFIX . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) || empty( $context_index ) ) {
			return array(
				'total_count'   => 0,
				'by_type'       => array(),
				'by_importance' => array(),
			);
		}

		$stats = array(
			'total_count'   => count( $context_index ),
			'by_type'       => array(),
			'by_importance' => array(),
			'expired_count' => 0,
		);

		$current_time = time();

		foreach ( $context_index as $entry ) {
			// Count by type.
			$type = isset( $entry['type'] ) ? $entry['type'] : 'unknown';
			if ( ! isset( $stats['by_type'][ $type ] ) ) {
				$stats['by_type'][ $type ] = 0;
			}
			$stats['by_type'][ $type ]++;

			// Count by importance.
			$importance = isset( $entry['importance'] ) ? $entry['importance'] : 'medium';
			if ( ! isset( $stats['by_importance'][ $importance ] ) ) {
				$stats['by_importance'][ $importance ] = 0;
			}
			$stats['by_importance'][ $importance ]++;

			// Count expired.
			if ( isset( $entry['expires_at'] ) ) {
				$expires_timestamp = strtotime( $entry['expires_at'] );
				if ( $expires_timestamp && $current_time > $expires_timestamp ) {
					$stats['expired_count']++;
				}
			}
		}

		return $stats;
	}

	/**
	 * Clear all contexts for an agent.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @return array Operation result.
	 */
	public function clear_agent_contexts( $agent_id ) {
		$index_key     = self::INDEX_PREFIX . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) ) {
			return array(
				'success' => true,
				'deleted' => 0,
			);
		}

		$deleted_count = 0;

		// Delete each context.
		foreach ( $context_index as $ctx_id => $entry ) {
			$transient_key = self::CONTEXT_PREFIX . md5( $agent_id . '_' . $ctx_id );
			delete_transient( $transient_key );
			$deleted_count++;
		}

		// Delete the index.
		delete_transient( $index_key );

		return array(
			'success' => true,
			'deleted' => $deleted_count,
		);
	}
}
