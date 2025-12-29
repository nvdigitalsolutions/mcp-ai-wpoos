<?php
/**
 * Tests for Phase 2.2: Assistant Service and Cron Status Service Dependency Injection
 *
 * This test file validates:
 * 1. Assistant Service uses Settings Repository (no direct get_option calls)
 * 2. Cron Status Service is registered in the container
 * 3. REST Controller uses lazy-loading for Cron Status Service
 * 4. Backward compatibility is maintained
 *
 * @package WP_MCP_AI
 */

/**
 * Test Phase 2.2 implementation
 */
class Test_Phase_2_2_Assistant_Cron_Services extends WP_UnitTestCase {

	/**
	 * Test that Assistant Service is registered in the container
	 */
	public function test_assistant_service_registered() {
		$container = wp_mcp_ai_container();
		$this->assertTrue( $container->has( 'service.assistant' ) );
	}

	/**
	 * Test that Assistant Service can be instantiated from container
	 */
	public function test_assistant_service_instantiation() {
		$container = wp_mcp_ai_container();
		$service   = $container->get( 'service.assistant' );

		$this->assertInstanceOf( 'WP_MCP_AI_Assistant_Service', $service );
	}

	/**
	 * Test that Assistant Service receives Settings Repository
	 */
	public function test_assistant_service_has_settings_repository() {
		$container = wp_mcp_ai_container();
		$service   = $container->get( 'service.assistant' );

		// Use reflection to check if settings repository was injected.
		$reflection = new ReflectionClass( $service );
		$property   = $reflection->getProperty( 'settings_repository' );
		$property->setAccessible( true );
		$settings_repo = $property->getValue( $service );

		$this->assertInstanceOf( 'WP_MCP_AI_Settings_Repository', $settings_repo );
	}

	/**
	 * Test that Assistant Service uses Settings Repository for default assistant
	 */
	public function test_assistant_service_uses_settings_repository() {
		// Set up a test default assistant in options.
		update_option( 'wp_mcp_ai_default_assistant', '123' );

		$container = wp_mcp_ai_container();
		$service   = $container->get( 'service.assistant' );

		$default_id = $service->get_default_assistant_id();
		$this->assertEquals( 123, $default_id );

		// Clean up.
		delete_option( 'wp_mcp_ai_default_assistant' );
	}

	/**
	 * Test that Assistant Service can be created with mock Settings Repository
	 */
	public function test_assistant_service_accepts_mock_settings_repository() {
		// Create a mock Settings Repository.
		$mock_repo = $this->getMockBuilder( 'WP_MCP_AI_Settings_Repository' )
			->getMock();

		// Configure mock to return a specific value.
		$mock_repo->expects( $this->once() )
			->method( 'get' )
			->with( 'default_assistant' )
			->willReturn( '456' );

		// Create service with mock.
		$service = new WP_MCP_AI_Assistant_Service( $mock_repo );

		// Call method that uses settings repository.
		$default_id = $service->get_default_assistant_id();

		// Verify mock was called correctly.
		$this->assertEquals( 456, $default_id );
	}

	/**
	 * Test that Assistant Service maintains backward compatibility
	 */
	public function test_assistant_service_backward_compatibility() {
		// Service should work when instantiated without arguments.
		$service = new WP_MCP_AI_Assistant_Service();
		$this->assertInstanceOf( 'WP_MCP_AI_Assistant_Service', $service );

		// Should be able to call methods without errors.
		$default_id = $service->get_default_assistant_id();
		$this->assertNull( $default_id ); // No default set.
	}

	/**
	 * Test that Cron Status Service is registered in the container
	 */
	public function test_cron_status_service_registered() {
		$container = wp_mcp_ai_container();
		$this->assertTrue( $container->has( 'service.cron_status' ) );
	}

	/**
	 * Test that Cron Status Service can be instantiated from container
	 */
	public function test_cron_status_service_instantiation() {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		}

		$container = wp_mcp_ai_container();
		$service   = $container->get( 'service.cron_status' );

		$this->assertInstanceOf( 'WP_MCP_AI_Cron_Status_Service', $service );
	}

	/**
	 * Test that Cron Status Service is a singleton
	 */
	public function test_cron_status_service_is_singleton() {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		}

		$container = wp_mcp_ai_container();
		$service1  = $container->get( 'service.cron_status' );
		$service2  = $container->get( 'service.cron_status' );

		$this->assertSame( $service1, $service2, 'Cron Status Service should be a singleton' );
	}

	/**
	 * Test that REST Controller has lazy-loading method for Cron Status Service
	 */
	public function test_rest_controller_has_cron_service_getter() {
		$container  = wp_mcp_ai_container();
		$controller = $container->get( 'rest_controller' );

		$reflection = new ReflectionClass( $controller );
		$this->assertTrue( $reflection->hasMethod( 'get_cron_status_service' ) );

		$method = $reflection->getMethod( 'get_cron_status_service' );
		$this->assertTrue( $method->isProtected() );
	}

	/**
	 * Test that REST Controller lazy-loads Cron Status Service
	 */
	public function test_rest_controller_lazy_loads_cron_service() {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		}

		$container  = wp_mcp_ai_container();
		$controller = $container->get( 'rest_controller' );

		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_cron_status_service' );
		$method->setAccessible( true );

		// First call should create the service.
		$service1 = $method->invoke( $controller );
		$this->assertInstanceOf( 'WP_MCP_AI_Cron_Status_Service', $service1 );

		// Second call should return the same instance (cached).
		$service2 = $method->invoke( $controller );
		$this->assertSame( $service1, $service2 );
	}

	/**
	 * Test that REST Controller property exists for cron_status_service
	 */
	public function test_rest_controller_has_cron_service_property() {
		$container  = wp_mcp_ai_container();
		$controller = $container->get( 'rest_controller' );

		$reflection = new ReflectionClass( $controller );
		$this->assertTrue( $reflection->hasProperty( 'cron_status_service' ) );
	}

	/**
	 * Test that no hard-coded service instantiation exists in handle_cron_status_request
	 */
	public function test_no_hard_coded_cron_service_in_handler() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_REST' );
		$method     = $reflection->getMethod( 'handle_cron_status_request' );
		$filename   = $reflection->getFileName();

		// Get the method source code.
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();
		$length     = $end_line - $start_line + 1;
		$source     = file( $filename );
		$body       = implode( '', array_slice( $source, $start_line - 1, $length ) );

		// Check that the method doesn't contain "new WP_MCP_AI_Cron_Status_Service()".
		$this->assertStringNotContainsString(
			'new WP_MCP_AI_Cron_Status_Service()',
			$body,
			'handle_cron_status_request should not contain hard-coded service instantiation'
		);

		// Check that it uses the lazy-loading method.
		$this->assertStringContainsString(
			'get_cron_status_service()',
			$body,
			'handle_cron_status_request should use get_cron_status_service()'
		);
	}

	/**
	 * Test container registrations documentation
	 */
	public function test_phase_2_2_documentation() {
		$container = wp_mcp_ai_container();

		// Get all registered services.
		$services = $container->get_registered_services();

		// Verify Phase 2.2 services are registered
		$this->assertContains( 'service.assistant', $services );
		$this->assertContains( 'service.cron_status', $services );

		// Verify these services are accessible.
		$this->assertTrue( $container->has( 'service.assistant' ) );
		$this->assertTrue( $container->has( 'service.cron_status' ) );
	}

	/**
	 * Test that all Phase 2.2 changes maintain backward compatibility
	 */
	public function test_phase_2_2_backward_compatibility() {
		// Test 1: Assistant Service can still be instantiated manually
		$assistant_service = new WP_MCP_AI_Assistant_Service();
		$this->assertInstanceOf( 'WP_MCP_AI_Assistant_Service', $assistant_service );

		// Test 2: REST Controller still works (it's created by container in normal flow)
		$container  = wp_mcp_ai_container();
		$controller = $container->get( 'rest_controller' );
		$this->assertInstanceOf( 'WP_MCP_AI_REST', $controller );

		// Test 3: Services can still be retrieved from container
		$service = $container->get( 'service.assistant' );
		$this->assertInstanceOf( 'WP_MCP_AI_Assistant_Service', $service );
	}

	/**
	 * Test Assistant Service no longer calls get_option directly
	 */
	public function test_assistant_service_no_direct_get_option() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Assistant_Service' );
		$method     = $reflection->getMethod( 'get_default_assistant_id' );
		$filename   = $reflection->getFileName();

		// Get the method source code.
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();
		$length     = $end_line - $start_line + 1;
		$source     = file( $filename );
		$body       = implode( '', array_slice( $source, $start_line - 1, $length ) );

		// Check that the method doesn't contain "get_option(".
		$this->assertStringNotContainsString(
			'get_option(',
			$body,
			'get_default_assistant_id should not call get_option directly'
		);

		// Check that it uses the settings repository.
		$this->assertStringContainsString(
			'settings_repository',
			$body,
			'get_default_assistant_id should use settings_repository'
		);
	}
}
