<?php
/**
 * Calendar Booking & Orchestration Performance Optimization
 *
 * Fixes autoload bloat on business hours and caps schedule counts
 * in the orchestration scheduler.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calendar Booking + Orchestration optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Calendar_Orchestration_Optimization {

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Calendar: force business hours to no-autoload.
		add_action( 'update_option_wp_mcp_ai_business_hours', array( __CLASS__, 'fix_business_hours_autoload' ), 10, 2 );
		add_action( 'added_option_wp_mcp_ai_business_hours', array( __CLASS__, 'fix_business_hours_autoload' ), 10, 2 );

		// Appointment retention: prune old appointments.
		if ( ! empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			add_action( 'init', array( __CLASS__, 'maybe_schedule_appointment_prune' ), 40 );
		}

		// Orchestration: cap schedule count on save.
		add_filter( 'pre_update_option_wp_mcp_ai_pro_schedules', array( __CLASS__, 'cap_schedule_count' ), 10, 2 );

		// Orchestration: orphan detection (weekly).
		if ( ! empty( $settings['enable_orchestration_toolkit'] ) ) {
			add_action( 'wp_mcp_ai_orch_weekly_cleanup', array( __CLASS__, 'detect_orphan_schedules' ) );
			add_action( 'init', array( __CLASS__, 'maybe_schedule_orch_cleanup' ), 40 );
		}
	}

	/**
	 * Force business hours option to no-autoload.
	 *
	 * @since 2.9.0
	 * @param mixed $old       Previous value (unused).
	 * @param mixed $new_value New value (unused).
	 */
	public static function fix_business_hours_autoload( $old, $new_value ) {
		unset( $old, $new_value );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => 'wp_mcp_ai_business_hours' ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Schedule appointment pruning.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule_appointment_prune() {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cal_prune_appointments' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'wp_mcp_ai_cal_prune_appointments' );
		}
		add_action( 'wp_mcp_ai_cal_prune_appointments', array( __CLASS__, 'prune_old_appointments' ) );
	}

	/**
	 * Prune appointments older than 365 days.
	 *
	 * @since 2.9.0
	 */
	public static function prune_old_appointments() {
		if ( ! post_type_exists( 'mcp_appointment' ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-365 days' ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_appointment',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'before' => $cutoff ),
				),
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $id ) {
			wp_delete_post( $id, true );
		}
		wp_reset_postdata();
	}

	/**
	 * Cap pro schedule count at 100 to prevent unbounded option growth.
	 *
	 * @since 2.9.0
	 * @param mixed $new_value New option value.
	 * @param mixed $_old_value Old option value (unused).
	 * @return mixed Capped value.
	 */
	public static function cap_schedule_count( $new_value, $_old_value ) {
		unset( $_old_value );
		if ( ! is_array( $new_value ) || count( $new_value ) <= 100 ) {
			return $new_value;
		}

		// Keep the 100 most recently modified schedules.
		uasort(
			$new_value,
			function ( $a, $b ) {
				$ta = isset( $a['updated_at'] ) ? strtotime( $a['updated_at'] ) : 0;
				$tb = isset( $b['updated_at'] ) ? strtotime( $b['updated_at'] ) : 0;
				return $tb <=> $ta;
			}
		);

		return array_slice( $new_value, 0, 100, true );
	}

	/**
	 * Schedule orchestration weekly cleanup.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule_orch_cleanup() {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_orch_weekly_cleanup' ) ) {
			wp_schedule_event( strtotime( 'next Sunday 04:00:00' ), 'weekly', 'wp_mcp_ai_orch_weekly_cleanup' );
		}
	}

	/**
	 * Detect and remove orphan schedules whose hooks no longer exist.
	 *
	 * @since 2.9.0
	 */
	public static function detect_orphan_schedules() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return;
		}

		$schedules = get_option( 'wp_mcp_ai_pro_schedules', array() );
		if ( ! is_array( $schedules ) ) {
			return;
		}

		$orphaned = array();

		foreach ( $schedules as $schedule_id => $schedule ) {
			// Check if the schedule still has a valid cron hook.
			$hook = isset( $schedule['cron_hook'] ) ? $schedule['cron_hook'] : '';
			if ( empty( $hook ) ) {
				continue;
			}

			// If the schedule is disabled and older than 30 days, flag as orphan.
			$status     = isset( $schedule['status'] ) ? $schedule['status'] : '';
			$updated_at = isset( $schedule['updated_at'] ) ? strtotime( $schedule['updated_at'] ) : 0;

			if ( 'disabled' === $status && $updated_at > 0 && ( time() - $updated_at ) > 30 * DAY_IN_SECONDS ) {
				$orphaned[] = $schedule_id;
			}
		}

		if ( ! empty( $orphaned ) ) {
			foreach ( $orphaned as $id ) {
				unset( $schedules[ $id ] );
			}
			update_option( 'wp_mcp_ai_pro_schedules', $schedules, false );
		}
	}
}

