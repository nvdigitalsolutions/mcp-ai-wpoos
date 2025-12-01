<?php
/**
 * Tests for WP MCP AI Core Server and Tools.
 *
 *
 * @package WP_MCP_AI_Core
 */

/**
 * Test case for Core MCP Server.
 */
class Test_WP_MCP_AI_Core_Server extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * Loads the Core plugin files before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load Core interfaces and classes.
		require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool-capability-flags.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool-rules.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Server/class-wp-mcp-ai-core-server.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Tools/class-wp-mcp-ai-core-tool-posts.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Tools/class-wp-mcp-ai-core-tool-media.php';
	}

	/**
	 * Test that the Core server singleton works.
	 */
	public function test_server_singleton() {
		$server1 = WP_MCP_AI_Core_Server::get_instance();
		$server2 = WP_MCP_AI_Core_Server::get_instance();

		$this->assertSame( $server1, $server2 );
	}

	/**
	 * Test tool registration.
	 */
	public function test_tool_registration() {
		$server = WP_MCP_AI_Core_Server::get_instance();

		// Register a tool.
		$result = $server->register_tool( new WP_MCP_AI_Core_Tool_Posts() );

		$this->assertTrue( $result );
		$this->assertTrue( $server->is_tool_registered( 'posts' ) );
	}

	/**
	 * Test getting a registered tool.
	 */
	public function test_get_tool() {
		$server = WP_MCP_AI_Core_Server::get_instance();
		$server->register_tool( new WP_MCP_AI_Core_Tool_Posts() );

		$tool = $server->get_tool( 'posts' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( WP_MCP_AI_Core_Tool_Interface::class, $tool );
		$this->assertEquals( 'posts', $tool->get_slug() );
	}

	/**
	 * Test that getting non-existent tool returns null.
	 */
	public function test_get_nonexistent_tool() {
		$server = WP_MCP_AI_Core_Server::get_instance();
		$tool   = $server->get_tool( 'nonexistent_tool' );

		$this->assertNull( $tool );
	}

	/**
	 * Test tool definition output.
	 */
	public function test_get_tool_definition() {
		$server = WP_MCP_AI_Core_Server::get_instance();
		$server->register_tool( new WP_MCP_AI_Core_Tool_Posts() );

		$definition = $server->get_tool_definition( 'posts' );

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'parameters', $definition );
		$this->assertEquals( 'posts', $definition['name'] );
	}

	/**
	 * Test unregistering a tool.
	 */
	public function test_unregister_tool() {
		$server = WP_MCP_AI_Core_Server::get_instance();
		$server->register_tool( new WP_MCP_AI_Core_Tool_Media() );

		$this->assertTrue( $server->is_tool_registered( 'media' ) );

		$server->unregister_tool( 'media' );

		$this->assertFalse( $server->is_tool_registered( 'media' ) );
	}
}

/**
 * Test case for Core Posts Tool.
 */
class Test_WP_MCP_AI_Core_Tool_Posts extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * Loads the Core plugin files before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load Core interfaces and classes.
		require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool-capability-flags.php';
		require_once WP_MCP_AI_PATH . 'core/includes/src/Tools/class-wp-mcp-ai-core-tool-posts.php';
	}

	/**
	 * Test posts tool has correct interface implementation.
	 */
	public function test_posts_tool_interface() {
		$tool = new WP_MCP_AI_Core_Tool_Posts();

		$this->assertInstanceOf( WP_MCP_AI_Core_Tool_Interface::class, $tool );
		$this->assertInstanceOf( WP_MCP_AI_Core_Tool_Capability_Flags_Interface::class, $tool );
	}

	/**
	 * Test posts tool slug.
	 */
	public function test_posts_tool_slug() {
		$tool = new WP_MCP_AI_Core_Tool_Posts();

		$this->assertEquals( 'posts', $tool->get_slug() );
	}

	/**
	 * Test posts tool has parameters schema.
	 */
	public function test_posts_tool_parameters_schema() {
		$tool   = new WP_MCP_AI_Core_Tool_Posts();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
	}

	/**
	 * Test posts tool capability flags.
	 */
	public function test_posts_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Core_Tool_Posts();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
	}

	/**
	 * Test posts tool list action.
	 */
	public function test_posts_tool_list_action() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		$tool   = new WP_MCP_AI_Core_Tool_Posts();
		$result = $tool->execute( array( 'action' => 'list' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	/**
	 * Test posts tool get action.
	 */
	public function test_posts_tool_get_action() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post for Get',
				'post_status' => 'publish',
			)
		);

		$tool   = new WP_MCP_AI_Core_Tool_Posts();
		$result = $tool->execute(
			array(
				'action'  => 'get',
				'post_id' => $post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertEquals( $post_id, $result['id'] );
		$this->assertEquals( 'Test Post for Get', $result['title'] );
	}
}
