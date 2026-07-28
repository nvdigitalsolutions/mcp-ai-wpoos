<?php
/**
 * Incident Notifier
 *
 * Dispatches notifications for incident lifecycle events via email, outbound
 * webhooks, and channel broadcast. Provides phase-aware message templates
 * that translate internal phases into customer-facing communication.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Incident_Notifier' ) ) {
	/**
	 * Incident Notifier class.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Incident_Notifier {

		/**
		 * Minimum seconds between notifications for the same incident.
		 *
		 * @since 1.4.0
		 * @var int
		 */
		const NOTIFICATION_COOLDOWN = 60;

		/**
		 * Initialize notification hooks.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wp_mcp_ai_incident_created', array( __CLASS__, 'notify_created' ), 10, 2 );
			add_action( 'wp_mcp_ai_incident_phase_changed', array( __CLASS__, 'notify_phase_changed' ), 10, 3 );
			add_action( 'wp_mcp_ai_incident_resolved', array( __CLASS__, 'notify_resolved' ), 10, 1 );
		}

		/**
		 * Send notification when an incident is created.
		 *
		 * @since 1.4.0
		 *
		 * @param int   $incident_id Incident post ID.
		 * @param array $data        Creation data.
		 * @return void
		 */
		public static function notify_created( int $incident_id, array $data ): void {
			$post = get_post( $incident_id );
			if ( ! $post ) {
				return;
			}

			$is_auto = isset( $data['auto_created'] ) && $data['auto_created'];

			$phase_label = WP_MCP_AI_Incident_CPT::get_phase_label( WP_MCP_AI_Incident_CPT::PHASE_DETECTED );

			$subject = sprintf(
				/* translators: 1: site name, 2: severity */
				__( '[%1$s] New Incident: %2$s', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' ),
				get_post_meta( $incident_id, '_mcp_ai_incident_severity', true )
			);

			$message = sprintf(
				/* translators: 1: title, 2: phase, 3: auto/manual indicator */
				__( "A new incident has been %3\$s.\n\nTitle: %1\$s\nStatus: %2\$s\n\nDetails will follow as the investigation progresses.", 'mcp-ai-wpoos-pro' ),
				$post->post_title,
				$phase_label,
				$is_auto ? __( 'automatically detected', 'mcp-ai-wpoos-pro' ) : __( 'reported', 'mcp-ai-wpoos-pro' )
			);

			self::send_email( $incident_id, $subject, $message );
			self::dispatch_webhook( 'incident.created', $post );
		}

		/**
		 * Send notification when an incident changes phase.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $incident_id Incident post ID.
		 * @param string $old_phase   Previous phase.
		 * @param string $new_phase   New phase.
		 * @return void
		 */
		public static function notify_phase_changed( int $incident_id, string $old_phase, string $new_phase ): void {
			unset( $old_phase );

			if ( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED === $new_phase ) {
				return; // Handled by notify_resolved.
			}

			if ( ! self::can_notify( $incident_id ) ) {
				return;
			}

			$post = get_post( $incident_id );
			if ( ! $post ) {
				return;
			}

			$phase_label = WP_MCP_AI_Incident_CPT::get_phase_label( $new_phase );
			$templates   = self::get_phase_templates();
			$template    = $templates[ $new_phase ] ?? __( 'Incident status updated to: %s.', 'mcp-ai-wpoos-pro' );

			$subject = sprintf(
				/* translators: 1: site name, 2: phase label */
				__( '[%1$s] Incident Update: %2$s', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' ),
				$phase_label
			);

			$message = sprintf( $template, $post->post_title );

			self::send_email( $incident_id, $subject, $message );
			self::dispatch_webhook( 'incident.updated', $post );
			self::broadcast_channels( $incident_id, $subject, $message );
		}

		/**
		 * Send notification when an incident is resolved.
		 *
		 * @since 1.4.0
		 *
		 * @param int $incident_id Incident post ID.
		 * @return void
		 */
		public static function notify_resolved( int $incident_id ): void {
			$post = get_post( $incident_id );
			if ( ! $post ) {
				return;
			}

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Incident Resolved', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: %s: incident title */
				__( "The incident \"%s\" has been resolved.\n\nAll affected services should now be operating normally.\n\nA post-incident review will be conducted to identify lessons learned.", 'mcp-ai-wpoos-pro' ),
				$post->post_title
			);

			self::send_email( $incident_id, $subject, $message );
			self::dispatch_webhook( 'incident.resolved', $post );
			self::broadcast_channels( $incident_id, $subject, $message );
		}

		/**
		 * Get phase-aware notification templates.
		 *
		 * @since 1.4.0
		 *
		 * @return array<string, string>
		 */
		private static function get_phase_templates(): array {
			return array(
				WP_MCP_AI_Incident_CPT::PHASE_DETECTED   =>
					/* translators: %s: incident title */
					__( 'We are investigating reports of "%s". More information to follow.', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_INVESTIGATING =>
					/* translators: %s: incident title */
					__( 'Investigation in progress for "%s". Our team is actively diagnosing the issue.', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_IDENTIFIED =>
					/* translators: %s: incident title */
					__( 'The issue causing "%s" has been identified. A fix is being prepared.', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Incident_CPT::PHASE_MONITORING =>
					/* translators: %s: incident title */
					__( 'A fix for "%s" has been deployed. We are monitoring the results to ensure stability.', 'mcp-ai-wpoos-pro' ),
			);
		}

		/**
		 * Check cooldown to prevent notification spam.
		 *
		 * @since 1.4.0
		 *
		 * @param int $incident_id Incident post ID.
		 * @return bool
		 */
		private static function can_notify( int $incident_id ): bool {
			$cooldown_key = 'wp_mcp_ai_incident_notify_cooldown_' . $incident_id;
			$last_notify  = (int) get_transient( $cooldown_key );

			if ( $last_notify > 0 ) {
				return false;
			}

			set_transient( $cooldown_key, time(), self::NOTIFICATION_COOLDOWN );
			return true;
		}

		/**
		 * Send email notification.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $incident_id Incident post ID.
		 * @param string $subject     Email subject.
		 * @param string $message     Email body.
		 * @return void
		 */
		private static function send_email( int $incident_id, string $subject, string $message ): void {
			$to = get_option( 'admin_email' );

			/**
			 * Filter: incident notification recipient email.
			 *
			 * @since 1.4.0
			 *
			 * @param string $to          Recipient email.
			 * @param int    $incident_id Incident post ID.
			 */
			$to = apply_filters( 'wp_mcp_ai_incident_notification_email', $to, $incident_id );

			wp_mail( $to, $subject, $message );
		}

		/**
		 * Dispatch outbound webhook.
		 *
		 * @since 1.4.0
		 *
		 * @param string  $event Webhook event name.
		 * @param WP_Post $post  Incident post.
		 * @return void
		 */
		private static function dispatch_webhook( string $event, WP_Post $post ): void {
			if ( ! class_exists( 'WP_MCP_AI_Outbound_Webhook' ) ) {
				return;
			}

			$services_raw = get_post_meta( $post->ID, '_mcp_ai_incident_services', true );

			$payload = array(
				'event'       => $event,
				'incident_id' => $post->ID,
				'title'       => $post->post_title,
				'phase'       => get_post_meta( $post->ID, '_mcp_ai_incident_phase', true ),
				'severity'    => get_post_meta( $post->ID, '_mcp_ai_incident_severity', true ),
				'services'    => is_array( $services_raw ) ? $services_raw : array(),
			);

			WP_MCP_AI_Outbound_Webhook::get_instance()->dispatch( $event, $payload );
		}

		/**
		 * Broadcast notification to configured channels.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $incident_id Incident post ID.
		 * @param string $subject     Notification subject.
		 * @param string $message     Notification body.
		 * @return void
		 */
		private static function broadcast_channels( int $incident_id, string $subject, string $message ): void {
			$channels_raw = get_post_meta( $incident_id, '_mcp_ai_incident_notify_channels', true );
			$channels     = is_array( $channels_raw ) ? $channels_raw : array();

			if ( empty( $channels ) ) {
				return;
			}

			/** This action is documented in class-wp-mcp-ai-maintenance-notifier.php. */
			do_action( 'wp_mcp_ai_maintenance_broadcast', $incident_id, $subject, $message, $channels );
		}
	}

	// Bootstrap.
	WP_MCP_AI_Incident_Notifier::init();
}
