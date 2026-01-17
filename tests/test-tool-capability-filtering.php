<?php
/**
 * Test tool capability filtering in build_tools_payload.
 *
 * @package WP_MCP_AI
 */

class Test_Tool_Capability_Filtering extends WP_UnitTestCase {

	/**
	 * Mock tool that requires manage_options capability.
	 */
	private $admin_tool;

	/**
	 * Mock tool that requires no specific capability.
	 */
	private $public_tool;

	/**
	 * Tool registry instance.
	 */
	private $registry;

	/**
	 * REST controller instance.
	 */
	private $rest_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mock admin-only tool.
		$this->admin_tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Interface' )
			->setMockClassName( 'Mock_Admin_Tool' )
			->getMock();

		$this->admin_tool->method( 'get_slug' )->willReturn( 'admin_tool' );
		$this->admin_tool->method( 'get_name' )->willReturn( 'Admin Tool' );
		$this->admin_tool->method( 'get_description' )->willReturn( 'A tool requiring admin capabilities' );
		$this->admin_tool->method( 'get_parameters_schema' )->willReturn(
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);
		$this->admin_tool->method( 'get_required_capability' )->willReturn( 'manage_options' );

		// Create mock public tool.
		$this->public_tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Interface' )
			->setMockClassName( 'Mock_Public_Tool' )
			->getMock();

		$this->public_tool->method( 'get_slug' )->willReturn( 'public_tool' );
		$this->public_tool->method( 'get_name' )->willReturn( 'Public Tool' );
		$this->public_tool->method( 'get_description' )->willReturn( 'A public tool' );
		$this->public_tool->method( 'get_parameters_schema' )->willReturn(
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);

		// Get tool registry.
		$this->registry = WP_MCP_AI_Container::get( 'tool_registry' );

		// Register mock tools.
		$this->registry->register_tool( $this->admin_tool );
		$this->registry->register_tool( $this->public_tool );

		// Get REST controller (we'll use reflection to test protected method).
		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}
		$this->rest_controller = new WP_MCP_AI_REST();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Unregister mock tools.
		if ( $this->registry ) {
			// Tool registry doesn't have unregister method, so we'll just reset.
			$reflection     = new ReflectionClass( $this->registry );
			$tools_property = $reflection->getProperty( 'tools' );
			$tools_property->setAccessible( true );
			$tools = $tools_property->getValue( $this->registry );
			unset( $tools['admin_tool'], $tools['public_tool'] );
			$tools_property->setValue( $this->registry, $tools );
		}

		parent::tearDown();
	}

	/**
	 * Test that admin-only tools are filtered for non-admin users.
	 */
	public function test_admin_tool_filtered_for_non_admin_user() {
		// Create non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Assistant config with both tools.
		$assistant_config = array(
			'tools'    => array( 'admin_tool', 'public_tool' ),
			'provider' => 'openai',
		);

		// Call build_tools_payload via reflection.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'build_tools_payload' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $assistant_config );

		// Should only include public_tool.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result, 'Should only include public tool for non-admin user' );
		$this->assertEquals( 'public_tool', $result[0]['function']['name'], 'Should include public_tool' );
	}

	/**
	 * Test that admin tools are included for admin users.
	 */
	public function test_admin_tool_included_for_admin_user() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Assistant config with both tools.
		$assistant_config = array(
			'tools'    => array( 'admin_tool', 'public_tool' ),
			'provider' => 'openai',
		);

		// Call build_tools_payload via reflection.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'build_tools_payload' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $assistant_config );

		// Should include both tools.
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result, 'Should include both tools for admin user' );

		$tool_names = array_column( array_column( $result, 'function' ), 'name' );
		$this->assertContains( 'admin_tool', $tool_names, 'Should include admin_tool for admin' );
		$this->assertContains( 'public_tool', $tool_names, 'Should include public_tool for admin' );
	}

	/**
	 * Test that tools without get_required_capability method are always included.
	 */
	public function test_tools_without_capability_method_always_included() {
		// Create non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Assistant config with only public tool.
		$assistant_config = array(
			'tools'    => array( 'public_tool' ),
			'provider' => 'openai',
		);

		// Call build_tools_payload via reflection.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'build_tools_payload' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $assistant_config );

		// Should include public tool.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result, 'Should include tool without capability requirement' );
		$this->assertEquals( 'public_tool', $result[0]['function']['name'] );
	}

	/**
	 * Test that empty tools array returns empty payload.
	 */
	public function test_empty_tools_returns_empty_payload() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Assistant config with no tools.
		$assistant_config = array(
			'tools'    => array(),
			'provider' => 'openai',
		);

		// Call build_tools_payload via reflection.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'build_tools_payload' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $assistant_config );

		// Should return empty array.
		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty array when no tools configured' );
	}

	/**
	 * Test logging when tool is filtered.
	 */
	public function test_logging_when_tool_filtered() {
		// Create non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Assistant config with admin tool.
		$assistant_config = array(
			'tools'    => array( 'admin_tool' ),
			'provider' => 'openai',
		);

		// Call build_tools_payload via reflection.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'build_tools_payload' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $assistant_config );

		// Check logs.
		$logs          = get_option( 'wp_mcp_ai_recent_activity', array() );
		$filtered_logs = array_filter(
			$logs,
			function ( $log ) {
				return isset( $log['event'] ) && 'tool_filtered_by_capability' === $log['event'];
			}
		);

		$this->assertNotEmpty( $filtered_logs, 'Should log when tool is filtered by capability' );
	}
}
