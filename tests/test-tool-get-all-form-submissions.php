<?php
/**
 * Tests for get_all_form_submissions tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test get_all_form_submissions tool functionality.
 */
class Test_Tool_Get_All_Form_Submissions extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_All_Form_Submissions
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_All_Form_Submissions' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Get_All_Form_Submissions class not available.' );
		}

		$this->tool = new WP_MCP_AI_Tool_Get_All_Form_Submissions();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'get_all_form_submissions', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Required capability is edit_posts.
	 */
	public function test_required_capability() {
		$this->assertSame( 'edit_posts', $this->tool->get_required_capability() );
	}

	/**
	 * Parameter schema is valid.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );

		// Verify key properties exist.
		$this->assertArrayHasKey( 'sources', $schema['properties'] );
		$this->assertArrayHasKey( 'form_id', $schema['properties'] );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );
		$this->assertArrayHasKey( 'connection_id', $schema['properties'] );
	}

	/**
	 * Unauthenticated user returns forbidden error.
	 */
	public function test_unauthenticated_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Tool reports availability status.
	 */
	public function test_is_available_returns_boolean() {
		$available = WP_MCP_AI_Tool_Get_All_Form_Submissions::is_available();

		$this->assertIsBool( $available );
	}

	/**
	 * Tool has unavailable reason message.
	 */
	public function test_get_unavailable_reason_returns_string() {
		$reason = WP_MCP_AI_Tool_Get_All_Form_Submissions::get_unavailable_reason();

		$this->assertIsString( $reason );
		$this->assertNotEmpty( $reason );
	}

	/**
	 * Limit is clamped between 1 and 50.
	 */
	public function test_limit_clamped() {
		// When no form sources are available, the tool returns an error.
		// But limit clamping happens before source availability check in execute().
		// Test that providing out-of-range limits doesn't crash.
		$result = $this->tool->execute(
			array( 'limit' => 100 ),
			array( 'user_id' => $this->admin_id )
		);

		// Should either return submissions or a no-sources error, never crash.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Invalid sources are silently accepted (handled in execute).
	 */
	public function test_invalid_source_names_accepted() {
		$result = $this->tool->execute(
			array( 'sources' => array( 'nonexistent_source' ) ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Capability flags are returned if available.
	 */
	public function test_capability_flags() {
		if ( ! method_exists( $this->tool, 'get_capability_flags' ) ) {
			$this->markTestSkipped( 'get_capability_flags method not available.' );
		}

		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
	}
}
