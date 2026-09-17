<?php
/**
 * CRM Toolkit Gmail Reply Poller
 *
 * Cron-driven inbound reply classification loop: polls Gmail for unread
 * replies from known CRM leads, classifies each snippet's sentiment via the
 * CRM classifier, stores the reply signals on the lead and its open deals,
 * and — when configured — advances a deal one stage with
 * `source = email_reply` on positive replies.
 *
 * Deterministic core adopted from JobNavigator's email monitor: the poller
 * only persists CRM records; it never deletes mail and never touches
 * unknown senders. Disabled by default (`gmail_reply_poll.enabled`).
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gmail reply poller.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_CRM_Gmail_Reply_Poller {

	/**
	 * Cron hook for the reply poll.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_crm_gmail_reply_poll';

	/**
	 * Option storing the ISO 8601 timestamp of the last completed poll.
	 *
	 * @var string
	 */
	const OPTION_LAST_POLL = 'wp_mcp_ai_crm_reply_last_poll';

	/**
	 * Register the cron handler and the scheduling pass.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
	}

	/**
	 * Schedule or unschedule the poll based on the toolkit settings.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$rules = self::get_rules();

		if ( empty( $rules['enabled'] ) ) {
			self::unschedule();
			return;
		}

		// Register a custom interval matching the configured minimum spacing.
		$interval_minutes = isset( $rules['min_interval_minutes'] ) ? max( 5, min( 1440, absint( $rules['min_interval_minutes'] ) ) ) : 15;

		add_filter(
			// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Dynamic interval from user-configurable minutes.
			'cron_schedules',
			static function ( $schedules ) use ( $interval_minutes ) {
				$interval_seconds                 = $interval_minutes * MINUTE_IN_SECONDS;
				$schedules['crm_gmail_reply_poll'] = array(
					'interval' => $interval_seconds,
					/* translators: %d: number of minutes */
					'display'  => sprintf( __( 'Every %d minutes (CRM Gmail reply poll)', 'mcp-ai-wpoos-pro' ), $interval_minutes ),
				);
				return $schedules;
			}
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'crm_gmail_reply_poll', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled poll.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Run one poll cycle.
	 *
	 * @return array Status summary.
	 */
	public static function run() {
		$rules = self::get_rules();

		$summary = array(
			'status'        => 'disabled',
			'messages_seen' => 0,
			'replies'       => 0,
			'advances'      => 0,
			'skipped'       => 0,
			'errors'        => 0,
		);

		if ( empty( $rules['enabled'] ) ) {
			return $summary;
		}

		if ( ! class_exists( 'WP_MCP_AI_CRM_Gmail_Client' ) ) {
			$_client = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-crm-gmail-client.php';
			if ( file_exists( $_client ) ) {
				require_once $_client;
			}
		}
		if ( ! class_exists( 'WP_MCP_AI_CRM_Gmail_Client' ) ) {
			$summary['status'] = 'client_unavailable';
			return $summary;
		}

		$max_per_poll = isset( $rules['max_per_poll'] ) ? max( 1, min( 25, absint( $rules['max_per_poll'] ) ) ) : 10;
		$advance      = ! empty( $rules['advance_on_positive'] );

		$arguments = array(
			'per_page' => $max_per_poll,
		);

		// Incremental window: only unread mail newer than the last poll
		// (Gmail `after:` operator via the client's date_from argument).
		$last_poll = (string) get_option( self::OPTION_LAST_POLL, '' );
		if ( '' !== $last_poll && false !== strtotime( $last_poll ) ) {
			$arguments['date_from'] = gmdate( 'Y/m/d', strtotime( $last_poll ) );
		}

		$client = new WP_MCP_AI_CRM_Gmail_Client();
		$result = $client->search_leads( $arguments );

		if ( ! is_array( $result ) || empty( $result['leads'] ) || ! is_array( $result['leads'] ) ) {
			// No connections configured or no new mail — either way this
			// poll completed with nothing to process.
			$summary['status'] = 'complete';
			self::stamp_last_poll();
			return $summary;
		}

		$summary['status']         = 'complete';
		$summary['messages_seen']  = count( $result['leads'] );

		foreach ( $result['leads'] as $message ) {
			$email   = isset( $message['email'] ) ? sanitize_email( $message['email'] ) : '';
			$snippet = isset( $message['gmail_snippet'] ) ? sanitize_textarea_field( $message['gmail_snippet'] ) : '';
			$date    = isset( $message['added_date'] ) ? sanitize_text_field( $message['added_date'] ) : '';

			if ( '' === $email || ! class_exists( 'WP_MCP_AI_CRM_Identity' ) ) {
				$summary['skipped']++;
				continue;
			}

			$lead_id = WP_MCP_AI_CRM_Identity::find_lead_by_email( $email );
			if ( ! $lead_id ) {
				// Unknown sender — never touch records we don't own.
				$summary['skipped']++;
				continue;
			}

			// Sentiment classification (heuristic by default; the
			// wp_mcp_ai_crm_classify_intent filter can swap in an LLM).
			$sentiment = 'unknown';
			if ( class_exists( 'WP_MCP_AI_CRM_Classifier' ) && '' !== $snippet ) {
				$classified = WP_MCP_AI_CRM_Classifier::classify( $snippet, 'email' );
				if ( is_array( $classified ) && ! empty( $classified['sentiment'] ) ) {
					$sentiment = sanitize_key( $classified['sentiment'] );
				}
			}

			$received_at = '' !== $date ? $date : gmdate( 'c' );

			// Normalize the Gmail date into ISO 8601 when parseable.
			$parsed = strtotime( $received_at );
			if ( false !== $parsed ) {
				$received_at = gmdate( 'c', $parsed );
			}

			$applied = WP_MCP_AI_Tool_Record_CRM_Reply::apply(
				$lead_id,
				0,
				substr( $snippet, 0, 500 ),
				$sentiment,
				$received_at,
				$advance && 'positive' === $sentiment
			);

			if ( is_wp_error( $applied ) ) {
				$summary['errors']++;
				continue;
			}

			$summary['replies']++;
			foreach ( $applied['deals_touched'] as $touched ) {
				if ( ! empty( $touched['advanced'] ) ) {
					$summary['advances']++;
				}
			}

			// Audit under a cron actor.
			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record(
					'reply_recorded',
					'lead',
					$lead_id,
					array(
						'sentiment' => $sentiment,
						'action'    => 'gmail_reply_poll',
					)
				);
			}
		}

		self::stamp_last_poll();

		return $summary;
	}

	/**
	 * Resolve the poll rules from the CRM toolkit settings.
	 *
	 * @return array
	 */
	private static function get_rules() {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return array();
		}

		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$rules    = isset( $settings['gmail_reply_poll'] ) && is_array( $settings['gmail_reply_poll'] )
			? $settings['gmail_reply_poll']
			: array();

		return $rules;
	}

	/**
	 * Record the completed-poll timestamp (non-autoloaded).
	 *
	 * @return void
	 */
	private static function stamp_last_poll() {
		update_option( self::OPTION_LAST_POLL, gmdate( 'c' ), false );
	}
}
