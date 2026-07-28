<?php
/**
 * Maintenance Notifier
 *
 * Dispatches notifications for maintenance window lifecycle events via
 * email, outbound webhooks, and channel broadcast. Hooks into the
 * maintenance CPT's action hooks to send phase-appropriate messages.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Maintenance_Notifier' ) ) {
	/**
	 * Maintenance Notifier class.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Maintenance_Notifier {

		/**
		 * Initialize notification hooks.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wp_mcp_ai_maintenance_scheduled', array( __CLASS__, 'notify_scheduled' ), 10, 2 );
			add_action( 'wp_mcp_ai_maintenance_started', array( __CLASS__, 'notify_started' ), 10, 1 );
			add_action( 'wp_mcp_ai_maintenance_completed', array( __CLASS__, 'notify_completed' ), 10, 1 );
			add_action( 'wp_mcp_ai_maintenance_cancelled', array( __CLASS__, 'notify_cancelled' ), 10, 1 );
			add_action( 'wp_mcp_ai_maintenance_reminder', array( __CLASS__, 'notify_reminder' ), 10, 2 );
		}

		/**
		 * Send notification when a maintenance window is scheduled.
		 *
		 * @since 1.3.0
		 *
		 * @param int   $window_id Maintenance window post ID.
		 * @param array $data      Request data from REST create.
		 * @return void
		 */
		public static function notify_scheduled( int $window_id, array $data ): void {
			$post = get_post( $window_id );
			if ( ! $post ) {
				return;
			}

			$start = get_post_meta( $window_id, '_mcp_ai_maintenance_start', true );
			$end   = get_post_meta( $window_id, '_mcp_ai_maintenance_end', true );

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Maintenance Window Scheduled', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: 1: maintenance title, 2: start time, 3: end time, 4: description */
				__(
					"A maintenance window has been scheduled.\n\nTitle: %1\$s\nStart: %2\$s\nEnd: %3\$s\n\n%4\$s\n\nYou will receive a reminder before the window begins.",
					'mcp-ai-wpoos-pro'
				),
				$post->post_title,
				$start,
				$end,
				wp_strip_all_tags( $post->post_content )
			);

			self::send_email( $subject, $message );
			self::dispatch_webhook( 'maintenance.scheduled', $post );
			self::broadcast_channels( $window_id, $subject, $message );
		}

		/**
		 * Send notification when a maintenance window starts.
		 *
		 * @since 1.3.0
		 *
		 * @param int $window_id Maintenance window post ID.
		 * @return void
		 */
		public static function notify_started( int $window_id ): void {
			$post = get_post( $window_id );
			if ( ! $post ) {
				return;
			}

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Maintenance Started', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: %s: maintenance title */
				__( "Maintenance window \"%s\" is now in progress.\n\nDuring this time, some services may be unavailable or degraded.", 'mcp-ai-wpoos-pro' ),
				$post->post_title
			);

			self::send_email( $subject, $message );
			self::dispatch_webhook( 'maintenance.started', $post );
			self::broadcast_channels( $window_id, $subject, $message );
		}

		/**
		 * Send notification when a maintenance window completes.
		 *
		 * @since 1.3.0
		 *
		 * @param int $window_id Maintenance window post ID.
		 * @return void
		 */
		public static function notify_completed( int $window_id ): void {
			$post = get_post( $window_id );
			if ( ! $post ) {
				return;
			}

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Maintenance Completed', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: %s: maintenance title */
				__( "Maintenance window \"%s\" has been completed.\n\nAll services should now be operating normally.", 'mcp-ai-wpoos-pro' ),
				$post->post_title
			);

			self::send_email( $subject, $message );
			self::dispatch_webhook( 'maintenance.completed', $post );
			self::broadcast_channels( $window_id, $subject, $message );
		}

		/**
		 * Send notification when a maintenance window is cancelled.
		 *
		 * @since 1.3.0
		 *
		 * @param int $window_id Maintenance window post ID.
		 * @return void
		 */
		public static function notify_cancelled( int $window_id ): void {
			$post = get_post( $window_id );
			if ( ! $post ) {
				return;
			}

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Maintenance Cancelled', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: %s: maintenance title */
				__( "Maintenance window \"%s\" has been cancelled.\n\nNo service interruption will occur.", 'mcp-ai-wpoos-pro' ),
				$post->post_title
			);

			self::send_email( $subject, $message );
			self::dispatch_webhook( 'maintenance.cancelled', $post );
		}

		/**
		 * Send pre-maintenance reminder notification.
		 *
		 * @since 1.3.0
		 *
		 * @param int $window_id     Maintenance window post ID.
		 * @param int $minutes_until Minutes until the window starts.
		 * @return void
		 */
		public static function notify_reminder( int $window_id, int $minutes_until ): void {
			$post = get_post( $window_id );
			if ( ! $post ) {
				return;
			}

			$start = get_post_meta( $window_id, '_mcp_ai_maintenance_start', true );

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Maintenance Reminder', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: 1: maintenance title, 2: minutes until start, 3: start time */
				__( "Reminder: Maintenance window \"%1\$s\" begins in %2\$d minutes at %3\$s.\n\nPlease ensure any critical work is saved before the window starts.", 'mcp-ai-wpoos-pro' ),
				$post->post_title,
				$minutes_until,
				$start
			);

			self::send_email( $subject, $message );
			self::broadcast_channels( $window_id, $subject, $message );
		}

		/**
		 * Send an email notification to the admin.
		 *
		 * @since 1.3.0
		 *
		 * @param string $subject Email subject.
		 * @param string $message Email body.
		 * @return void
		 */
		private static function send_email( string $subject, string $message ): void {
			$to = get_option( 'admin_email' );

			/**
			 * Filter: maintenance notification recipient email.
			 *
			 * @since 1.3.0
			 *
			 * @param string $to      Recipient email address.
			 * @param string $subject Email subject.
			 */
			$to = apply_filters( 'wp_mcp_ai_maintenance_notification_email', $to, $subject );

			wp_mail( $to, $subject, $message );
		}

		/**
		 * Dispatch an outbound webhook for a maintenance event.
		 *
		 * @since 1.3.0
		 *
		 * @param string  $event Webhook event name.
		 * @param WP_Post $post  Maintenance window post.
		 * @return void
		 */
		private static function dispatch_webhook( string $event, WP_Post $post ): void {
			if ( ! class_exists( 'WP_MCP_AI_Outbound_Webhook' ) ) {
				return;
			}

			$payload = array(
				'event'     => $event,
				'window_id' => $post->ID,
				'title'     => $post->post_title,
				'status'    => get_post_meta( $post->ID, '_mcp_ai_maintenance_status', true ),
				'start'     => get_post_meta( $post->ID, '_mcp_ai_maintenance_start', true ),
				'end'       => get_post_meta( $post->ID, '_mcp_ai_maintenance_end', true ),
				'services'  => is_array( get_post_meta( $post->ID, '_mcp_ai_maintenance_services', true ) ) ? get_post_meta( $post->ID, '_mcp_ai_maintenance_services', true ) : array(),
			);

			WP_MCP_AI_Outbound_Webhook::get_instance()->dispatch( $event, $payload );
		}

		/**
		 * Broadcast notification to configured channels.
		 *
		 * @since 1.3.0
		 *
		 * @param int    $window_id Maintenance window post ID.
		 * @param string $subject   Notification subject.
		 * @param string $message   Notification body.
		 * @return void
		 */
		private static function broadcast_channels( int $window_id, string $subject, string $message ): void {
			$channels = get_post_meta( $window_id, '_mcp_ai_maintenance_notify_channels', true );
			if ( empty( $channels ) || ! is_array( $channels ) ) {
				return;
			}

			/**
			 * Fires to broadcast a maintenance notification to configured channels.
			 *
			 * Channel broadcast integrations (Schedule Manager's unified_channel_broadcast
			 * tool) should hook into this action.
			 *
			 * @since 1.3.0
			 *
			 * @param int    $window_id Maintenance window post ID.
			 * @param string $subject   Notification subject.
			 * @param string $message   Notification body.
			 * @param array  $channels  Target channel identifiers.
			 */
			do_action( 'wp_mcp_ai_maintenance_broadcast', $window_id, $subject, $message, $channels );
		}
	}

	// Bootstrap.
	WP_MCP_AI_Maintenance_Notifier::init();
}
