<?php
/**
 * Test Outbound Booking booking flow.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Booking creation tests.
 */
class Test_OA_Booking extends WP_UnitTestCase {

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
		require_once $base . 'class-wp-mcp-ai-oa-booking.php';

		update_option( 'wp_mcp_ai_settings', array( 'enable_outbound_booking_toolkit' => 1 ) );
		WP_MCP_AI_OA_Settings::update( array( 'email_mode' => 'off' ) );

		if ( ! post_type_exists( 'mcp_appointment' ) ) {
			register_post_type( 'mcp_appointment', array( 'public' => false, 'label' => 'Appointments' ) );
		}
		if ( ! post_type_exists( 'mcp_ai_lead' ) ) {
			register_post_type( 'mcp_ai_lead', array( 'public' => false, 'label' => 'Leads' ) );
		}
		if ( ! post_type_exists( 'mcp_ai_oa_booking' ) ) {
			register_post_type( 'mcp_ai_oa_booking', array( 'public' => false, 'label' => 'Booking Links' ) );
		}

		$this->booking_link_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_booking',
				'post_title'  => 'Strategy Call',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->booking_link_id, '_oa_booking_active', '1' );
		update_post_meta( $this->booking_link_id, '_oa_booking_mode', 'internal' );
		update_post_meta( $this->booking_link_id, '_oa_booking_call_title', 'Strategy Call' );
		update_post_meta( $this->booking_link_id, '_oa_booking_duration', 30 );
		update_post_meta( $this->booking_link_id, '_oa_booking_confirmation', 'See you on the call!' );
	}

	/**
	 * A valid internal booking creates an appointment and marks the lead booked.
	 */
	public function test_create_booking_internal_creates_appointment_and_lead() {
		$slot = gmdate( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );

		$result = WP_MCP_AI_OA_Booking::create_booking(
			$this->booking_link_id,
			'Jane Smith',
			'jane@example.test',
			'Acme Co',
			$slot
		);
		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['appointment_id'] );
		$this->assertNotEmpty( $result['lead_id'] );

		$appointment = get_post( $result['appointment_id'] );
		$this->assertSame( 'mcp_appointment', $appointment->post_type );
		$this->assertSame( 'Jane Smith', get_post_meta( $result['appointment_id'], '_client_name', true ) );
		$this->assertSame( 'pending', get_post_meta( $result['appointment_id'], '_status', true ) );

		$lead = get_post( $result['lead_id'] );
		$this->assertSame( 'mcp_ai_lead', $lead->post_type );
		$this->assertSame( 'jane@example.test', get_post_meta( $result['lead_id'], 'email', true ) );
		$this->assertSame( 'booked', get_post_meta( $result['lead_id'], '_oa_status', true ) );
		$this->assertSame( $this->booking_link_id, (int) get_post_meta( $result['lead_id'], '_oa_booking_link_id', true ) );

		// Booking counter incremented.
		$this->assertSame( 1, (int) get_post_meta( $this->booking_link_id, '_oa_booking_bookings', true ) );
	}

	/**
	 * Re-booking the same email + link within a day is idempotent.
	 */
	public function test_create_booking_is_idempotent_per_day() {
		$slot = gmdate( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );

		$first  = WP_MCP_AI_OA_Booking::create_booking( $this->booking_link_id, 'Jane Smith', 'jane@example.test', '', $slot );
		$second = WP_MCP_AI_OA_Booking::create_booking( $this->booking_link_id, 'Jane Smith', 'jane@example.test', '', $slot );

		$this->assertTrue( $second['duplicate'] );
		$this->assertSame( $first['appointment_id'], $second['appointment_id'] );
	}

	/**
	 * Past slots are rejected.
	 */
	public function test_create_booking_rejects_past_slots() {
		$slot   = gmdate( 'Y-m-d\TH:i', time() - 2 * DAY_IN_SECONDS );
		$result = WP_MCP_AI_OA_Booking::create_booking( $this->booking_link_id, 'Jane Smith', 'jane@example.test', '', $slot );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_slot', $result->get_error_code() );
	}

	/**
	 * External-mode links cannot create internal bookings.
	 */
	public function test_create_booking_rejects_external_links() {
		update_post_meta( $this->booking_link_id, '_oa_booking_mode', 'external' );
		$slot   = gmdate( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );
		$result = WP_MCP_AI_OA_Booking::create_booking( $this->booking_link_id, 'Jane Smith', 'jane@example.test', '', $slot );
		$this->assertWPError( $result );
		$this->assertSame( 'external_mode', $result->get_error_code() );
	}

	/**
	 * Attribution tokens are verified when a lead_id is provided.
	 */
	public function test_lead_token_round_trip() {
		$lead_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_lead',
				'post_title' => 'Existing Lead',
			)
		);
		$token = WP_MCP_AI_OA_Booking_Link_CPT::get_lead_token( $lead_id, $this->booking_link_id );
		$this->assertTrue( WP_MCP_AI_OA_Booking_Link_CPT::verify_lead_token( $lead_id, $this->booking_link_id, $token ) );
		$this->assertFalse( WP_MCP_AI_OA_Booking_Link_CPT::verify_lead_token( $lead_id, $this->booking_link_id, 'tampered' ) );
	}

	/**
	 * Invalid names/emails are rejected before any writes.
	 */
	public function test_create_booking_validates_fields() {
		$slot   = gmdate( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );
		$result = WP_MCP_AI_OA_Booking::create_booking( $this->booking_link_id, '', 'not-an-email', '', $slot );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_fields', $result->get_error_code() );
	}
}
