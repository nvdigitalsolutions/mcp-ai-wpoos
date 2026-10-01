<?php
/**
 * Outbound Appointment Booking — automation engine.
 *
 * The heart of the toolkit. An hourly tick advances leads enrolled in CRM
 * sequences: it renders the next step from the angle bank, honours send
 * windows and daily caps, creates outbox messages, and dispatches them per
 * channel policy. It also ingests replies (positive → booking invite,
 * negative → stop, objection → matrix response) and runs the weekly
 * champion/challenger promotion.
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
 * Outbound automation engine.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Engine {

	/**
	 * Cron hook for the hourly tick.
	 *
	 * @var string
	 */
	const TICK_HOOK = 'wp_mcp_ai_oa_tick';

	/**
	 * Cron hook for weekly A/B test promotion.
	 *
	 * @var string
	 */
	const WEEKLY_HOOK = 'wp_mcp_ai_oa_weekly_tests';

	/**
	 * Cron hook for the daily digest.
	 *
	 * @var string
	 */
	const DIGEST_HOOK = 'wp_mcp_ai_oa_digest';

	/**
	 * Day-state option name.
	 *
	 * @var string
	 */
	const DAY_OPTION = 'wp_mcp_ai_oa_day';

	/**
	 * Test-history option name.
	 *
	 * @var string
	 */
	const HISTORY_OPTION = 'wp_mcp_ai_oa_test_history';

	/**
	 * Lead statuses that halt the sequence.
	 *
	 * @var string[]
	 */
	const STOPPED_STATUSES = array( 'booked', 'stopped', 'completed' );

	/**
	 * Initialize.
	 *
	 * @since 2.12.0
	 */
	public static function init() {
		if ( ! WP_MCP_AI_OA_Settings::is_enabled() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );
		add_action( self::TICK_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'run_weekly_tests' ) );
		add_action( self::DIGEST_HOOK, array( 'WP_MCP_AI_OA_Notifications', 'run_digest' ) );
		// Inbound replies logged by the CRM message log.
		add_action( 'wp_mcp_ai_crm_message_logged', array( __CLASS__, 'handle_inbound_message' ), 10, 2 );
	}

	/**
	 * Schedule the recurring jobs (idempotent).
	 *
	 * @since 2.12.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::TICK_HOOK );
		}
		if ( ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_event( strtotime( 'next monday 09:00', current_time( 'timestamp' ) ), 'weekly', self::WEEKLY_HOOK );
		}
		if ( ! wp_next_scheduled( self::DIGEST_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 09:00', current_time( 'timestamp' ) ), 'daily', self::DIGEST_HOOK );
		}
	}

	/**
	 * Whether the current time falls inside the configured send window.
	 *
	 * @since 2.12.0
	 * @return bool
	 */
	public static function within_send_window() {
		$s     = WP_MCP_AI_OA_Settings::get();
		$hour  = (int) current_time( 'H' );
		$start = (int) $s['send_window_start'];
		$end   = (int) $s['send_window_end'];
		if ( $start === $end ) {
			return true; // All-day window.
		}
		if ( $start < $end ) {
			return $hour >= $start && $hour < $end;
		}
		// Overnight window (e.g. 20:00–06:00).
		return $hour >= $start || $hour < $end;
	}

	/**
	 * Hourly tick: advance every due lead one step.
	 *
	 * @since 2.12.0
	 * @return array Tick summary.
	 */
	public static function run_tick() {
		$summary = array( 'processed' => 0, 'sent' => 0, 'skipped' => 0, 'reason' => '' );

		if ( ! post_type_exists( 'mcp_ai_lead' ) ) {
			$summary['reason'] = 'crm_disabled';
			return $summary;
		}
		if ( ! self::within_send_window() ) {
			$summary['reason'] = 'outside_window';
			return $summary;
		}

		$s   = WP_MCP_AI_OA_Settings::get();
		$cap = (int) $s['daily_cap'];
		$day = get_option( self::DAY_OPTION, array( 'date' => '', 'sent' => 0 ) );
		if ( ! is_array( $day ) ) {
			$day = array( 'date' => '', 'sent' => 0 );
		}
		$today = current_time( 'Y-m-d' );
		if ( $day['date'] !== $today ) {
			$day = array( 'date' => $today, 'sent' => 0 );
		}
		if ( $day['sent'] >= $cap ) {
			$summary['reason'] = 'daily_cap';
			return $summary;
		}

		$lead_ids = get_posts(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_active_sequence_id', 'compare' => 'EXISTS' ),
					array( 'key' => '_sequence_paused', 'compare' => 'NOT EXISTS' ),
				),
			)
		);

		foreach ( $lead_ids as $lead_id ) {
			if ( $day['sent'] >= $cap ) {
				break;
			}
			$result = self::process_lead( (int) $lead_id );
			$summary['processed']++;
			if ( ! empty( $result['sent'] ) ) {
				$day['sent']++;
				$summary['sent']++;
			} elseif ( ! empty( $result['skipped'] ) ) {
				$summary['skipped']++;
			}
		}

		update_option( self::DAY_OPTION, $day, false );
		$summary['day_state'] = $day;
		return $summary;
	}

	/**
	 * Advance a single enrolled lead through its next sequence step.
	 *
	 * @since 2.12.0
	 * @param int $lead_id Lead post ID.
	 * @return array Step result.
	 */
	public static function process_lead( $lead_id ) {
		$lead_id = absint( $lead_id );
		$lead    = get_post( $lead_id );
		if ( ! $lead || 'mcp_ai_lead' !== $lead->post_type ) {
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'not_found' );
		}

		$status = (string) get_post_meta( $lead_id, '_oa_status', true );
		if ( in_array( $status, self::STOPPED_STATUSES, true ) ) {
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'status_' . $status );
		}

		$due = (int) get_post_meta( $lead_id, '_oa_next_due', true );
		if ( $due && $due > time() ) {
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'not_due' );
		}

		$sequence_id = absint( get_post_meta( $lead_id, '_active_sequence_id', true ) );
		$steps       = $sequence_id ? get_post_meta( $sequence_id, 'steps', true ) : array();
		if ( ! is_array( $steps ) || empty( $steps ) ) {
			update_post_meta( $lead_id, '_oa_last_error', 'empty_sequence' );
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'empty_sequence' );
		}
		$steps = array_values( $steps );

		$index = absint( get_post_meta( $lead_id, '_sequence_step', true ) );
		if ( $index >= count( $steps ) ) {
			update_post_meta( $lead_id, '_oa_status', 'completed' );
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'sequence_complete' );
		}

		$step    = $steps[ $index ];
		$channel = isset( $step['channel'] ) ? sanitize_key( $step['channel'] ) : 'email';
		$wait    = isset( $step['wait_hours'] ) ? absint( $step['wait_hours'] ) : 24;
		if ( ! $wait ) {
			$wait = 24;
		}

		if ( ! in_array( $channel, WP_MCP_AI_OA_Channels::CHANNELS, true ) ) {
			update_post_meta( $lead_id, '_oa_last_error', 'unsupported_channel_' . $channel );
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'unsupported_channel' );
		}
		if ( 'off' === WP_MCP_AI_OA_Channels::mode_for( $channel ) ) {
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'channel_off' );
		}

		// Email requires consent attestation (set at import) or CRM consent.
		if ( 'email' === $channel && '1' !== (string) get_post_meta( $lead_id, '_oa_email_consent', true ) ) {
			if ( ! class_exists( 'WP_MCP_AI_CRM_Consent' ) || ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'email' ) ) {
				return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'consent_required' );
			}
		}

		// Resolve the angle for this step.
		$template_ref = isset( $step['template_id'] ) ? $step['template_id'] : '';
		$angle_id     = WP_MCP_AI_OA_Angle_CPT::resolve( $template_ref );
		if ( $angle_id ) {
			$angle_id = WP_MCP_AI_OA_Angle_CPT::pick_variant( $angle_id );
		}

		$subject = '';
		$body    = '';
		if ( $angle_id ) {
			$subject = get_post_meta( $angle_id, '_oa_angle_subject', true );
			$body    = get_post_meta( $angle_id, '_oa_angle_body', true );
			if ( ! $body ) {
				$body = get_post_field( 'post_content', $angle_id );
			}
			$first_line = get_post_meta( $angle_id, '_oa_angle_first_line', true );
			if ( $first_line && false === strpos( $body, $first_line ) ) {
				$body = $first_line . "\n\n" . $body;
			}
		}

		$booking_link_id = self::get_booking_link_for_lead( $lead_id );
		if ( $angle_id ) {
			update_post_meta( $lead_id, '_oa_last_angle_id', $angle_id );
		}
		$context         = array(
			'sequence_name' => get_the_title( $sequence_id ),
			'booking_url'   => $booking_link_id ? WP_MCP_AI_OA_Booking_Link_CPT::get_booking_url( $booking_link_id, $lead_id ) : '',
			'angle_subject' => $subject,
			'angle_first_line' => $angle_id ? get_post_meta( $angle_id, '_oa_angle_first_line', true ) : '',
		);
		$subject = WP_MCP_AI_OA_Templates::render( $subject, $lead_id, $context );
		$body    = WP_MCP_AI_OA_Templates::render( $body, $lead_id, $context );

		$outbox_id = WP_MCP_AI_OA_Outbox::create(
			array(
				'lead_id'         => $lead_id,
				'sequence_id'     => $sequence_id,
				'step'            => $index,
				'channel'         => $channel,
				'subject'         => $subject,
				'body'            => $body,
				'status'          => 'pending',
				'kind'            => 'sequence',
				'angle_id'        => $angle_id,
				'booking_link_id' => $booking_link_id,
			)
		);
		if ( is_wp_error( $outbox_id ) ) {
			update_post_meta( $lead_id, '_oa_last_error', $outbox_id->get_error_message() );
			return array( 'lead_id' => $lead_id, 'skipped' => true, 'reason' => 'outbox_error' );
		}

		$sent = false;
		if ( WP_MCP_AI_OA_Channels::is_auto( $channel ) ) {
			$dispatch = WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
			$sent     = ! is_wp_error( $dispatch ) && empty( $dispatch['needs_manual_send'] );
		}

		// Advance the enrollment state.
		update_post_meta( $lead_id, '_sequence_step', $index + 1 );
		update_post_meta( $lead_id, '_oa_next_due', time() + ( $wait * HOUR_IN_SECONDS ) );
		if ( ! $status ) {
			update_post_meta( $lead_id, '_oa_status', 'active' );
		}
		delete_post_meta( $lead_id, '_oa_last_error' );

		return array(
			'lead_id'   => $lead_id,
			'sent'      => $sent,
			'outbox_id' => $outbox_id,
			'channel'   => $channel,
			'step'      => $index + 1,
		);
	}

	/**
	 * Resolve the booking link to attach for a lead (explicit or default).
	 *
	 * @since 2.12.0
	 * @param int $lead_id Lead post ID.
	 * @return int Booking link post ID or 0.
	 */
	public static function get_booking_link_for_lead( $lead_id ) {
		$explicit = absint( get_post_meta( $lead_id, '_oa_booking_link_id', true ) );
		if ( $explicit ) {
			return $explicit;
		}
		$default = absint( WP_MCP_AI_OA_Settings::get_setting( 'default_booking_link', 0 ) );
		return $default && get_post( $default ) ? $default : 0;
	}

	/**
	 * Handle an inbound message logged by the CRM message log.
	 *
	 * @since 2.12.0
	 * @param int   $message_post_id Message post ID.
	 * @param array $args            Original message args.
	 */
	public static function handle_inbound_message( $message_post_id, $args ) {
		$email = isset( $args['sender_email'] ) ? sanitize_email( $args['sender_email'] ) : '';
		if ( ! $email ) {
			return;
		}
		$leads = get_posts(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded reply-matching lookup.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded reply-matching lookup.
			)
		);
		if ( empty( $leads ) ) {
			return;
		}
		$lead_id = (int) $leads[0];
		if ( ! get_post_meta( $lead_id, '_active_sequence_id', true ) ) {
			return;
		}
		self::ingest_reply(
			$lead_id,
			isset( $args['channel'] ) ? sanitize_key( $args['channel'] ) : 'email',
			isset( $args['body'] ) ? sanitize_textarea_field( $args['body'] ) : ''
		);
	}

	/**
	 * Ingest a reply for a lead: classify and route.
	 *
	 * @since 2.12.0
	 * @param int    $lead_id Lead post ID.
	 * @param string $channel Channel slug.
	 * @param string $body    Reply body.
	 * @return array Result.
	 */
	public static function ingest_reply( $lead_id, $channel, $body ) {
		$lead_id = absint( $lead_id );
		$body    = trim( (string) $body );
		if ( ! get_post( $lead_id ) ) {
			return array( 'success' => false, 'reason' => 'not_found' );
		}

		// Record the reply on the angle that earned it.
		$last = get_posts(
			array(
				'post_type'      => WP_MCP_AI_OA_Outbox::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_oa_ob_lead_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded reply-attribution lookup.
				'meta_value'     => $lead_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded reply-attribution lookup.
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		if ( ! empty( $last ) ) {
			$angle_id = (int) get_post_meta( $last[0], '_oa_ob_angle_id', true );
			if ( $angle_id ) {
				WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'reply' );
			}
		}

		$sentiment = self::classify( $body );
		if ( 'objection' === $sentiment && self::maybe_respond_to_objection( $lead_id, $channel, $body ) ) {
			update_post_meta( $lead_id, '_oa_status', 'replied' );
			return array( 'success' => true, 'lead_id' => $lead_id, 'sentiment' => 'objection', 'routed' => 'objection_response' );
		}

		update_post_meta( $lead_id, '_oa_replied_at', current_time( 'mysql', true ) );
		update_post_meta( $lead_id, '_oa_reply_channel', sanitize_key( $channel ) );

		if ( 'positive' === $sentiment ) {
			return self::handle_positive_reply( $lead_id, $channel );
		}
		if ( 'negative' === $sentiment ) {
			update_post_meta( $lead_id, '_oa_status', 'stopped' );
			update_post_meta( $lead_id, '_sequence_paused', '1' );
			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record( 'outbound_reply_negative', 'lead', $lead_id, array( 'channel' => $channel ) );
			}
			return array( 'success' => true, 'lead_id' => $lead_id, 'sentiment' => 'negative', 'routed' => 'stopped' );
		}

		update_post_meta( $lead_id, '_oa_status', 'replied' );
		return array( 'success' => true, 'lead_id' => $lead_id, 'sentiment' => 'neutral', 'routed' => 'manual_review' );
	}

	/**
	 * Route a positive reply: pause the sequence and invite to book.
	 *
	 * @since 2.12.0
	 * @param int    $lead_id Lead post ID.
	 * @param string $channel Reply channel.
	 * @return array Result.
	 */
	private static function handle_positive_reply( $lead_id, $channel ) {
		update_post_meta( $lead_id, '_oa_status', 'replied_positive' );
		update_post_meta( $lead_id, '_oa_positive_at', current_time( 'mysql', true ) );
		update_post_meta( $lead_id, '_sequence_paused', '1' ); // The ball is in the prospect's court.

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_reply_positive', 'lead', $lead_id, array( 'channel' => $channel ) );
		}
		WP_MCP_AI_OA_Notifications::notify_positive_reply( $lead_id );

		$booking_link_id = self::get_booking_link_for_lead( $lead_id );
		if ( ! $booking_link_id ) {
			return array( 'success' => true, 'lead_id' => $lead_id, 'sentiment' => 'positive', 'routed' => 'slack_handoff' );
		}

		// Build the invite from the booking link template and enqueue it.
		$invite = get_post_meta( $booking_link_id, '_oa_booking_invite_body', true );
		if ( ! $invite ) {
			$invite = __( "Hey {{first_name}}, glad this resonated — you can grab a time that suits you right here: {{booking_url}}", 'mcp-ai-wpoos-pro' );
		}
		$body  = WP_MCP_AI_OA_Templates::render(
			$invite,
			$lead_id,
			array( 'booking_url' => WP_MCP_AI_OA_Booking_Link_CPT::get_booking_url( $booking_link_id, $lead_id ) )
		);
		$subject = sprintf(
			/* translators: %s: call title */
			__( 'Booking link for your %s', 'mcp-ai-wpoos-pro' ),
			get_post_meta( $booking_link_id, '_oa_booking_call_title', true )
		);
		$outbox_id = WP_MCP_AI_OA_Outbox::create(
			array(
				'lead_id'         => $lead_id,
				'sequence_id'     => 0,
				'step'            => 0,
				'channel'         => 'email',
				'subject'         => $subject,
				'body'            => $body,
				'status'          => 'pending',
				'kind'            => 'booking_invite',
				'angle_id'        => 0,
				'booking_link_id' => $booking_link_id,
			)
		);
		if ( is_wp_error( $outbox_id ) ) {
			return array( 'success' => false, 'lead_id' => $lead_id, 'reason' => $outbox_id->get_error_message() );
		}
		$sent = false;
		if ( WP_MCP_AI_OA_Channels::is_auto( 'email' ) ) {
			$dispatch = WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
			$sent     = ! is_wp_error( $dispatch ) && empty( $dispatch['needs_manual_send'] );
		}
		return array( 'success' => true, 'lead_id' => $lead_id, 'sentiment' => 'positive', 'routed' => 'booking_invite', 'outbox_id' => $outbox_id, 'sent' => $sent );
	}

	/**
	 * Classify a reply body: positive | negative | neutral | objection.
	 *
	 * @since 2.12.0
	 * @param string $body Reply body.
	 * @return string Sentiment slug.
	 */
	public static function classify( $body ) {
		$body = strtolower( $body );

		$positive = array( 'interested', 'book a call', 'book a time', 'sounds great', 'would love', 'love to talk', 'yes', 'sounds good', "let's talk", 'schedule', 'free this week', 'available', 'tell me more', 'pricing', 'how much', 'curious', 'set up a call', 'set up a time' );
		$negative = array( 'unsubscribe', 'not interested', 'stop emailing', 'remove me', 'no thanks', 'never contact', 'do not contact', 'leave me alone' );

		// Objections are negative-sounding but answerable — check them first.
		foreach ( self::get_objection_needles() as $needle ) {
			if ( false !== strpos( $body, $needle ) ) {
				return 'objection';
			}
		}
		foreach ( $negative as $word ) {
			if ( false !== strpos( $body, $word ) ) {
				return 'negative';
			}
		}
		foreach ( $positive as $word ) {
			if ( false !== strpos( $body, $word ) ) {
				return 'positive';
			}
		}

		/**
		 * Filter the sentiment classification (e.g. an AI classifier).
		 *
		 * @since 2.12.0
		 * @param string $sentiment Default keyword-based sentiment.
		 * @param string $body      Reply body.
		 * @return string Sentiment slug.
		 */
		return apply_filters( 'wp_mcp_ai_oa_classify_reply', 'neutral', $body );
	}

	/**
	 * Collect cached objection needles from the matrix (titles + objection lines).
	 *
	 * @since 2.12.0
	 * @return string[] Lowercased needles.
	 */
	private static function get_objection_needles() {
		$needles = get_transient( 'wp_mcp_ai_oa_objection_needles' );
		if ( is_array( $needles ) ) {
			return $needles;
		}
		$needles = array();
		$entries = get_posts(
			array(
				'post_type'      => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);
		foreach ( $entries as $entry ) {
			if ( 'objection' !== get_post_meta( $entry->ID, '_oa_angle_kind', true ) ) {
				continue;
			}
			foreach ( array( get_post_meta( $entry->ID, '_oa_angle_objection', true ), $entry->post_title ) as $line ) {
				$line = strtolower( trim( (string) $line ) );
				if ( strlen( $line ) >= 3 ) {
					$needles[] = $line;
				}
			}
		}
		$needles = array_unique( $needles );
		set_transient( 'wp_mcp_ai_oa_objection_needles', $needles, HOUR_IN_SECONDS );
		return $needles;
	}

	/**
	 * Respond to a detected objection using the objection matrix.
	 *
	 * @since 2.12.0
	 * @param int    $lead_id Lead post ID.
	 * @param string $channel Reply channel.
	 * @param string $body    Reply body.
	 * @return bool True if a response was enqueued.
	 */
	private static function maybe_respond_to_objection( $lead_id, $channel, $body ) {
		$entries = get_posts(
			array(
				'post_type'      => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);
		$body_lc = strtolower( $body );
		foreach ( $entries as $entry ) {
			if ( 'objection' !== get_post_meta( $entry->ID, '_oa_angle_kind', true ) ) {
				continue;
			}
			$objection = get_post_meta( $entry->ID, '_oa_angle_objection', true );
			$title     = $entry->post_title;
			foreach ( array( $objection, $title ) as $needle ) {
				$needle = strtolower( trim( (string) $needle ) );
				if ( ! $needle || strlen( $needle ) < 3 ) {
					continue;
				}
				if ( false !== strpos( $body_lc, $needle ) ) {
					$response = get_post_meta( $entry->ID, '_oa_angle_response', true );
					if ( ! $response ) {
						continue;
					}
					$outbox_id = WP_MCP_AI_OA_Outbox::create(
						array(
							'lead_id'         => $lead_id,
							'sequence_id'     => 0,
							'step'            => 0,
							'channel'         => 'email',
							'subject'         => __( 'Re: your question', 'mcp-ai-wpoos-pro' ),
							'body'            => WP_MCP_AI_OA_Templates::render( $response, $lead_id, array( 'objection' => $needle, 'response' => $response ) ),
							'status'          => 'pending',
							'kind'            => 'objection_reply',
							'angle_id'        => (int) $entry->ID,
							'booking_link_id' => self::get_booking_link_for_lead( $lead_id ),
						)
					);
					if ( ! is_wp_error( $outbox_id ) && WP_MCP_AI_OA_Channels::is_auto( 'email' ) ) {
						WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
					}
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Weekly A/B test promotion: pick the winning variant per group.
	 *
	 * @since 2.12.0
	 * @return array Promotion summary.
	 */
	public static function run_weekly_tests() {
		$settings = WP_MCP_AI_OA_Settings::get();
		$min      = (int) $settings['min_test_sends'];

		$angles = get_posts(
			array(
				'post_type'      => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);

		// Build groups keyed by root ID.
		$groups = array();
		foreach ( $angles as $angle ) {
			$variant_of = (int) get_post_meta( $angle->ID, '_oa_angle_variant_of', true );
			$status     = (string) get_post_meta( $angle->ID, '_oa_angle_test_status', true );
			$in_test    = '' !== $status || $variant_of;
			if ( ! $in_test || 'objection' === get_post_meta( $angle->ID, '_oa_angle_kind', true ) ) {
				continue;
			}
			$root = $variant_of ? $variant_of : $angle->ID;
			if ( ! isset( $groups[ $root ] ) ) {
				$groups[ $root ] = array();
			}
			$groups[ $root ][] = $angle;
		}

		$history   = get_option( self::HISTORY_OPTION, array() );
		$promotions = array();
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		foreach ( $groups as $root => $members ) {
			if ( count( $members ) < 2 ) {
				continue; // Nothing to test against.
			}
			$sends = 0;
			foreach ( $members as $member ) {
				$sends += (int) get_post_meta( $member->ID, '_oa_angle_sends', true );
			}
			if ( $sends < $min ) {
				continue; // Not enough data yet.
			}

			$winner = null;
			$best   = -1.0;
			foreach ( $members as $member ) {
				$member_sends   = max( 1, (int) get_post_meta( $member->ID, '_oa_angle_sends', true ) );
				$member_replies = (int) get_post_meta( $member->ID, '_oa_angle_replies', true );
				$rate           = $member_replies / $member_sends;
				if ( $rate > $best ) {
					$best   = $rate;
					$winner = $member;
				}
			}
			if ( ! $winner ) {
				continue;
			}
			foreach ( $members as $member ) {
				$new_status = ( $member->ID === $winner->ID ) ? 'champion' : 'paused';
				update_post_meta( $member->ID, '_oa_angle_test_status', $new_status );
			}
			$promotions[] = array(
				'root'    => $root,
				'group'   => get_the_title( $root ),
				'winner'  => $winner->post_title,
				'sends'   => $sends,
				'replies' => (int) get_post_meta( $winner->ID, '_oa_angle_replies', true ),
			);
		}

		if ( ! empty( $promotions ) ) {
			array_unshift(
				$history,
				array(
					'date'       => current_time( 'mysql', true ),
					'promotions' => $promotions,
				)
			);
			$history = array_slice( $history, 0, 10 );
			update_option( self::HISTORY_OPTION, $history, false );
		}

		if ( ! empty( $promotions ) ) {
			WP_MCP_AI_OA_Notifications::notify_weekly_tests( $promotions );
		}
		return array(
			'groups'     => count( $groups ),
			'promotions' => $promotions,
		);
	}

	/**
	 * Pipeline funnel stats for the dashboard, digest, and tools.
	 *
	 * @since 2.12.0
	 * @return array Stats.
	 */
	public static function get_pipeline_stats() {
		$stats = array(
			'prospects' => 0,
			'messaged'  => 0,
			'replied'   => 0,
			'positive'  => 0,
			'booked'    => 0,
			'shown'     => 0,
			'reply_rate' => 0,
			'positive_rate' => 0,
			'book_rate' => 0,
			'show_rate' => 0,
			'pending_outbox' => 0,
		);

		if ( post_type_exists( 'mcp_ai_lead' ) ) {
			$leads = get_posts(
				array(
					'post_type'      => 'mcp_ai_lead',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $leads as $lead_id ) {
				$status = (string) get_post_meta( $lead_id, '_oa_status', true );
				if ( '' === $status ) {
					continue;
				}
				$stats['prospects']++;
				if ( get_post_meta( $lead_id, '_oa_last_message_at', true ) ) {
					$stats['messaged']++;
				}
				if ( in_array( $status, array( 'replied', 'replied_positive', 'booked' ), true ) ) {
					$stats['replied']++;
				}
				if ( in_array( $status, array( 'replied_positive', 'booked' ), true ) ) {
					$stats['positive']++;
				}
				if ( 'booked' === $status ) {
					$stats['booked']++;
				}
			}
		}

		if ( post_type_exists( 'mcp_appointment' ) ) {
			$appointments = get_posts(
				array(
					'post_type'      => 'mcp_appointment',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $appointments as $appointment_id ) {
				if ( ! get_post_meta( $appointment_id, '_oa_booking_link_id', true ) ) {
					continue;
				}
				$status = get_post_meta( $appointment_id, '_status', true );
				if ( in_array( $status, array( 'confirmed', 'completed' ), true ) ) {
					$stats['shown']++;
				}
			}
		}

		$stats['pending_outbox'] = WP_MCP_AI_OA_Outbox::get_status_counts()['pending'] ?? 0;
		$stats['reply_rate']     = $stats['messaged'] > 0 ? round( $stats['replied'] / $stats['messaged'] * 100, 1 ) : 0;
		$stats['positive_rate']  = $stats['messaged'] > 0 ? round( $stats['positive'] / $stats['messaged'] * 100, 1 ) : 0;
		$stats['book_rate']      = $stats['positive'] > 0 ? round( $stats['booked'] / $stats['positive'] * 100, 1 ) : 0;
		$stats['show_rate']      = $stats['booked'] > 0 ? round( $stats['shown'] / $stats['booked'] * 100, 1 ) : 0;

		return $stats;
	}
}
