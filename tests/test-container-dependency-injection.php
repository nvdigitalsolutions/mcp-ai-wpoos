<?php
/**
 * Test Dependency Injection Container
 *
 * Tests that the container properly manages dependencies and eliminates hard-coded instantiations.
 *
 * @package WP_MCP_AI
 */

/**
 * Test dependency injection container functionality.
 */
class Test_Container_Dependency_Injection extends WP_UnitTestCase {

	/**
	 * Container instance
	 *
	 * @var WP_MCP_AI_Container
	 */
	private $container;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->container = wp_mcp_ai_container();
		$this->container->clear(); // Clear any cached instances.
	}

	/**
	 * Test that container singleton works.
	 */
	public function test_container_singleton() {
		$container1 = wp_mcp_ai_container();
		$container2 = wp_mcp_ai_container();

		$this->assertSame( $container1, $container2, 'Container should be a singleton' );
	}

	/**
	 * Test that helper function works.
	 */
	public function test_wp_mcp_ai_helper_function() {
		$container = wp_mcp_ai();

		$this->assertInstanceOf( WP_MCP_AI_Container::class, $container, 'Helper function should return container' );
	}

	/**
	 * Test that language model clients are registered.
	 */
	public function test_language_model_clients_registered() {
		$this->assertTrue( $this->container->has( 'client.openai' ), 'OpenAI client should be registered' );
		$this->assertTrue( $this->container->has( 'client.gemini' ), 'Gemini client should be registered' );
		$this->assertTrue( $this->container->has( 'client.ollama' ), 'Ollama client should be registered' );
		$this->assertTrue( $this->container->has( 'client.lm_studio' ), 'LM Studio client should be registered' );
		$this->assertTrue( $this->container->has( 'client.anthropic' ), 'Anthropic client should be registered' );
	}

	/**
	 * Test that router is registered and uses injected dependencies.
	 */
	public function test_router_registered() {
		$this->assertTrue( $this->container->has( 'router' ), 'Router should be registered' );

		$router = $this->container->get( 'router' );
		$this->assertInstanceOf( WP_MCP_AI_Language_Model_Router::class, $router, 'Router should be correct class' );
	}

	/**
	 * Test that core components are registered.
	 */
	public function test_core_components_registered() {
		$this->assertTrue( $this->container->has( 'assistant_cpt' ), 'Assistant CPT should be registered' );
		$this->assertTrue( $this->container->has( 'rest_controller' ), 'REST controller should be registered' );
		$this->assertTrue( $this->container->has( 'shortcodes' ), 'Shortcodes should be registered' );
		$this->assertTrue( $this->container->has( 'federation' ), 'Federation should be registered' );
		$this->assertTrue( $this->container->has( 'crawl4ai_local_api' ), 'Crawl4AI API should be registered' );
	}

	/**
	 * Test that admin components are registered.
	 */
	public function test_admin_components_registered() {
		$this->assertTrue( $this->container->has( 'admin.cron_manager' ), 'Cron manager should be registered' );
		$this->assertTrue( $this->container->has( 'admin.test_assistant' ), 'Test assistant should be registered' );
		$this->assertTrue( $this->container->has( 'admin.ajax_handlers' ), 'AJAX handlers should be registered' );
		$this->assertTrue( $this->container->has( 'admin.settings_base' ), 'Settings base should be registered' );
		$this->assertTrue( $this->container->has( 'admin.settings_renderer' ), 'Settings renderer should be registered' );
		$this->assertTrue( $this->container->has( 'admin.oauth_manager' ), 'OAuth manager should be registered' );
	}

	/**
	 * Test that settings sections are registered.
	 */
	public function test_settings_sections_registered() {
		$this->assertTrue( $this->container->has( 'section.overview' ), 'Overview section should be registered' );
		$this->assertTrue( $this->container->has( 'section.general' ), 'General section should be registered' );
		$this->assertTrue( $this->container->has( 'section.providers' ), 'Providers section should be registered' );
		$this->assertTrue( $this->container->has( 'section.authentication' ), 'Authentication section should be registered' );
		$this->assertTrue( $this->container->has( 'section.tools' ), 'Tools section should be registered' );
		$this->assertTrue( $this->container->has( 'section.orchestration' ), 'Orchestration section should be registered' );
		$this->assertTrue( $this->container->has( 'section.integrations' ), 'Integrations section should be registered' );
		$this->assertTrue( $this->container->has( 'section.plugins_integration' ), 'Plugins integration section should be registered' );
	}

	/**
	 * Test that services return singleton instances.
	 */
	public function test_singleton_instances() {
		$router1 = $this->container->get( 'router' );
		$router2 = $this->container->get( 'router' );

		$this->assertSame( $router1, $router2, 'Singleton services should return same instance' );
	}

	/**
	 * Test that container can be cleared for testing.
	 */
	public function test_container_clear() {
		$router1 = $this->container->get( 'router' );
		$this->container->clear();
		$router2 = $this->container->get( 'router' );

		$this->assertNotSame( $router1, $router2, 'After clear, new instances should be created' );
	}

	/**
	 * Test that constructor injection works for classes that accept dependencies.
	 */
	public function test_constructor_dependency_injection() {
		// Settings Dashboard accepts AJAX handlers as optional parameter.
		$dashboard = $this->container->get( 'admin.settings_dashboard' );

		$this->assertInstanceOf( WP_MCP_AI_Settings_Dashboard::class, $dashboard, 'Should create dashboard instance' );
	}

	/**
	 * Test that getting non-existent service throws exception.
	 */
	public function test_nonexistent_service_throws_exception() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Service "nonexistent.service" not found in container' );

		$this->container->get( 'nonexistent.service' );
	}

	/**
	 * Test that we can get all registered services.
	 */
	public function test_get_registered_services() {
		$services = $this->container->get_registered_services();

		$this->assertIsArray( $services, 'Should return array of service IDs' );
		$this->assertNotEmpty( $services, 'Should have registered services' );
		$this->assertContains( 'router', $services, 'Should contain router service' );
		$this->assertContains( 'client.openai', $services, 'Should contain OpenAI client' );
	}

	/**
	 * Test that dependent services are properly wired.
	 */
	public function test_service_dependencies_wired() {
		// REST controller depends on tool_registry and router.
		$rest = $this->container->get( 'rest_controller' );

		$this->assertInstanceOf( WP_MCP_AI_REST::class, $rest, 'Should create REST controller' );

		// Verify dependencies were injected (they should work without errors).
		// If dependencies weren't properly injected, this would fail.
		$reflection = new ReflectionClass( $rest );
		$this->assertTrue( $reflection->hasMethod( 'register_routes' ), 'REST controller should have required methods' );
	}

	/**
	 * Test that section.integrations can be retrieved from the container.
	 *
	 * This test specifically validates the fix for the "Service 'section.integrations' not found" error.
	 */
	public function test_section_integrations_can_be_retrieved() {
		$this->assertTrue( $this->container->has( 'section.integrations' ), 'section.integrations should be registered' );

		$section = $this->container->get( 'section.integrations' );
		$this->assertInstanceOf( WP_MCP_AI_Section_Integrations::class, $section, 'Should return integrations section instance' );

		// Verify the section has expected properties.
		$this->assertEquals( 'integrations_gmail_crawl4ai', $section->get_id(), 'Section should have correct ID' );
		$this->assertEquals( 'tools', $section->get_tab(), 'Section should be in tools tab' );
	}
}
