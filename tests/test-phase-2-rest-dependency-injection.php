<?php
/**
 * Test Phase 2: REST Dependency Injection
 *
 * Tests that REST controller uses dependency injection for authenticator, validator, and SSE handler.
 * Part of Phase 2 separation of concerns refactoring.
 *
 * @package WP_MCP_AI
 */

/**
 * Test REST controller dependency injection.
 */
class Test_Phase_2_REST_Dependency_Injection extends WP_UnitTestCase {

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
	}

	/**
	 * Test that REST components are registered in container.
	 */
	public function test_rest_components_registered() {
		$this->assertTrue( $this->container->has( 'rest.authenticator' ), 'REST authenticator should be registered' );
		$this->assertTrue( $this->container->has( 'rest.validator' ), 'REST validator should be registered' );
		$this->assertTrue( $this->container->has( 'rest.sse_handler' ), 'SSE handler should be registered' );
	}

	/**
	 * Test that REST authenticator can be retrieved from container.
	 */
	public function test_rest_authenticator_instantiation() {
		$authenticator = $this->container->get( 'rest.authenticator' );

		$this->assertInstanceOf( WP_MCP_AI_REST_Authenticator::class, $authenticator, 'Should create authenticator instance' );
		$this->assertTrue( method_exists( $authenticator, 'reset_auth_context' ), 'Authenticator should have required methods' );
	}

	/**
	 * Test that REST validator can be retrieved from container.
	 */
	public function test_rest_validator_instantiation() {
		$validator = $this->container->get( 'rest.validator' );

		$this->assertInstanceOf( WP_MCP_AI_REST_Validator::class, $validator, 'Should create validator instance' );
		$this->assertTrue( method_exists( $validator, 'validate_messages_array' ), 'Validator should have required methods' );
	}

	/**
	 * Test that SSE handler can be retrieved from container.
	 */
	public function test_sse_handler_instantiation() {
		$sse_handler = $this->container->get( 'rest.sse_handler' );

		$this->assertInstanceOf( WP_MCP_AI_SSE_Handler::class, $sse_handler, 'Should create SSE handler instance' );
		$this->assertTrue( method_exists( $sse_handler, 'send_sse_headers' ), 'SSE handler should have required methods' );
	}

	/**
	 * Test that REST components return singleton instances.
	 */
	public function test_rest_components_are_singletons() {
		$authenticator1 = $this->container->get( 'rest.authenticator' );
		$authenticator2 = $this->container->get( 'rest.authenticator' );

		$this->assertSame( $authenticator1, $authenticator2, 'REST authenticator should be singleton' );

		$validator1 = $this->container->get( 'rest.validator' );
		$validator2 = $this->container->get( 'rest.validator' );

		$this->assertSame( $validator1, $validator2, 'REST validator should be singleton' );

		$sse1 = $this->container->get( 'rest.sse_handler' );
		$sse2 = $this->container->get( 'rest.sse_handler' );

		$this->assertSame( $sse1, $sse2, 'SSE handler should be singleton' );
	}

	/**
	 * Test that REST controller receives injected dependencies.
	 */
	public function test_rest_controller_uses_dependency_injection() {
		$rest = $this->container->get( 'rest_controller' );

		$this->assertInstanceOf( WP_MCP_AI_REST::class, $rest, 'Should create REST controller' );

		// Use reflection to check that dependencies were injected.
		$reflection = new ReflectionClass( $rest );

		// Check that authenticator property exists.
		$auth_property = $reflection->getProperty( 'authenticator' );
		$auth_property->setAccessible( true );
		$authenticator = $auth_property->getValue( $rest );

		$this->assertInstanceOf( WP_MCP_AI_REST_Authenticator::class, $authenticator, 'Authenticator should be injected' );

		// Check that validator property exists.
		$validator_property = $reflection->getProperty( 'validator' );
		$validator_property->setAccessible( true );
		$validator = $validator_property->getValue( $rest );

		$this->assertInstanceOf( WP_MCP_AI_REST_Validator::class, $validator, 'Validator should be injected' );

		// Check that SSE handler property exists.
		$sse_property = $reflection->getProperty( 'sse_handler' );
		$sse_property->setAccessible( true );
		$sse_handler = $sse_property->getValue( $rest );

		$this->assertInstanceOf( WP_MCP_AI_SSE_Handler::class, $sse_handler, 'SSE handler should be injected' );
	}

	/**
	 * Test that REST controller can be instantiated without container (backward compatibility).
	 */
	public function test_rest_controller_backward_compatibility() {
		// Get required dependencies.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$router   = $this->container->get( 'router' );

		// Create REST controller without injecting optional dependencies.
		$rest = new WP_MCP_AI_REST( $registry, $router );

		$this->assertInstanceOf( WP_MCP_AI_REST::class, $rest, 'Should create REST controller without DI' );

		// Verify that dependencies were created internally.
		$reflection = new ReflectionClass( $rest );

		$auth_property = $reflection->getProperty( 'authenticator' );
		$auth_property->setAccessible( true );
		$authenticator = $auth_property->getValue( $rest );

		$this->assertInstanceOf( WP_MCP_AI_REST_Authenticator::class, $authenticator, 'Should create authenticator internally' );
	}

	/**
	 * Test that REST controller accepts mock dependencies for testing.
	 */
	public function test_rest_controller_accepts_mock_dependencies() {
		// Create mock dependencies.
		$registry      = WP_MCP_AI_Tool_Registry::get_instance();
		$router        = $this->container->get( 'router' );
		$authenticator = $this->createMock( WP_MCP_AI_REST_Authenticator::class );
		$validator     = $this->createMock( WP_MCP_AI_REST_Validator::class );
		$sse_handler   = $this->createMock( WP_MCP_AI_SSE_Handler::class );

		// Create REST controller with mock dependencies.
		$rest = new WP_MCP_AI_REST( $registry, $router, $authenticator, $validator, $sse_handler );

		$this->assertInstanceOf( WP_MCP_AI_REST::class, $rest, 'Should create REST controller with mocks' );

		// Verify that mocks were injected.
		$reflection = new ReflectionClass( $rest );

		$auth_property = $reflection->getProperty( 'authenticator' );
		$auth_property->setAccessible( true );
		$injected_auth = $auth_property->getValue( $rest );

		$this->assertSame( $authenticator, $injected_auth, 'Should use injected mock authenticator' );
	}

	/**
	 * Test that no hard-coded instantiations remain in REST controller constructor.
	 */
	public function test_no_hard_coded_instantiations_in_constructor() {
		// Get the source code of the REST controller constructor.
		$reflection  = new ReflectionClass( WP_MCP_AI_REST::class );
		$constructor = $reflection->getConstructor();

		$filename   = $constructor->getFileName();
		$start_line = $constructor->getStartLine();
		$end_line   = $constructor->getEndLine();

		$source           = file( $filename );
		$constructor_code = implode( '', array_slice( $source, $start_line, $end_line - $start_line ) );

		// Check that the constructor uses the null coalescing operator pattern.
		// This indicates dependency injection with fallback.
		$this->assertStringContainsString( '??', $constructor_code, 'Constructor should use null coalescing for DI' );

		// Verify the specific pattern for each dependency.
		$this->assertStringContainsString( '$authenticator ??', $constructor_code, 'Should use DI for authenticator' );
		$this->assertStringContainsString( '$validator ??', $constructor_code, 'Should use DI for validator' );
		$this->assertStringContainsString( '$sse_handler ??', $constructor_code, 'Should use DI for SSE handler' );
	}

	/**
	 * Test that REST components are properly documented.
	 */
	public function test_rest_components_documentation() {
		$reflection  = new ReflectionClass( WP_MCP_AI_REST::class );
		$constructor = $reflection->getConstructor();

		$doc_comment = $constructor->getDocComment();

		// Verify that new parameters are documented.
		$this->assertStringContainsString( '@param WP_MCP_AI_REST_Authenticator', $doc_comment, 'Authenticator param should be documented' );
		$this->assertStringContainsString( '@param WP_MCP_AI_REST_Validator', $doc_comment, 'Validator param should be documented' );
		$this->assertStringContainsString( '@param WP_MCP_AI_SSE_Handler', $doc_comment, 'SSE handler param should be documented' );
	}

	/**
	 * Test that OpenAI client is lazy-loaded from container.
	 */
	public function test_openai_client_lazy_loading() {
		// Get the REST controller from container.
		$rest = $this->container->get( 'rest_controller' );

		// Use reflection to access protected property.
		$reflection = new ReflectionClass( $rest );
		$property   = $reflection->getProperty( 'openai_client' );
		$property->setAccessible( true );

		// Initially null (not loaded yet).
		$this->assertNull( $property->getValue( $rest ), 'OpenAI client should be null initially' );

		// Call the getter method.
		$method = $reflection->getMethod( 'get_openai_client' );
		$method->setAccessible( true );
		$client = $method->invoke( $rest );

		// Now it should be loaded.
		$this->assertInstanceOf( WP_MCP_AI_OpenAI_Client::class, $client, 'Should return OpenAI client instance' );

		// Property should now be set.
		$this->assertInstanceOf( WP_MCP_AI_OpenAI_Client::class, $property->getValue( $rest ), 'Property should be set after lazy load' );

		// Calling again should return same instance.
		$client2 = $method->invoke( $rest );
		$this->assertSame( $client, $client2, 'Should return same instance on subsequent calls' );
	}
}
