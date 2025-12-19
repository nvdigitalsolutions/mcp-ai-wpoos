<?php
/**
 * Tests for Symfony Cache integration
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Cache_Service
 *
 * Tests for the Symfony Cache service integration.
 */
class Test_WP_MCP_AI_Cache_Service extends WP_UnitTestCase {

	/**
	 * Cache service instance.
	 *
	 * @var WP_MCP_AI\Cache\WP_MCP_AI_Cache_Service
	 */
	private $cache_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load cache service.
		require_once dirname( __DIR__ ) . '/includes/cache/class-wp-mcp-ai-cache-service.php';
		$this->cache_service = \WP_MCP_AI\Cache\WP_MCP_AI_Cache_Service::get_instance();
		$this->cache_service->clear();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->cache_service->clear();
		parent::tearDown();
	}

	/**
	 * Test that cache service is a singleton.
	 */
	public function test_cache_service_is_singleton() {
		$instance1 = \WP_MCP_AI\Cache\WP_MCP_AI_Cache_Service::get_instance();
		$instance2 = \WP_MCP_AI\Cache\WP_MCP_AI_Cache_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Cache service should be a singleton' );
	}

	/**
	 * Test basic set and get operations.
	 */
	public function test_set_and_get() {
		$key   = 'test_key';
		$value = 'test_value';

		$this->cache_service->set( $key, $value, 3600 );
		$cached = $this->cache_service->get( $key );

		$this->assertEquals( $value, $cached, 'Cached value should match set value' );
	}

	/**
	 * Test get with default value when key doesn't exist.
	 */
	public function test_get_with_default() {
		$key     = 'nonexistent_key';
		$default = 'default_value';

		$result = $this->cache_service->get( $key, $default );

		$this->assertEquals( $default, $result, 'Should return default value when key not found' );
	}

	/**
	 * Test delete operation.
	 */
	public function test_delete() {
		$key   = 'test_delete';
		$value = 'value_to_delete';

		$this->cache_service->set( $key, $value );
		$this->assertEquals( $value, $this->cache_service->get( $key ), 'Value should be cached' );

		$this->cache_service->delete( $key );
		$this->assertNull( $this->cache_service->get( $key ), 'Value should be deleted' );
	}

	/**
	 * Test get_or_set with callback.
	 */
	public function test_get_or_set() {
		$key            = 'test_callback';
		$expected_value = 'generated_value';
		$call_count     = 0;

		$callback = function () use ( $expected_value, &$call_count ) {
			++$call_count;
			return $expected_value;
		};

		// First call should execute callback.
		$result = $this->cache_service->get_or_set( $key, $callback );
		$this->assertEquals( $expected_value, $result, 'Should return generated value' );
		$this->assertEquals( 1, $call_count, 'Callback should be called once' );

		// Second call should use cached value.
		$result = $this->cache_service->get_or_set( $key, $callback );
		$this->assertEquals( $expected_value, $result, 'Should return cached value' );
		$this->assertEquals( 1, $call_count, 'Callback should not be called again' );
	}

	/**
	 * Test cache with tags.
	 */
	public function test_cache_with_tags() {
		$key1 = 'tagged_item_1';
		$key2 = 'tagged_item_2';
		$key3 = 'untagged_item';

		$this->cache_service->set( $key1, 'value1', 3600, array( 'tag1', 'tag2' ) );
		$this->cache_service->set( $key2, 'value2', 3600, array( 'tag1' ) );
		$this->cache_service->set( $key3, 'value3', 3600 );

		// Verify all items are cached.
		$this->assertEquals( 'value1', $this->cache_service->get( $key1 ) );
		$this->assertEquals( 'value2', $this->cache_service->get( $key2 ) );
		$this->assertEquals( 'value3', $this->cache_service->get( $key3 ) );

		// Invalidate by tag.
		$this->cache_service->invalidate_tags( array( 'tag1' ) );

		// Tagged items should be invalidated.
		$this->assertNull( $this->cache_service->get( $key1 ), 'Item with tag1 should be invalidated' );
		$this->assertNull( $this->cache_service->get( $key2 ), 'Item with tag1 should be invalidated' );
		$this->assertEquals( 'value3', $this->cache_service->get( $key3 ), 'Untagged item should remain' );
	}

	/**
	 * Test clear all cache.
	 */
	public function test_clear() {
		$this->cache_service->set( 'key1', 'value1' );
		$this->cache_service->set( 'key2', 'value2' );
		$this->cache_service->set( 'key3', 'value3' );

		$this->cache_service->clear();

		$this->assertNull( $this->cache_service->get( 'key1' ), 'All cache should be cleared' );
		$this->assertNull( $this->cache_service->get( 'key2' ), 'All cache should be cleared' );
		$this->assertNull( $this->cache_service->get( 'key3' ), 'All cache should be cleared' );
	}

	/**
	 * Test get_adapter_type returns valid adapter.
	 */
	public function test_get_adapter_type() {
		$adapter_type = $this->cache_service->get_adapter_type();

		$this->assertContains(
			$adapter_type,
			array( 'redis', 'apcu', 'filesystem' ),
			'Adapter type should be one of the supported types'
		);
	}

	/**
	 * Test cache with complex data types.
	 */
	public function test_cache_complex_data() {
		$key  = 'complex_data';
		$data = array(
			'array'  => array( 1, 2, 3 ),
			'object' => (object) array( 'foo' => 'bar' ),
			'nested' => array(
				'deep' => array(
					'value' => 'test',
				),
			),
		);

		$this->cache_service->set( $key, $data );
		$cached = $this->cache_service->get( $key );

		$this->assertEquals( $data, $cached, 'Complex data should be cached and retrieved correctly' );
	}

	/**
	 * Test stampede protection with get_or_set.
	 */
	public function test_stampede_protection() {
		$key        = 'stampede_test';
		$call_count = 0;

		$callback = function () use ( &$call_count ) {
			++$call_count;
			// Simulate expensive operation.
			usleep( 100000 ); // 100ms.
			return 'expensive_result';
		};

		// Simulate concurrent requests.
		$result1 = $this->cache_service->get_or_set( $key, $callback );
		$result2 = $this->cache_service->get_or_set( $key, $callback );

		$this->assertEquals( 'expensive_result', $result1 );
		$this->assertEquals( 'expensive_result', $result2 );
		$this->assertEquals( 1, $call_count, 'Callback should only be called once due to stampede protection' );
	}
}
