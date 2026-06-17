<?php
/**
 * Support Ticket Automation
 *
 * Handles background automation for support tickets:
 *  - Auto-close resolved tickets after N days
 *  - Auto-escalate tickets waiting on third party > N days
 *  - SLA breach detection and hook firing
 *  - Workflow rule trigger registration
 *
 * Follows the CRM IMAP Listener pattern using wp_schedule_event
 * for background processing.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ticket automation manager.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_CRM_Ticket_Automation {

	/**
	 * Cron hook for SLA check + auto-close / auto-escalate.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_crm_ticket_sla_check';

	/**
	 * Cron interval for background checks (every 15 minutes).
	 *
	 * @var string
	 */
	const CRON_INTERVAL = 'wp_mcp_ai_every_15_minutes';

	/**
	 * Initialize.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		// Schedule the cron job.
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );

		// Register the cron handler.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sla_check' ) );

		// Register custom cron interval if not already present.
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );

		// Register workflow rule triggers.
		add_filter( 'wp_mcp_ai_crm_workflow_triggers', array( __CLASS__, 'register_workflow_triggers' ) );

		// Clean up on deactivation.
		register_deactivation_hook(
			WP_MCP_AI_PRO_PATH . 'addons.php',
			array( __CLASS__, 'deactivate' )
		);
	}

	/**
	 * Schedule the cron job if not already scheduled.
	 *
	 * @since 2.6.0
	 */
	public static function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Register custom 15-minute cron interval.
	 *
	 * @since 2.6.0
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function register_cron_interval( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				/* translators: %d: number of minutes */
				'display'  => sprintf( __( 'Every %d Minutes', 'mcp-ai-wpoos-pro' ), 15 ),
			);
		}
		return $schedules;
	}

	/**
	 * Deactivate: clear the scheduled cron hook.
	 *
	 * @since 2.6.0
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run the SLA check for all non-closed tickets.
	 *
	 * Handles:
	 *  - SLA status recalculation (on_track / at_risk / breached)
	 *  - Auto-close of resolved tickets older than threshold
	 *  - Auto-escalate of waiting_on_third_party tickets older than threshold
	 *  - SLA breach hook firing
	 *
	 * @since 2.6.0
	 */
	public static function run_sla_check() {
		if ( ! class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			return;
		}

		$settings = self::get_sla_settings();

		// Query all non-closed tickets.
		$ticket_ids = get_posts(
			array(
				'post_type'      => 'mcp_ai_ticket',
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Background cron batch.
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_ticket_status',
						'value'   => array( 'new', 'triaged', 'in_progress', 'waiting_on_customer', 'waiting_on_third_party', 'resolved' ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $ticket_ids as $ticket_id ) {
			$ticket = get_post( $ticket_id );
			$status = get_post_meta( $ticket_id, '_ticket_status', true );

			// Recalculate SLA status.
			$previous_sla = get_post_meta( $ticket_id, '_ticket_sla_status', true );
			$new_sla      = WP_MCP_AI_Support_Ticket_CPT::recalc_ticket_sla( $ticket_id );

			// Fire SLA breach hook if newly breached.
			if ( 'breached' === $new_sla && 'breached' !== $previous_sla ) {
				/**
				 * Fires when a support ticket SLA is breached.
				 *
				 * @since 2.6.0
				 * @param int    $ticket_id   Ticket post ID.
				 * @param string $breach_type 'first_response' or 'resolution'.
				 */
				do_action( 'wp_mcp_ai_crm_ticket_sla_breached', $ticket_id, self::detect_breach_type( $ticket_id ) );
			}

			// Fire SLA at-risk hook if newly at risk.
			if ( 'at_risk' === $new_sla && 'at_risk' !== $previous_sla && 'breached' !== $previous_sla ) {
				/**
				 * Fires when a support ticket SLA enters at-risk status.
				 *
				 * @since 2.6.0
				 * @param int    $ticket_id Ticket post ID.
				 * @param string $timer_type 'first_response' or 'resolution'.
				 */
				do_action( 'wp_mcp_ai_crm_ticket_sla_at_risk', $ticket_id, self::detect_breach_type( $ticket_id ) );
			}

			// Auto-close resolved tickets.
			if ( 'resolved' === $status && $ticket ) {
				$resolved_at = get_post_meta( $ticket_id, '_ticket_sla_resolved_at', true );
				if ( $resolved_at ) {
					$resolved_ts    = strtotime( $resolved_at );
					$auto_close_sec = (int) $settings['auto_close_resolved_days'] * DAY_IN_SECONDS;
					if ( $auto_close_sec > 0 && ( time() - $resolved_ts ) >= $auto_close_sec ) {
						self::auto_close_ticket( $ticket_id );
					}
				}
			}

			// Auto-escalate tickets waiting on third party.
			if ( 'waiting_on_third_party' === $status && $ticket ) {
				$entered_stage = self::get_stage_entered_time( $ticket_id, 'waiting_on_third_party' );
				$auto_esc_sec  = (int) $settings['auto_escalate_waiting_days'] * DAY_IN_SECONDS;
				if ( $auto_esc_sec > 0 && $entered_stage > 0 && ( time() - $entered_stage ) >= $auto_esc_sec ) {
					self::auto_escalate_ticket( $ticket_id );
				}
			}
		}
	}

	/**
	 * Auto-close a resolved ticket.
	 *
	 * @since 2.6.0
	 * @param int $ticket_id Ticket post ID.
	 */
	private static function auto_close_ticket( $ticket_id ) {
		$old_status = get_post_meta( $ticket_id, '_ticket_status', true );
		update_post_meta( $ticket_id, '_ticket_status', 'closed' );
		update_post_meta( $ticket_id, '_ticket_closed_at', current_time( 'mysql' ) );
		update_post_meta( $ticket_id, '_ticket_closed_by', 0 ); // System (0 = auto).

		// Add activity note.
		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => sprintf(
					/* translators: %d: ticket ID */
					__( 'Ticket #%d auto-closed', 'mcp-ai-wpoos-pro' ),
					$ticket_id
				),
				'post_content' => __( 'Ticket was automatically closed after resolution period expired without customer response.', 'mcp-ai-wpoos-pro' ),
				'post_status'  => 'publish',
			)
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'note' );
			update_post_meta( $activity_id, 'related_type', 'ticket' );
			update_post_meta( $activity_id, 'related_id', $ticket_id );
		}

		// Fire hooks.
		do_action( 'wp_mcp_ai_crm_ticket_status_changed', $ticket_id, $old_status, 'closed' );

		/**
		 * Fires when a support ticket is automatically closed.
		 *
		 * @since 2.6.0
		 * @param int    $ticket_id    Ticket post ID.
		 * @param string $close_reason 'auto'.
		 */
		do_action( 'wp_mcp_ai_crm_ticket_closed', $ticket_id, 'auto' );
	}

	/**
	 * Auto-escalate a ticket waiting on third party.
	 *
	 * @since 2.6.0
	 * @param int $ticket_id Ticket post ID.
	 */
	private static function auto_escalate_ticket( $ticket_id ) {
		$current_priority = get_post_meta( $ticket_id, '_ticket_priority', true );
		$current_priority = $current_priority ? $current_priority : 'p2_high';

		// Determine escalated priority.
		$priority_order = array(
			'p4_low'      => 'p3_medium',
			'p3_medium'   => 'p2_high',
			'p2_high'     => 'p1_critical',
			'p1_critical' => 'p1_critical', // Already max.
		);

		$new_priority = isset( $priority_order[ $current_priority ] )
			? $priority_order[ $current_priority ]
			: 'p2_high';

		if ( $new_priority === $current_priority ) {
			return; // Already at max.
		}

		update_post_meta( $ticket_id, '_ticket_priority', $new_priority );

		// Recalculate SLA targets with new priority.
		$ticket = get_post( $ticket_id );
		if ( $ticket && class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			$sla = WP_MCP_AI_Support_Ticket_CPT::calculate_sla_targets( $new_priority, $ticket->post_date );
			update_post_meta( $ticket_id, '_ticket_sla_first_response_by', $sla['first_response_by'] );
			update_post_meta( $ticket_id, '_ticket_sla_resolution_by', $sla['resolution_by'] );
		}

		// Add activity note.
		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => sprintf(
					/* translators: 1: ticket ID, 2: old priority, 3: new priority */
					__( 'Ticket #%1$d auto-escalated from %2$s to %3$s', 'mcp-ai-wpoos-pro' ),
					$ticket_id,
					$current_priority,
					$new_priority
				),
				'post_content' => __( 'Ticket was automatically escalated after being blocked on third party for too long.', 'mcp-ai-wpoos-pro' ),
				'post_status'  => 'publish',
			)
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'note' );
			update_post_meta( $activity_id, 'related_type', 'ticket' );
			update_post_meta( $activity_id, 'related_id', $ticket_id );
		}
	}

	/**
	 * Detect which SLA timer was breached.
	 *
	 * @since 2.6.0
	 * @param int $ticket_id Ticket post ID.
	 * @return string 'first_response' | 'resolution' | 'unknown'
	 */
	private static function detect_breach_type( $ticket_id ) {
		$fr_at  = get_post_meta( $ticket_id, '_ticket_sla_first_response_at', true );
		$fr_by  = get_post_meta( $ticket_id, '_ticket_sla_first_response_by', true );
		$res_at = get_post_meta( $ticket_id, '_ticket_sla_resolved_at', true );
		$res_by = get_post_meta( $ticket_id, '_ticket_sla_resolution_by', true );
		$now    = time();

		if ( ! $fr_at && $fr_by && $now > strtotime( $fr_by ) ) {
			return 'first_response';
		}
		if ( ! $res_at && $res_by && $now > strtotime( $res_by ) ) {
			return 'resolution';
		}
		return 'unknown';
	}

	/**
	 * Get the approximate time a ticket entered a specific stage.
	 *
	 * Uses the post modified date as a proxy when no explicit timestamp exists.
	 *
	 * @since 2.6.0
	 * @param int    $ticket_id  Ticket post ID.
	 * @param string $stage_slug Stage slug.
	 * @return int Unix timestamp, or 0 if not determinable.
	 */
	private static function get_stage_entered_time( $ticket_id, $stage_slug ) {
		$current_status = get_post_meta( $ticket_id, '_ticket_status', true );
		if ( $current_status !== $stage_slug ) {
			return 0;
		}

		// Use post modified time as best estimate for when stage was entered.
		$ticket = get_post( $ticket_id );
		return $ticket ? strtotime( $ticket->post_modified ) : 0;
	}

	/**
	 * Get resolved SLA settings.
	 *
	 * @since 2.6.0
	 * @return array
	 */
	private static function get_sla_settings() {
		$defaults = array(
			'auto_close_resolved_days'   => 3,
			'auto_escalate_waiting_days' => 3,
		);

		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$engine_settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			if ( isset( $engine_settings['sla'] ) ) {
				$defaults['auto_close_resolved_days']   = (int) ( $engine_settings['sla']['auto_close_resolved_days'] ?? 3 );
				$defaults['auto_escalate_waiting_days'] = (int) ( $engine_settings['sla']['auto_escalate_waiting_days'] ?? 3 );
			}
		}

		return $defaults;
	}

	/**
	 * Register workflow rule triggers for the Pro Workflow Builder.
	 *
	 * @since 2.6.0
	 * @param array $triggers Existing triggers map of slug => label.
	 * @return array
	 */
	public static function register_workflow_triggers( $triggers ) {
		if ( ! is_array( $triggers ) ) {
			$triggers = array();
		}

		$triggers['ticket_created']      = __( 'Support Ticket — Created', 'mcp-ai-wpoos-pro' );
		$triggers['ticket_resolved']     = __( 'Support Ticket — Resolved', 'mcp-ai-wpoos-pro' );
		$triggers['ticket_sla_breached'] = __( 'Support Ticket — SLA Breached', 'mcp-ai-wpoos-pro' );
		$triggers['ticket_reopened']     = __( 'Support Ticket — Reopened', 'mcp-ai-wpoos-pro' );
		$triggers['ticket_escalated']    = __( 'Support Ticket — Escalated', 'mcp-ai-wpoos-pro' );
		$triggers['ticket_closed']       = __( 'Support Ticket — Closed', 'mcp-ai-wpoos-pro' );

		return $triggers;
	}
}
