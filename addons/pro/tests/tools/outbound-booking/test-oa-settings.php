<?php
/**
 * Test Outbound Booking settings accessor.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Settings tests.
 */
class Test_OA_Settings extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-settings.php';
		delete_option( WP_MCP_AI_OA_Settings::OPTION );
	}

	/**
	 * Defaults merge over a missing option.
	 */
	public function test_defaults_apply_when_option_missing() {
		$s = WP_MCP_AI_OA_Settings::get();
		$this->assertSame( 'approval', $s['email_mode'] );
		$this->assertSame( 100, $s['daily_cap'] );
		$this->assertSame( 80, $s['champion_allocation'] );
		$this->assertSame( 10, $s['min_test_sends'] );
	}

	/**
	 * Update sanitises mode enums and clamps numeric fields.
	 */
	public function test_update_sanitises_values() {
		WP_MCP_AI_OA_Settings::update(
			array(
				'email_mode'          => 'garbage',
				'linkedin_mode'       => 'webhook',
				'daily_cap'           => 99999,
				'send_window_start'   => 99,
				'champion_allocation' => 10,
				'min_test_sends'      => 0,
				'from_name'           => '<b>Acme</b>',
				'digest_enabled'      => '',
			)
		);
		$s = WP_MCP_AI_OA_Settings::get();
		$this->assertSame( 'approval', $s['email_mode'] );
		$this->assertSame( 'webhook', $s['linkedin_mode'] );
		$this->assertSame( 1000, $s['daily_cap'] );
		$this->assertSame( 23, $s['send_window_start'] );
		$this->assertSame( 50, $s['champion_allocation'] );
		$this->assertSame( 1, $s['min_test_sends'] );
		$this->assertSame( 'Acme', $s['from_name'] );
		$this->assertSame( '0', $s['digest_enabled'] );
	}

	/**
	 * Reply token is only overwritten when a non-empty value is provided.
	 */
	public function test_reply_token_not_wiped_by_empty_value() {
		WP_MCP_AI_OA_Settings::update( array( 'reply_token' => 'secret-123' ) );
		WP_MCP_AI_OA_Settings::update( array( 'reply_token' => '' ) );
		$this->assertSame( 'secret-123', WP_MCP_AI_OA_Settings::get_setting( 'reply_token', '' ) );
	}

	/**
	 * The toolkit flag reads from the master settings option.
	 */
	public function test_is_enabled_tracks_feature_flag() {
		delete_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( WP_MCP_AI_OA_Settings::is_enabled() );
		update_option( 'wp_mcp_ai_settings', array( 'enable_outbound_booking_toolkit' => 1 ) );
		$this->assertTrue( WP_MCP_AI_OA_Settings::is_enabled() );
	}
}
