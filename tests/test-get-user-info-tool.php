<?php
/**
 * Tests for the Get User Info tool.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test the Get User Info tool functionality.
 */
class WP_MCP_AI_Get_User_Info_Tool_Test extends WP_UnitTestCase {

	/**
	 * Test that the get_user_info tool is registered.
	 */
	public function test_get_user_info_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		$this->assertNotNull( $tool, 'The get_user_info tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test getting current user info without parameters.
	 */
	public function test_get_current_user_info() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'get_user_info' );
		$user_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test Admin',
				'user_login'   => 'testadmin',
				'user_email'   => 'admin@example.com',
			)
		);

		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertArrayHasKey( 'display_name', $result );
		$this->assertArrayHasKey( 'user_login', $result );
		$this->assertArrayHasKey( 'user_email', $result );
		$this->assertArrayHasKey( 'roles', $result );
		$this->assertSame( $user_id, $result['ID'] );
		$this->assertSame( 'Test Admin', $result['display_name'] );
		$this->assertSame( 'testadmin', $result['user_login'] );
	}

	/**
	 * Test getting specific user info with user_id parameter.
	 */
	public function test_get_specific_user_info() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		// Create an admin who will execute the tool.
		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Create a target user to inspect.
		$target_user_id = $this->factory->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Target User',
				'user_login'   => 'targetuser',
				'user_email'   => 'target@example.com',
			)
		);

		$result = $tool->execute(
			array( 'user_id' => $target_user_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertSame( $target_user_id, $result['ID'] );
		$this->assertSame( 'Target User', $result['display_name'] );
		$this->assertSame( 'targetuser', $result['user_login'] );
	}

	/**
	 * Test that additional parameters are accepted without errors.
	 *
	 * This test verifies the fix for the "Invalid parameter(s): messages" issue.
	 * The tool should accept and ignore additional parameters that aren't defined
	 * in its schema, allowing flexibility when called by AI providers.
	 */
	public function test_accepts_additional_parameters() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'get_user_info' );
		$user_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test User',
			)
		);

		// Execute with additional parameters that aren't in the schema.
		// This simulates what might happen when called by OpenAI or other AI providers.
		$result = $tool->execute(
			array(
				'user_id'  => $user_id,
				'messages' => 'This is an extra parameter that should be ignored',
				'extra'    => array( 'data' => 'value' ),
				'foo'      => 'bar',
			),
			array( 'user_id' => $user_id )
		);

		// The tool should execute successfully and return user info.
		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Tool should not return an error with additional parameters.' );
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertSame( $user_id, $result['ID'] );
		$this->assertSame( 'Test User', $result['display_name'] );
	}

	/**
	 * Test that the schema does not have additionalProperties set to false.
	 */
	public function test_schema_allows_additional_properties() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool   = $registry->get_tool( 'get_user_info' );
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema, 'Schema should be an array.' );

		// Verify that additionalProperties is not set to false.
		// It can be absent (which allows additional properties) or explicitly set to true.
		if ( isset( $schema['additionalProperties'] ) ) {
			$this->assertNotSame(
				false,
				$schema['additionalProperties'],
				'Schema should not have additionalProperties set to false to allow flexibility.'
			);
		}
	}

	/**
	 * Test that the result includes a message field for chat UI display.
	 *
	 * The message field is used by the chat UI to display tool results
	 * in localStorage and CCT (Custom Content Type) for persistence.
	 */
	public function test_result_includes_message_field_for_display() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'get_user_info' );
		$user_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test Admin',
				'user_login'   => 'testadmin',
				'user_email'   => 'admin@example.com',
			)
		);

		// Add first and last name to test full name display.
		update_user_meta( $user_id, 'first_name', 'Test' );
		update_user_meta( $user_id, 'last_name', 'Admin' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertArrayHasKey( 'message', $result, 'Result should include a message field for display.' );
		$this->assertIsString( $result['message'], 'Message field should be a string.' );
		$this->assertNotEmpty( $result['message'], 'Message field should not be empty.' );

		// Verify the message contains expected information.
		$this->assertStringContainsString( 'Test Admin', $result['message'], 'Message should contain display name.' );
		$this->assertStringContainsString( (string) $user_id, $result['message'], 'Message should contain user ID.' );
		$this->assertStringContainsString( 'admin@example.com', $result['message'], 'Message should contain email.' );
		$this->assertStringContainsString( 'administrator', $result['message'], 'Message should contain role.' );
	}

	/**
	 * Test permission checks - non-admin cannot view other users.
	 */
	public function test_permission_check_for_viewing_other_users() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );

		// Create a subscriber.
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		// Create another user to try to inspect.
		$target_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$result = $tool->execute(
			array( 'user_id' => $target_user_id ),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Non-admin should not be able to view other users.' );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}
}
