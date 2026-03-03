<?php
/**
 * Tool for memory versioning and audit trail.
 *
 * Implements industry best practices for tracking memory changes over time.
 * Maintains version history and audit trail for compliance and debugging.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Track and manage memory version history.
 *
 * This tool enables:
 * - Version history tracking for contexts
 * - Rollback to previous versions
 * - Audit trail of all changes
 * - Compare versions
 * - View change history
 *
 * Follows enterprise RAG best practices for governance and compliance.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Memory_Audit_Trail implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'memory_audit_trail';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Memory Audit Trail', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Track and manage memory version history with audit trail. View change history, compare versions, rollback to previous states, and maintain compliance records for all memory modifications.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'     => array(
					'type'        => 'string',
					'description' => __( 'Audit trail action to perform', 'mcp-ai-wpoos' ),
					'enum'        => array( 'get_history', 'compare_versions', 'rollback', 'get_audit_log', 'get_stats' ),
				),
				'agent_id'   => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'context_id' => array(
					'type'        => 'string',
					'description' => __( 'Context ID for single-context operations', 'mcp-ai-wpoos' ),
				),
				'version'    => array(
					'type'        => 'integer',
					'description' => __( 'Version number for rollback operation', 'mcp-ai-wpoos' ),
				),
				'versions'   => array(
					'type'        => 'object',
					'description' => __( 'Version numbers to compare', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'from' => array( 'type' => 'integer' ),
						'to'   => array( 'type' => 'integer' ),
					),
				),
				'options'    => array(
					'type'       => 'object',
					'properties' => array(
						'limit'       => array(
							'type'    => 'integer',
							'default' => 50,
							'maximum' => 200,
						),
						'date_from'   => array( 'type' => 'string' ),
						'date_to'     => array( 'type' => 'string' ),
						'action_type' => array(
							'type' => 'string',
							'enum' => array( 'create', 'update', 'delete', 'access' ),
						),
					),
				),
			),
			'required'             => array( 'action', 'agent_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( empty( $arguments['action'] ) || empty( $arguments['agent_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Action and agent ID are required.', 'mcp-ai-wpoos' ),
			);
		}

		$action   = sanitize_key( $arguments['action'] );
		$agent_id = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$options  = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();

		switch ( $action ) {
			case 'get_history':
				return $this->get_version_history( $agent_id, $arguments, $options );

			case 'compare_versions':
				return $this->compare_versions( $agent_id, $arguments );

			case 'rollback':
				return $this->rollback_version( $agent_id, $arguments );

			case 'get_audit_log':
				return $this->get_audit_log( $agent_id, $options );

			case 'get_stats':
				return $this->get_audit_stats( $agent_id );

			default:
				return array(
					'success' => false,
					'message' => __( 'Invalid action.', 'mcp-ai-wpoos' ),
				);
		}
	}

	/**
	 * Get version history for a context.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @param array      $options   Options.
	 * @return array Result.
	 */
	private function get_version_history( $agent_id, $arguments, $options ) {
		if ( empty( $arguments['context_id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID is required for get_history action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$limit      = isset( $options['limit'] ) ? absint( $options['limit'] ) : 50;

		// Get version history from transient.
		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) ) {
			return array(
				'success'  => true,
				'message'  => __( 'No version history found for this context.', 'mcp-ai-wpoos' ),
				'versions' => array(),
			);
		}

		// Sort by version number descending.
		krsort( $history );

		// Apply limit.
		$history = array_slice( $history, 0, $limit, true );

		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %d: number of versions */
				__( 'Found %d versions.', 'mcp-ai-wpoos' ),
				count( $history )
			),
			'context_id'     => $context_id,
			'versions'       => $history,
			'total_versions' => count( $history ),
		);
	}

	/**
	 * Compare two versions of a context.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @return array Result.
	 */
	private function compare_versions( $agent_id, $arguments ) {
		if ( empty( $arguments['context_id'] ) || empty( $arguments['versions'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID and versions are required for compare_versions action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$versions   = $arguments['versions'];

		$from_version = isset( $versions['from'] ) ? absint( $versions['from'] ) : 0;
		$to_version   = isset( $versions['to'] ) ? absint( $versions['to'] ) : 0;

		// Get version history.
		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) || ! isset( $history[ $from_version ] ) || ! isset( $history[ $to_version ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'One or both versions not found.', 'mcp-ai-wpoos' ),
			);
		}

		$from_data = $history[ $from_version ];
		$to_data   = $history[ $to_version ];

		// Calculate differences.
		$differences = $this->calculate_differences( $from_data['data'], $to_data['data'] );

		return array(
			'success'      => true,
			'message'      => __( 'Version comparison complete.', 'mcp-ai-wpoos' ),
			'context_id'   => $context_id,
			'from_version' => $from_version,
			'to_version'   => $to_version,
			'differences'  => $differences,
			'from_data'    => $from_data,
			'to_data'      => $to_data,
		);
	}

	/**
	 * Rollback context to a previous version.
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @return array Result.
	 */
	private function rollback_version( $agent_id, $arguments ) {
		if ( empty( $arguments['context_id'] ) || ! isset( $arguments['version'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context ID and version are required for rollback action.', 'mcp-ai-wpoos' ),
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$version    = absint( $arguments['version'] );

		// Get version history.
		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) || ! isset( $history[ $version ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Version not found.', 'mcp-ai-wpoos' ),
			);
		}

		$version_data = $history[ $version ];

		// Get current context.
		$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$current_context = $context_manager->retrieve_context( $agent_id, $context_id, false );

		if ( ! $current_context ) {
			return array(
				'success' => false,
				'message' => __( 'Current context not found or has expired.', 'mcp-ai-wpoos' ),
			);
		}

		// Save current state to history before rollback.
		$this->save_version( $agent_id, $context_id, $current_context, 'rollback_before' );

		// Restore version data.
		$current_context['data'] = $version_data['data'];

		// Add rollback metadata.
		if ( ! isset( $current_context['data']['metadata'] ) ) {
			$current_context['data']['metadata'] = array();
		}
		$current_context['data']['metadata']['rolled_back_at']   = current_time( 'mysql' );
		$current_context['data']['metadata']['rolled_back_from'] = $version;

		// Save rolled back context.
		$remaining_ttl = strtotime( $current_context['expires_at'] ) - time();
		if ( $remaining_ttl > 0 ) {
			$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
			set_transient( $transient_key, $current_context, $remaining_ttl );

			// Save rollback to history.
			$this->save_version( $agent_id, $context_id, $current_context, 'rollback' );

			// Log audit trail.
			$this->log_audit_event(
				$agent_id,
				$context_id,
				'rollback',
				array(
					'version' => $version,
				)
			);

			return array(
				'success'        => true,
				'message'        => sprintf(
					/* translators: %d: version number */
					__( 'Successfully rolled back to version %d.', 'mcp-ai-wpoos' ),
					$version
				),
				'context_id'     => $context_id,
				'version'        => $version,
				'rolled_back_at' => $current_context['data']['metadata']['rolled_back_at'],
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Context has expired and cannot be rolled back.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get audit log for an agent.
	 *
	 * @param int|string $agent_id Agent ID.
	 * @param array      $options  Options.
	 * @return array Result.
	 */
	private function get_audit_log( $agent_id, $options ) {
		$limit       = isset( $options['limit'] ) ? absint( $options['limit'] ) : 50;
		$date_from   = isset( $options['date_from'] ) ? sanitize_text_field( $options['date_from'] ) : null;
		$date_to     = isset( $options['date_to'] ) ? sanitize_text_field( $options['date_to'] ) : null;
		$action_type = isset( $options['action_type'] ) ? sanitize_key( $options['action_type'] ) : null;

		// Get audit log from transient.
		$audit_key = 'mcp_ai_audit_log_' . md5( (string) $agent_id );
		$audit_log = get_transient( $audit_key );

		if ( ! is_array( $audit_log ) ) {
			return array(
				'success' => true,
				'message' => __( 'No audit log entries found.', 'mcp-ai-wpoos' ),
				'entries' => array(),
			);
		}

		// Filter by date range.
		if ( $date_from || $date_to ) {
			$audit_log = array_filter(
				$audit_log,
				function ( $entry ) use ( $date_from, $date_to ) {
					$entry_time = strtotime( $entry['timestamp'] );

					if ( $date_from && $entry_time < strtotime( $date_from ) ) {
						return false;
					}

					if ( $date_to && $entry_time > strtotime( $date_to ) ) {
						return false;
					}

					return true;
				}
			);
		}

		// Filter by action type.
		if ( $action_type ) {
			$audit_log = array_filter(
				$audit_log,
				function ( $entry ) use ( $action_type ) {
					return $entry['action'] === $action_type;
				}
			);
		}

		// Sort by timestamp descending.
		usort(
			$audit_log,
			function ( $a, $b ) {
				return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
			}
		);

		// Apply limit.
		$audit_log = array_slice( $audit_log, 0, $limit );

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: %d: number of audit entries */
				__( 'Found %d audit log entries.', 'mcp-ai-wpoos' ),
				count( $audit_log )
			),
			'entries'       => $audit_log,
			'total_entries' => count( $audit_log ),
			'filters'       => array(
				'date_from'   => $date_from,
				'date_to'     => $date_to,
				'action_type' => $action_type,
			),
		);
	}

	/**
	 * Get audit statistics for an agent.
	 *
	 * @param int|string $agent_id Agent ID.
	 * @return array Result.
	 */
	private function get_audit_stats( $agent_id ) {
		$audit_key = 'mcp_ai_audit_log_' . md5( (string) $agent_id );
		$audit_log = get_transient( $audit_key );

		if ( ! is_array( $audit_log ) ) {
			return array(
				'success' => true,
				'message' => __( 'No audit data available.', 'mcp-ai-wpoos' ),
				'stats'   => array(),
			);
		}

		$stats = array(
			'total_events'        => count( $audit_log ),
			'by_action'           => array(),
			'by_hour'             => array(),
			'recent_24h'          => 0,
			'most_active_context' => null,
		);

		$context_activity = array();
		$now              = time();
		$day_ago          = $now - DAY_IN_SECONDS;

		foreach ( $audit_log as $entry ) {
			// Count by action.
			$action = $entry['action'];
			if ( ! isset( $stats['by_action'][ $action ] ) ) {
				$stats['by_action'][ $action ] = 0;
			}
			++$stats['by_action'][ $action ];

			// Count by hour.
			$hour = gmdate( 'H', strtotime( $entry['timestamp'] ) );
			if ( ! isset( $stats['by_hour'][ $hour ] ) ) {
				$stats['by_hour'][ $hour ] = 0;
			}
			++$stats['by_hour'][ $hour ];

			// Count recent 24h.
			if ( strtotime( $entry['timestamp'] ) > $day_ago ) {
				++$stats['recent_24h'];
			}

			// Track context activity.
			if ( isset( $entry['context_id'] ) ) {
				$ctx_id = $entry['context_id'];
				if ( ! isset( $context_activity[ $ctx_id ] ) ) {
					$context_activity[ $ctx_id ] = 0;
				}
				++$context_activity[ $ctx_id ];
			}
		}

		// Find most active context.
		if ( ! empty( $context_activity ) ) {
			arsort( $context_activity );
			$most_active_id               = key( $context_activity );
			$stats['most_active_context'] = array(
				'context_id' => $most_active_id,
				'events'     => $context_activity[ $most_active_id ],
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Audit statistics retrieved.', 'mcp-ai-wpoos' ),
			'stats'   => $stats,
		);
	}

	/**
	 * Save a version to history.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param string     $context_id Context ID.
	 * @param array      $context    Context data.
	 * @param string     $change_type Type of change.
	 */
	private function save_version( $agent_id, $context_id, $context, $change_type = 'update' ) {
		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Get next version number.
		$version = empty( $history ) ? 1 : max( array_keys( $history ) ) + 1;

		// Store version.
		$history[ $version ] = array(
			'version'     => $version,
			'data'        => $context['data'],
			'change_type' => $change_type,
			'timestamp'   => current_time( 'mysql' ),
		);

		// Keep only last 100 versions.
		if ( count( $history ) > 100 ) {
			krsort( $history );
			$history = array_slice( $history, 0, 100, true );
		}

		// Save history (1 year TTL).
		set_transient( $history_key, $history, YEAR_IN_SECONDS );
	}

	/**
	 * Log an audit event.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param string     $context_id Context ID.
	 * @param string     $action     Action performed.
	 * @param array      $metadata   Additional metadata.
	 */
	private function log_audit_event( $agent_id, $context_id, $action, $metadata = array() ) {
		$audit_key = 'mcp_ai_audit_log_' . md5( (string) $agent_id );
		$audit_log = get_transient( $audit_key );

		if ( ! is_array( $audit_log ) ) {
			$audit_log = array();
		}

		$audit_log[] = array(
			'context_id' => $context_id,
			'action'     => $action,
			'metadata'   => $metadata,
			'timestamp'  => current_time( 'mysql' ),
			'user_id'    => get_current_user_id(),
		);

		// Keep only last 1000 entries.
		if ( count( $audit_log ) > 1000 ) {
			$audit_log = array_slice( $audit_log, -1000 );
		}

		// Save audit log (1 year TTL).
		set_transient( $audit_key, $audit_log, YEAR_IN_SECONDS );
	}

	/**
	 * Calculate differences between two data arrays.
	 *
	 * @param array $from From data.
	 * @param array $to   To data.
	 * @return array Differences.
	 */
	private function calculate_differences( $from, $to ) {
		$differences = array(
			'added'    => array(),
			'removed'  => array(),
			'modified' => array(),
		);

		$all_keys = array_unique( array_merge( array_keys( $from ), array_keys( $to ) ) );

		foreach ( $all_keys as $key ) {
			if ( ! isset( $from[ $key ] ) && isset( $to[ $key ] ) ) {
				$differences['added'][ $key ] = $to[ $key ];
			} elseif ( isset( $from[ $key ] ) && ! isset( $to[ $key ] ) ) {
				$differences['removed'][ $key ] = $from[ $key ];
			} elseif ( $from[ $key ] !== $to[ $key ] ) {
				$differences['modified'][ $key ] = array(
					'from' => $from[ $key ],
					'to'   => $to[ $key ],
				);
			}
		}

		return $differences;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ai_model_management',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'system_administrator', 'compliance_officer', 'ai_researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,
			'local-only'        => true,
			'read-only'         => false,
			'idempotent'        => false,
			'cacheable'         => true,
			'requires-auth'     => true,
			'blocking'          => false,
			'uses-network'      => false,
			'modifies-wp'       => true,
			'expensive'         => false,
			'requires-approval' => false,
		);
	}
}
