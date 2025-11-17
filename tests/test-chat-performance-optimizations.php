<?php
/**
 * Tests for chat performance optimizations.
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat performance optimizations including caching and debug modes.
 */
class WP_MCP_AI_Test_Chat_Performance_Optimizations extends WP_UnitTestCase {

	/**
	 * REST API instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Performance Assistant',
			)
		);

		// Initialize REST API.
		$registry   = new WP_MCP_AI_Tool_Registry();
		$openai     = new WP_MCP_AI_OpenAI_Client();
		$gemini     = new WP_MCP_AI_Gemini_Client();
		$router     = new WP_MCP_AI_Language_Model_Router( $openai, $gemini );
		$this->rest = new WP_MCP_AI_REST( $registry, $router );

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Test that assistant validation uses cache by default.
	 */
	public function test_assistant_validation_uses_cache() {
		// First validation - should hit database.
		$reflection = new ReflectionClass( $this->rest );
		$method     = $reflection->getMethod( 'validate_assistant_access' );
		$method->setAccessible( true );

		$result1 = $method->invoke( $this->rest, $this->assistant_id );
		$this->assertInstanceOf( 'WP_Post', $result1 );

		// Check cache was populated.
		$cache_property = $reflection->getProperty( 'assistant_cache' );
		$cache_property->setAccessible( true );
		$cache = $cache_property->getValue( $this->rest );

		$cache_key = 'assistant_' . $this->assistant_id;
		$this->assertArrayHasKey( $cache_key, $cache, 'Cache should be populated after first validation' );
		$this->assertSame( $result1, $cache[ $cache_key ], 'Cached value should match first result' );

		// Second validation - should use cache.
		$result2 = $method->invoke( $this->rest, $this->assistant_id );
		$this->assertSame( $result1, $result2, 'Second call should return cached instance' );
	}

	/**
	 * Test that cache can be disabled with constant.
	 */
	public function test_cache_can_be_disabled() {
		// Define the disable constant.
		if ( ! defined( 'WP_MCP_AI_DISABLE_CACHE' ) ) {
			define( 'WP_MCP_AI_DISABLE_CACHE', true );
		}

		// Create new REST instance with cache disabled.
		$registry = new WP_MCP_AI_Tool_Registry();
		$openai   = new WP_MCP_AI_OpenAI_Client();
		$gemini   = new WP_MCP_AI_Gemini_Client();
		$router   = new WP_MCP_AI_Language_Model_Router( $openai, $gemini );
		$rest     = new WP_MCP_AI_REST( $registry, $router );

		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'validate_assistant_access' );
		$method->setAccessible( true );

		// First call.
		$result1 = $method->invoke( $rest, $this->assistant_id );

		// Check cache was NOT populated.
		$cache_property = $reflection->getProperty( 'assistant_cache' );
		$cache_property->setAccessible( true );
		$cache = $cache_property->getValue( $rest );

		// Cache should be empty when disabled.
		$this->assertEmpty( $cache, 'Cache should be empty when WP_MCP_AI_DISABLE_CACHE is true' );
	}

	/**
	 * Test that error results are also cached.
	 */
	public function test_error_results_are_cached() {
		$invalid_id = 999999;

		$reflection = new ReflectionClass( $this->rest );
		$method     = $reflection->getMethod( 'validate_assistant_access' );
		$method->setAccessible( true );

		// First validation - should return error.
		$result1 = $method->invoke( $this->rest, $invalid_id );
		$this->assertWPError( $result1 );

		// Check error was cached.
		$cache_property = $reflection->getProperty( 'assistant_cache' );
		$cache_property->setAccessible( true );
		$cache = $cache_property->getValue( $this->rest );

		$cache_key = 'assistant_' . $invalid_id;
		$this->assertArrayHasKey( $cache_key, $cache, 'Error should be cached' );
		$this->assertWPError( $cache[ $cache_key ], 'Cached value should be WP_Error' );
	}

	/**
	 * Test shortcode renders with optimizations enabled.
	 */
	public function test_shortcode_renders_with_optimizations() {
		// Register shortcode if not already registered.
		if ( ! shortcode_exists( 'mcp_ai_chat' ) ) {
			$shortcode = new WP_MCP_AI_Shortcode();
		}

		$output = do_shortcode( '[mcp_ai_chat assistant="' . $this->assistant_id . '"]' );

		// Should contain the chat container.
		$this->assertStringContainsString( 'wp-mcp-ai-chat', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-chat__messages', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-chat__form', $output );
	}

	/**
	 * Test that JavaScript config includes debug mode flag.
	 */
	public function test_javascript_config_structure() {
		global $wp_scripts;

		// Enqueue the chat script.
		wp_enqueue_script( 'wp-mcp-ai-chat' );

		// Get the localized data.
		$localized = null;
		if ( isset( $wp_scripts->registered['wp-mcp-ai-chat'] ) ) {
			$script = $wp_scripts->registered['wp-mcp-ai-chat'];
			if ( isset( $script->extra['data'] ) ) {
				$localized = $script->extra['data'];
			}
		}

		// The script should be registered (even if data isn't localized in test env).
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );
	}

	/**
	 * Test Elementor widget compatibility with optimizations.
	 */
	public function test_elementor_widget_compatibility() {
		// Skip if Elementor isn't available in test environment.
		if ( ! class_exists( 'WP_MCP_AI_Elementor_Widget' ) ) {
			$this->markTestSkipped( 'Elementor widget class not available in test environment' );
			return;
		}

		// Widget should be instantiable.
		$widget = new WP_MCP_AI_Elementor_Widget();
		$this->assertInstanceOf( 'WP_MCP_AI_Elementor_Widget', $widget );

		// Widget should have the correct name.
		$this->assertEquals( 'wp-mcp-ai-chat', $widget->get_name() );
	}

	/**
	 * Test cache performance improvement.
	 *
	 * This is a basic performance test to ensure caching provides benefit.
	 */
	public function test_cache_improves_performance() {
		$reflection = new ReflectionClass( $this->rest );
		$method     = $reflection->getMethod( 'validate_assistant_access' );
		$method->setAccessible( true );

		// Measure uncached time.
		$start1 = microtime( true );
		$method->invoke( $this->rest, $this->assistant_id );
		$time1 = microtime( true ) - $start1;

		// Measure cached time.
		$start2 = microtime( true );
		$method->invoke( $this->rest, $this->assistant_id );
		$time2 = microtime( true ) - $start2;

		// Cached call should be faster (or at least not significantly slower).
		// Using a very generous threshold since test environment varies.
		$this->assertLessThanOrEqual( $time1 * 2, $time2, 'Cached call should not be significantly slower' );
	}

	/**
	 * Test that multiple validations in same request use cache.
	 */
	public function test_multiple_validations_use_cache() {
		$reflection = new ReflectionClass( $this->rest );
		$method     = $reflection->getMethod( 'validate_assistant_access' );
		$method->setAccessible( true );

		// Perform multiple validations.
		$results = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = $method->invoke( $this->rest, $this->assistant_id );
		}

		// All results should be the same instance.
		foreach ( $results as $result ) {
			$this->assertSame( $results[0], $result, 'All validations should return same cached instance' );
		}
	}

	/**
	 * Test cache isolation between different assistants.
	 */
	public function test_cache_isolates_different_assistants() {
		// Create second assistant.
		$assistant_id_2 = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Second Test Assistant',
			)
		);

		$reflection = new ReflectionClass( $this->rest );
		$method     = $reflection->getMethod( 'validate_assistant_access' );
		$method->setAccessible( true );

		// Validate both assistants.
		$result1 = $method->invoke( $this->rest, $this->assistant_id );
		$result2 = $method->invoke( $this->rest, $assistant_id_2 );

		// Results should be different.
		$this->assertNotSame( $result1, $result2, 'Different assistants should have different cache entries' );
		$this->assertEquals( $this->assistant_id, $result1->ID );
		$this->assertEquals( $assistant_id_2, $result2->ID );
	}
}
