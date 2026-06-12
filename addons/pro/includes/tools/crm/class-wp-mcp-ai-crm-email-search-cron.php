<?php
/**
 * CRM Cron Scheduler
 *
 * Centralized WP Cron registration for CRM background jobs:
 * email search cache refresh, automatic lead pruning, and
 * message log compaction.
 *
 * Hooks wired:
 *  - wp_mcp_ai_crm_email_search_leads_refresh          (hourly)
 *  - wp_mcp_ai_crm_email_search_correspondence_refresh  (hourly)
 *  - wp_mcp_ai_crm_email_search_accounting_refresh      (twicedaily)
 *  - wp_mcp_ai_crm_auto_prune                           (daily)
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Cron scheduler.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Email_Search_Cron {

	/**
	 * Cron hook for automatic lead pruning.
	 *
	 * @since 2.9.0
	 * @var string
	 */
	const AUTO_PRUNE_HOOK = 'wp_mcp_ai_crm_auto_prune';

	/**
	 * Hooks to schedule: hook_name => recurrence.
	 *
	 * @var array<string, string>
	 */
	const SCHEDULES = array(
		'wp_mcp_ai_crm_email_search_leads_refresh'          => 'hourly',
		'wp_mcp_ai_crm_email_search_correspondence_refresh'  => 'hourly',
		'wp_mcp_ai_crm_email_search_accounting_refresh'      => 'twicedaily',
	);

	/**
	 * Initialize cron scheduling.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'maybe_schedule_all' ), 30 );

		// Register the auto-prune handler.
		add_action( self::AUTO_PRUNE_HOOK, array( __CLASS__, 'run_auto_prune' ) );
	}

	/**
	 * Schedule all email search cron hooks and auto-prune if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule_all() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		foreach ( self::SCHEDULES as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}

		// Schedule auto-prune if hygiene pruning is enabled.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$hygiene       = WP_MCP_AI_CRM_Engine::get_hygiene_settings();
			$prune_enabled = ! empty( $hygiene['auto_prune_spam'] )
				|| ! empty( $hygiene['auto_prune_excluded'] )
				|| ( ! empty( $hygiene['auto_prune_stale_days'] ) && $hygiene['auto_prune_stale_days'] > 0 );

			if ( $prune_enabled && ! wp_next_scheduled( self::AUTO_PRUNE_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::AUTO_PRUNE_HOOK );
			} elseif ( ! $prune_enabled ) {
				$ts = wp_next_scheduled( self::AUTO_PRUNE_HOOK );
				if ( $ts ) {
					wp_unschedule_event( $ts, self::AUTO_PRUNE_HOOK );
				}
			}
		}
	}

	/**
	 * Run automatic lead pruning based on hygiene settings.
	 *
	 * Called daily via WP Cron. Respects the hygiene configuration:
	 * auto_prune_spam, auto_prune_excluded, auto_prune_stale_days.
	 * Safety-capped at 100 leads per run to prevent mass deletion.
	 *
	 * @since 2.9.0
	 */
	public static function run_auto_prune() {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return;
		}

		$hygiene = WP_MCP_AI_CRM_Engine::get_hygiene_settings();

		$prune_spam     = ! empty( $hygiene['auto_prune_spam'] );
		$prune_excluded = ! empty( $hygiene['auto_prune_excluded'] );
		$prune_stale    = ! empty( $hygiene['auto_prune_stale_days'] )
			? absint( $hygiene['auto_prune_stale_days'] )
			: 0;

		// Skip if nothing is configured to auto-prune.
		if ( ! $prune_spam && ! $prune_excluded && $prune_stale <= 0 ) {
			return;
		}

		// Load the prune tool.
		$_prune_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-prune-crm-messages.php';
		if ( ! file_exists( $_prune_file ) ) {
			return;
		}
		require_once $_prune_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Prune_CRM_Messages' ) ) {
			return;
		}

		$pruner = new WP_MCP_AI_Tool_Prune_CRM_Messages();
		$result = $pruner->execute(
			array(
				'dry_run'            => false,
				'prune_spam'         => $prune_spam,
				'prune_excluded'     => $prune_excluded,
				'prune_stale_days'   => $prune_stale,
				'prune_never_engaged' => false, // Opt-in separately.
				'max_prune'          => 100,     // Safety cap per run.
			),
			array( 'user_id' => 0 )
		);

		if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CRM auto-prune error: ' . $result->get_error_message() );
		}
	}

	/**
	 * Clear all email search cron hooks (called on deactivation).
	 *
	 * @since 2.9.0
	 */
	public static function unschedule_all() {
		foreach ( array_keys( self::SCHEDULES ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		$ts = wp_next_scheduled( self::AUTO_PRUNE_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::AUTO_PRUNE_HOOK );
		}
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_CRM_Email_Search_Cron', 'init' ), 30 );
