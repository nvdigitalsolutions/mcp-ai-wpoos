<?php
/**
 * Tests for the WP_MCP_AI_Cache_Helper class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test cache helper functionality.
 */
class WP_MCP_AI_Cache_Helper_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear all caches before each test.
		WP_MCP_AI_Cache_Helper::clear_all_caches();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Clear all caches after each test.
		WP_MCP_AI_Cache_Helper::clear_all_caches();
		parent::tearDown();
	}

	/**
	 * Test basic cache get/set functionality.
	 */
	public function test_cache_get_set() {
		$key   = 'test_key';
		$value = 'test_value';

		// Cache should be empty initially.
		$cached = WP_MCP_AI_Cache_Helper::get( $key );
		$this->assertFalse( $cached, 'Cache should be empty initially' );

		// Set cache.
		$result = WP_MCP_AI_Cache_Helper::set( $key, $value, HOUR_IN_SECONDS );
		$this->assertTrue( $result, 'Cache set should succeed' );

		// Retrieve cached value.
		$cached = WP_MCP_AI_Cache_Helper::get( $key );
		$this->assertSame( $value, $cached, 'Cached value should match' );
	}

	/**
	 * Test cache deletion.
	 */
	public function test_cache_delete() {
		$key   = 'test_key';
		$value = 'test_value';

		// Set cache.
		WP_MCP_AI_Cache_Helper::set( $key, $value );

		// Verify it's cached.
		$this->assertSame( $value, WP_MCP_AI_Cache_Helper::get( $key ) );

		// Delete cache.
		$result = WP_MCP_AI_Cache_Helper::delete( $key );
		$this->assertTrue( $result, 'Cache delete should succeed' );

		// Verify it's deleted.
		$cached = WP_MCP_AI_Cache_Helper::get( $key );
		$this->assertFalse( $cached, 'Cache should be deleted' );
	}

	/**
	 * Test pattern-based cache deletion.
	 */
	public function test_cache_delete_pattern() {
		// Set multiple related caches.
		WP_MCP_AI_Cache_Helper::set( 'assistant_1', 'value1' );
		WP_MCP_AI_Cache_Helper::set( 'assistant_2', 'value2' );
		WP_MCP_AI_Cache_Helper::set( 'other_cache', 'value3' );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'assistant_1' ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'assistant_2' ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'other_cache' ) );

		// Delete pattern.
		$deleted = WP_MCP_AI_Cache_Helper::delete_pattern( 'assistant_%' );
		$this->assertGreaterThanOrEqual( 2, $deleted, 'Should delete at least 2 caches' );

		// Verify pattern caches are deleted.
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'assistant_1' ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'assistant_2' ) );

		// Verify other cache still exists.
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'other_cache' ) );
	}

	/**
	 * Test caching is enabled by default.
	 */
	public function test_caching_enabled_by_default() {
		$enabled = WP_MCP_AI_Cache_Helper::is_caching_enabled();
		$this->assertTrue( $enabled, 'Caching should be enabled by default' );
	}

	/**
	 * Test assistant list caching with callback.
	 */
	public function test_get_assistants_list_caching() {
		$args = array(
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'publish',
		);

		$test_data = array(
			array(
				'id'    => 1,
				'title' => 'Assistant 1',
			),
			array(
				'id'    => 2,
				'title' => 'Assistant 2',
			),
		);

		$call_count = 0;
		$callback   = function () use ( $test_data, &$call_count ) {
			++$call_count;
			return $test_data;
		};

		// First call - should execute callback.
		$result = WP_MCP_AI_Cache_Helper::get_assistants_list( $args, $callback );
		$this->assertSame( $test_data, $result, 'First call should return data' );
		$this->assertSame( 1, $call_count, 'Callback should be called once' );

		// Second call - should use cache.
		$result = WP_MCP_AI_Cache_Helper::get_assistants_list( $args, $callback );
		$this->assertSame( $test_data, $result, 'Second call should return cached data' );
		$this->assertSame( 1, $call_count, 'Callback should not be called again' );
	}

	/**
	 * Test assistant config caching with callback.
	 */
	public function test_get_assistant_config_caching() {
		$assistant_id = 123;
		$config       = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		$call_count = 0;
		$callback   = function ( $id ) use ( $config, &$call_count ) {
			++$call_count;
			return $config;
		};

		// First call - should execute callback.
		$result = WP_MCP_AI_Cache_Helper::get_assistant_config( $assistant_id, $callback );
		$this->assertSame( $config, $result, 'First call should return config' );
		$this->assertSame( 1, $call_count, 'Callback should be called once' );

		// Second call - should use cache.
		$result = WP_MCP_AI_Cache_Helper::get_assistant_config( $assistant_id, $callback );
		$this->assertSame( $config, $result, 'Second call should return cached config' );
		$this->assertSame( 1, $call_count, 'Callback should not be called again' );
	}

	/**
	 * Test Elementor options caching with callback.
	 */
	public function test_get_elementor_options_caching() {
		$options = array(
			''  => 'Default Assistant',
			'1' => 'Assistant 1',
			'2' => 'Assistant 2',
		);

		$call_count = 0;
		$callback   = function () use ( $options, &$call_count ) {
			++$call_count;
			return $options;
		};

		// First call - should execute callback.
		$result = WP_MCP_AI_Cache_Helper::get_elementor_options( $callback );
		$this->assertSame( $options, $result, 'First call should return options' );
		$this->assertSame( 1, $call_count, 'Callback should be called once' );

		// Second call - should use cache.
		$result = WP_MCP_AI_Cache_Helper::get_elementor_options( $callback );
		$this->assertSame( $options, $result, 'Second call should return cached options' );
		$this->assertSame( 1, $call_count, 'Callback should not be called again' );
	}

	/**
	 * Test assistant cache invalidation.
	 */
	public function test_invalidate_assistant_cache() {
		$assistant_id = 123;

		// Set various caches for this assistant.
		WP_MCP_AI_Cache_Helper::set( "assistant_config_{$assistant_id}", array( 'test' => 'config' ) );
		WP_MCP_AI_Cache_Helper::set( "assistant_meta_{$assistant_id}", array( 'test' => 'meta' ) );
		WP_MCP_AI_Cache_Helper::set( 'assistants_list', array( 'test' => 'list' ) );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( "assistant_config_{$assistant_id}" ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( "assistant_meta_{$assistant_id}" ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'assistants_list' ) );

		// Invalidate assistant cache.
		WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $assistant_id );

		// Verify assistant-specific caches are cleared.
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( "assistant_config_{$assistant_id}" ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( "assistant_meta_{$assistant_id}" ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'assistants_list' ) );
	}

	/**
	 * Test invalidate all assistant caches.
	 */
	public function test_invalidate_assistant_caches() {
		// Set various caches.
		WP_MCP_AI_Cache_Helper::set( 'assistants_list', array( 'test' => 'list' ) );
		WP_MCP_AI_Cache_Helper::set( 'assistants_list_ids', array( 1, 2, 3 ) );
		WP_MCP_AI_Cache_Helper::set( 'elementor_assistant_options', array( 'test' => 'options' ) );
		WP_MCP_AI_Cache_Helper::set( 'assistant_config_1', array( 'test' => 'config1' ) );
		WP_MCP_AI_Cache_Helper::set( 'assistant_config_2', array( 'test' => 'config2' ) );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'assistants_list' ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'elementor_assistant_options' ) );

		// Invalidate all assistant caches.
		WP_MCP_AI_Cache_Helper::invalidate_assistant_caches();

		// Verify all caches are cleared.
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'assistants_list' ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'assistants_list_ids' ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'elementor_assistant_options' ) );
	}

	/**
	 * Test clear all caches.
	 */
	public function test_clear_all_caches() {
		// Set multiple caches.
		WP_MCP_AI_Cache_Helper::set( 'test1', 'value1' );
		WP_MCP_AI_Cache_Helper::set( 'test2', 'value2' );
		WP_MCP_AI_Cache_Helper::set( 'test3', 'value3' );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'test1' ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'test2' ) );
		$this->assertNotFalse( WP_MCP_AI_Cache_Helper::get( 'test3' ) );

		// Clear all caches.
		$deleted = WP_MCP_AI_Cache_Helper::clear_all_caches();
		$this->assertGreaterThanOrEqual( 3, $deleted, 'Should delete at least 3 caches' );

		// Verify all caches are cleared.
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'test1' ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'test2' ) );
		$this->assertFalse( WP_MCP_AI_Cache_Helper::get( 'test3' ) );
	}

	/**
	 * Test cache expiration.
	 */
	public function test_cache_expiration() {
		$key   = 'test_expiring';
		$value = 'test_value';

		// Set cache with 1 second expiration.
		WP_MCP_AI_Cache_Helper::set( $key, $value, 1 );

		// Should be cached immediately.
		$this->assertSame( $value, WP_MCP_AI_Cache_Helper::get( $key ) );

		// Wait for expiration.
		sleep( 2 );

		// Should be expired.
		$cached = WP_MCP_AI_Cache_Helper::get( $key );
		$this->assertFalse( $cached, 'Cache should be expired' );
	}
}
