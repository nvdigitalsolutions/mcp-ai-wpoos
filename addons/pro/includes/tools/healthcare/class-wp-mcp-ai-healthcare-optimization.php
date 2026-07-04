<?php
/**
 * Healthcare Toolkit Performance Optimization
 *
 * Fixes per-member option autoload bloat, adds retention caps,
 * and hardens cron scheduling. The healthcare toolkit stores vital signs,
 * health metrics, care plans, and reminders as per-member or global
 * options that were autoloading on every WordPress page load.
 *
 * Industry reference: WordPress 6.6+ autoload thresholds, HIPAA-aligned
 * data minimization (only keep what's needed, automatically purge the rest).
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Healthcare optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Healthcare_Optimization {

	/**
	 * Cron hook for daily optimization.
	 *
	 * @var string
	 */
	const OPTIMIZE_HOOK = 'wp_mcp_ai_hc_daily_optimize';

	/**
	 * Max care plans per member (prevents unbounded growth).
	 *
	 * @var int
	 */
	const MAX_CARE_PLANS = 50;

	/**
	 * Max health reminders globally.
	 *
	 * @var int
	 */
	const MAX_REMINDERS = 500;

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_healthcare_toolkit'] ) ) {
			return;
		}

		// Register optimization cron.
		add_action( self::OPTIMIZE_HOOK, array( __CLASS__, 'run_daily_optimization' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Force no-autoload on all per-member options via generic pre-update hook.
		add_filter( 'pre_update_option', array( __CLASS__, 'intercept_per_member_options' ), 10, 3 );

		// Cron guard for health reminders.
		add_action( 'wp_mcp_ai_health_reminder_notification', array( __CLASS__, 'handle_reminder_notification' ), 10, 2 );
	}

	/**
	 * Schedule daily optimization.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::OPTIMIZE_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', self::OPTIMIZE_HOOK );
		}
	}

	/**
	 * Intercept per-member option saves to force no-autoload.
	 *
	 * WordPress defaults to autoload=yes when update_option() is called
	 * without the third parameter. Per-member options like
	 * wp_mcp_ai_vital_signs_{id} and wp_mcp_ai_care_plans_{id} should
	 * never autoload as they accumulate with every member.
	 *
	 * @since 2.9.0
	 * @param mixed  $value     New option value.
	 * @param mixed  $old_value Old option value.
	 * @param string $option    Option name.
	 * @return mixed Unmodified value.
	 */
	public static function intercept_per_member_options( $value, $old_value, $option ) {
		// $option should always be a string (the option name), but guard
		// against unexpected callers that may pass non-string values.
		if ( ! is_string( $option ) ) {
			return $value;
		}

		static $per_member_prefixes = array(
			'wp_mcp_ai_vital_signs_',
			'wp_mcp_ai_health_metrics_',
			'wp_mcp_ai_care_plans_',
		);

		$is_per_member = false;
		foreach ( $per_member_prefixes as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				$is_per_member = true;
				break;
			}
		}

		if ( ! $is_per_member ) {
			return $value;
		}

		// Enforce no-autoload.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => $option ),
			array( '%s' ),
			array( '%s' )
		);

		return $value;
	}

	/**
	 * Daily optimization: compact per-member options, prune old reminders.
	 *
	 * @since 2.9.0
	 */
	public static function run_daily_optimization() {
		// 1. Prune expired/old health reminders.
		self::prune_expired_reminders();

		// 2. Cap care plans per member.
		self::cap_care_plans();

		// 3. Compact audit log.
		self::compact_healthcare_audit();
	}

	/**
	 * Prune expired and old completed health reminders.
	 *
	 * @since 2.9.0
	 */
	private static function prune_expired_reminders() {
		$reminders = get_option( 'wp_mcp_ai_health_reminders', array() );
		if ( ! is_array( $reminders ) || empty( $reminders ) ) {
			return;
		}

		$now         = time();
		$thirty_days = 30 * DAY_IN_SECONDS;
		$pruned      = 0;

		foreach ( $reminders as $id => $data ) {
			$timestamp = isset( $data['reminder_timestamp'] ) ? (int) $data['reminder_timestamp'] : 0;
			$status    = isset( $data['status'] ) ? $data['status'] : '';

			// Remove if expired more than 30 days ago, or marked completed.
			if ( ( $timestamp > 0 && ( $now - $timestamp ) > $thirty_days )
				|| 'completed' === $status
			) {
				unset( $reminders[ $id ] );
				++$pruned;
			}
		}

		if ( $pruned > 0 ) {
			update_option( 'wp_mcp_ai_health_reminders', $reminders, false );
		}
	}

	/**
	 * Cap care plans per member to prevent unbounded growth.
	 *
	 * @since 2.9.0
	 */
	private static function cap_care_plans() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'wp_mcp_ai_care_plans_%'
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$plans = get_option( $row->option_name, array() );
			if ( ! is_array( $plans ) || count( $plans ) <= self::MAX_CARE_PLANS ) {
				continue;
			}

			// Keep the most recent plans (sort by created_at).
			uasort(
				$plans,
				function ( $a, $b ) {
					$ta = isset( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
					$tb = isset( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
					return $tb <=> $ta;
				}
			);

			$plans = array_slice( $plans, 0, self::MAX_CARE_PLANS, true );
			update_option( $row->option_name, $plans, false );
		}
	}

	/**
	 * Compact the healthcare audit log if it exceeds recommended size.
	 *
	 * @since 2.9.0
	 */
	private static function compact_healthcare_audit() {
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			return;
		}

		$entries = get_option( 'wp_mcp_ai_healthcare_audit_log', array() );
		if ( ! is_array( $entries ) || count( $entries ) <= 5000 ) {
			return;
		}

		$entries = array_slice( $entries, count( $entries ) - 5000 );
		update_option( 'wp_mcp_ai_healthcare_audit_log', $entries, false );
	}

	/**
	 * Handle reminder notification cron (guards against duplicate firing).
	 *
	 * The original create-health-reminder tool schedules wp_schedule_single_event
	 * without checking wp_next_scheduled first. This handler checks the reminder
	 * status before proceeding as a defence-in-depth measure.
	 *
	 * @since 2.9.0
	 * @param string $reminder_id   Reminder ID.
	 * @param array  $reminder_data Reminder data.
	 */
	public static function handle_reminder_notification( $reminder_id, $reminder_data ) {
		// Check if reminder still exists and is active.
		$all_reminders = get_option( 'wp_mcp_ai_health_reminders', array() );
		if ( ! isset( $all_reminders[ $reminder_id ] ) ) {
			return;
		}

		$stored = $all_reminders[ $reminder_id ];
		if ( isset( $stored['status'] ) && 'completed' === $stored['status'] ) {
			return; // Already handled.
		}

		/**
		 * Fires when a health reminder notification should be sent.
		 *
		 * Plugins should hook here to send email/SMS/push notifications.
		 *
		 * @since 2.9.0
		 * @param string $reminder_id   Reminder identifier.
		 * @param array  $reminder_data Full reminder data.
		 */
		do_action( 'wp_mcp_ai_health_reminder_notify', $reminder_id, $reminder_data );

		// Mark as completed.
		$all_reminders[ $reminder_id ]['status']       = 'completed';
		$all_reminders[ $reminder_id ]['completed_at'] = current_time( 'mysql' );
		update_option( 'wp_mcp_ai_health_reminders', $all_reminders, false );
	}
}
