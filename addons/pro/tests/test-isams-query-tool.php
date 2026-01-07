<?php
/**
 * Tests for the iSAMS Query Tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for iSAMS query tool.
 */
class Test_ISAMS_Query_Tool extends WP_UnitTestCase {

	/**
	 * Test tool registration and availability.
	 */
	public function test_tool_registered() {
		// Check if tool registry exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Registry' ), 'Tool registry class should exist' );

		// Get the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertNotNull( $registry, 'Tool registry instance should not be null' );

		// Tool may not be registered if credentials are not configured.
		// So we check if the class exists instead.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_ISAMS_Query' ), 'iSAMS query tool class should exist' );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_ISAMS_Query();

		$this->assertEquals( 'isams_query', $tool->get_slug() );
		$this->assertEquals( 'Query iSAMS', $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'iSAMS', $tool->get_description() );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_ISAMS_Query();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'read-only', $flags );
	}

	/**
	 * Test parameter schema has required fields.
	 */
	public function test_parameter_schema() {
		$tool   = new WP_MCP_AI_Tool_ISAMS_Query();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required endpoint field.
		$this->assertContains( 'endpoint', $schema['required'] );
		$this->assertArrayHasKey( 'endpoint', $schema['properties'] );

		// Check endpoint has valid enum values.
		$this->assertArrayHasKey( 'enum', $schema['properties']['endpoint'] );
		$endpoints = $schema['properties']['endpoint']['enum'];
		$this->assertContains( 'pupils', $endpoints );
		$this->assertContains( 'employees', $endpoints );
		$this->assertContains( 'departments', $endpoints );
		$this->assertContains( 'houses', $endpoints );
		$this->assertContains( 'terms', $endpoints );
		$this->assertContains( 'subjects', $endpoints );
		$this->assertContains( 'year_groups', $endpoints );
		$this->assertContains( 'admission_applicants', $endpoints );

		// Check optional fields exist.
		$this->assertArrayHasKey( 'id', $schema['properties'] );
		$this->assertArrayHasKey( 'page', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );

		// Check pagination defaults.
		$this->assertEquals( 1, $schema['properties']['page']['default'] );
		$this->assertEquals( 20, $schema['properties']['limit']['default'] );
		$this->assertEquals( 100, $schema['properties']['limit']['maximum'] );
	}

	/**
	 * Test tool availability check.
	 */
	public function test_is_available() {
		// Tool should always be available (credentials checked in execute()).
		$this->assertTrue( WP_MCP_AI_Tool_ISAMS_Query::is_available() );

		// Set credentials.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'isams_api_url'    => 'https://example.isams.cloud/',
				'isams_api_key'    => 'test_key',
				'isams_api_secret' => 'test_secret',
			)
		);

		// Tool should still be available.
		$this->assertTrue( WP_MCP_AI_Tool_ISAMS_Query::is_available() );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test execute requires authentication.
	 */
	public function test_execute_requires_auth() {
		$tool = new WP_MCP_AI_Tool_ISAMS_Query();

		// Execute without authentication.
		$result = $tool->execute( array( 'endpoint' => 'pupils' ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute requires endpoint parameter.
	 */
	public function test_execute_requires_endpoint() {
		// Create a user with read capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_ISAMS_Query();

		// Execute without endpoint.
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_endpoint', $result->get_error_code() );
	}

	/**
	 * Test execute requires configuration.
	 */
	public function test_execute_requires_configuration() {
		// Create a user with read capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_ISAMS_Query();

		// Execute without configuration.
		$result = $tool->execute(
			array( 'endpoint' => 'pupils' ),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_isams_not_configured', $result->get_error_code() );
	}

	/**
	 * Test tool is in the correct group.
	 */
	public function test_tool_group() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'isams_query', $group_map );
		$this->assertEquals( 'external-tools', $group_map['isams_query'] );
	}

	/**
	 * Test unavailable reason message.
	 */
	public function test_unavailable_reason() {
		$reason = WP_MCP_AI_Tool_ISAMS_Query::get_unavailable_reason();

		$this->assertNotEmpty( $reason );
		$this->assertStringContainsString( 'iSAMS', $reason );
		$this->assertStringContainsString( 'credentials', $reason );
	}
}
