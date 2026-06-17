<?php
/**
 * CRM Gmail API Polling Listener
 *
 * Periodically polls Gmail inboxes via the Gmail API (OAuth) and routes
 * new messages to the CRM inbound evaluation pipeline. Uses Action Scheduler
 * for cron scheduling.
 *
 * Mirrors WP_MCP_AI_CRM_IMAP_Listener but uses the Gmail REST API instead
 * of IMAP, which means it works with the OAuth credentials already configured
 * in CRM Engine settings or Remote Sites connections.
 *
 * Industry-standard pattern: HubSpot, Copper, Streak, and Salesflare all
 * use Gmail API polling with incremental historyId sync for efficient
 * background email import.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gmail API polling listener for the CRM toolkit.
 *
 * Registers a recurring Action Scheduler job that checks Gmail inboxes
 * for new messages via the Gmail REST API and feeds them into
 * evaluate_inbound_message.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Gmail_Listener {

	/**
	 * Action Scheduler hook name for the Gmail polling job.
	 *
	 * @var string
	 */
	const JOB_HOOK = 'wp_mcp_ai_crm_gmail_poll';

	/**
	 * Default polling interval in seconds (5 minutes).
	 *
	 * @var int
	 */
	const DEFAULT_INTERVAL = 300;

	/**
	 * Action Scheduler group name.
	 *
	 * @var string
	 */
	const AS_GROUP = 'crm-gmail';

	/**
	 * Bootstrap hooks. Called during plugins_loaded.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		// Register the Action Scheduler handler.
		add_action( self::JOB_HOOK, array( __CLASS__, 'poll' ) );

		// Schedule the recurring job on init.
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );

		// Clean up on deactivation.
		if ( defined( 'WP_MCP_AI_PRO_FILE' ) ) {
			register_deactivation_hook(
				WP_MCP_AI_PRO_FILE,
				array( __CLASS__, 'unschedule' )
			);
		}
	}

	/**
	 * Schedule the recurring Gmail polling job if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Check if any Gmail connections exist.
		if ( ! self::has_gmail_connections() ) {
			return;
		}

		if ( false === as_has_scheduled_action( self::JOB_HOOK ) ) {
			$crm_settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
				? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
				: array();
			$interval = isset( $crm_settings['integrations']['gmail_poll_interval'] )
				? absint( $crm_settings['integrations']['gmail_poll_interval'] )
				: self::DEFAULT_INTERVAL;
			// Clamp to reasonable bounds: 60s – 3600s.
			$interval = max( 60, min( 3600, $interval ) );

			as_schedule_recurring_action(
				time() + $interval,
				$interval,
				self::JOB_HOOK,
				array(),
				self::AS_GROUP
			);
		}
	}

	/**
	 * Clear the Gmail polling job (called on deactivation).
	 *
	 * @since 2.9.0
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::JOB_HOOK );
		}
	}

	/**
	 * Check whether any Gmail API connections are configured.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	private static function has_gmail_connections() {
		// Check Remote Sites connections.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $connections ) ) {
				foreach ( $connections as $conn ) {
					if ( isset( $conn['connection_type'] )
						&& in_array( $conn['connection_type'], array( 'gmail', 'google_workspace' ), true )
						&& ! empty( $conn['client_id'] )
						&& ! empty( $conn['refresh_token'] )
					) {
						return true;
					}
				}
			}
		}

		// Check global settings.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['gmail_client_id'] ) && ! empty( $settings['gmail_refresh_token'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Poll all configured Gmail connections and import new messages.
	 *
	 * Uses the import_gmail_to_crm tool for each Gmail connection. Processes
	 * incrementally via historyId when available; falls back to search-based
	 * polling.
	 *
	 * @since 2.9.0
	 */
	public static function poll() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Gmail_To_CRM' ) ) {
			$_import_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-import-gmail-to-crm.php';
			if ( file_exists( $_import_file ) ) {
				require_once $_import_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Gmail_To_CRM' ) ) {
				return;
			}
		}

		$crm_settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		// Resolve default query and per-poll limits.
		$default_query  = $crm_settings['integrations']['gmail_default_query'] ?? 'newer_than:7d is:unread';
		$max_per_poll   = isset( $crm_settings['integrations']['gmail_max_per_poll'] )
			? absint( $crm_settings['integrations']['gmail_max_per_poll'] )
			: 10;
		$max_per_poll   = max( 1, min( 25, $max_per_poll ) );
		$use_history_sync = ! empty( $crm_settings['integrations']['gmail_use_history_sync'] );

		$user_context = array( 'user_id' => 0 ); // System context.

		// Collect Gmail connections.
		$connections_to_poll = self::get_gmail_connections();
		if ( empty( $connections_to_poll ) ) {
			return;
		}

		$importer = new WP_MCP_AI_Tool_Import_Gmail_To_CRM();

		foreach ( $connections_to_poll as $conn ) {
			$connection_id = $conn['connection_id'] ?? '';

			// Check per-connection enable toggle (default: enabled).
			if ( isset( $conn['gmail_auto_import'] ) && ! $conn['gmail_auto_import'] ) {
				continue;
			}

			// Determine query. Per-connection override takes priority.
			$query = $conn['gmail_query'] ?? $default_query;

			// Determine incremental sync vs fresh search.
			if ( $use_history_sync && ! empty( $connection_id ) ) {
				$last_history_id = get_option( 'wp_mcp_ai_crm_gmail_history_id_' . $connection_id, '' );
				if ( ! empty( $last_history_id ) ) {
					// Delegate to incremental sync via import tool.
					$args = array(
						'query'           => $query,
						'max_results'     => $max_per_poll,
						'auto_reply'      => false,
						'connection_id'   => $connection_id,
						'use_history_sync' => true,
					);
				} else {
					$args = array(
						'query'         => $query,
						'max_results'   => $max_per_poll,
						'auto_reply'    => false,
						'connection_id' => $connection_id,
					);
				}
			} else {
				$args = array(
					'query'         => $query,
					'max_results'   => $max_per_poll,
					'auto_reply'    => false,
					'connection_id' => $connection_id,
				);
			}

			try {
				$result = $importer->execute( $args, $user_context );

				if ( is_wp_error( $result ) ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'CRM Gmail poll error: ' . $result->get_error_message() );
					}
					continue;
				}

				// Log summary for diagnostics.
				if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
					WP_MCP_AI_CRM_Audit::record(
						'gmail_poll_complete',
						'gmail_connection',
						$connection_id,
						array(
							'emails_fetched' => isset( $result['stats']['total_found'] )
								? (int) $result['stats']['total_found'] : 0,
							'leads_created'  => isset( $result['stats']['leads_created'] )
								? (int) $result['stats']['leads_created'] : 0,
						)
					);
				}
			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'CRM Gmail poll exception: ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Collect all configured Gmail connections across sources.
	 *
	 * @since 2.9.0
	 * @return array<int, array> List of connection data arrays.
	 */
	private static function get_gmail_connections() {
		$connections = array();

		// 1. Remote Sites connections.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $all ) ) {
				foreach ( $all as $cid => $conn ) {
					if ( isset( $conn['connection_type'] )
						&& in_array( $conn['connection_type'], array( 'gmail', 'google_workspace' ), true )
						&& ! empty( $conn['client_id'] )
						&& ! empty( $conn['refresh_token'] )
					) {
						$conn['connection_id'] = $cid;
						$connections[] = $conn;
					}
				}
			}
		}

		// 2. Global settings fallback.
		if ( empty( $connections ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['gmail_client_id'] ) && ! empty( $settings['gmail_refresh_token'] ) ) {
				$connections[] = array(
					'connection_id'   => 'settings',
					'client_id'       => $settings['gmail_client_id'] ?? '',
					'refresh_token'   => $settings['gmail_refresh_token'] ?? '',
					'client_secret'   => $settings['gmail_client_secret'] ?? '',
					'user_email'      => $settings['gmail_user_email'] ?? '',
					'connection_type' => 'gmail',
				);
			}
		}

		return $connections;
	}

	/**
	 * Force re-schedule on settings change (called after CRM settings save).
	 *
	 * @since 2.9.0
	 */
	public static function reschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::JOB_HOOK );
		}
		self::maybe_schedule();
	}
}

// Initialize the listener.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_CRM_Gmail_Listener', 'init' ), 20 );

// Re-schedule when CRM toolkit settings are updated.
add_action( 'update_option_wp_mcp_ai_crm_toolkit_settings', array( 'WP_MCP_AI_CRM_Gmail_Listener', 'reschedule' ) );
add_action( 'wp_mcp_ai_crm_toolkit_settings_saved', array( 'WP_MCP_AI_CRM_Gmail_Listener', 'reschedule' ) );
