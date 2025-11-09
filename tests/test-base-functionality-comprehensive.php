<?php
/**
 * Comprehensive tests for base plugin functionality.
 *
 * This test suite validates the core features that must work in base version mode
 * without any third-party dependencies (JetEngine, WooCommerce, Elementor, etc.).
 *
 * @package WP_MCP_AI
 */

/**
 * Base Functionality Comprehensive Test Class.
 */
class WP_MCP_AI_Base_Functionality_Comprehensive_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure base version mode is active for these tests.
		add_filter( 'wp_mcp_ai_base_version', '__return_true', 999 );

		// Clear any cached instances.
		$this->reset_singletons();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_base_version', '__return_true', 999 );
		$this->reset_singletons();

		parent::tearDown();
	}

	/**
	 * Reset singleton instances to ensure clean test state.
	 */
	private function reset_singletons() {
		// Reset tool registry.
		$registry_reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		if ( $registry_reflection->hasProperty( 'instance' ) ) {
			$instance_property = $registry_reflection->getProperty( 'instance' );
			$instance_property->setAccessible( true );
			$instance_property->setValue( null, null );
		}
	}

	/**
	 * Test 1: Plugin constants are defined correctly.
	 */
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'WP_MCP_AI_VERSION' ), 'WP_MCP_AI_VERSION constant should be defined' );
		$this->assertTrue( defined( 'WP_MCP_AI_PATH' ), 'WP_MCP_AI_PATH constant should be defined' );
		$this->assertTrue( defined( 'WP_MCP_AI_URL' ), 'WP_MCP_AI_URL constant should be defined' );

		$this->assertEquals( '1.0.0', WP_MCP_AI_VERSION, 'Plugin version should be 1.0.0' );
		$this->assertStringContainsString( 'wp-mcp-ai', WP_MCP_AI_PATH, 'Plugin path should contain plugin directory' );
	}

	/**
	 * Test 2: Base version detection function works correctly.
	 */
	public function test_base_version_detection() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_is_base_version' ), 'wp_mcp_ai_is_base_version function should exist' );
		$this->assertTrue( wp_mcp_ai_is_base_version(), 'Base version should be active for these tests' );
	}

	/**
	 * Test 3: Assistant CPT is registered.
	 */
	public function test_assistant_cpt_registered() {
		$post_type = 'mcp_ai_assistant';

		$this->assertTrue( post_type_exists( $post_type ), 'Assistant post type should be registered' );

		$post_type_object = get_post_type_object( $post_type );
		$this->assertNotNull( $post_type_object, 'Assistant post type object should exist' );
		$this->assertEquals( 'AI Assistants', $post_type_object->labels->name, 'Assistant post type should have correct label' );
	}

	/**
	 * Test 4: Tool registry is initialized and functional.
	 */
	public function test_tool_registry_initialized() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Registry' ), 'Tool registry class should exist' );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Registry', $registry, 'Tool registry should be instantiated' );

		$tools = $registry->get_tools();
		$this->assertIsArray( $tools, 'get_tools() should return an array' );
		$this->assertNotEmpty( $tools, 'Base version should have at least some tools registered' );
	}

	/**
	 * Test 5: Base tools are registered.
	 */
	public function test_base_tools_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();

		// Convert tools to slugs for easier checking.
		$tool_slugs = array();
		foreach ( $tools as $tool ) {
			$tool_slugs[] = $tool->get_slug();
		}

		// Core base tools that should always be available.
		$required_base_tools = array(
			'get_recent_posts',
			'search_content',
			'get_user_info',
			'get_site_summary',
		);

		foreach ( $required_base_tools as $required_slug ) {
			$this->assertContains(
				$required_slug,
				$tool_slugs,
				"Base tool '{$required_slug}' should be registered"
			);
		}

		// Verify we have a reasonable number of base tools (at least 20).
		$this->assertGreaterThanOrEqual(
			20,
			count( $tool_slugs ),
			'Base version should have at least 20 tools registered'
		);
	}

	/**
	 * Test 6: REST endpoints are registered.
	 */
	public function test_rest_endpoints_registered() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_REST_Endpoints' ), 'REST endpoints class should exist' );

		// Trigger REST API initialization.
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$namespace = '/mcp-ai/v1';

		// Check for key REST endpoints.
		$expected_endpoints = array(
			$namespace . '/assistants',
			$namespace . '/chat',
			$namespace . '/tools',
		);

		foreach ( $expected_endpoints as $endpoint ) {
			$this->assertArrayHasKey(
				$endpoint,
				$routes,
				"REST endpoint '{$endpoint}' should be registered"
			);
		}
	}

	/**
	 * Test 7: Admin settings class is loaded.
	 */
	public function test_admin_settings_loaded() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Admin_Settings' ), 'Admin settings class should exist' );
	}

	/**
	 * Test 8: OpenAI client is available.
	 */
	public function test_openai_client_available() {
		$this->assertTrue( class_exists( 'OpenAI_Client' ), 'OpenAI client class should exist' );

		// Test that we can instantiate it.
		$client = new OpenAI_Client();
		$this->assertInstanceOf( 'OpenAI_Client', $client, 'OpenAI client should be instantiable' );
	}

	/**
	 * Test 9: Tool registry can retrieve a specific tool.
	 */
	public function test_tool_registry_get_tool() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Get a known base tool.
		$tool = $registry->get_tool( 'get_recent_posts' );
		$this->assertNotNull( $tool, 'Should be able to retrieve get_recent_posts tool' );
		$this->assertEquals( 'get_recent_posts', $tool->get_slug(), 'Retrieved tool should have correct slug' );
	}

	/**
	 * Test 10: Tool has required methods.
	 */
	public function test_tool_has_required_methods() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'get_recent_posts' );

		$this->assertNotNull( $tool, 'Tool should exist' );
		$this->assertTrue( method_exists( $tool, 'get_slug' ), 'Tool should have get_slug method' );
		$this->assertTrue( method_exists( $tool, 'get_definition' ), 'Tool should have get_definition method' );
		$this->assertTrue( method_exists( $tool, 'execute' ), 'Tool should have execute method' );
	}

	/**
	 * Test 11: Tool definition has required structure.
	 */
	public function test_tool_definition_structure() {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$tool       = $registry->get_tool( 'get_recent_posts' );
		$definition = $tool->get_definition();

		$this->assertIsArray( $definition, 'Tool definition should be an array' );
		$this->assertArrayHasKey( 'name', $definition, 'Tool definition should have name' );
		$this->assertArrayHasKey( 'description', $definition, 'Tool definition should have description' );
	}

	/**
	 * Test 12: Logger class is available.
	 */
	public function test_logger_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Logger' ), 'Logger class should exist' );
	}

	/**
	 * Test 13: Shortcode is registered.
	 */
	public function test_shortcode_registered() {
		global $shortcode_tags;

		$this->assertArrayHasKey( 'wp_mcp_ai_chat', $shortcode_tags, 'wp_mcp_ai_chat shortcode should be registered' );
	}

	/**
	 * Test 14: Rate limit manager is available.
	 */
	public function test_rate_limit_manager_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Rate_Limit_Manager' ), 'Rate limit manager class should exist' );
	}

	/**
	 * Test 15: Token budget manager is available.
	 */
	public function test_token_budget_manager_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Token_Budget_Manager' ), 'Token budget manager class should exist' );
	}

	/**
	 * Test 16: Assistant can be created.
	 */
	public function test_create_assistant() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_assistant',
				'post_title' => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $assistant_id, 'Assistant should be created successfully' );
		$this->assertEquals( 'mcp_ai_assistant', get_post_type( $assistant_id ), 'Created post should have correct post type' );
	}

	/**
	 * Test 17: Capabilities check function exists.
	 */
	public function test_chat_capability_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_get_required_chat_capability' ),
			'wp_mcp_ai_get_required_chat_capability function should exist'
		);
	}

	/**
	 * Test 18: Chat capability returns expected default.
	 */
	public function test_chat_capability_default() {
		$capability = wp_mcp_ai_get_required_chat_capability();
		$this->assertEquals( 'edit_posts', $capability, 'Default chat capability should be edit_posts' );
	}

	/**
	 * Test 19: Chat capability can be filtered.
	 */
	public function test_chat_capability_filtered() {
		$callback = function( $capability ) {
			return 'read';
		};

		add_filter( 'wp_mcp_ai_chat_capability', $callback );
		$capability = wp_mcp_ai_get_required_chat_capability();
		remove_filter( 'wp_mcp_ai_chat_capability', $callback );

		$this->assertEquals( 'read', $capability, 'Chat capability should be filterable' );
	}

	/**
	 * Test 20: Usage tracker is available.
	 */
	public function test_usage_tracker_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Usage_Tracker' ), 'Usage tracker class should exist' );
	}

	/**
	 * Test 21: Credentials class is available.
	 */
	public function test_credentials_class_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Credentials' ), 'Credentials class should exist' );
	}

	/**
	 * Test 22: Request context class is available.
	 */
	public function test_request_context_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Request_Context' ), 'Request context class should exist' );
	}

	/**
	 * Test 23: Base version excludes third-party plugin integration classes.
	 */
	public function test_base_version_excludes_integrations() {
		// In base version mode, these classes should not be loaded.
		$integration_classes = array(
			'WP_MCP_AI_JetEngine_Tool_Handlers',
			'WP_MCP_AI_JetFormBuilder_Tool_Handlers',
			'WP_MCP_AI_Elementor_Integration',
			'WP_MCP_AI_ChatKit_Integration',
		);

		foreach ( $integration_classes as $class_name ) {
			$this->assertFalse(
				class_exists( $class_name ),
				"Integration class '{$class_name}' should not be loaded in base version"
			);
		}
	}

	/**
	 * Test 24: Extended tools are not registered in base version.
	 */
	public function test_extended_tools_not_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();

		// Convert tools to slugs.
		$tool_slugs = array();
		foreach ( $tools as $tool ) {
			$tool_slugs[] = $tool->get_slug();
		}

		// These tools require third-party plugins.
		$extended_tools = array(
			'get_jetengine_items',
			'create_jetengine_item',
			'update_jetengine_item',
			'delete_jetengine_item',
			'get_woo_products',
			'create_woo_product',
			'update_woo_product',
		);

		foreach ( $extended_tools as $extended_slug ) {
			$this->assertNotContains(
				$extended_slug,
				$tool_slugs,
				"Extended tool '{$extended_slug}' should not be registered in base version"
			);
		}
	}

	/**
	 * Test 25: Error handler is available.
	 */
	public function test_error_handler_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Error_Handler' ), 'Error handler class should exist' );
	}

	/**
	 * Test 26: Cron manager is available.
	 */
	public function test_cron_manager_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Cron_Manager' ), 'Cron manager class should exist' );
	}

	/**
	 * Test 27: Nefarious usage monitor is available.
	 */
	public function test_nefarious_usage_monitor_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Nefarious_Usage_Monitor' ), 'Nefarious usage monitor class should exist' );
	}

	/**
	 * Test 28: Root security key class is available.
	 */
	public function test_root_security_key_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Root_Security_Key' ), 'Root security key class should exist' );
	}

	/**
	 * Test 29: Gemini client is available.
	 */
	public function test_gemini_client_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Gemini_Client' ), 'Gemini client class should exist' );
	}

	/**
	 * Test 30: Ollama client is available.
	 */
	public function test_ollama_client_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Ollama_Client' ), 'Ollama client class should exist' );
	}

	/**
	 * Test 31: Language model router is available.
	 */
	public function test_language_model_router_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Language_Model_Router' ), 'Language model router class should exist' );
	}

	/**
	 * Test 32: Message attachments handler is available.
	 */
	public function test_message_attachments_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Message_Attachments' ), 'Message attachments class should exist' );
	}

	/**
	 * Test 33: Response attachments handler is available.
	 */
	public function test_response_attachments_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Response_Attachments' ), 'Response attachments class should exist' );
	}

	/**
	 * Test 34: Tool token limits manager is available.
	 */
	public function test_tool_token_limits_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ), 'Tool token limits class should exist' );
	}

	/**
	 * Test 35: Chat transcript recorder is available.
	 */
	public function test_chat_transcript_recorder_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ), 'Chat transcript recorder class should exist' );
	}

	/**
	 * Test 36: All base tool classes are autoloaded.
	 */
	public function test_base_tool_classes_autoloaded() {
		$base_tool_classes = array(
			'WP_MCP_AI_Tool_Get_Recent_Posts',
			'WP_MCP_AI_Tool_Search_Content',
			'WP_MCP_AI_Tool_Get_User_Info',
			'WP_MCP_AI_Tool_Get_Site_Summary',
		);

		foreach ( $base_tool_classes as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				"Base tool class '{$class_name}' should be autoloaded"
			);
		}
	}

	/**
	 * Test 37: Federation system is available.
	 */
	public function test_federation_system_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Federation' ), 'Federation class should exist' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Federation_Settings' ), 'Federation settings class should exist' );
	}

	/**
	 * Test 38: Circuit breaker is available.
	 */
	public function test_circuit_breaker_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Circuit_Breaker' ), 'Circuit breaker class should exist' );
	}

	/**
	 * Test 39: Metrics class is available.
	 */
	public function test_metrics_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Metrics' ), 'Metrics class should exist' );
	}

	/**
	 * Test 40: Model selector is available.
	 */
	public function test_model_selector_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Model_Selector' ), 'Model selector class should exist' );
	}
}
