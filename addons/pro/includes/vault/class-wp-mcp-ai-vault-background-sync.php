<?php
/**
 * Password Vault Background Sync Service
 *
 * Handles automatic background synchronization with Bitwarden servers using WP-Cron.
 *
 * @package MCP_AI_WP_OOS
 * @subpackage Pro/Vault
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Background Sync Service Class
 *
 * Provides automatic vault synchronization with external Bitwarden/Vaultwarden servers.
 */
class WP_MCP_AI_Vault_Background_Sync {

	/**
	 * Sync service instance
	 *
	 * @var WP_MCP_AI_Bitwarden_Sync_Service
	 */
	private $sync_service;

	/**
	 * Cron hook name
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_vault_background_sync';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->sync_service = new WP_MCP_AI_Bitwarden_Sync_Service();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Register cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Register cron action.
		add_action( self::CRON_HOOK, array( $this, 'run_background_sync' ) );

		// Schedule sync on settings update.
		add_action( 'update_option_wp_mcp_ai_vault_sync_settings', array( $this, 'schedule_sync' ), 10, 2 );
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes', 'mcp-ai-wpoos' ),
		);
		$schedules['every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 Minutes', 'mcp-ai-wpoos' ),
		);
		$schedules['every_2_hours']    = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 2 Hours', 'mcp-ai-wpoos' ),
		);
		$schedules['every_6_hours']    = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours', 'mcp-ai-wpoos' ),
		);

		return $schedules;
	}

	/**
	 * Schedule background sync
	 *
	 * @param mixed $old_value Old option value.
	 * @param mixed $new_value New option value.
	 */
	public function schedule_sync( $old_value, $new_value ) {
		// Clear existing schedule.
		$this->unschedule_sync();

		// Check if auto sync is enabled.
		if ( empty( $new_value['auto_sync_enabled'] ) ) {
			return;
		}

		// Get sync interval.
		$interval = ! empty( $new_value['sync_interval'] ) ? $new_value['sync_interval'] : 'hourly';

		// Schedule next sync.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $interval, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule background sync
	 */
	public function unschedule_sync() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run background sync
	 */
	public function run_background_sync() {
		// Get sync settings.
		$settings = get_option( 'wp_mcp_ai_vault_sync_settings', array() );

		if ( empty( $settings['auto_sync_enabled'] ) ) {
			return;
		}

		// Log sync start.
		$this->log_sync_event( 'Background sync started' );

		try {
			// Get sync direction.
			$direction = ! empty( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'pull';

			// Perform sync.
			$result = $this->sync_service->sync( $direction, $settings );

			if ( is_wp_error( $result ) ) {
				$this->log_sync_event( 'Sync failed: ' . $result->get_error_message(), 'error' );
			} else {
				$this->log_sync_event( 'Sync completed successfully' );

				// Update last sync time.
				update_option( 'wp_mcp_ai_vault_last_sync', time() );
			}
		} catch ( Exception $e ) {
			$this->log_sync_event( 'Sync exception: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Log sync event
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (info, error).
	 */
	private function log_sync_event( $message, $level = 'info' ) {
		$log_entry = array(
			'timestamp' => current_time( 'mysql' ),
			'message'   => $message,
			'level'     => $level,
		);

		// Get existing logs.
		$logs = get_option( 'wp_mcp_ai_vault_sync_logs', array() );

		// Add new log entry.
		array_unshift( $logs, $log_entry );

		// Keep only last 50 entries.
		$logs = array_slice( $logs, 0, 50 );

		// Save logs.
		update_option( 'wp_mcp_ai_vault_sync_logs', $logs, false );

		// Also log to WordPress debug log if enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'WP_MCP_AI Vault Sync [%s]: %s', $level, $message ) );
		}
	}

	/**
	 * Get sync logs
	 *
	 * @param int $limit Number of logs to retrieve.
	 * @return array Sync logs.
	 */
	public function get_sync_logs( $limit = 20 ) {
		$logs = get_option( 'wp_mcp_ai_vault_sync_logs', array() );
		return array_slice( $logs, 0, $limit );
	}

	/**
	 * Clear sync logs
	 */
	public function clear_sync_logs() {
		delete_option( 'wp_mcp_ai_vault_sync_logs' );
	}

	/**
	 * Get last sync time
	 *
	 * @return int|null Unix timestamp of last sync or null if never synced.
	 */
	public function get_last_sync_time() {
		return get_option( 'wp_mcp_ai_vault_last_sync', null );
	}

	/**
	 * Get next scheduled sync time
	 *
	 * @return int|false Unix timestamp of next sync or false if not scheduled.
	 */
	public function get_next_sync_time() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Manually trigger sync
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function trigger_manual_sync() {
		$settings  = get_option( 'wp_mcp_ai_vault_sync_settings', array() );
		$direction = ! empty( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'pull';

		try {
			$result = $this->sync_service->sync( $direction, $settings );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			update_option( 'wp_mcp_ai_vault_last_sync', time() );
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'sync_exception', $e->getMessage() );
		}
	}
}
