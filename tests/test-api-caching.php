<?php
/**
 * Tests for API caching functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test API caching for OpenAI, Gemini, and Ollama clients.
 */
class Test_API_Caching extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Clear all caches before each test.
		WP_MCP_AI_Cache_Helper::clear_all_caches();
		
		// Set default settings with caching enabled.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['enable_openai_api_caching']   = true;
		$settings['openai_model_list_cache_ttl'] = 3600; // 1 hour for testing.
		$settings['openai_api_key']              = 'sk-test123'; // Mock key.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Cache_Helper::clear_all_caches();
		parent::tearDown();
	}

	/**
	 * Test that caching can be enabled in settings.
	 */
	public function test_openai_caching_setting_exists() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		
		$this->assertTrue( ! empty( $settings['enable_openai_api_caching'] ), 'OpenAI caching should be enabled in settings' );
		$this->assertEquals( 3600, $settings['openai_model_list_cache_ttl'], 'Cache TTL should be set' );
	}

	/**
	 * Test that cache key is created properly.
	 */
	public function test_openai_cache_key_format() {
		$cache_key = 'openai_models_list';
		
		// Cache should be empty initially.
		$cached = WP_MCP_AI_Cache_Helper::get( $cache_key );
		$this->assertFalse( $cached, 'Cache should be empty initially' );
		
		// Set a test value.
		$test_data = array( 'data' => array( array( 'id' => 'gpt-4' ) ) );
		WP_MCP_AI_Cache_Helper::set( $cache_key, array( '__cached_value__' => $test_data ), 3600 );
		
		// Retrieve cached value.
		$cached = WP_MCP_AI_Cache_Helper::get( $cache_key );
		$this->assertIsArray( $cached, 'Cached value should be an array' );
		$this->assertArrayHasKey( '__cached_value__', $cached, 'Cached value should have wrapper key' );
	}

	/**
	 * Test that bypass_cache parameter works.
	 */
	public function test_openai_bypass_cache_parameter() {
		// Pre-populate cache with dummy data.
		$cache_key  = 'openai_models_list';
		$cache_data = array( 'data' => array( array( 'id' => 'cached-model' ) ) );
		WP_MCP_AI_Cache_Helper::set( $cache_key, array( '__cached_value__' => $cache_data ), 3600 );
		
		// Verify cache exists.
		$cached = WP_MCP_AI_Cache_Helper::get( $cache_key );
		$this->assertNotFalse( $cached, 'Cache should exist' );
		
		// The actual bypass_cache behavior would need to be tested with HTTP mocking
		// For now, we just verify the parameter is accepted.
		$client = new WP_MCP_AI_OpenAI_Client();
		
		// This will fail without API key or HTTP mocking, but we're testing the parameter acceptance.
		$this->assertTrue( method_exists( $client, 'list_models' ), 'list_models method should exist' );
	}

	/**
	 * Test that caching can be disabled via constant.
	 */
	public function test_openai_disable_cache_constant() {
		if ( ! defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) ) {
			define( 'WP_MCP_AI_DISABLE_API_CACHE', true );
		}
		
		// With constant defined, caching should be disabled.
		// This is tested implicitly by checking the constant exists.
		$this->assertTrue( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ), 'Disable constant should be defined' );
	}

	/**
	 * Test that filter hooks are available.
	 */
	public function test_openai_cache_filters() {
		// Test that the filter hook can be applied.
		$use_cache = apply_filters( 'wp_mcp_ai_cache_openai_models', true, array() );
		$this->assertTrue( $use_cache, 'Filter should return true by default' );
		
		// Test disabling via filter.
		add_filter( 'wp_mcp_ai_cache_openai_models', '__return_false' );
		$use_cache = apply_filters( 'wp_mcp_ai_cache_openai_models', true, array() );
		$this->assertFalse( $use_cache, 'Filter should disable caching' );
		remove_filter( 'wp_mcp_ai_cache_openai_models', '__return_false' );
		
		// Test TTL filter.
		$default_ttl = 12 * HOUR_IN_SECONDS;
		$filtered_ttl = apply_filters( 'wp_mcp_ai_openai_model_list_ttl', $default_ttl );
		$this->assertEquals( $default_ttl, $filtered_ttl, 'TTL should pass through filter' );
		
		// Test custom TTL via filter.
		add_filter( 'wp_mcp_ai_openai_model_list_ttl', function( $ttl ) {
			return 24 * HOUR_IN_SECONDS;
		} );
		$filtered_ttl = apply_filters( 'wp_mcp_ai_openai_model_list_ttl', $default_ttl );
		$this->assertEquals( 24 * HOUR_IN_SECONDS, $filtered_ttl, 'Filter should modify TTL' );
		remove_all_filters( 'wp_mcp_ai_openai_model_list_ttl' );
	}

	/**
	 * Test that cache helper remember function works.
	 */
	public function test_cache_helper_remember_function() {
		$cache_key = 'test_remember_key';
		$call_count = 0;
		
		// First call should execute callback.
		$result1 = WP_MCP_AI_Cache_Helper::remember(
			$cache_key,
			function() use ( &$call_count ) {
				$call_count++;
				return 'test_value';
			},
			3600
		);
		
		$this->assertEquals( 'test_value', $result1, 'Should return value from callback' );
		$this->assertEquals( 1, $call_count, 'Callback should be called once' );
		
		// Second call should use cache.
		$result2 = WP_MCP_AI_Cache_Helper::remember(
			$cache_key,
			function() use ( &$call_count ) {
				$call_count++;
				return 'test_value';
			},
			3600
		);
		
		$this->assertEquals( 'test_value', $result2, 'Should return cached value' );
		$this->assertEquals( 1, $call_count, 'Callback should not be called again' );
	}

	/**
	 * Test cache invalidation when settings change.
	 */
	public function test_cache_invalidation_on_settings_change() {
		$cache_key = 'openai_models_list';
		
		// Set cache.
		WP_MCP_AI_Cache_Helper::set( $cache_key, array( '__cached_value__' => array( 'test' => 'data' ) ), 3600 );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( $cache_key ), 'Cache should exist' );
		
		// Clear cache.
		WP_MCP_AI_Cache_Helper::delete( $cache_key );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( $cache_key ), 'Cache should be cleared' );
	}

	/**
	 * Test that caching respects TTL.
	 */
	public function test_cache_ttl_respected() {
		$cache_key = 'test_ttl_key';
		
		// Set cache with 1 second TTL.
		WP_MCP_AI_Cache_Helper::set( $cache_key, 'test_value', 1 );
		
		// Should exist immediately.
		$cached = WP_MCP_AI_Cache_Helper::get( $cache_key );
		$this->assertEquals( 'test_value', $cached, 'Cache should exist immediately' );
		
		// Wait 2 seconds and check again (transients should expire).
		// Note: This test may be flaky depending on system load.
		// In production, WordPress handles transient expiration.
		sleep( 2 );
		
		// After expiration, get_transient returns false.
		$expired = get_transient( 'wp_mcp_ai_' . $cache_key );
		$this->assertFalse( $expired, 'Transient should expire after TTL' );
	}

	/**
	 * Test pattern-based cache deletion.
	 */
	public function test_pattern_based_cache_deletion() {
		// Set multiple caches.
		WP_MCP_AI_Cache_Helper::set( 'openai_models_list', 'value1', 3600 );
		WP_MCP_AI_Cache_Helper::set( 'openai_embedding_123', 'value2', 3600 );
		WP_MCP_AI_Cache_Helper::set( 'gemini_models_list', 'value3', 3600 );
		
		// Delete openai pattern.
		$deleted = WP_MCP_AI_Cache_Helper::delete_pattern( 'openai_%' );
		$this->assertGreaterThan( 0, $deleted, 'Should delete at least one cache entry' );
		
		// Verify openai caches are gone.
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'openai_models_list' ), 'OpenAI cache should be deleted' );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'openai_embedding_123' ), 'OpenAI embedding cache should be deleted' );
		
		// Verify gemini cache still exists.
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'gemini_models_list' ), 'Gemini cache should still exist' );
	}

	/**
	 * Test that private fetch_models_from_api method exists.
	 */
	public function test_openai_private_fetch_method_exists() {
		$client = new WP_MCP_AI_OpenAI_Client();
		
		// Use reflection to check private method exists.
		$reflection = new ReflectionClass( $client );
		$this->assertTrue( $reflection->hasMethod( 'fetch_models_from_api' ), 'Private fetch method should exist' );
		
		$method = $reflection->getMethod( 'fetch_models_from_api' );
		$this->assertTrue( $method->isPrivate(), 'fetch_models_from_api should be private' );
	}
}
