<?php
/**
 * Tests for per-call and per-session token limits
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for per-call and per-session limits
 */
class Test_Per_Call_And_Session_Limits extends WP_UnitTestCase {

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Initialize the tool token limits system.
		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			WP_MCP_AI_Tool_Token_Limits::init();
		}
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		// Clean up user meta and transients.
		delete_user_meta( $this->test_user_id, '_wp_mcp_ai_tool_token_usage' );

		parent::tearDown();
	}

	/**
	 * Test that per-session usage is tracked correctly
	 */
	public function test_session_usage_tracking() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );

		// Simulate tool execution result.
		$result = str_repeat( 'a', 4000 ); // ~1000 tokens.

		// Record usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Get session usage.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );

		// Should be approximately 1000 tokens.
		$this->assertGreaterThan( 900, $usage );
		$this->assertLessThan( 1100, $usage );
	}

	/**
	 * Test that session data includes tool breakdown
	 */
	public function test_session_data_structure() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );

		// Simulate tool execution.
		$result = str_repeat( 'a', 4000 ); // ~1000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Get session data.
		$session_data = WP_MCP_AI_Tool_Token_Limits::get_session_data( $this->test_user_id, $session_id );

		// Verify structure.
		$this->assertIsArray( $session_data );
		$this->assertArrayHasKey( 'total_tokens', $session_data );
		$this->assertArrayHasKey( 'tool_calls', $session_data );
		$this->assertArrayHasKey( 'started_at', $session_data );
		$this->assertArrayHasKey( $tool_slug, $session_data['tool_calls'] );
		$this->assertEquals( 1, $session_data['tool_calls'][ $tool_slug ]['count'] );
	}

	/**
	 * Test that per-session limit checking throws exception when exceeded
	 */
	public function test_per_session_limit_enforcement() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits with low threshold.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'per_session_token_limit', 500 );

		// Record usage that exceeds the limit.
		$result = str_repeat( 'a', 4000 ); // ~1000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Try to execute another tool call - should throw exception.
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/Tool execution blocked/' );

		WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug, array(), $context );
	}

	/**
	 * Test that per-session limits can be disabled
	 */
	public function test_per_session_limit_disabled() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Disable per-session limits.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', false );
		WP_MCP_AI_Settings_Registry::update_setting( 'per_session_token_limit', 100 );

		// Record usage that would exceed limit if enabled.
		$result = str_repeat( 'a', 4000 ); // ~1000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Should not throw exception since limits are disabled.
		try {
			WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug, array(), $context );
			$this->assertTrue( true ); // Passed if no exception.
		} catch ( Exception $e ) {
			$this->fail( 'Exception should not be thrown when limits are disabled' );
		}
	}

	/**
	 * Test that session usage can be reset
	 */
	public function test_reset_session_usage() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );

		// Record some usage.
		$result = str_repeat( 'a', 4000 );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Verify usage is tracked.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );
		$this->assertGreaterThan( 0, $usage );

		// Reset session.
		WP_MCP_AI_Tool_Token_Limits::reset_session_usage( $this->test_user_id, $session_id );

		// Verify usage is now zero.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );
		$this->assertEquals( 0, $usage );
	}

	/**
	 * Test that per-call limit logging works
	 */
	public function test_per_call_limit_logging() {
		$tool_slug = 'test_tool';
		$context   = array(
			'user_id'    => $this->test_user_id,
			'session_id' => 'test-session',
		);

		// Enable per-call limits with low threshold.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_call_limits', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'per_call_token_limit', 500 );

		// Hook into the per-call limit exceeded action.
		$action_fired = false;
		$logged_data  = null;

		add_action(
			'wp_mcp_ai_per_call_limit_exceeded',
			function ( $user_id, $slug, $tokens, $limit, $ctx ) use ( &$action_fired, &$logged_data ) {
				$action_fired = true;
				$logged_data  = array(
					'user_id'   => $user_id,
					'tool_slug' => $slug,
					'tokens'    => $tokens,
					'limit'     => $limit,
				);
			},
			10,
			5
		);

		// Record usage that exceeds per-call limit.
		$result = str_repeat( 'a', 4000 ); // ~1000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Verify action was fired.
		$this->assertTrue( $action_fired, 'Per-call limit exceeded action should fire' );
		$this->assertNotNull( $logged_data );
		$this->assertEquals( $this->test_user_id, $logged_data['user_id'] );
		$this->assertEquals( $tool_slug, $logged_data['tool_slug'] );
		$this->assertGreaterThan( 500, $logged_data['tokens'] );
	}

	/**
	 * Test multiple tool calls accumulate in session
	 */
	public function test_session_accumulation() {
		$session_id = 'test-session-' . time();
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );

		// Simulate multiple tool calls.
		for ( $i = 0; $i < 3; $i++ ) {
			$result = str_repeat( 'a', 2000 ); // ~500 tokens each.
			WP_MCP_AI_Tool_Token_Limits::record_tool_usage( "tool_{$i}", array(), $context, $result );
		}

		// Get session usage.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );

		// Should be approximately 1500 tokens (3 * 500).
		$this->assertGreaterThan( 1300, $usage );
		$this->assertLessThan( 1700, $usage );
	}

	/**
	 * Test that session data expires after 24 hours
	 */
	public function test_session_data_expiration() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );

		// Record usage.
		$result = str_repeat( 'a', 4000 );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Verify data exists.
		$data = WP_MCP_AI_Tool_Token_Limits::get_session_data( $this->test_user_id, $session_id );
		$this->assertNotNull( $data );

		// Simulate expiration by deleting the transient.
		delete_transient( "wp_mcp_ai_session_{$this->test_user_id}_{$session_id}" );

		// Verify data is gone.
		$data = WP_MCP_AI_Tool_Token_Limits::get_session_data( $this->test_user_id, $session_id );
		$this->assertNull( $data );
	}

	/**
	 * Test that session limit enforcement uses safety buffer to prevent overage.
	 *
	 * This test validates the fix for the issue where sessions could exceed
	 * their limit on the final call because pre-execution checks didn't account
	 * for tokens from the current call.
	 */
	public function test_session_limit_safety_buffer_prevents_overage() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits with low threshold (10K tokens).
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'per_session_token_limit', 10000 );

		// First call: Use 6K tokens (60% of limit).
		$result1 = str_repeat( 'a', 24000 ); // ~6000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result1 );

		// Verify session usage is ~6K.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );
		$this->assertGreaterThan( 5500, $usage );
		$this->assertLessThan( 6500, $usage );

		// Second call: Try to use another 6K tokens.
		// With 20% safety buffer, effective limit is 8K.
		// Current usage (6K) + safety buffer check should trigger at 8K.
		// Since 6K < 8K, this call should still be allowed.
		$result2 = str_repeat( 'a', 24000 ); // ~6000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug . '_2', array(), $context, $result2 );

		// After second call, total should be ~12K (exceeded 10K limit).
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );
		$this->assertGreaterThan( 11000, $usage );

		// Third call should now be blocked because session is marked over-budget.
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/Tool execution blocked/' );

		WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug . '_3', array(), $context );
	}

	/**
	 * Test that session marked as over-budget blocks all subsequent calls.
	 */
	public function test_over_budget_session_blocks_future_calls() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits with very low threshold.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'per_session_token_limit', 5000 );

		// First call: Use 8K tokens (exceeds 5K limit).
		$result = str_repeat( 'a', 32000 ); // ~8000 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Session should now be marked as over-budget.
		$session_data = WP_MCP_AI_Tool_Token_Limits::get_session_data( $this->test_user_id, $session_id );
		$this->assertTrue( $session_data['over_budget'] );

		// Second call should be blocked immediately.
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/Tool execution blocked/' );

		WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug . '_2', array(), $context );
	}

	/**
	 * Test that the near-limit warning action fires at 75% of session limit.
	 */
	public function test_session_limit_approaching_action_fires_at_75_percent() {
		$session_id = 'test-session-' . time();
		$tool_slug  = 'test_tool';
		$context    = array(
			'user_id'    => $this->test_user_id,
			'session_id' => $session_id,
		);

		// Enable per-session limits with 10K threshold.
		// Warning fires at 75% (7,500 tokens), effective limit is at 80% (8,000 tokens).
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_per_session_limits', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'per_session_token_limit', 10000 );

		$logged_data = null;
		add_action(
			'wp_mcp_ai_session_limit_approaching',
			function ( $user_id, $sid, $usage, $limit ) use ( &$logged_data ) {
				$logged_data = compact( 'user_id', 'sid', 'usage', 'limit' );
			},
			10,
			4
		);

		// Record ~7,750 tokens (77.5% of 10K limit — between 75% warning threshold and 80% effective limit).
		// estimate_tokens uses floor(strlen($str) / 4), so target_chars = target_tokens * 4.
		$target_tokens = 7750; // 77.5% of 10,000; above 7,500 (75%) and below 8,000 (80%).
		$result        = str_repeat( 'a', $target_tokens * 4 );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Verify session usage is in the 75-80% zone.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $this->test_user_id, $session_id );
		$this->assertGreaterThanOrEqual( 7500, $usage, 'Usage should be at or above 75% warning threshold' );
		$this->assertLessThan( 8000, $usage, 'Usage should be below 80% effective limit' );

		// Check: the approaching action should fire on the next tool call check.
		WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug . '_2', array(), $context );

		// The approaching action should have fired with the correct parameters.
		$this->assertNotNull( $logged_data, 'Near-limit warning action should fire when session usage exceeds 75% of limit' );
		$this->assertEquals( $this->test_user_id, $logged_data['user_id'] );
		$this->assertEquals( $session_id, $logged_data['sid'] );
		$this->assertGreaterThanOrEqual( 7500, $logged_data['usage'] );
		$this->assertEquals( 10000, $logged_data['limit'] );
	}
}
