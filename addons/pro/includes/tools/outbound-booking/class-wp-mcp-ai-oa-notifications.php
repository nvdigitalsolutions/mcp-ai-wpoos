<?php
/**
 * Outbound Appointment Booking — notifications (Slack + daily digest).
 *
 * Fires Slack webhook messages for booked calls, positive replies, and test
 * promotions, plus a daily pipeline digest (Slack and optional email) so the
 * operator always knows the board state.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slack + digest notifications.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Notifications {

	/**
	 * Send a Slack webhook message.
	 *
	 * @since 2.12.0
	 * @param string $text  Message text (Slack markdown).
	 * @param array  $facts Optional structured facts for payload filters.
	 * @return bool True when delivered.
	 */
	public static function slack( $text, $facts = array() ) {
		$url = WP_MCP_AI_OA_Settings::get_setting( 'slack_webhook_url', '' );
		if ( ! $url ) {
			return false;
		}
		$payload = array( 'text' => $text );

		/**
		 * Filter the Slack payload before delivery.
		 *
		 * @since 2.12.0
		 * @param array  $payload Payload.
		 * @param array  $facts   Context facts.
		 */
		$payload  = apply_filters( 'wp_mcp_ai_oa_slack_payload', $payload, $facts );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return wp_remote_retrieve_response_code( $response ) < 400;
	}

	/**
	 * Notify on a booked call.
	 *
	 * @since 2.12.0
	 * @param int $appointment_id  Appointment post ID.
	 * @param int $lead_id         Lead post ID.
	 * @param int $booking_link_id Booking link post ID.
	 * @return bool
	 */
	public static function notify_booked( $appointment_id, $lead_id, $booking_link_id ) {
		$lead = $lead_id ? get_the_title( $lead_id ) : __( 'New prospect', 'mcp-ai-wpoos-pro' );
		$slot = $appointment_id ? get_post_meta( $appointment_id, '_start_time', true ) : '';
		$link = get_edit_post_link( $appointment_id, '' );

		$text = sprintf(
			/* translators: 1: lead name, 2: booked slot, 3: appointment edit link */
			__( "🎯 *New call booked*\n• Lead: %1\$s\n• Time: %2\$s\n• <%3\$s|Open appointment>", 'mcp-ai-wpoos-pro' ),
			$lead,
			$slot ? $slot : __( 'requested', 'mcp-ai-wpoos-pro' ),
			$link ? $link : admin_url( 'edit.php?post_type=mcp_appointment' )
		);
		return self::slack( $text, compact( 'appointment_id', 'lead_id', 'booking_link_id' ) );
	}

	/**
	 * Notify on a positive reply.
	 *
	 * @since 2.12.0
	 * @param int $lead_id Lead post ID.
	 * @return bool
	 */
	public static function notify_positive_reply( $lead_id ) {
		$link = get_edit_post_link( $lead_id, '' );
		$text = sprintf(
			/* translators: 1: lead name, 2: lead edit link */
			__( "🔥 *Positive reply*\n• Lead: %1\$s\n• <%2\$s|Open lead>", 'mcp-ai-wpoos-pro' ),
			get_the_title( $lead_id ),
			$link ? $link : admin_url( 'edit.php?post_type=mcp_ai_lead' )
		);
		return self::slack( $text, array( 'lead_id' => $lead_id ) );
	}

	/**
	 * Notify on weekly test promotions.
	 *
	 * @since 2.12.0
	 * @param array $promotions Promotion rows.
	 * @return bool
	 */
	public static function notify_weekly_tests( $promotions ) {
		$lines = array( __( "🧪 *Weekly angle tests*\nWinning variants promoted to champion:", 'mcp-ai-wpoos-pro' ) );
		foreach ( $promotions as $p ) {
			$lines[] = sprintf(
				/* translators: 1: angle group, 2: winning variant, 3: sends, 4: replies */
				__( '• %1$s → *%2$s* (%3$d sends, %4$d replies)', 'mcp-ai-wpoos-pro' ),
				$p['group'],
				$p['winner'],
				$p['sends'],
				$p['replies']
			);
		}
		return self::slack( implode( "\n", $lines ), array( 'promotions' => $promotions ) );
	}

	/**
	 * Build the daily digest text from pipeline stats.
	 *
	 * @since 2.12.0
	 * @param array $stats Pipeline stats.
	 * @return string
	 */
	public static function get_digest_text( $stats ) {
		return sprintf(
			/* translators: 1: prospects, 2: messaged, 3: replied, 4: positive, 5: booked, 6: shown */
			__( "📊 *Outbound daily digest*\n• Prospects: %1\$d\n• Messaged: %2\$d\n• Replied: %3\$d (%4\$s)\n• Positive: %5\$d\n• Booked calls: %6\$d\n• Shown up: %7\$d (%8\$s)", 'mcp-ai-wpoos-pro' ),
			$stats['prospects'],
			$stats['messaged'],
			$stats['replied'],
			$stats['reply_rate'] . '%',
			$stats['positive'],
			$stats['booked'],
			$stats['shown'],
			$stats['show_rate'] . '%'
		);
	}

	/**
	 * Daily digest job: Slack + optional admin email.
	 *
	 * @since 2.12.0
	 * @return array Summary.
	 */
	public static function run_digest() {
		$settings = WP_MCP_AI_OA_Settings::get();
		$stats    = WP_MCP_AI_OA_Engine::get_pipeline_stats();
		$text     = self::get_digest_text( $stats );
		$sent     = false;

		if ( ! empty( $settings['digest_enabled'] ) ) {
			$sent = self::slack( $text, array( 'stats' => $stats ) );

			if ( ! empty( $settings['digest_email'] ) ) {
				wp_mail(
					$settings['digest_email'],
					__( 'Outbound daily digest', 'mcp-ai-wpoos-pro' ),
					strip_tags( str_replace( array( '*', '<', '>' ), '', $text ) )
				);
			}
		}
		return array( 'stats' => $stats, 'sent' => $sent );
	}
}
