<?php
/**
 * Tests for WP_MCP_AI_Tool_Token_Limits class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool token limits functionality.
 */
class Test_Tool_Token_Limits extends WP_UnitTestCase {

	/**
	 * Test user ID for testing.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Clean up any existing data.
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
		delete_option( WP_MCP_AI_Tool_Token_Limits::LIMITS_OPTION );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
		delete_option( WP_MCP_AI_Tool_Token_Limits::LIMITS_OPTION );

		parent::tearDown();
	}

	/**
	 * Test getting default tool limits.
	 */
	public function test_get_default_tool_limits() {
		// Test crawl4ai specific limit.
		$crawl4ai_limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'run_crawl4ai_job' );
		$this->assertEquals( WP_MCP_AI_Tool_Token_Limits::DEFAULT_CRAWL4AI_LIMIT, $crawl4ai_limit );

		// Test general tool limit.
		$general_limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'some_other_tool' );
		$this->assertEquals( WP_MCP_AI_Tool_Token_Limits::DEFAULT_GENERAL_LIMIT, $general_limit );
	}

	/**
	 * Test setting and getting custom tool limits.
	 */
	public function test_set_and_get_tool_limit() {
		$tool_slug = 'test_tool';
		$limit     = 50000;

		// Set custom limit.
		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_limit( $tool_slug, $limit );
		$this->assertTrue( $result );

		// Verify limit was set.
		$retrieved_limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( $tool_slug );
		$this->assertEquals( $limit, $retrieved_limit );
	}

	/**
	 * Test recording tool usage.
	 */
	public function test_record_tool_usage() {
		$tool_slug = 'test_tool';
		$arguments = array( 'test' => 'data' );
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = 'This is a test result with some content';

		// Record usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, $arguments, $context, $result );

		// Verify usage was recorded.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );

		$this->assertArrayHasKey( $tool_slug, $usage );
		$this->assertGreaterThan( 0, $usage[ $tool_slug ]['total_tokens'] );
		$this->assertEquals( 1, $usage[ $tool_slug ]['requests'] );
		$this->assertNotEmpty( $usage[ $tool_slug ]['first_used'] );
		$this->assertNotEmpty( $usage[ $tool_slug ]['last_used'] );
	}

	/**
	 * Test accumulating tool usage across multiple calls.
	 */
	public function test_accumulate_tool_usage() {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = 'Test result';

		// Record usage multiple times.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Verify usage accumulated.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );

		$this->assertEquals( 3, $usage[ $tool_slug ]['requests'] );
		$this->assertGreaterThan( 0, $usage[ $tool_slug ]['total_tokens'] );
	}

	/**
	 * Test getting daily usage for a tool.
	 */
	public function test_get_daily_usage() {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = str_repeat( 'a', 400 ); // ~100 tokens.

		// Record usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Get daily usage.
		$daily_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_daily_usage( $this->test_user_id, $tool_slug );

		$this->assertGreaterThan( 0, $daily_usage );
		$this->assertLessThanOrEqual( 200, $daily_usage ); // Should be around 100 tokens.
	}

	/**
	 * Test checking tool limits.
	 */
	public function test_check_tool_limit() {
		$tool_slug = 'test_tool';
		$limit     = 1000;
		$context   = array( 'user_id' => $this->test_user_id );

		// Set a low limit.
		WP_MCP_AI_Tool_Token_Limits::set_tool_limit( $tool_slug, $limit );

		// Record usage that exceeds the limit.
		$large_result = str_repeat( 'a', 5000 ); // ~1250 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $large_result );

		// Check if limit was exceeded (should trigger logging).
		$daily_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_daily_usage( $this->test_user_id, $tool_slug );
		$this->assertGreaterThan( $limit, $daily_usage );

		// Track if event was fired.
		$event_fired = false;
		add_action(
			'wp_mcp_ai_tool_token_limit_exceeded',
			function() use ( &$event_fired ) {
				$event_fired = true;
			}
		);

		// Check limit (should fire event).
		WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug, array(), $context );

		$this->assertTrue( $event_fired, 'Tool token limit exceeded event should fire' );
	}

	/**
	 * Test resetting user tool usage.
	 */
	public function test_reset_user_tool_usage() {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = 'Test result';

		// Record some usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Verify usage exists.
		$usage_before = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayHasKey( $tool_slug, $usage_before );

		// Reset for specific tool.
		WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $this->test_user_id, $tool_slug );

		// Verify usage was reset.
		$usage_after = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayNotHasKey( $tool_slug, $usage_after );
	}

	/**
	 * Test resetting all tool usage for a user.
	 */
	public function test_reset_all_user_tool_usage() {
		$tool_slug1 = 'test_tool_1';
		$tool_slug2 = 'test_tool_2';
		$context    = array( 'user_id' => $this->test_user_id );
		$result     = 'Test result';

		// Record usage for multiple tools.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug1, array(), $context, $result );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug2, array(), $context, $result );

		// Verify usage exists.
		$usage_before = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayHasKey( $tool_slug1, $usage_before );
		$this->assertArrayHasKey( $tool_slug2, $usage_before );

		// Reset all usage.
		WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $this->test_user_id );

		// Verify all usage was reset.
		$usage_after = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertEmpty( $usage_after );
	}

	/**
	 * Test getting tool statistics.
	 */
	public function test_get_tool_statistics() {
		$tool_slug = 'test_tool';
		$context1  = array( 'user_id' => $this->test_user_id );

		// Create second test user.
		$test_user_id2 = $this->factory->user->create( array( 'role' => 'editor' ) );
		$context2      = array( 'user_id' => $test_user_id2 );

		$result = str_repeat( 'a', 400 ); // ~100 tokens.

		// Record usage for both users.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context1, $result );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context2, $result );

		// Get statistics.
		$stats = WP_MCP_AI_Tool_Token_Limits::get_tool_statistics( $tool_slug );

		$this->assertEquals( $tool_slug, $stats['tool_slug'] );
		$this->assertEquals( 2, $stats['total_users'] );
		$this->assertEquals( 2, $stats['total_requests'] );
		$this->assertGreaterThan( 0, $stats['total_tokens'] );
		$this->assertGreaterThan( 0, $stats['limit'] );

		// Clean up second user.
		delete_user_meta( $test_user_id2, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
	}

	/**
	 * Test token estimation for different data types.
	 */
	public function test_token_estimation() {
		$context = array( 'user_id' => $this->test_user_id );

		// Test string result.
		$string_result = str_repeat( 'a', 400 ); // Should be ~100 tokens.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( 'test1', array(), $context, $string_result );
		$usage1 = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertGreaterThan( 50, $usage1['test1']['total_tokens'] );
		$this->assertLessThan( 150, $usage1['test1']['total_tokens'] );

		// Test array result.
		$array_result = array(
			'key1' => 'value1',
			'key2' => 'value2',
			'key3' => str_repeat( 'x', 200 ),
		);
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( 'test2', array(), $context, $array_result );
		$usage2 = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertGreaterThan( 0, $usage2['test2']['total_tokens'] );

		// Test number result.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( 'test3', array(), $context, 12345 );
		$usage3 = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertGreaterThan( 0, $usage3['test3']['total_tokens'] );
	}

	/**
	 * Test that expired daily data is cleaned up.
	 */
	public function test_cleanup_expired_usage() {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = 'Test result';

		// Record some usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Manually add old daily entry.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$old_date = gmdate( 'Y-m-d', strtotime( '-35 days', current_time( 'timestamp', true ) ) );
		$usage[ $tool_slug ]['daily'][ $old_date ] = 100;
		update_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $usage );

		// Run cleanup.
		WP_MCP_AI_Tool_Token_Limits::cleanup_expired_usage();

		// Verify old entry was removed.
		$usage_after = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayNotHasKey( $old_date, $usage_after[ $tool_slug ]['daily'] );
	}
}
