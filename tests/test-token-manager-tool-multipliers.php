<?php
/**
 * Tests for Token Manager tool multiplier functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool multiplier get/set operations.
 */
class Test_Token_Manager_Tool_Multipliers extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clean up the option.
		delete_option( 'wp_mcp_ai_tool_multipliers' );
	}

	/**
	 * Test getting default multipliers.
	 */
	public function test_get_tool_multipliers_returns_defaults() {
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();

		$this->assertIsArray( $multipliers );
		$this->assertNotEmpty( $multipliers );

		// Check that default high-output tools have multipliers.
		$this->assertArrayHasKey( 'run_crawl4ai_job', $multipliers );
		$this->assertEquals( 2.0, $multipliers['run_crawl4ai_job'] );

		$this->assertArrayHasKey( 'search_content', $multipliers );
		$this->assertEquals( 1.5, $multipliers['search_content'] );

		$this->assertArrayHasKey( 'web_search', $multipliers );
		$this->assertEquals( 1.5, $multipliers['web_search'] );

		$this->assertArrayHasKey( 'submit_document_prompt', $multipliers );
		$this->assertEquals( 2.0, $multipliers['submit_document_prompt'] );
	}

	/**
	 * Test setting a custom multiplier.
	 */
	public function test_set_tool_multiplier_saves_successfully() {
		$tool_slug  = 'test_tool';
		$multiplier = 3.5;

		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $multiplier );

		$this->assertTrue( $result, 'set_tool_multiplier should return true on success' );

		// Verify it was saved to the option.
		$saved = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		$this->assertArrayHasKey( $tool_slug, $saved );
		$this->assertEquals( $multiplier, $saved[ $tool_slug ] );

		// Verify get_tool_multipliers returns the custom value.
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		$this->assertArrayHasKey( $tool_slug, $multipliers );
		$this->assertEquals( $multiplier, $multipliers[ $tool_slug ] );
	}

	/**
	 * Test setting multiple custom multipliers.
	 */
	public function test_set_multiple_tool_multipliers() {
		$tool1       = 'tool_one';
		$tool2       = 'tool_two';
		$multiplier1 = 2.5;
		$multiplier2 = 4.0;

		WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool1, $multiplier1 );
		WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool2, $multiplier2 );

		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();

		$this->assertArrayHasKey( $tool1, $multipliers );
		$this->assertEquals( $multiplier1, $multipliers[ $tool1 ] );

		$this->assertArrayHasKey( $tool2, $multipliers );
		$this->assertEquals( $multiplier2, $multipliers[ $tool2 ] );
	}

	/**
	 * Test that custom multipliers override defaults.
	 */
	public function test_custom_multiplier_overrides_default() {
		$tool_slug      = 'run_crawl4ai_job';
		$new_multiplier = 5.0;

		// Default should be 2.0.
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		$this->assertEquals( 2.0, $multipliers[ $tool_slug ] );

		// Set custom multiplier.
		WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $new_multiplier );

		// Should now return custom value.
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		$this->assertEquals( $new_multiplier, $multipliers[ $tool_slug ] );
	}

	/**
	 * Test multiplier validation (minimum).
	 */
	public function test_set_tool_multiplier_validates_minimum() {
		$tool_slug          = 'test_tool';
		$invalid_multiplier = 0.05; // Below minimum of 0.1.

		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $invalid_multiplier );

		$this->assertFalse( $result, 'set_tool_multiplier should return false for invalid multiplier' );

		// Verify it was not saved.
		$saved = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		$this->assertArrayNotHasKey( $tool_slug, $saved );
	}

	/**
	 * Test multiplier validation (maximum).
	 */
	public function test_set_tool_multiplier_validates_maximum() {
		$tool_slug          = 'test_tool';
		$invalid_multiplier = 15.0; // Above maximum of 10.

		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $invalid_multiplier );

		$this->assertFalse( $result, 'set_tool_multiplier should return false for invalid multiplier' );

		// Verify it was not saved.
		$saved = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		$this->assertArrayNotHasKey( $tool_slug, $saved );
	}

	/**
	 * Test multiplier validation (empty tool slug).
	 */
	public function test_set_tool_multiplier_validates_empty_slug() {
		$tool_slug  = '';
		$multiplier = 2.0;

		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $multiplier );

		$this->assertFalse( $result, 'set_tool_multiplier should return false for empty tool slug' );
	}

	/**
	 * Test that multiplier affects user tool limits.
	 */
	public function test_multiplier_affects_user_tool_limit() {
		$user_id    = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$tool_slug  = 'test_tool';
		$multiplier = 3.0;

		// Set custom multiplier.
		WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $multiplier );

		// Free tier base limit is 50000.
		// Expected limit with 3.0x multiplier: 150000.
		$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, $tool_slug );

		$this->assertEquals( 150000, $limit, 'User tool limit should be base limit × multiplier' );
	}

	/**
	 * Test tool with no multiplier uses default 1.0.
	 */
	public function test_tool_without_multiplier_uses_default() {
		$user_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$tool_slug = 'generic_tool';

		// No multiplier set for this tool.
		$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, $tool_slug );

		// Free tier base limit is 50000.
		// Expected limit with default 1.0x multiplier: 50000.
		$this->assertEquals( 50000, $limit, 'Tool without multiplier should use base limit (1.0x)' );
	}
}
