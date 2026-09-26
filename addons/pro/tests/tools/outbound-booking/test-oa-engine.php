<?php
/**
 * Test Outbound Booking automation engine.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Engine tests: sequence stepping, dispatch, reply routing.
 */
class Test_OA_Engine extends WP_UnitTestCase {

	/**
	 * Lead ID used in tests.
	 *
	 * @var int
	 */
	private $lead_id;

	/**
	 * Sequence ID used in tests.
	 *
	 * @var int
	 */
	private $sequence_id;

	/**
	 * Angle ID used in tests.
	 *
	 * @var int
	 */
	private $angle_id;

	/**
	 * Booking link ID used in tests.
	 *
	 * @var int
	 */
	private $booking_link_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/';
		require_once $base . 'class-wp-mcp-ai-oa-settings.php';
		require_once $base . 'class-wp-mcp-ai-oa-templates.php';
		require_once $base . 'class-wp-mcp-ai-oa-angle-cpt.php';
		require_once $base . 'class-wp-mcp-ai-oa-booking-link-cpt.php';
		require_once $base . 'class-wp-mcp-ai-oa-outbox.php';
		require_once $base . 'class-wp-mcp-ai-oa-channels.php';
		require_once $base . 'class-wp-mcp-ai-oa-notifications.php';
		require_once $base . 'class-wp-mcp-ai-oa-engine.php';

		update_option( 'wp_mcp_ai_settings', array( 'enable_outbound_booking_toolkit' => 1 ) );
		WP_MCP_AI_OA_Settings::update( array( 'email_mode' => 'approval' ) );
		delete_option( WP_MCP_AI_OA_Engine::DAY_OPTION );
		delete_option( WP_MCP_AI_OA_Engine::HISTORY_OPTION );

		foreach ( array( 'mcp_ai_oa_angle', 'mcp_ai_oa_booking', 'mcp_ai_oa_outbox', 'mcp_ai_lead', 'mcp_ai_sequence' ) as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				register_post_type( $pt, array( 'public' => false, 'label' => $pt ) );
			}
		}

		$this->lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Jane Smith',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->lead_id, 'email', 'jane@example.test' );
		update_post_meta( $this->lead_id, 'first_name', 'Jane' );
		update_post_meta( $this->lead_id, '_oa_email_consent', '1' );

		$this->angle_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'Teardown Angle',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->angle_id, '_oa_angle_subject', 'Quick question for {{company}}' );
		update_post_meta( $this->angle_id, '_oa_angle_body', 'Hi {{first_name}}, body line.' );

		$this->booking_link_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_booking',
				'post_title'  => 'Strategy Call',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->booking_link_id, '_oa_booking_slug', 'strategy' );
		update_post_meta( $this->booking_link_id, '_oa_booking_active', '1' );
		update_post_meta( $this->booking_link_id, '_oa_booking_mode', 'external' );
		update_post_meta( $this->booking_link_id, '_oa_booking_calendar_url', 'https://cal.test/book' );
		WP_MCP_AI_OA_Settings::update( array( 'default_booking_link' => $this->booking_link_id ) );

		$this->sequence_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_sequence',
				'post_title'  => 'Cadence One',
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$this->sequence_id,
			'steps',
			array(
				array(
					'order'           => 1,
					'channel'         => 'email',
					'template_id'     => (string) $this->angle_id,
					'wait_hours'      => 24,
					'branch_on_reply' => true,
				),
				array(
					'order'           => 2,
					'channel'         => 'linkedin_dm',
					'template_id'     => (string) $this->angle_id,
					'wait_hours'      => 48,
					'branch_on_reply' => true,
				),
			)
		);
		update_post_meta( $this->lead_id, '_active_sequence_id', $this->sequence_id );
		update_post_meta( $this->lead_id, '_sequence_step', 0 );
		update_post_meta( $this->lead_id, '_oa_email_consent', '1' );
	}

	/**
	 * Helpers: create a fresh enrolled lead.
	 *
	 * @return int Lead ID.
	 */
	private function enroll_lead() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'John Doe',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $lead_id, 'email', 'john@example.test' );
		update_post_meta( $lead_id, 'first_name', 'John' );
		update_post_meta( $lead_id, '_oa_email_consent', '1' );
		update_post_meta( $lead_id, '_active_sequence_id', $this->sequence_id );
		update_post_meta( $lead_id, '_sequence_step', 0 );
		return $lead_id;
	}

	/**
	 * In approval mode, the tick creates a pending outbox message and advances the step.
	 */
	public function test_process_lead_creates_pending_message_in_approval_mode() {
		$result = WP_MCP_AI_OA_Engine::process_lead( $this->lead_id );
		$this->assertNotEmpty( $result['outbox_id'] );
		$this->assertFalse( $result['sent'] );
		$this->assertSame( 'email', $result['channel'] );

		$this->assertSame( '1', (string) get_post_meta( $this->lead_id, '_sequence_step', true ) );
		$this->assertGreaterThan( time(), (int) get_post_meta( $this->lead_id, '_oa_next_due', true ) );
		$this->assertSame( 'pending', get_post_meta( $result['outbox_id'], '_oa_ob_status', true ) );

		$body = get_post_meta( $result['outbox_id'], '_oa_ob_body', true );
		$this->assertStringContainsString( 'Hi Jane', $body );

		// Not due again until the wait window elapses.
		$again = WP_MCP_AI_OA_Engine::process_lead( $this->lead_id );
		$this->assertTrue( $again['skipped'] );
		$this->assertSame( 'not_due', $again['reason'] );
	}

	/**
	 * In auto mode with a mail interceptor, the message is sent immediately.
	 */
	public function test_process_lead_auto_sends_email() {
		WP_MCP_AI_OA_Settings::update( array( 'email_mode' => 'auto' ) );
		add_filter( 'pre_wp_mail', '__return_true' );

		$result = WP_MCP_AI_OA_Engine::process_lead( $this->lead_id );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( 'sent', get_post_meta( $result['outbox_id'], '_oa_ob_status', true ) );
		$this->assertSame( 'active', get_post_meta( $this->lead_id, '_oa_status', true ) );
	}

	/**
	 * Leads without consent attestation are skipped in email mode.
	 */
	public function test_process_lead_skips_without_consent() {
		WP_MCP_AI_OA_Settings::update( array( 'email_mode' => 'auto' ) );
		delete_post_meta( $this->lead_id, '_oa_email_consent' );

		$result = WP_MCP_AI_OA_Engine::process_lead( $this->lead_id );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'consent_required', $result['reason'] );
	}

	/**
	 * Completing the final step marks the lead completed.
	 */
	public function test_sequence_completion_sets_status() {
		update_post_meta( $this->lead_id, '_sequence_step', 2 );
		$result = WP_MCP_AI_OA_Engine::process_lead( $this->lead_id );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'sequence_complete', $result['reason'] );
		$this->assertSame( 'completed', get_post_meta( $this->lead_id, '_oa_status', true ) );
	}

	/**
	 * The daily cap stops the tick before processing further leads.
	 */
	public function test_run_tick_respects_daily_cap() {
		WP_MCP_AI_OA_Settings::update(
			array(
				'email_mode'        => 'auto',
				'send_window_start' => 0,
				'send_window_end'   => 0, // All-day window.
			)
		);
		update_option( WP_MCP_AI_OA_Engine::DAY_OPTION, array( 'date' => current_time( 'Y-m-d' ), 'sent' => 100 ) );

		$summary = WP_MCP_AI_OA_Engine::run_tick();
		$this->assertSame( 'daily_cap', $summary['reason'] );
		$this->assertSame( 0, $summary['processed'] );
	}

	/**
	 * The keyword classifier buckets replies.
	 */
	public function test_classify_keywords() {
		$this->assertSame( 'positive', WP_MCP_AI_OA_Engine::classify( 'I am interested, let\'s book a call' ) );
		$this->assertSame( 'negative', WP_MCP_AI_OA_Engine::classify( 'unsubscribe me now' ) );
		$this->assertSame( 'neutral', WP_MCP_AI_OA_Engine::classify( 'Random words here' ) );
	}

	/**
	 * A positive reply pauses the sequence and enqueues a booking invite.
	 */
	public function test_ingest_reply_positive_routes_to_booking_invite() {
		$result = WP_MCP_AI_OA_Engine::ingest_reply( $this->lead_id, 'email', 'Sounds great, I would love to talk!' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'positive', $result['sentiment'] );
		$this->assertSame( 'replied_positive', get_post_meta( $this->lead_id, '_oa_status', true ) );
		$this->assertSame( '1', (string) get_post_meta( $this->lead_id, '_sequence_paused', true ) );

		$invites = get_posts(
			array(
				'post_type'      => 'mcp_ai_oa_outbox',
				'post_status'    => 'any',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_key'       => '_oa_ob_kind', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small test board.
				'meta_value'     => 'booking_invite', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small test board.
			)
		);
		$this->assertNotEmpty( $invites );
		$body = get_post_meta( $invites[0]->ID, '_oa_ob_body', true );
		$this->assertStringContainsString( 'link=strategy', $body );
	}

	/**
	 * A negative reply stops the sequence.
	 */
	public function test_ingest_reply_negative_stops_sequence() {
		$result = WP_MCP_AI_OA_Engine::ingest_reply( $this->lead_id, 'email', 'Please remove me from your list' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'negative', $result['sentiment'] );
		$this->assertSame( 'stopped', get_post_meta( $this->lead_id, '_oa_status', true ) );
	}

	/**
	 * Objection replies route through the objection matrix.
	 */
	public function test_ingest_reply_objection_uses_matrix() {
		$objection_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'too expensive',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $objection_id, '_oa_angle_kind', 'objection' );
		update_post_meta( $objection_id, '_oa_angle_objection', 'too expensive' );
		update_post_meta( $objection_id, '_oa_angle_response', 'Fair — here is the ROI math for {{first_name}}.' );

		$result = WP_MCP_AI_OA_Engine::ingest_reply( $this->lead_id, 'email', 'This looks too expensive for us right now' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'objection', $result['sentiment'] );
		$this->assertSame( 'objection_response', $result['routed'] );

		$replies = get_posts(
			array(
				'post_type'      => 'mcp_ai_oa_outbox',
				'post_status'    => 'any',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_key'       => '_oa_ob_kind', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small test board.
				'meta_value'     => 'objection_reply', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small test board.
			)
		);
		$this->assertNotEmpty( $replies );
		$this->assertStringContainsString( 'Jane', get_post_meta( $replies[0]->ID, '_oa_ob_body', true ) );
	}

	/**
	 * Pipeline stats aggregate the funnel.
	 */
	public function test_get_pipeline_stats() {
		if ( ! post_type_exists( 'mcp_appointment' ) ) {
			register_post_type( 'mcp_appointment', array( 'public' => false, 'label' => 'Appointments' ) );
		}
		update_post_meta( $this->lead_id, '_oa_status', 'booked' );
		update_post_meta( $this->lead_id, '_oa_last_message_at', current_time( 'mysql' ) );

		$appointment_id = wp_insert_post( array( 'post_type' => 'mcp_appointment', 'post_title' => 'Jane — Call', 'post_status' => 'publish' ) );
		update_post_meta( $appointment_id, '_oa_booking_link_id', $this->booking_link_id );
		update_post_meta( $appointment_id, '_status', 'confirmed' );

		$stats = WP_MCP_AI_OA_Engine::get_pipeline_stats();
		$this->assertGreaterThanOrEqual( 1, $stats['prospects'] );
		$this->assertGreaterThanOrEqual( 1, $stats['booked'] );
		$this->assertGreaterThanOrEqual( 1, $stats['shown'] );
	}
}
