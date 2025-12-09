<?php
/**
 * Tests for WP_MCP_AI_Tool_Get_User_Info_Validated class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test get_user_info_validated tool.
 */
class Test_Get_User_Info_Validated_Tool extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_User_Info_Validated
	 */
	private $tool;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Get_User_Info_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'get_user_info', $this->tool->get_slug() );
		$this->assertEquals( 'Get User Information', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );

		$schema = $this->tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/**
	 * Test successful execution with current user (no arguments).
	 */
	public function test_execute_with_current_user() {
		$user_id = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'testuser',
				'user_email' => 'testuser@example.com',
			)
		);

		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'user_id', $result );
		$this->assertEquals( $user_id, $result['user_id'] );
	}

	/**
	 * Test successful execution with specified user ID.
	 */
	public function test_execute_with_specified_user() {
		$acting_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$target_user_id = $this->factory->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'targetuser',
			)
		);

		$result = $this->tool->execute(
			array( 'user_id' => $target_user_id ),
			array( 'user_id' => $acting_user_id )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertEquals( $target_user_id, $result['user_id'] );
	}

	/**
	 * Test validation failure with negative user ID.
	 */
	public function test_validation_fails_with_negative_user_id() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $this->tool->execute(
			array( 'user_id' => -5 ),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test validation failure with invalid user ID type.
	 */
	public function test_validation_fails_with_invalid_type() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $this->tool->execute(
			array( 'user_id' => 'not-a-number' ),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test permission check - unauthenticated user.
	 */
	public function test_permission_denied_for_unauthenticated() {
		$result = $this->tool->execute(
			array(),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test permission check - user cannot view other users without permission.
	 */
	public function test_permission_denied_for_other_user_without_caps() {
		$acting_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$target_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->tool->execute(
			array( 'user_id' => $target_user_id ),
			array( 'user_id' => $acting_user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test user can view their own profile.
	 */
	public function test_user_can_view_own_profile() {
		$user_id = $this->factory->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'ownuser',
			)
		);

		$result = $this->tool->execute(
			array( 'user_id' => $user_id ),
			array( 'user_id' => $user_id )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( $user_id, $result['user_id'] );
	}

	/**
	 * Test capability flags delegation.
	 */
	public function test_capability_flags_delegation() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
		// The original tool implements capability flags interface.
		$this->assertContains( 'read', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test zero user ID defaults to current user.
	 */
	public function test_zero_user_id_defaults_to_current() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$result = $this->tool->execute(
			array( 'user_id' => 0 ),
			array( 'user_id' => $user_id )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( $user_id, $result['user_id'] );
	}

	/**
	 * Test admin can view any user.
	 */
	public function test_admin_can_view_any_user() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$user_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->tool->execute(
			array( 'user_id' => $user_id ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( $user_id, $result['user_id'] );
	}

	/**
	 * Test non-existent user returns error.
	 */
	public function test_nonexistent_user_returns_error() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $this->tool->execute(
			array( 'user_id' => 99999 ),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_user_not_found', $result->get_error_code() );
	}
}
