<?php
/**
 * Test Outbound Booking angle bank CPT.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Angle CPT tests.
 */
class Test_OA_Angle_CPT extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-settings.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-angle-cpt.php';
		if ( ! post_type_exists( WP_MCP_AI_OA_Angle_CPT::POST_TYPE ) ) {
			WP_MCP_AI_OA_Angle_CPT::register_post_type();
		}
		update_option( 'wp_mcp_ai_settings', array( 'enable_outbound_booking_toolkit' => 1 ) );
	}

	/**
	 * Resolve an angle by post ID and by slug.
	 */
	public function test_resolve_by_id_and_slug() {
		$angle_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'The Teardown Angle',
				'post_name'   => 'teardown-angle',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $angle_id, WP_MCP_AI_OA_Angle_CPT::resolve( $angle_id ) );
		$this->assertSame( $angle_id, WP_MCP_AI_OA_Angle_CPT::resolve( 'teardown-angle' ) );
		$this->assertSame( 0, WP_MCP_AI_OA_Angle_CPT::resolve( 'no-such-angle' ) );
		$this->assertSame( 0, WP_MCP_AI_OA_Angle_CPT::resolve( '' ) );
	}

	/**
	 * Variant groups contain the root and its children.
	 */
	public function test_get_group_collects_root_and_children() {
		$root = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'Root Angle',
				'post_status' => 'publish',
			)
		);
		$child = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'Variant B',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $child, '_oa_angle_variant_of', $root );
		update_post_meta( $root, '_oa_angle_test_status', 'champion' );
		update_post_meta( $child, '_oa_angle_test_status', 'challenger' );

		$group = WP_MCP_AI_OA_Angle_CPT::get_group( $child );
		$this->assertCount( 2, $group );
		$this->assertArrayHasKey( $root, $group );
		$this->assertArrayHasKey( $child, $group );
	}

	/**
	 * Champion allocation routes the configured share of sends to the champion.
	 */
	public function test_pick_variant_prefers_champion_at_full_allocation() {
		$root = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'Root Angle',
				'post_status' => 'publish',
			)
		);
		$child = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'Variant B',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $child, '_oa_angle_variant_of', $root );
		update_post_meta( $root, '_oa_angle_test_status', 'champion' );
		update_post_meta( $child, '_oa_angle_test_status', 'challenger' );
		WP_MCP_AI_OA_Settings::update( array( 'champion_allocation' => 100 ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( $root, WP_MCP_AI_OA_Angle_CPT::pick_variant( $root ) );
		}
	}

	/**
	 * Event counters increment per event type.
	 */
	public function test_record_event_increments_counters() {
		$angle_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'Stats Angle',
				'post_status' => 'publish',
			)
		);
		WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'send' );
		WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'send' );
		WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'reply' );
		WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'booking' );

		$this->assertSame( 2, (int) get_post_meta( $angle_id, '_oa_angle_sends', true ) );
		$this->assertSame( 1, (int) get_post_meta( $angle_id, '_oa_angle_replies', true ) );
		$this->assertSame( 1, (int) get_post_meta( $angle_id, '_oa_angle_bookings', true ) );
	}

	/**
	 * Meta box save applies nonce + capability + sanitisation gates.
	 */
	public function test_save_meta_sanitises_fields() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$angle_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
				'post_title'  => 'Saveable Angle',
				'post_status' => 'publish',
			)
		);
		$_POST['wp_mcp_ai_oa_angle_nonce'] = wp_create_nonce( 'wp_mcp_ai_oa_angle_save' );
		$_POST['oa_angle']                = array(
			'kind'        => 'objection',
			'channel'     => 'instagram_dm',
			'subject'     => '<script>alert(1)</script>',
			'first_line'  => '  Trimmed line  ',
			'objection'   => 'Too expensive',
			'response'    => 'Here is why it pays for itself.',
			'variant_of'  => 0,
			'test_status' => 'paused',
		);
		WP_MCP_AI_OA_Angle_CPT::save_meta( $angle_id, get_post( $angle_id ) );

		$this->assertSame( 'objection', get_post_meta( $angle_id, '_oa_angle_kind', true ) );
		$this->assertSame( 'instagram_dm', get_post_meta( $angle_id, '_oa_angle_channel', true ) );
		$this->assertSame( 'Trimmed line', get_post_meta( $angle_id, '_oa_angle_first_line', true ) );
		$this->assertStringNotContainsString( '<script>', get_post_meta( $angle_id, '_oa_angle_subject', true ) );
		$this->assertSame( 'paused', get_post_meta( $angle_id, '_oa_angle_test_status', true ) );
	}
}
