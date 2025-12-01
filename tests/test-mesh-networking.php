<?php
/**
 * Tests for mesh networking functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Mesh_Networking
 */
class Test_Mesh_Networking extends WP_UnitTestCase {
	/**
	 * Admin user for testing.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Test that mesh settings have default values.
	 */
	public function test_default_mesh_settings() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$this->assertArrayHasKey( 'enable_mesh', $settings );
		$this->assertFalse( $settings['enable_mesh'] );

		$this->assertArrayHasKey( 'mesh_inbound_api_key', $settings );
		$this->assertEquals( '', $settings['mesh_inbound_api_key'] );

		$this->assertArrayHasKey( 'mesh_peer_sites', $settings );
		$this->assertIsArray( $settings['mesh_peer_sites'] );
		$this->assertEmpty( $settings['mesh_peer_sites'] );
	}

	/**
	 * Test mesh API key generation when enabling mesh.
	 */
	public function test_mesh_api_key_generation() {
		$admin = new WP_MCP_AI_Admin_Settings();

		// Simulate enabling mesh without an existing key.
		$input = array(
			'enable_mesh'          => true,
			'mesh_inbound_api_key' => '',
			'mesh_peer_sites'      => array(),
		);

		$sanitized = $admin->sanitize_settings( $input );

		$this->assertTrue( $sanitized['enable_mesh'] );
		$this->assertNotEmpty( $sanitized['mesh_inbound_api_key'] );
		$this->assertStringStartsWith( 'mesh_', $sanitized['mesh_inbound_api_key'] );
		$this->assertGreaterThan( 40, strlen( $sanitized['mesh_inbound_api_key'] ) );
	}

	/**
	 * Test mesh API key preservation when already set.
	 */
	public function test_mesh_api_key_preservation() {
		$admin        = new WP_MCP_AI_Admin_Settings();
		$existing_key = 'mesh_existingkey123456789012345678901234567890';

		$input = array(
			'enable_mesh'          => true,
			'mesh_inbound_api_key' => $existing_key,
			'mesh_peer_sites'      => array(),
		);

		$sanitized = $admin->sanitize_settings( $input );

		$this->assertEquals( $existing_key, $sanitized['mesh_inbound_api_key'] );
	}

	/**
	 * Test sanitization of peer sites array.
	 */
	public function test_peer_sites_sanitization() {
		$admin = new WP_MCP_AI_Admin_Settings();

		$input = array(
			'enable_mesh'          => true,
			'mesh_inbound_api_key' => 'mesh_test123',
			'mesh_peer_sites'      => array(
				array(
					'name'    => 'Test Site',
					'url'     => 'https://example.com',
					'api_key' => 'mesh_remote123',
				),
				array(
					'name'    => '<script>alert("xss")</script>',
					'url'     => 'javascript:alert("xss")',
					'api_key' => 'mesh_key<script>',
				),
				array(
					'name'    => '',
					'url'     => '',
					'api_key' => '',
				),
			),
		);

		$sanitized = $admin->sanitize_settings( $input );

		$this->assertCount( 2, $sanitized['mesh_peer_sites'] );

		// First entry should be preserved.
		$this->assertEquals( 'Test Site', $sanitized['mesh_peer_sites'][0]['name'] );
		$this->assertEquals( 'https://example.com', $sanitized['mesh_peer_sites'][0]['url'] );
		$this->assertEquals( 'mesh_remote123', $sanitized['mesh_peer_sites'][0]['api_key'] );

		// Second entry should be sanitized.
		$this->assertStringNotContainsString( '<script>', $sanitized['mesh_peer_sites'][1]['name'] );
		$this->assertStringNotContainsString( 'javascript:', $sanitized['mesh_peer_sites'][1]['url'] );
		$this->assertStringNotContainsString( '<script>', $sanitized['mesh_peer_sites'][1]['api_key'] );

		// Empty entry should be removed.
		$this->assertCount( 2, $sanitized['mesh_peer_sites'] );
	}

	/**
	 * Test mesh authentication with valid key.
	 */
	public function test_mesh_authentication_with_valid_key() {
		// Enable mesh and set an API key.
		$api_key = 'mesh_testkey123456789012345678901234567890';
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => $api_key,
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->getMock();

		$rest = new WP_MCP_AI_REST( $registry, $client );

		// Create a mock request with mesh key header.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', $api_key );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'test',
				),
			)
		);

		$result = $rest->permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test mesh authentication with invalid key.
	 */
	public function test_mesh_authentication_with_invalid_key() {
		// Enable mesh and set an API key.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => 'mesh_correctkey123456789012345678901234567890',
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->getMock();

		$rest = new WP_MCP_AI_REST( $registry, $client );

		// Create a mock request with invalid mesh key.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', 'mesh_wrongkey' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'test',
				),
			)
		);

		$result = $rest->permissions_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_mesh_key', $result->get_error_code() );
	}

	/**
	 * Test mesh authentication when mesh is disabled.
	 */
	public function test_mesh_authentication_when_disabled() {
		// Disable mesh.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => false,
				'mesh_inbound_api_key' => 'mesh_testkey123',
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->getMock();

		$rest = new WP_MCP_AI_REST( $registry, $client );

		// Create a mock request with mesh key.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', 'mesh_testkey123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'test',
				),
			)
		);

		$result = $rest->permissions_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_mesh_disabled', $result->get_error_code() );
	}

	/**
	 * Test query_remote_site tool registration.
	 */
	public function test_query_remote_site_tool_registration() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_remote_site' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Query_Remote_Site', $tool );
		$this->assertEquals( 'query_remote_site', $tool->get_slug() );
		$this->assertEquals( 'Query Remote Site', $tool->get_name() );
	}

	/**
	 * Test query_remote_site tool with missing peer.
	 */
	public function test_query_remote_site_tool_missing_peer() {
		wp_set_current_user( $this->admin_user_id );

		// Enable mesh but no peers.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => 'mesh_test123',
				'mesh_peer_sites'      => array(),
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_remote_site' );

		$result = $tool->execute(
			array(
				'peer_name' => 'nonexistent',
				'prompt'    => 'Hello',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_peer_not_found', $result->get_error_code() );
	}

	/**
	 * Test query_remote_site tool when mesh is disabled.
	 */
	public function test_query_remote_site_tool_mesh_disabled() {
		wp_set_current_user( $this->admin_user_id );

		// Disable mesh.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => false,
				'mesh_inbound_api_key' => '',
				'mesh_peer_sites'      => array(),
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_remote_site' );

		$result = $tool->execute(
			array(
				'peer_name' => 'test',
				'prompt'    => 'Hello',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_mesh_disabled', $result->get_error_code() );
	}

	/**
	 * Test query_remote_site tool without permission.
	 */
	public function test_query_remote_site_tool_without_permission() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_remote_site' );

		$result = $tool->execute(
			array(
				'peer_name' => 'test',
				'prompt'    => 'Hello',
			),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test query_remote_site tool parameter schema.
	 */
	public function test_query_remote_site_tool_schema() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_remote_site' );
		$schema   = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'peer_name', $schema['properties'] );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'peer_name', $schema['required'] );
		$this->assertContains( 'prompt', $schema['required'] );
	}
}
