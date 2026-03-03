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
			'context_id'    => $context_id,
			'agent_id'      => $agent_id,
			'context_type'  => sanitize_key( $context_type ),
			'data'          => $context_data,
			'stored_at'     => current_time( 'mysql' ),
			'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'ttl'           => $ttl,
			'access_count'  => 0,
			'last_accessed' => null,
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
			'agent_id'         => $agent_id,
			'session_id'       => $session_id ? $session_id : 'session_' . time(),
			'context_count'    => count( $contexts ),
			'contexts'         => $contexts,
			'contexts_by_type' => $grouped,
			'recovered_at'     => current_time( 'mysql' ),
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
		$tool          = $tool_registry->get_tool( 'prioritize_context' );

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
			$context_data  = maybe_unserialize( $transient->option_value );

			// Check if expired.
			if ( isset( $context_data['expires_at'] ) ) {
				$expires_timestamp = strtotime( $context_data['expires_at'] );
				if ( $expires_timestamp && $current_time > $expires_timestamp ) {
					// Delete expired context.
					delete_transient( $transient_key );
					++$pruned_count;
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
			$index_key  = str_replace( '_transient_', '', $index->option_name );
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
			++$stats['by_type'][ $type ];

			// Count by importance.
			$importance = isset( $entry['importance'] ) ? $entry['importance'] : 'medium';
			if ( ! isset( $stats['by_importance'][ $importance ] ) ) {
				$stats['by_importance'][ $importance ] = 0;
			}
			++$stats['by_importance'][ $importance ];

			// Count expired.
			if ( isset( $entry['expires_at'] ) ) {
				$expires_timestamp = strtotime( $entry['expires_at'] );
				if ( $expires_timestamp && $current_time > $expires_timestamp ) {
					++$stats['expired_count'];
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
			++$deleted_count;
		}

		// Delete the index.
		delete_transient( $index_key );

		return array(
			'success' => true,
			'deleted' => $deleted_count,
		);
	}

	/**
	 * Update an existing context.
	 *
	 * @param int|string $agent_id     Agent identifier.
	 * @param string     $context_id   Context ID.
	 * @param array      $updated_data Updated data fields.
	 * @return array Operation result.
	 */
	public function update_context( $agent_id, $context_id, $updated_data ) {
		$context = $this->retrieve_context( $agent_id, $context_id, false );

		if ( ! $context ) {
			return array(
				'success' => false,
				'message' => __( 'Context not found or has expired.', 'mcp-ai-wpoos' ),
			);
		}

		// Update the context data.
		foreach ( $updated_data as $key => $value ) {
			if ( isset( $context['data'][ $key ] ) || in_array( $key, array( 'title', 'content', 'metadata', 'tags', 'importance' ), true ) ) {
				$context['data'][ $key ] = $value;
			}
		}

		// Add update metadata.
		if ( ! isset( $context['data']['metadata'] ) ) {
			$context['data']['metadata'] = array();
		}
		$context['data']['metadata']['last_updated'] = current_time( 'mysql' );

		// Re-save the context.
		$remaining_ttl = strtotime( $context['expires_at'] ) - time();
		if ( $remaining_ttl > 0 ) {
			$transient_key = self::CONTEXT_PREFIX . md5( $agent_id . '_' . $context_id );
			set_transient( $transient_key, $context, $remaining_ttl );

			return array(
				'success'    => true,
				'context_id' => $context_id,
				'updated_at' => $context['data']['metadata']['last_updated'],
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Context has expired.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Delete a specific context.
	 *
	 * @param int|string $agent_id   Agent identifier.
	 * @param string     $context_id Context ID.
	 * @return array Operation result.
	 */
	public function delete_context( $agent_id, $context_id ) {
		$context = $this->retrieve_context( $agent_id, $context_id, true );

		if ( ! $context ) {
			return array(
				'success' => false,
				'message' => __( 'Context not found.', 'mcp-ai-wpoos' ),
			);
		}

		// Delete the context.
		$transient_key = self::CONTEXT_PREFIX . md5( $agent_id . '_' . $context_id );
		$deleted       = delete_transient( $transient_key );

		// Update index.
		$index_key     = self::INDEX_PREFIX . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( is_array( $context_index ) && isset( $context_index[ $context_id ] ) ) {
			unset( $context_index[ $context_id ] );

			if ( empty( $context_index ) ) {
				delete_transient( $index_key );
			} else {
				set_transient( $index_key, $context_index, MONTH_IN_SECONDS );
			}
		}

		return array(
			'success'    => $deleted,
			'context_id' => $context_id,
		);
	}

	/**
	 * Track context access for frequency scoring.
	 *
	 * Updates access count and last accessed timestamp for a context.
	 * Implements frequency tracking as per RAG best practices.
	 *
	 * @param int|string $agent_id   Agent identifier.
	 * @param string     $context_id Context ID.
	 * @return bool Success status.
	 */
	public function track_context_access( $agent_id, $context_id ) {
		$transient_key  = self::CONTEXT_PREFIX . md5( $agent_id . '_' . $context_id );
		$context_record = get_transient( $transient_key );

		if ( ! $context_record ) {
			return false;
		}

		// Update access tracking.
		if ( ! isset( $context_record['access_count'] ) ) {
			$context_record['access_count'] = 0;
		}
		++$context_record['access_count'];
		$context_record['last_accessed'] = current_time( 'mysql' );

		// Re-save context with updated tracking.
		$remaining_ttl = strtotime( $context_record['expires_at'] ) - time();
		if ( $remaining_ttl > 0 ) {
			set_transient( $transient_key, $context_record, $remaining_ttl );
			return true;
		}

		return false;
	}

	/**
	 * Get context with compression applied if needed.
	 *
	 * Implements automatic compression based on context age and TTL.
	 * Follows RAG best practices for memory management.
	 *
	 * @param int|string $agent_id         Agent identifier.
	 * @param string     $context_id       Context ID.
	 * @param bool       $apply_compression Whether to apply compression.
	 * @return array|null Context record or null if not found.
	 */
	public function retrieve_context_compressed( $agent_id, $context_id, $apply_compression = true ) {
		$context = $this->retrieve_context( $agent_id, $context_id, false );

		if ( null === $context ) {
			return null;
		}

		// Track access.
		$this->track_context_access( $agent_id, $context_id );

		// Apply compression if enabled and service available.
		if ( $apply_compression && class_exists( 'WP_MCP_AI_Context_Compression_Service' ) ) {
			$compression_service = WP_MCP_AI_Context_Compression_Service::get_instance();
			$context             = $compression_service->apply_compression_policy( $context );
		}

		return $context;
	}

	/**
	 * Calculate enhanced context score with decay and frequency.
	 *
	 * Implements RAG best practices for context scoring:
	 * - Recency with exponential decay
	 * - Frequency of access
	 * - Importance level
	 * - TTL awareness
	 *
	 * @param array $context Context record.
	 * @param array $weights Scoring weights.
	 * @return float Enhanced score (0-1).
	 */
	public function calculate_enhanced_score( $context, $weights = array() ) {
		$defaults = array(
			'recency'    => 0.3,
			'frequency'  => 0.2,
			'importance' => 0.4,
			'ttl'        => 0.1,
		);

		$weights = wp_parse_args( $weights, $defaults );

		// Recency score with exponential decay.
		$recency_score = $this->calculate_recency_decay( $context );

		// Frequency score.
		$access_count    = isset( $context['access_count'] ) ? $context['access_count'] : 0;
		$frequency_score = min( 1.0, $access_count / 10 ); // Normalize to 0-1 (10+ accesses = max).

		// Importance score.
		$importance       = isset( $context['data']['importance'] ) ? $context['data']['importance'] : 'medium';
		$importance_map   = array(
			'critical' => 1.0,
			'high'     => 0.75,
			'medium'   => 0.5,
			'low'      => 0.25,
		);
		$importance_score = isset( $importance_map[ $importance ] ) ? $importance_map[ $importance ] : 0.5;

		// TTL score (higher for contexts with more time remaining).
		$ttl_score = $this->calculate_ttl_score( $context );

		// Calculate weighted score.
		$total_score = (
			( $recency_score * $weights['recency'] ) +
			( $frequency_score * $weights['frequency'] ) +
			( $importance_score * $weights['importance'] ) +
			( $ttl_score * $weights['ttl'] )
		);

		return min( 1.0, max( 0.0, $total_score ) );
	}

	/**
	 * Calculate recency score with exponential decay.
	 *
	 * Implements RAG best practice for time-based scoring.
	 *
	 * @param array $context Context record.
	 * @return float Recency score (0-1).
	 */
	private function calculate_recency_decay( $context ) {
		if ( ! isset( $context['stored_at'] ) ) {
			return 0.5;
		}

		$stored_timestamp = strtotime( $context['stored_at'] );
		if ( ! $stored_timestamp ) {
			return 0.5;
		}

		$age_seconds = time() - $stored_timestamp;
		$age_days    = $age_seconds / DAY_IN_SECONDS;

		// Exponential decay: score = e^(-age_days / decay_factor).
		// Perfect score for items < 1 day old.
		// 0.5 score at ~7 days.
		// 0.25 score at ~14 days.
		if ( $age_days < 1 ) {
			return 1.0;
		}

		$decay_factor = 10; // Adjust for desired decay rate.
		$score        = exp( -$age_days / $decay_factor );

		return max( 0.0, min( 1.0, $score ) );
	}

	/**
	 * Calculate TTL-based score.
	 *
	 * Higher score for contexts with more time remaining.
	 *
	 * @param array $context Context record.
	 * @return float TTL score (0-1).
	 */
	private function calculate_ttl_score( $context ) {
		if ( ! isset( $context['stored_at'] ) || ! isset( $context['expires_at'] ) ) {
			return 0.5;
		}

		$stored_time  = strtotime( $context['stored_at'] );
		$expires_time = strtotime( $context['expires_at'] );
		$current_time = time();

		if ( ! $stored_time || ! $expires_time ) {
			return 0.5;
		}

		$total_lifetime     = $expires_time - $stored_time;
		$remaining_lifetime = $expires_time - $current_time;

		if ( $total_lifetime <= 0 ) {
			return 0.0;
		}

		$ttl_ratio = $remaining_lifetime / $total_lifetime;
		return max( 0.0, min( 1.0, $ttl_ratio ) );
	}

	/**
	 * Get context health metrics.
	 *
	 * Provides insights into context quality and usage patterns.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @return array Health metrics.
	 */
	public function get_context_health_metrics( $agent_id ) {
		$index_key     = self::INDEX_PREFIX . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) || empty( $context_index ) ) {
			return array(
				'health_score' => 0,
				'total_count'  => 0,
				'metrics'      => array(),
			);
		}

		$metrics = array(
			'total_contexts'      => count( $context_index ),
			'active_contexts'     => 0,
			'expiring_soon'       => 0,
			'frequently_accessed' => 0,
			'never_accessed'      => 0,
			'avg_age_days'        => 0,
			'avg_access_count'    => 0,
		);

		$current_time       = time();
		$total_age          = 0;
		$total_access       = 0;
		$expiring_threshold = 7 * DAY_IN_SECONDS; // 7 days.

		foreach ( $context_index as $ctx_id => $entry ) {
			// Get full context.
			$context = $this->retrieve_context( $agent_id, $ctx_id, false );
			if ( ! $context ) {
				continue;
			}

			// Active contexts (not expired).
			$expires_time = strtotime( $context['expires_at'] );
			if ( $expires_time && $current_time < $expires_time ) {
				++$metrics['active_contexts'];

				// Expiring soon?
				if ( ( $expires_time - $current_time ) < $expiring_threshold ) {
					++$metrics['expiring_soon'];
				}
			}

			// Access patterns.
			$access_count  = isset( $context['access_count'] ) ? $context['access_count'] : 0;
			$total_access += $access_count;

			if ( $access_count >= 5 ) {
				++$metrics['frequently_accessed'];
			} elseif ( 0 === $access_count ) {
				++$metrics['never_accessed'];
			}

			// Age tracking.
			$stored_time = strtotime( $context['stored_at'] );
			if ( $stored_time ) {
				$age_days   = ( $current_time - $stored_time ) / DAY_IN_SECONDS;
				$total_age += $age_days;
			}
		}

		// Calculate averages.
		if ( $metrics['total_contexts'] > 0 ) {
			$metrics['avg_age_days']     = round( $total_age / $metrics['total_contexts'], 1 );
			$metrics['avg_access_count'] = round( $total_access / $metrics['total_contexts'], 1 );
		}

		// Health score (0-100).
		// Based on: active ratio, access ratio, expiration management.
		$active_ratio = $metrics['total_contexts'] > 0 ? $metrics['active_contexts'] / $metrics['total_contexts'] : 0;
		$access_ratio = $metrics['total_contexts'] > 0 ? ( $metrics['total_contexts'] - $metrics['never_accessed'] ) / $metrics['total_contexts'] : 0;
		$health_score = ( $active_ratio * 0.5 + $access_ratio * 0.5 ) * 100;

		return array(
			'health_score' => round( $health_score, 1 ),
			'total_count'  => $metrics['total_contexts'],
			'metrics'      => $metrics,
		);
	}
}
