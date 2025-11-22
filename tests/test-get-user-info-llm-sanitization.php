<?php
/**
 * Tests for Get User Info tool LLM sanitization.
 *
 * @package WP_MCP_AI
 */

/**
 * Test the LLM sanitization functionality of the Get User Info tool.
 */
class WP_MCP_AI_Get_User_Info_LLM_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that the tool implements the LLM sanitizer interface.
	 */
	public function test_tool_implements_sanitizer_interface() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		$this->assertNotNull( $tool, 'The get_user_info tool should be registered.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_LLM_Sanitizer_Interface::class, $tool, 'Tool should implement LLM sanitizer interface.' );
	}

	/**
	 * Test that sanitize_for_llm removes PII fields.
	 */
	public function test_sanitize_for_llm_removes_pii() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		// Create a mock tool result with all fields.
		$result = array(
			'summary'      => 'User: Test User',
			'ID'           => 123,
			'display_name' => 'Test User',
			'user_login'   => 'testuser',
			'user_email'   => 'test@example.com',
			'roles'        => array( 'administrator' ),
			'registered'   => '2024-01-01 00:00:00',
			'first_name'   => 'Test',
			'last_name'    => 'User',
		);

		// Sanitize for LLM.
		$sanitized = $tool->sanitize_for_llm( $result );

		// Verify PII fields are removed.
		$this->assertIsArray( $sanitized, 'Sanitized result should be an array.' );
		$this->assertArrayNotHasKey( 'user_email', $sanitized, 'Email should be removed for privacy.' );
		$this->assertArrayNotHasKey( 'first_name', $sanitized, 'First name should be removed for privacy.' );
		$this->assertArrayNotHasKey( 'last_name', $sanitized, 'Last name should be removed for privacy.' );
		$this->assertArrayNotHasKey( 'registered', $sanitized, 'Registration date should be removed as it\'s not needed.' );
	}

	/**
	 * Test that sanitize_for_llm keeps essential fields.
	 */
	public function test_sanitize_for_llm_keeps_essential_fields() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		// Create a mock tool result.
		$result = array(
			'summary'      => 'User: Test User',
			'ID'           => 123,
			'display_name' => 'Test User',
			'user_login'   => 'testuser',
			'user_email'   => 'test@example.com',
			'roles'        => array( 'administrator' ),
			'registered'   => '2024-01-01 00:00:00',
			'first_name'   => 'Test',
			'last_name'    => 'User',
		);

		// Sanitize for LLM.
		$sanitized = $tool->sanitize_for_llm( $result );

		// Verify essential fields are kept.
		$this->assertArrayHasKey( 'summary', $sanitized, 'Summary should be kept.' );
		$this->assertArrayHasKey( 'ID', $sanitized, 'User ID should be kept.' );
		$this->assertArrayHasKey( 'display_name', $sanitized, 'Display name should be kept.' );
		$this->assertArrayHasKey( 'user_login', $sanitized, 'User login should be kept.' );
		$this->assertArrayHasKey( 'roles', $sanitized, 'Roles should be kept.' );

		// Verify values are unchanged.
		$this->assertSame( 'User: Test User', $sanitized['summary'] );
		$this->assertSame( 123, $sanitized['ID'] );
		$this->assertSame( 'Test User', $sanitized['display_name'] );
		$this->assertSame( 'testuser', $sanitized['user_login'] );
		$this->assertSame( array( 'administrator' ), $sanitized['roles'] );
	}

	/**
	 * Test that sanitize_for_llm handles non-array input gracefully.
	 */
	public function test_sanitize_for_llm_handles_non_array() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		// Test with string input.
		$result = 'User not found';
		$sanitized = $tool->sanitize_for_llm( $result );
		$this->assertSame( 'User not found', $sanitized, 'String input should pass through unchanged.' );

		// Test with null input.
		$result = null;
		$sanitized = $tool->sanitize_for_llm( $result );
		$this->assertNull( $sanitized, 'Null input should pass through unchanged.' );
	}

	/**
	 * Test that full execution result contains PII, but sanitized version doesn't.
	 *
	 * This simulates the agentic workflow where the full result is stored in
	 * tool_results[] for frontend display, but the sanitized version is sent to the LLM.
	 */
	public function test_full_result_vs_sanitized_result() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		// Create a test user.
		$user_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Admin User',
				'user_login'   => 'adminuser',
				'user_email'   => 'admin@example.com',
			)
		);
		update_user_meta( $user_id, 'first_name', 'Admin' );
		update_user_meta( $user_id, 'last_name', 'User' );

		// Execute the tool to get full result.
		$full_result = $tool->execute(
			array( 'user_id' => $user_id ),
			array( 'user_id' => $user_id )
		);

		// Verify full result contains PII.
		$this->assertIsArray( $full_result, 'Full result should be an array.' );
		$this->assertArrayHasKey( 'user_email', $full_result, 'Full result should include email.' );
		$this->assertArrayHasKey( 'first_name', $full_result, 'Full result should include first name.' );
		$this->assertArrayHasKey( 'last_name', $full_result, 'Full result should include last name.' );
		$this->assertSame( 'admin@example.com', $full_result['user_email'] );
		$this->assertSame( 'Admin', $full_result['first_name'] );
		$this->assertSame( 'User', $full_result['last_name'] );

		// Sanitize for LLM.
		$sanitized_result = $tool->sanitize_for_llm( $full_result );

		// Verify sanitized result does not contain PII.
		$this->assertArrayNotHasKey( 'user_email', $sanitized_result, 'Sanitized result should not include email.' );
		$this->assertArrayNotHasKey( 'first_name', $sanitized_result, 'Sanitized result should not include first name.' );
		$this->assertArrayNotHasKey( 'last_name', $sanitized_result, 'Sanitized result should not include last name.' );

		// Verify sanitized result still has essential context.
		$this->assertArrayHasKey( 'ID', $sanitized_result );
		$this->assertArrayHasKey( 'display_name', $sanitized_result );
		$this->assertArrayHasKey( 'user_login', $sanitized_result );
		$this->assertArrayHasKey( 'roles', $sanitized_result );
		$this->assertSame( $user_id, $sanitized_result['ID'] );
		$this->assertSame( 'Admin User', $sanitized_result['display_name'] );
	}
}
