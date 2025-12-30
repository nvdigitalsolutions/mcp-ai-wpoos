<?php
/**
 * Tests for WP_MCP_AI_Core_Server lazy initialization.
 *
 * Verifies that pro tools and other dynamically registered tools
 * are available when admin pages render by ensuring the server
 * automatically initializes when getter methods are called.
 *
 * @package WP_MCP_AI_Core
 */

// Load Core interfaces early so mock class can implement them.
if ( ! interface_exists( 'WP_MCP_AI_Core_Tool_Interface' ) ) {
	require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool.php';
}
if ( ! interface_exists( 'WP_MCP_AI_Core_Tool_Capability_Flags_Interface' ) ) {
	require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool-capability-flags.php';
}
if ( ! interface_exists( 'WP_MCP_AI_Core_Tool_Rules_Interface' ) ) {
	require_once WP_MCP_AI_PATH . 'core/includes/src/Interfaces/interface-wp-mcp-ai-core-tool-rules.php';
}

/**
 * Mock pro tool for testing dynamic registration in separated plugin architecture.
 */
class WP_MCP_AI_Mock_Core_Pro_Tool implements WP_MCP_AI_Core_Tool_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'mock_pro_tool';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return 'Mock Pro Tool';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'A mock pro tool registered via action hook in separated architecture';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'pro_tool_executed' );
	}
}

/**
 * @group core-server
 * @group lazy-init
 */
class WP_MCP_AI_Core_Server_Lazy_Init_Tests extends WP_UnitTestCase {

	/**
	 * Original server instance.
	 *
	 * @var WP_MCP_AI_Core_Server|null
	 */
	protected $original_instance;

	/**
	 * Flag to track if pro tools were registered.
	 *
	 * @var bool
	 */
	protected $pro_tools_registered = false;

	/**
	 * Set up test fixtures.
	 *
	 * Loads the Core plugin files before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load Core server class.
		if ( ! class_exists( 'WP_MCP_AI_Core_Server' ) ) {
			require_once WP_MCP_AI_PATH . 'core/includes/src/Server/class-wp-mcp-ai-core-server.php';
		}

		// Reset the singleton instance using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Core_Server' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );

		// Reset the initialized flag.
		$initialized_property = $reflection->getProperty( 'initialized' );
		$initialized_property->setAccessible( true );
		$initialized_property->setValue( null, false );

		$this->pro_tools_registered = false;
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Remove our test action.
		remove_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		// Restore original instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Core_Server' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	/**
	 * Simulate pro addon tool registration.
	 *
	 * This mimics what the Pro addon does in addons/pro/wp-mcp-ai-pro.php
	 *
	 * @param WP_MCP_AI_Core_Server $server Core server instance.
	 */
	public function register_pro_tool( $server ) {
		$server->register_tool( new WP_MCP_AI_Mock_Core_Pro_Tool() );
		$this->pro_tools_registered = true;
	}

	/**
	 * Test that get_tools() triggers lazy initialization.
	 *
	 * This simulates the scenario where the admin Tools Manager page
	 * calls get_tools() before the server has been explicitly initialized.
	 *
	 * In the separated plugin architecture (core + pro), pro addon hooks
	 * into wp_mcp_ai_register_tools at priority 20, but if the core is
	 * initialized before that hook is added, pro tools won't load.
	 *
	 * Lazy initialization fixes this by deferring the action hook until
	 * the first getter is called.
	 */
	public function test_get_tools_triggers_lazy_initialization() {
		// Simulate pro addon registering a tool via action hook.
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		// Get server instance WITHOUT calling init().
		$server = WP_MCP_AI_Core_Server::get_instance();

		// Call get_tools() - this should trigger lazy initialization.
		$tools = $server->get_tools();

		// Verify pro tools were registered.
		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered via action hook' );

		// Verify the pro tool is in the list.
		$tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		$this->assertContains( 'mock_pro_tool', $tool_slugs, 'Pro tool should be in the tools list' );
	}

	/**
	 * Test that get_tool() triggers lazy initialization.
	 */
	public function test_get_tool_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$server = WP_MCP_AI_Core_Server::get_instance();

		// Call get_tool() - this should trigger lazy initialization.
		$tool = $server->get_tool( 'mock_pro_tool' );

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Core_Tool_Interface', $tool, 'Should retrieve pro tool instance' );
		$this->assertEquals( 'mock_pro_tool', $tool->get_slug() );
	}

	/**
	 * Test that is_tool_registered() triggers lazy initialization.
	 */
	public function test_is_tool_registered_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$server = WP_MCP_AI_Core_Server::get_instance();

		// Call is_tool_registered() - this should trigger lazy initialization.
		$is_registered = $server->is_tool_registered( 'mock_pro_tool' );

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertTrue( $is_registered, 'Pro tool should be registered' );
	}

	/**
	 * Test that multiple calls to getters don't re-initialize.
	 */
	public function test_lazy_init_only_runs_once() {
		$init_count = 0;

		add_action(
			'wp_mcp_ai_register_tools',
			function ( $server ) use ( &$init_count ) {
				$init_count++;
				$this->register_pro_tool( $server );
			},
			20
		);

		$server = WP_MCP_AI_Core_Server::get_instance();

		// Call multiple getter methods.
		$server->get_tools();
		$server->get_tool( 'mock_pro_tool' );
		$server->is_tool_registered( 'mock_pro_tool' );

		// The action should only fire once.
		$this->assertEquals( 1, $init_count, 'Server should only initialize once despite multiple getter calls' );
	}

	/**
	 * Test that lazy init works when server is already initialized.
	 */
	public function test_lazy_init_with_already_initialized_server() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$server = WP_MCP_AI_Core_Server::get_instance();

		// Explicitly initialize.
		$server->init();

		// Verify it's initialized.
		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered after init' );

		// Reset the flag.
		$this->pro_tools_registered = false;

		// Now call a getter - it should NOT re-initialize.
		$tools = $server->get_tools();

		$this->assertFalse( $this->pro_tools_registered, 'Should not re-register tools on subsequent getter calls' );

		// But the pro tool should still be in the list.
		$tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		$this->assertContains( 'mock_pro_tool', $tool_slugs, 'Pro tool should still be in the tools list' );
	}

	/**
	 * Test that baseline core tools are loaded on lazy init.
	 */
	public function test_baseline_tools_loaded_on_lazy_init() {
		$server = WP_MCP_AI_Core_Server::get_instance();

		// Call get_tools() - should trigger lazy init and load baseline tools.
		$tools = $server->get_tools();

		$tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		// Verify baseline tools are loaded.
		$this->assertContains( 'posts', $tool_slugs, 'Posts tool should be loaded' );
		$this->assertContains( 'media', $tool_slugs, 'Media tool should be loaded' );
		$this->assertContains( 'users', $tool_slugs, 'Users tool should be loaded' );
		$this->assertContains( 'taxonomies', $tool_slugs, 'Taxonomies tool should be loaded' );
	}
}
