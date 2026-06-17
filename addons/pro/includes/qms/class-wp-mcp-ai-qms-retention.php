<?php
/**
 * QMS Retention & Disposition.
 *
 * Daily cron evaluates `_qms_effective_date + _qms_retention_years`. When due,
 * the record is automatically marked obsolete and (if PARA is enabled) moved
 * to the Archives bucket. Records are never destroyed by automation; manual
 * purge requires `manage_qms`.
 *
 * Also surfaces upcoming review reminders.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention cron.
 */
class WP_MCP_AI_QMS_Retention {

	const CRON_HOOK   = 'wp_mcp_ai_qms_retention_sweep';
	const REVIEW_HOOK = 'wp_mcp_ai_qms_review_due';

	/**
	 * Initialize.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sweep' ) );
		add_action( self::REVIEW_HOOK, array( __CLASS__, 'run_review_check' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 25 );
	}

	/**
	 * Schedule cron jobs.
	 */
	public static function schedule() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::REVIEW_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::REVIEW_HOOK );
		}
	}

	/**
	 * Unschedule.
	 */
	public static function unschedule() {
		foreach ( array( self::CRON_HOOK, self::REVIEW_HOOK ) as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}

	/**
	 * Run the retention sweep.
	 */
	public static function run_sweep() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}
		$query = new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_qms_status',
						'value'   => array(
							WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
							WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED,
						),
						'compare' => 'IN',
					),
					array(
						'key'     => '_qms_retention_years',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$now = time();
		foreach ( $query->posts as $post_id ) {
			$effective       = (string) get_post_meta( $post_id, '_qms_effective_date', true );
			$retention_years = (int) get_post_meta( $post_id, '_qms_retention_years', true );
			if ( ! $effective || $retention_years <= 0 ) {
				continue;
			}
			$effective_ts = strtotime( $effective . ' 00:00:00 UTC' );
			if ( ! $effective_ts ) {
				continue;
			}
			$retention_seconds = $retention_years * YEAR_IN_SECONDS;
			if ( ( $effective_ts + $retention_seconds ) <= $now ) {
				$result = WP_MCP_AI_QMS_Workflow::transition(
					$post_id,
					WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
					array( 'reason' => __( 'Retention period elapsed.', 'mcp-ai-wpoos-pro' ) )
				);
				unset( $result );
			}
		}
	}

	/**
	 * Daily review-due check: emails owners of records whose next_review_date is today or past.
	 */
	public static function run_review_check() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}
		$today = current_time( 'Y-m-d' );

		$query = new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_qms_next_review_date',
						'value'   => $today,
						'compare' => '<=',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_qms_status',
						'value'   => array(
							WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
							WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
						),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			/**
			 * Fires when a controlled document is due (or overdue) for review.
			 *
			 * @since 1.2.0
			 *
			 * @param int $post_id Record ID.
			 */
			do_action( 'wp_mcp_ai_qms_review_due_for_record', (int) $post_id );
		}
	}
}
