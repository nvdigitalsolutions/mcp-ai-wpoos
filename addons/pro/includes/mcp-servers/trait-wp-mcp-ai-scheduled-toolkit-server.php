<?php
/**
 * Scheduled Toolkit Server Trait
 *
 * Shared behaviour for toolkit MCP servers backed by an Action Scheduler sync engine
 * and a JetEngine CCT cache layer (FlowHub, Shopify Sync, EZuite).
 *
 * Provides:
 * - get_sync_interval()           — reads the configured sync interval from the
 *                                   sync engine's option key.
 * - get_sync_status()             — last sync timestamp, status, row count.
 * - get_connection_status()       — whether the remote API connection is healthy.
 * - annotate_sync_limits()        — merges sync_interval_seconds into the
 *                                   effective_limits() return.
 * - sync_tool_is_read_only()      — marks the sync trigger as write, all others
 *                                   as read (for descriptor scoping).
 * - get_default_limits()          — sensible defaults matching sync-server
 *                                   workload profiles.
 *
 * @package WP_MCP_AI_Pro
 * @since  1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for toolkit MCP servers backed by an Action Scheduler sync engine.
 *
 * Consumed by FlowHub, Shopify Sync, and EZuite MCP servers.  The Pro Scheduler
 * server does NOT use this trait — its architecture is based on WP-Cron +
 * WP_MCP_AI_Pro_Schedule_Manager, not Action Scheduler + CCT cache + external API.
 *
 * @since 1.5.0
 */
trait WP_MCP_AI_Scheduled_Toolkit_Server_Trait {

	/**
	 * Get the fully-qualified class name of the sync engine.
	 *
	 * Concrete servers MUST override this.
	 *
	 * @return string
	 */
	abstract public function get_sync_engine_class();

	/**
	 * Get the Action Scheduler hook name for the full sync action.
	 *
	 * Concrete servers MUST override this.
	 *
	 * @return string
	 */
	abstract public function get_sync_hook_name();

	/**
	 * Get the WordPress option key that stores the sync interval.
	 *
	 * Concrete servers SHOULD override this if their interval is stored in a
	 * non-standard option key.  Default returns the toolkit settings option.
	 *
	 * @return string
	 */
	public function get_sync_interval_option_key() {
		return 'wp_mcp_ai_' . $this->get_slug() . '_settings';
	}

	/**
	 * Read the configured sync interval in seconds.
	 *
	 * @return int Sync interval in seconds, 0 if unknown.
	 */
	public function get_sync_interval() {
		$option  = get_option( $this->get_sync_interval_option_key(), array() );
		$interval = isset( $option['sync_interval'] ) ? (int) $option['sync_interval'] : 0;
		if ( $interval <= 0 && isset( $option['sync_interval_minutes'] ) ) {
			$interval = (int) $option['sync_interval_minutes'] * MINUTE_IN_SECONDS;
		}
		if ( $interval <= 0 ) {
			// Sensible default: 5 minutes.
			$interval = 5 * MINUTE_IN_SECONDS;
		}
		return max( 60, $interval ); // Floor at 1 minute.
	}

	/**
	 * Get the last sync status summary.
	 *
	 * @return array{last_sync: int, status: string, row_count: int}
	 */
	public function get_sync_status() {
		$hook = $this->get_sync_hook_name();
		$last_sync = 0;
		$status    = 'unknown';
		$row_count = 0;

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$args = array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_COMPLETE,
				'per_page' => 1,
				'orderby'  => 'scheduled_date',
				'order'    => 'DESC',
			);
			$actions = as_get_scheduled_actions( $args );
			if ( ! empty( $actions ) ) {
				$action    = reset( $actions );
				$last_sync = $action->get_schedule() ? $action->get_schedule()->get_date()->getTimestamp() : 0;
				$status    = 'completed';
			} else {
				// Check for running or pending actions.
				$pending_args = array(
					'hook'     => $hook,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				);
				$pending = as_get_scheduled_actions( $pending_args );
				if ( ! empty( $pending ) ) {
					$status = 'pending';
				}
			}
		}

		/**
		 * Filter the sync status array for a scheduled toolkit server.
		 *
		 * @since 1.5.0
		 *
		 * @param array  $status     {last_sync, status, row_count}.
		 * @param string $server_slug The server slug.
		 */
		return apply_filters(
			'wp_mcp_ai_scheduled_toolkit_sync_status',
			array(
				'last_sync' => $last_sync,
				'status'    => $status,
				'row_count' => $row_count,
			),
			$this->get_slug()
		);
	}

	/**
	 * Check whether the remote API connection is healthy.
	 *
	 * Default implementation checks for the existence of a connection in the
	 * Remote Sites manager.  Concrete servers may override for more granular
	 * health checks (e.g. ping the API).
	 *
	 * @return bool
	 */
	public function get_connection_status() {
		// Default: check if this toolkit has a configured remote connection.
		$connections = get_option( 'wp_mcp_ai_remote_connections', array() );
		if ( ! is_array( $connections ) || empty( $connections ) ) {
			return false;
		}

		foreach ( $connections as $connection ) {
			if ( isset( $connection['toolkit'] ) && $connection['toolkit'] === $this->get_slug() ) {
				return ! empty( $connection['api_key'] );
			}
		}

		return false;
	}

	/**
	 * Annotate the limits array with sync-specific metadata.
	 *
	 * Merges `sync_interval_seconds` into the limits return so the MCP
	 * descriptor advertises the background sync cadence to clients.
	 *
	 * @param array $limits Base limits from effective_limits().
	 * @return array
	 */
	public function annotate_sync_limits( $limits ) {
		if ( ! is_array( $limits ) ) {
			$limits = array();
		}
		$limits['sync_interval_seconds'] = $this->get_sync_interval();
		return $limits;
	}

	/**
	 * Determine whether a tool slug is read-only in the context of this server.
	 *
	 * Sync-trigger tools are marked read-write; all CCT-cache query tools and
	 * settings/analytics tools are read-only.
	 *
	 * @param string $tool_slug The tool slug to evaluate.
	 * @return string 'read_only' or 'read_write'.
	 */
	public function sync_tool_is_read_only( $tool_slug ) {
		$write_slugs = array( 'sync', 'trigger', 'writeback' );
		foreach ( $write_slugs as $write_hint ) {
			if ( false !== strpos( $tool_slug, $write_hint ) ) {
				return 'read_write';
			}
		}
		return 'read_only';
	}

	/**
	 * Default limits suitable for a CCT-cache-backed sync server.
	 *
	 * Override in concrete servers for domain-specific defaults.
	 *
	 * @return array{requests_per_minute: int, max_payload_bytes: int, max_iterations: int}
	 */
	public function get_default_limits() {
		return array(
			'requests_per_minute' => 60,
			'max_payload_bytes'   => 262144, // 256 KB.
			'max_iterations'      => 3,
		);
	}

	/**
	 * Effective limits merged with defaults and sync annotations.
	 *
	 * Overrides WP_MCP_AI_Toolkit_Server_Base::effective_limits() when the
	 * trait is used.
	 *
	 * @return array<string,mixed>
	 */
	public function effective_limits() {
		$config     = $this->get_configuration();
		$defaults   = $this->get_default_limits();
		$limits     = array(
			'requests_per_minute' => isset( $config['requests_per_minute'] ) && (int) $config['requests_per_minute'] > 0
				? (int) $config['requests_per_minute']
				: $defaults['requests_per_minute'],
			'max_payload_bytes'   => isset( $config['max_payload_bytes'] ) && (int) $config['max_payload_bytes'] > 0
				? (int) $config['max_payload_bytes']
				: $defaults['max_payload_bytes'],
			'max_iterations'      => isset( $config['max_iterations'] ) && (int) $config['max_iterations'] > 0
				? (int) $config['max_iterations']
				: $defaults['max_iterations'],
		);

		/**
		 * Filter the effective per-server limits.
		 *
		 * @since 1.5.0
		 *
		 * @param array  $limits Limits with `requests_per_minute`, `max_payload_bytes`, `max_iterations`.
		 * @param string $slug   Server slug.
		 */
		$limits = apply_filters( 'wp_mcp_ai_toolkit_mcp_server_limits', $limits, $this->get_slug() );

		return $this->annotate_sync_limits( $limits );
	}

	/**
	 * Compute tool_scopes annotation — marks each candidate tool as
	 * read_only or read_write based on sync_tool_is_read_only().
	 *
	 * @return array<string, string>
	 */
	public function compute_tool_scopes() {
		$scopes = array();
		foreach ( $this->candidate_tool_slugs() as $slug ) {
			$scopes[ $slug ] = $this->sync_tool_is_read_only( $slug );
		}
		return $scopes;
	}
}
