<?php
/**
 * Email-to-Ticket & SLA Notifications Handler
 *
 * Hooks into the CRM ticket lifecycle to provide:
 *  1. Auto-ticket creation from inbound support messages
 *  2. Admin notification on SLA breach
 *  3. CSAT survey trigger on ticket resolution
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ticket lifecycle hooks and notifications.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_CRM_Ticket_Notifications {

	/**
	 * Initialize hooks.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		// SLA breach notification.
		add_action( 'wp_mcp_ai_crm_ticket_sla_breached', array( __CLASS__, 'notify_sla_breach' ), 10, 2 );

		// CSAT survey trigger on resolution.
		add_action( 'wp_mcp_ai_crm_ticket_resolved', array( __CLASS__, 'trigger_csat_survey' ), 10, 2 );

		// Inbound support request → auto-create ticket.
		add_action( 'wp_mcp_ai_crm_inbound_support_detected', array( __CLASS__, 'auto_create_support_ticket' ), 10, 2 );

		// Send close notification.
		add_action( 'wp_mcp_ai_crm_ticket_closed', array( __CLASS__, 'notify_ticket_closed' ), 10, 2 );
	}

	/**
	 * Notify admin when a ticket SLA is breached.
	 *
	 * Sends an email to the site admin with breach details.
	 *
	 * @since 2.6.0
	 * @param int    $ticket_id   Ticket post ID.
	 * @param string $breach_type 'first_response' or 'resolution'.
	 */
	public static function notify_sla_breach( $ticket_id, $breach_type ) {
		$ticket = get_post( $ticket_id );
		if ( ! $ticket ) {
			return;
		}

		$priority  = get_post_meta( $ticket_id, '_ticket_priority', true );
		$assignee  = (int) get_post_meta( $ticket_id, '_ticket_assignee_id', true );
		$admin_url = get_edit_post_link( $ticket_id, 'raw' );

		$breach_label = 'first_response' === $breach_type
			? __( 'First Response', 'mcp-ai-wpoos-pro' )
			: __( 'Resolution', 'mcp-ai-wpoos-pro' );

		$subject = sprintf(
			/* translators: 1: breach type, 2: ticket ID, 3: ticket subject */
			__( '[SLA BREACH] %1$s — Ticket #%2$d: %3$s', 'mcp-ai-wpoos-pro' ),
			$breach_label,
			$ticket_id,
			$ticket->post_title
		);

		$body = sprintf(
			/* translators: 1: ticket ID, 2: breach type, 3: priority, 4: admin URL */
			__(
				"SLA Breach Alert\n\nTicket #%1\$d has breached its %2\$s SLA target.\n\nPriority: %3\$s\n\nView ticket: %4\$s\n\nPlease take immediate action.",
				'mcp-ai-wpoos-pro'
			),
			$ticket_id,
			$breach_label,
			strtoupper( $priority ),
			$admin_url
		);

		$to = get_option( 'admin_email' );

		// Also notify assignee if different from admin.
		if ( $assignee ) {
			$user = get_userdata( $assignee );
			if ( $user && $user->user_email !== $to ) {
				$to .= ',' . $user->user_email;
			}
		}

		/**
		 * Filter the SLA breach notification recipient(s).
		 *
		 * @since 2.6.0
		 * @param string $to          Comma-separated email addresses.
		 * @param int    $ticket_id   Ticket post ID.
		 * @param string $breach_type Breach type slug.
		 */
		$to = apply_filters( 'wp_mcp_ai_crm_sla_breach_recipients', $to, $ticket_id, $breach_type );

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Trigger CSAT (Customer Satisfaction) survey when a ticket is resolved.
	 *
	 * Fires a hook that integration code can listen to for sending surveys.
	 *
	 * @since 2.6.0
	 * @param int    $ticket_id       Ticket post ID.
	 * @param string $resolution_type Resolution type slug.
	 */
	public static function trigger_csat_survey( $ticket_id, $resolution_type ) {
		$contact_id = get_post_meta( $ticket_id, '_ticket_contact_id', true );
		if ( ! $contact_id ) {
			return;
		}

		$contact_email = get_post_meta( $contact_id, 'email', true );
		$ticket        = get_post( $ticket_id );

		$survey_data = array(
			'ticket_id'       => $ticket_id,
			'ticket_subject'  => $ticket ? $ticket->post_title : '',
			'contact_id'      => (int) $contact_id,
			'contact_email'   => $contact_email ? $contact_email : '',
			'resolution_type' => $resolution_type,
			'resolved_at'     => current_time( 'mysql' ),
		);

		/**
		 * Fires when a CSAT survey should be sent.
		 *
		 * Hook into this to send an email survey, create a webhook call,
		 * or queue a third-party survey (e.g., Delighted, SurveyMonkey).
		 *
		 * @since 2.6.0
		 * @param array $survey_data Survey context data.
		 */
		do_action( 'wp_mcp_ai_crm_ticket_csat_trigger', $survey_data );

		// Record that a survey was queued.
		update_post_meta( $ticket_id, '_ticket_csat_queued', current_time( 'mysql' ) );
	}

	/**
	 * Auto-create a support ticket from an inbound support request.
	 *
	 * Called by the evaluate_inbound_message tool when intent is
	 * classified as 'support_request'.
	 *
	 * @since 2.6.0
	 * @param int   $contact_id Lead/contact ID of the sender.
	 * @param array $message    Message context (body, subject, channel, email, etc.).
	 * @return int|WP_Error Ticket ID or error.
	 */
	public static function auto_create_support_ticket( $contact_id, $message ) {
		if ( ! class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			return new WP_Error( 'no_cpt', __( 'Support ticket post type is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$subject = isset( $message['subject'] ) && ! empty( $message['subject'] )
			? sanitize_text_field( $message['subject'] )
			: sprintf(
				/* translators: %d: contact ID */
				__( 'Support Request from Contact #%d', 'mcp-ai-wpoos-pro' ),
				$contact_id
			);

		$body    = isset( $message['body'] ) ? sanitize_textarea_field( $message['body'] ) : '';
		$channel = isset( $message['channel'] ) ? sanitize_key( $message['channel'] ) : 'email';

		$ticket_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_ticket',
				'post_title'   => $subject,
				'post_content' => $body,
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		// Set meta.
		update_post_meta( $ticket_id, '_ticket_status', 'new' );
		update_post_meta( $ticket_id, '_ticket_priority', 'p2_high' );
		update_post_meta( $ticket_id, '_ticket_source', $channel );
		update_post_meta( $ticket_id, '_ticket_category', 'question' );
		update_post_meta( $ticket_id, '_ticket_contact_id', $contact_id );
		update_post_meta( $ticket_id, '_ticket_assignee_id', 0 );
		update_post_meta( $ticket_id, '_ticket_sla_status', 'on_track' );
		update_post_meta( $ticket_id, '_ticket_reopened_count', 0 );

		// Calculate SLA targets.
		if ( class_exists( 'WP_MCP_AI_Support_Ticket_CPT' ) ) {
			$sla = WP_MCP_AI_Support_Ticket_CPT::calculate_sla_targets( 'p2_high', current_time( 'mysql' ) );
			update_post_meta( $ticket_id, '_ticket_sla_first_response_by', $sla['first_response_by'] );
			update_post_meta( $ticket_id, '_ticket_sla_resolution_by', $sla['resolution_by'] );
		}

		// Fire creation hook.
		do_action(
			'wp_mcp_ai_crm_ticket_created',
			$ticket_id,
			array(
				'subject'    => $subject,
				'priority'   => 'p2_high',
				'contact_id' => $contact_id,
				'auto'       => true,
			)
		);

		return $ticket_id;
	}

	/**
	 * Notify when a ticket is closed.
	 *
	 * @since 2.6.0
	 * @param int    $ticket_id    Ticket post ID.
	 * @param string $close_reason 'auto' or 'manual'.
	 */
	public static function notify_ticket_closed( $ticket_id, $close_reason ) {
		$contact_id = get_post_meta( $ticket_id, '_ticket_contact_id', true );
		if ( ! $contact_id ) {
			return;
		}

		$contact_email = get_post_meta( $contact_id, 'email', true );
		$ticket        = get_post( $ticket_id );

		if ( ! $contact_email || ! $ticket ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: ticket subject */
			__( 'Your support ticket has been closed: %s', 'mcp-ai-wpoos-pro' ),
			$ticket->post_title
		);

		$body = sprintf(
			/* translators: 1: ticket subject, 2: ticket ID */
			__(
				"Hello,\n\nYour support ticket \"%1\$s\" (#%2\$d) has been closed.\n\nIf you need further assistance, please reply to this email to reopen the ticket.\n\nThank you.",
				'mcp-ai-wpoos-pro'
			),
			$ticket->post_title,
			$ticket_id
		);

		/**
		 * Filter whether to send close notification to customers.
		 *
		 * @since 2.6.0
		 * @param bool   $send       Whether to send. Default true.
		 * @param int    $ticket_id  Ticket post ID.
		 * @param string $close_reason Close reason.
		 */
		if ( apply_filters( 'wp_mcp_ai_crm_ticket_send_close_notification', true, $ticket_id, $close_reason ) ) {
			wp_mail( $contact_email, $subject, $body );
		}
	}
}
