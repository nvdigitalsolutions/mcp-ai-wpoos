<?php
/**
 * Tests for the WP_MCP_AI_Restriction_Registry class.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test restriction registry flagging, listing, and lifting.
 */
class Test_Restriction_Registry extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		delete_option( WP_MCP_AI_Restriction_Registry::INDEX_OPTION );
		delete_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Restriction_Registry::USER_META_KEY );
		delete_option( WP_MCP_AI_Restriction_Registry::INDEX_OPTION );
		delete_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION );

		parent::tearDown();
	}

	/**
	 * Test that flagging stores an active record and updates the index.
	 */
	public function test_flag_creates_active_restriction() {
		$record = WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array(
				'scope'       => 'chat',
				'limit'       => 60,
				'window'      => 60,
				'released_at' => time() + 60,
			)
		);

		$this->assertIsArray( $record );
		$this->assertSame( WP_MCP_AI_Restriction_Registry::STATUS_ACTIVE, $record['status'] );
		$this->assertSame( 'chat', $record['scope'] );
		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ) );
		$this->assertSame( 1, WP_MCP_AI_Restriction_Registry::count_active() );

		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $this->test_user_id );
		$this->assertArrayHasKey( WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT, $records );
	}

	/**
	 * Test that invalid types are rejected.
	 */
	public function test_flag_rejects_invalid_type() {
		$result = WP_MCP_AI_Restriction_Registry::flag( $this->test_user_id, 'bogus_type' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_restriction_type', $result->get_error_code() );
	}

	/**
	 * Test that re-flagging increments the trigger counter instead of duplicating.
	 */
	public function test_reflag_increments_trigger_count() {
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array( 'released_at' => time() + 60 )
		);
		$second = WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array( 'released_at' => time() + 120 )
		);

		$this->assertSame( 2, $second['trigger_count'] );
		$this->assertSame( 1, WP_MCP_AI_Restriction_Registry::count_active() );
	}

	/**
	 * Test that lifting clears the restriction and resets the index.
	 */
	public function test_lift_clears_restriction() {
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE,
			array(
				'scope'       => 'tool',
				'tool_slug'   => 'test_tool',
				'released_at' => time() + 3600,
			)
		);

		$result = WP_MCP_AI_Restriction_Registry::lift( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE, 1 );

		$this->assertTrue( $result );
		$this->assertFalse( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id ) );
		$this->assertSame( 0, WP_MCP_AI_Restriction_Registry::count_active() );

		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $this->test_user_id );
		$this->assertSame( WP_MCP_AI_Restriction_Registry::STATUS_CLEARED, $records[ WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE ]['status'] );
		$this->assertSame( 1, $records[ WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE ]['cleared_by'] );
	}

	/**
	 * Test that lifting a missing restriction returns a WP_Error.
	 */
	public function test_lift_missing_restriction_errors() {
		$result = WP_MCP_AI_Restriction_Registry::lift( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_MANUAL );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_no_active_restriction', $result->get_error_code() );
	}

	/**
	 * Test that "all" lifts every active restriction for the user.
	 */
	public function test_lift_all_clears_every_type() {
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array( 'released_at' => time() + 60 )
		);
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL,
			array( 'reason' => 'Manual test block' )
		);

		$result = WP_MCP_AI_Restriction_Registry::lift( $this->test_user_id, 'all', 7 );

		$this->assertTrue( $result );
		$this->assertFalse( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id ) );
		$this->assertSame( 0, WP_MCP_AI_Restriction_Registry::count_active() );
	}

	/**
	 * Test that expired restrictions are dropped from the active index.
	 */
	public function test_maybe_expire_removes_elapsed_restrictions() {
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array( 'released_at' => time() - 10 )
		);

		$this->assertSame( 0, WP_MCP_AI_Restriction_Registry::count_active() );

		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $this->test_user_id );
		$this->assertSame( WP_MCP_AI_Restriction_Registry::STATUS_EXPIRED, $records[ WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ]['status'] );
	}

	/**
	 * Test the token overage hook handler flags the user.
	 */
	public function test_on_tool_token_limit_exceeded_flags_user() {
		WP_MCP_AI_Restriction_Registry::on_tool_token_limit_exceeded(
			$this->test_user_id,
			'test_tool',
			50001,
			50000,
			gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			'free'
		);

		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE ) );

		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $this->test_user_id );
		$this->assertSame( 'test_tool', $records[ WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE ]['tool_slug'] );
		$this->assertSame( 50000, $records[ WP_MCP_AI_Restriction_Registry::TYPE_TOKEN_OVERAGE ]['limit'] );
	}

	/**
	 * Test the session limit hook handler flags the user.
	 */
	public function test_on_per_session_limit_exceeded_flags_user() {
		WP_MCP_AI_Restriction_Registry::on_per_session_limit_exceeded(
			$this->test_user_id,
			'session-123',
			45000,
			50000
		);

		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_SESSION_LIMIT ) );
	}

	/**
	 * Test the rate limit hook handler flags users for chat keys.
	 */
	public function test_on_rate_limit_exceeded_flags_chat_keys() {
		WP_MCP_AI_Restriction_Registry::on_rate_limit_exceeded( 'chat:' . $this->test_user_id . ':42', 60, 60 );

		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ) );

		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $this->test_user_id );
		$this->assertSame( 42, $records[ WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ]['assistant_id'] );
	}

	/**
	 * Test the rate limit hook handler ignores non-chat keys.
	 */
	public function test_on_rate_limit_exceeded_ignores_unrecognized_keys() {
		WP_MCP_AI_Restriction_Registry::on_rate_limit_exceeded( 'other:key', 10, 60 );

		$this->assertSame( 0, WP_MCP_AI_Restriction_Registry::count_active() );
	}

	/**
	 * Test the REST request rate-limit hook handler flags users.
	 */
	public function test_on_rest_request_rate_limit_exceeded_flags_users() {
		$window_end = time() + 3600;

		WP_MCP_AI_Restriction_Registry::on_rest_request_rate_limit_exceeded(
			$this->test_user_id,
			998,
			3600,
			998,
			$window_end
		);

		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ) );

		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $this->test_user_id );
		$record  = $records[ WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ];

		$this->assertSame( 'rest', $record['scope'] );
		$this->assertSame( 998, $record['limit'] );
		$this->assertSame( 3600, $record['window'] );
		$this->assertSame( 998, $record['usage'] );
		$this->assertSame( $window_end, $record['released_at'] );
	}

	/**
	 * Test the REST request rate-limit hook handler ignores guests.
	 */
	public function test_on_rest_request_rate_limit_exceeded_ignores_guests() {
		WP_MCP_AI_Restriction_Registry::on_rest_request_rate_limit_exceeded( 0, 100, 3600, 100, time() + 3600 );

		$this->assertSame( 0, WP_MCP_AI_Restriction_Registry::count_active() );
	}

	/**
	 * Test lifting a REST rate-limit flag deletes the request-limit window.
	 */
	public function test_lift_rate_limit_deletes_rest_request_window() {
		$transient_key = 'wp_mcp_ai_rate_limit_user_' . $this->test_user_id;
		set_transient(
			$transient_key,
			array(
				'count'      => 5,
				'first_seen' => time(),
			),
			60
		);

		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array(
				'scope'       => 'rest',
				'limit'       => 5,
				'window'      => 60,
				'released_at' => time() + 60,
			)
		);

		$result = WP_MCP_AI_Restriction_Registry::lift( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT );

		$this->assertTrue( $result );
		$this->assertFalse( get_transient( $transient_key ), 'Lifting should clear the REST request-limit window' );
		$this->assertFalse( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ) );
	}

	/**
	 * Test manual blocks via the public helper.
	 */
	public function test_add_manual_block() {
		$record = WP_MCP_AI_Restriction_Registry::add_manual(
			$this->test_user_id,
			array( 'reason' => 'Testing' ),
			1
		);

		$this->assertIsArray( $record );
		$this->assertSame( 'Testing', $record['reason'] );
		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_MANUAL ) );
	}

	/**
	 * Test that get_active hydrates user display data.
	 */
	public function test_get_active_hydrates_user_data() {
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL,
			array( 'reason' => 'Hydration test' )
		);

		$data = WP_MCP_AI_Restriction_Registry::get_active();

		$this->assertSame( 1, $data['total'] );
		$this->assertNotEmpty( $data['rows'][0]['display_name'] );
		$this->assertNotEmpty( $data['rows'][0]['user_login'] );
	}

	/**
	 * Test that the flagged and lifted actions fire.
	 */
	public function test_actions_fire_on_flag_and_lift() {
		$flagged = 0;
		$lifted  = 0;

		add_action(
			'wp_mcp_ai_restriction_flagged',
			function () use ( &$flagged ) {
				++$flagged;
			}
		);
		add_action(
			'wp_mcp_ai_restriction_lifted',
			function () use ( &$lifted ) {
				++$lifted;
			}
		);

		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL
		);
		WP_MCP_AI_Restriction_Registry::lift( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_MANUAL, 1 );

		$this->assertSame( 1, $flagged );
		$this->assertSame( 1, $lifted );
	}

	/**
	 * Test that admin notices queue by default and respect the settings toggle.
	 */
	public function test_admin_notices_respect_settings_toggle() {
		// Default: notices queue on flag.
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL
		);
		$notices = get_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION, array() );
		$this->assertNotEmpty( $notices );

		delete_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION );

		// Toggle off: no notices queue, but the restriction still flags.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array( 'enable_restriction_admin_notices' => false ) );

		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array( 'released_at' => time() + 60 )
		);

		$this->assertEmpty( get_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION, array() ) );
		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ) );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}
}
