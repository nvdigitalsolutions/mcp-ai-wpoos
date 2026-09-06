<?php
/**
 * Memory Audit Trail tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Memory_Audit_Trail — byte-identical slug, schema,
 * error codes, envelope, transient keys, and diff/stat shaping;
 * per-mode context-retrieval seam).
 *
 * The base tool resolves the current context through
 * WP_MCP_AI_Agent_Context_Manager::retrieve_context(). Standalone, that
 * class does not exist; the port reads the base-identical transient
 * ('mcp_ai_ctx_' . md5(agent_context)) directly with the same expiry
 * semantics, so rollback works against contexts stored by either the
 * base manager or the ecosystem's own writers.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Tracks memory version history with audit trail: history, diffing,
 * rollback, audit log, and compliance statistics.
 */
class MemoryAuditTrailTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'memory_audit_trail';
	}

	public function getName(): string {
		return __( 'Memory Audit Trail', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Track and manage memory version history with audit trail. View change history, compare versions, rollback to previous states, and maintain compliance records for all memory modifications.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'     => array(
					'type'        => 'string',
					'description' => __( 'Audit trail action to perform', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'get_history', 'compare_versions', 'rollback', 'get_audit_log', 'get_stats' ),
				),
				'agent_id'   => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'nvoos-content-graph-ai' ),
				),
				'context_id' => array(
					'type'        => 'string',
					'description' => __( 'Context ID for single-context operations', 'nvoos-content-graph-ai' ),
				),
				'version'    => array(
					'type'        => 'integer',
					'description' => __( 'Version number for rollback operation', 'nvoos-content-graph-ai' ),
				),
				'versions'   => array(
					'type'        => 'object',
					'description' => __( 'Version numbers to compare', 'nvoos-content-graph-ai' ),
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

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'local-only', 'write', 'state-changing', 'cacheable', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		if ( empty( $arguments['action'] ) || empty( $arguments['agent_id'] ) ) {
				return new \WP_Error(
					'wp_mcp_ai_error',
					__( 'Action and agent ID are required.', 'nvoos-content-graph-ai' )
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
				return new \WP_Error(
					'wp_mcp_ai_error',
					__( 'Invalid action.', 'nvoos-content-graph-ai' )
				);
		}
	}

	/**
	 * Get version history for a context (base-identical).
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @param array      $options   Options.
	 * @return array Result.
	 */
	private function get_version_history( $agent_id, $arguments, $options ) {
		if ( empty( $arguments['context_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Context ID is required for get_history action.', 'nvoos-content-graph-ai' )
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$limit      = isset( $options['limit'] ) ? absint( $options['limit'] ) : 50;

		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) ) {
			return array(
				'success'  => true,
				'message'  => __( 'No version history found for this context.', 'nvoos-content-graph-ai' ),
				'versions' => array(),
			);
		}

		krsort( $history );

		$history = array_slice( $history, 0, $limit, true );

		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %d: number of versions */
				__( 'Found %d versions.', 'nvoos-content-graph-ai' ),
				count( $history )
			),
			'context_id'     => $context_id,
			'versions'       => $history,
			'total_versions' => count( $history ),
		);
	}

	/**
	 * Compare two versions of a context (base-identical).
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @return array Result.
	 */
	private function compare_versions( $agent_id, $arguments ) {
		if ( empty( $arguments['context_id'] ) || empty( $arguments['versions'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Context ID and versions are required for compare_versions action.', 'nvoos-content-graph-ai' )
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$versions   = $arguments['versions'];

		$from_version = isset( $versions['from'] ) ? absint( $versions['from'] ) : 0;
		$to_version   = isset( $versions['to'] ) ? absint( $versions['to'] ) : 0;

		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) || ! isset( $history[ $from_version ] ) || ! isset( $history[ $to_version ] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'One or both versions not found.', 'nvoos-content-graph-ai' )
			);
		}

		$from_data = $history[ $from_version ];
		$to_data   = $history[ $to_version ];

		$differences = $this->calculate_differences( $from_data['data'], $to_data['data'] );

		return array(
			'success'      => true,
			'message'      => __( 'Version comparison complete.', 'nvoos-content-graph-ai' ),
			'context_id'   => $context_id,
			'from_version' => $from_version,
			'to_version'   => $to_version,
			'differences'  => $differences,
			'from_data'    => $from_data,
			'to_data'      => $to_data,
		);
	}

	/**
	 * Rollback context to a previous version (base-identical + seam).
	 *
	 * @param int|string $agent_id  Agent ID.
	 * @param array      $arguments Arguments.
	 * @return array|\WP_Error Result.
	 */
	private function rollback_version( $agent_id, $arguments ) {
		if ( empty( $arguments['context_id'] ) || ! isset( $arguments['version'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Context ID and version are required for rollback action.', 'nvoos-content-graph-ai' )
			);
		}

		$context_id = sanitize_text_field( $arguments['context_id'] );
		$version    = absint( $arguments['version'] );

		$history_key = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) || ! isset( $history[ $version ] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Version not found.', 'nvoos-content-graph-ai' )
			);
		}

		$version_data = $history[ $version ];

		// Per-mode seam: the base context manager in monolith installs,
		// the base-identical transient standalone.
		$current_context = $this->retrieve_context( $agent_id, $context_id );

		if ( ! $current_context ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Current context not found or has expired.', 'nvoos-content-graph-ai' )
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
					__( 'Successfully rolled back to version %d.', 'nvoos-content-graph-ai' ),
					$version
				),
				'context_id'     => $context_id,
				'version'        => $version,
				'rolled_back_at' => $current_context['data']['metadata']['rolled_back_at'],
			);
		}

		return new \WP_Error(
			'wp_mcp_ai_error',
			__( 'Context has expired and cannot be rolled back.', 'nvoos-content-graph-ai' )
		);
	}

	/**
	 * Retrieve a live context record (per-mode seam).
	 *
	 * Monolith: the base Agent_Context_Manager. Standalone: the
	 * base-identical transient with the same expiry semantics.
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param string     $context_id Context ID.
	 * @return array|null Context record or null if not found/expired.
	 */
	private function retrieve_context( $agent_id, $context_id ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager = \WP_MCP_AI_Agent_Context_Manager::get_instance();
			return $manager->retrieve_context( $agent_id, $context_id, false );
		}

		$transient_key  = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$context_record = get_transient( $transient_key );

		if ( ! $context_record ) {
			return null;
		}

		if ( isset( $context_record['expires_at'] ) ) {
			$expires_timestamp = strtotime( $context_record['expires_at'] );
			if ( $expires_timestamp && time() > $expires_timestamp ) {
				return null;
			}
		}

		return $context_record;
	}

	/**
	 * Get audit log for an agent (base-identical).
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

		$audit_key = 'mcp_ai_audit_log_' . md5( (string) $agent_id );
		$audit_log = get_transient( $audit_key );

		if ( ! is_array( $audit_log ) ) {
			return array(
				'success' => true,
				'message' => __( 'No audit log entries found.', 'nvoos-content-graph-ai' ),
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
				__( 'Found %d audit log entries.', 'nvoos-content-graph-ai' ),
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
	 * Get audit statistics for an agent (base-identical).
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
				'message' => __( 'No audit data available.', 'nvoos-content-graph-ai' ),
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
			'message' => __( 'Audit statistics retrieved.', 'nvoos-content-graph-ai' ),
			'stats'   => $stats,
		);
	}

	/**
	 * Save a version to history (base-identical).
	 *
	 * @param int|string $agent_id    Agent ID.
	 * @param string     $context_id  Context ID.
	 * @param array      $context     Context data.
	 * @param string     $change_type Type of change.
	 * @return void
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
	 * Log an audit event (base-identical).
	 *
	 * @param int|string $agent_id   Agent ID.
	 * @param string     $context_id Context ID.
	 * @param string     $action     Action performed.
	 * @param array      $metadata   Additional metadata.
	 * @return void
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
	 * Calculate differences between two data arrays (base-identical).
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
}
