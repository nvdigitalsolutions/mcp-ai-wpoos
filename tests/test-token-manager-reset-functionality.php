<?php
/**
 * Tests for Token Manager reset functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test token manager's reset functionality for tool-specific usage.
 */
class Test_Token_Manager_Reset_Functionality extends WP_UnitTestCase {

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

		// Create a test user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Clean up any existing data.
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );

		parent::tearDown();
	}

	/**
	 * Test that reset_user_tool_usage resets all tool usage for a user.
	 */
	public function test_reset_user_tool_usage_clears_all_tools() {
		$tool_slug1 = 'test_tool_1';
		$tool_slug2 = 'test_tool_2';
		$context    = array( 'user_id' => $this->test_user_id );
		$result     = 'Test result with some content';

		// Record usage for multiple tools.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug1, array(), $context, $result );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug2, array(), $context, $result );

		// Verify usage was recorded.
		$usage_before = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayHasKey( $tool_slug1, $usage_before );
		$this->assertArrayHasKey( $tool_slug2, $usage_before );

		// Reset all tool usage.
		WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $this->test_user_id );

		// Verify all usage was cleared.
		$usage_after = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertEmpty( $usage_after );
	}

	/**
	 * Test that reset_user_tool_usage can reset a specific tool.
	 */
	public function test_reset_user_tool_usage_clears_specific_tool() {
		$tool_slug1 = 'test_tool_1';
		$tool_slug2 = 'test_tool_2';
		$context    = array( 'user_id' => $this->test_user_id );
		$result     = 'Test result with some content';

		// Record usage for multiple tools.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug1, array(), $context, $result );
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug2, array(), $context, $result );

		// Verify usage was recorded.
		$usage_before = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayHasKey( $tool_slug1, $usage_before );
		$this->assertArrayHasKey( $tool_slug2, $usage_before );

		// Reset only tool 1.
		WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $this->test_user_id, $tool_slug1 );

		// Verify only tool 1 was cleared.
		$usage_after = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayNotHasKey( $tool_slug1, $usage_after );
		$this->assertArrayHasKey( $tool_slug2, $usage_after );
	}

	/**
	 * Test that both general usage and tool usage are reset together.
	 */
	public function test_comprehensive_reset_clears_both_trackers() {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = 'Test result with some content';

		// Record tool-specific usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage( $tool_slug, array(), $context, $result );

		// Simulate general usage tracking.
		$general_usage = array(
			'openai' => array(
				'gpt-4' => array(
					'requests'          => 1,
					'total_tokens'      => 100,
					'prompt_tokens'     => 50,
					'completion_tokens' => 50,
					'cached_tokens'     => 0,
				),
			),
		);
		update_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, $general_usage );

		// Verify both types of data exist.
		$tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$general    = get_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );

		$this->assertNotEmpty( $tool_usage );
		$this->assertNotEmpty( $general );

		// Perform comprehensive reset (simulating what the AJAX handler does).
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
		WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $this->test_user_id );

		// Verify both types of data were cleared.
		$tool_usage_after = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $this->test_user_id );
		$general_after    = get_user_meta( $this->test_user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );

		$this->assertEmpty( $tool_usage_after );
		$this->assertEmpty( $general_after );
	}
}
