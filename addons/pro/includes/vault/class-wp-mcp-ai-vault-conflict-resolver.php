<?php
/**
 * Password Vault Conflict Resolution Service
 *
 * Handles conflict resolution for bidirectional sync operations.
 *
 * @package MCP_AI_WP_OOS
 * @subpackage Pro/Vault
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Conflict Resolver Class
 *
 * Provides intelligent conflict resolution for vault synchronization.
 */
class WP_MCP_AI_Vault_Conflict_Resolver {

	/**
	 * Conflict resolution strategies
	 */
	const STRATEGY_LOCAL_WINS  = 'local_wins';
	const STRATEGY_REMOTE_WINS = 'remote_wins';
	const STRATEGY_NEWEST_WINS = 'newest_wins';
	const STRATEGY_MANUAL      = 'manual';
	const STRATEGY_MERGE       = 'merge';

	/**
	 * Resolve conflict between local and remote items
	 *
	 * @param array  $local_item    Local vault item data.
	 * @param array  $remote_item   Remote vault item data.
	 * @param string $strategy      Conflict resolution strategy.
	 * @return array Resolved item data.
	 */
	public function resolve_conflict( $local_item, $remote_item, $strategy = self::STRATEGY_NEWEST_WINS ) {
		switch ( $strategy ) {
			case self::STRATEGY_LOCAL_WINS:
				return $this->local_wins( $local_item, $remote_item );

			case self::STRATEGY_REMOTE_WINS:
				return $this->remote_wins( $local_item, $remote_item );

			case self::STRATEGY_NEWEST_WINS:
				return $this->newest_wins( $local_item, $remote_item );

			case self::STRATEGY_MERGE:
				return $this->merge_items( $local_item, $remote_item );

			case self::STRATEGY_MANUAL:
				return $this->queue_manual_resolution( $local_item, $remote_item );

			default:
				return $this->newest_wins( $local_item, $remote_item );
		}
	}

	/**
	 * Local item wins strategy
	 *
	 * @param array $local_item  Local vault item.
	 * @param array $remote_item Remote vault item.
	 * @return array Local item.
	 */
	private function local_wins( $local_item, $remote_item ) {
		$this->log_resolution( $local_item, $remote_item, 'local_wins' );
		return $local_item;
	}

	/**
	 * Remote item wins strategy
	 *
	 * @param array $local_item  Local vault item.
	 * @param array $remote_item Remote vault item.
	 * @return array Remote item.
	 */
	private function remote_wins( $local_item, $remote_item ) {
		$this->log_resolution( $local_item, $remote_item, 'remote_wins' );
		return $remote_item;
	}

	/**
	 * Newest item wins strategy
	 *
	 * @param array $local_item  Local vault item.
	 * @param array $remote_item Remote vault item.
	 * @return array Newest item.
	 */
	private function newest_wins( $local_item, $remote_item ) {
		$local_time  = strtotime( $local_item['modified'] ?? $local_item['created'] ?? '1970-01-01' );
		$remote_time = strtotime( $remote_item['modified'] ?? $remote_item['created'] ?? '1970-01-01' );

		$winner = ( $local_time >= $remote_time ) ? $local_item : $remote_item;
		$this->log_resolution( $local_item, $remote_item, 'newest_wins', $winner === $local_item ? 'local' : 'remote' );

		return $winner;
	}

	/**
	 * Merge items strategy
	 *
	 * Intelligently merges local and remote items.
	 *
	 * @param array $local_item  Local vault item.
	 * @param array $remote_item Remote vault item.
	 * @return array Merged item.
	 */
	private function merge_items( $local_item, $remote_item ) {
		$merged = $local_item;

		// Use newest timestamps.
		$local_time  = strtotime( $local_item['modified'] ?? $local_item['created'] ?? '1970-01-01' );
		$remote_time = strtotime( $remote_item['modified'] ?? $remote_item['created'] ?? '1970-01-01' );

		// Merge name (use non-empty).
		if ( empty( $merged['name'] ) && ! empty( $remote_item['name'] ) ) {
			$merged['name'] = $remote_item['name'];
		}

		// Merge credentials based on modification time.
		if ( ! empty( $remote_item['username'] ) && $remote_time > $local_time ) {
			$merged['username'] = $remote_item['username'];
		}
		if ( ! empty( $remote_item['password'] ) && $remote_time > $local_time ) {
			$merged['password'] = $remote_item['password'];
		}

		// Merge URIs (combine unique URIs).
		if ( ! empty( $remote_item['uri'] ) ) {
			$local_uris    = $this->parse_uris( $local_item['uri'] ?? '' );
			$remote_uris   = $this->parse_uris( $remote_item['uri'] );
			$merged_uris   = array_unique( array_merge( $local_uris, $remote_uris ) );
			$merged['uri'] = implode( "\n", $merged_uris );
		}

		// Merge TOTP (use non-empty, prefer newer).
		if ( empty( $merged['totp'] ) && ! empty( $remote_item['totp'] ) ) {
			$merged['totp'] = $remote_item['totp'];
		} elseif ( ! empty( $remote_item['totp'] ) && $remote_time > $local_time ) {
			$merged['totp'] = $remote_item['totp'];
		}

		// Merge notes (combine if both exist).
		if ( ! empty( $local_item['notes'] ) && ! empty( $remote_item['notes'] ) ) {
			if ( $local_item['notes'] !== $remote_item['notes'] ) {
				$merged['notes'] = $local_item['notes'] . "\n\n---\n\n" . $remote_item['notes'];
			}
		} elseif ( ! empty( $remote_item['notes'] ) ) {
			$merged['notes'] = $remote_item['notes'];
		}

		// Merge custom fields (combine unique fields).
		if ( ! empty( $remote_item['custom_fields'] ) ) {
			$local_fields            = $local_item['custom_fields'] ?? array();
			$remote_fields           = $remote_item['custom_fields'];
			$merged['custom_fields'] = $this->merge_custom_fields( $local_fields, $remote_fields );
		}

		// Use latest modification time.
		$merged['modified'] = ( $remote_time > $local_time ) ? $remote_item['modified'] : $local_item['modified'];

		// Favorite status (OR logic - if either is favorite, result is favorite).
		$merged['favorite'] = ( ! empty( $local_item['favorite'] ) || ! empty( $remote_item['favorite'] ) );

		$this->log_resolution( $local_item, $remote_item, 'merge' );

		return $merged;
	}

	/**
	 * Parse URIs into array
	 *
	 * @param string $uri_string URI string (newline or comma separated).
	 * @return array Array of URIs.
	 */
	private function parse_uris( $uri_string ) {
		if ( empty( $uri_string ) ) {
			return array();
		}

		// Split by newline or comma.
		$uris = preg_split( '/[\n,]+/', $uri_string );

		// Trim and filter empty.
		$uris = array_map( 'trim', $uris );
		$uris = array_filter( $uris );

		return $uris;
	}

	/**
	 * Merge custom fields
	 *
	 * @param array $local_fields  Local custom fields.
	 * @param array $remote_fields Remote custom fields.
	 * @return array Merged custom fields.
	 */
	private function merge_custom_fields( $local_fields, $remote_fields ) {
		$merged     = array();
		$seen_names = array();

		// Add all local fields.
		foreach ( $local_fields as $field ) {
			$merged[]     = $field;
			$seen_names[] = $field['name'] ?? '';
		}

		// Add remote fields that don't exist locally.
		foreach ( $remote_fields as $field ) {
			$name = $field['name'] ?? '';
			if ( ! in_array( $name, $seen_names, true ) ) {
				$merged[] = $field;
			}
		}

		return $merged;
	}

	/**
	 * Queue item for manual resolution
	 *
	 * @param array $local_item  Local vault item.
	 * @param array $remote_item Remote vault item.
	 * @return array Local item with conflict flag.
	 */
	private function queue_manual_resolution( $local_item, $remote_item ) {
		// Store conflict for manual resolution.
		$conflicts = get_option( 'wp_mcp_ai_vault_conflicts', array() );

		$conflict_id               = wp_generate_uuid4();
		$conflicts[ $conflict_id ] = array(
			'id'          => $conflict_id,
			'local_item'  => $local_item,
			'remote_item' => $remote_item,
			'timestamp'   => current_time( 'mysql' ),
			'status'      => 'pending',
		);

		update_option( 'wp_mcp_ai_vault_conflicts', $conflicts );

		$this->log_resolution( $local_item, $remote_item, 'manual', 'queued' );

		// Return local item with conflict marker.
		$local_item['has_conflict'] = true;
		$local_item['conflict_id']  = $conflict_id;

		return $local_item;
	}

	/**
	 * Log conflict resolution
	 *
	 * @param array  $local_item  Local vault item.
	 * @param array  $remote_item Remote vault item.
	 * @param string $strategy    Resolution strategy used.
	 * @param string $result      Result description.
	 */
	private function log_resolution( $local_item, $remote_item, $strategy, $result = '' ) {
		$log_entry = array(
			'timestamp'   => current_time( 'mysql' ),
			'item_name'   => $local_item['name'] ?? 'Unknown',
			'strategy'    => $strategy,
			'result'      => $result,
			'local_time'  => $local_item['modified'] ?? '',
			'remote_time' => $remote_item['modified'] ?? '',
		);

		$logs = get_option( 'wp_mcp_ai_vault_conflict_logs', array() );
		array_unshift( $logs, $log_entry );
		$logs = array_slice( $logs, 0, 100 );

		update_option( 'wp_mcp_ai_vault_conflict_logs', $logs );
	}

	/**
	 * Get pending conflicts
	 *
	 * @return array Pending conflicts.
	 */
	public function get_pending_conflicts() {
		$conflicts = get_option( 'wp_mcp_ai_vault_conflicts', array() );
		return array_filter(
			$conflicts,
			function ( $conflict ) {
				return $conflict['status'] === 'pending';
			}
		);
	}

	/**
	 * Resolve queued conflict
	 *
	 * @param string $conflict_id Conflict ID.
	 * @param string $choice      Resolution choice ('local', 'remote', 'merge').
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function resolve_queued_conflict( $conflict_id, $choice ) {
		$conflicts = get_option( 'wp_mcp_ai_vault_conflicts', array() );

		if ( ! isset( $conflicts[ $conflict_id ] ) ) {
			return new WP_Error( 'invalid_conflict', 'Conflict not found' );
		}

		$conflict    = $conflicts[ $conflict_id ];
		$local_item  = $conflict['local_item'];
		$remote_item = $conflict['remote_item'];

		switch ( $choice ) {
			case 'local':
				$resolved_item = $local_item;
				break;
			case 'remote':
				$resolved_item = $remote_item;
				break;
			case 'merge':
				$resolved_item = $this->merge_items( $local_item, $remote_item );
				break;
			default:
				return new WP_Error( 'invalid_choice', 'Invalid resolution choice' );
		}

		// Mark conflict as resolved.
		$conflicts[ $conflict_id ]['status']      = 'resolved';
		$conflicts[ $conflict_id ]['resolution']  = $choice;
		$conflicts[ $conflict_id ]['resolved_at'] = current_time( 'mysql' );
		update_option( 'wp_mcp_ai_vault_conflicts', $conflicts );

		return $resolved_item;
	}

	/**
	 * Clear resolved conflicts
	 */
	public function clear_resolved_conflicts() {
		$conflicts = get_option( 'wp_mcp_ai_vault_conflicts', array() );
		$pending   = array_filter(
			$conflicts,
			function ( $conflict ) {
				return $conflict['status'] === 'pending';
			}
		);
		update_option( 'wp_mcp_ai_vault_conflicts', $pending );
	}

	/**
	 * Get conflict resolution logs
	 *
	 * @param int $limit Number of logs to retrieve.
	 * @return array Conflict resolution logs.
	 */
	public function get_conflict_logs( $limit = 50 ) {
		$logs = get_option( 'wp_mcp_ai_vault_conflict_logs', array() );
		return array_slice( $logs, 0, $limit );
	}
}
