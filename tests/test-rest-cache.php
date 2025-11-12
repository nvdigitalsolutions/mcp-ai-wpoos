<?php
/**
 * Tests for the WP_MCP_AI_REST_Cache class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test REST API cache functionality.
 */
class WP_MCP_AI_REST_Cache_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear all caches before each test.
		WP_MCP_AI_REST_Cache::clear_all_caches();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Clear all caches after each test.
		WP_MCP_AI_REST_Cache::clear_all_caches();
		parent::tearDown();
	}

	/**
	 * Test basic cache get/set for REST responses.
	 */
	public function test_rest_cache_get_set() {
		$endpoint = 'assistants';
		$params   = array( 'status' => 'publish' );
		$response = array(
			'assistants' => array( 1, 2, 3 ),
			'total'      => 3,
		);

		// Cache should be empty initially.
		$cached = WP_MCP_AI_REST_Cache::get_response( $endpoint, $params );
		$this->assertFalse( $cached, 'Cache should be empty initially' );

		// Set cache.
		$result = WP_MCP_AI_REST_Cache::set_response( $endpoint, $params, $response );
		$this->assertTrue( $result, 'Cache set should succeed' );

		// Retrieve cached response.
		$cached = WP_MCP_AI_REST_Cache::get_response( $endpoint, $params );
		$this->assertSame( $response, $cached, 'Cached response should match' );
	}

	/**
	 * Test cache key generation is consistent.
	 */
	public function test_cache_key_consistency() {
		$endpoint  = 'assistants';
		$params1   = array(
			'status'   => 'publish',
			'per_page' => 10,
		);
		$params2   = array(
			'per_page' => 10,
			'status'   => 'publish',
		); // Different order.
		$response1 = array( 'data' => 'test1' );
		$response2 = array( 'data' => 'test2' );

		// Set cache with params1.
		WP_MCP_AI_REST_Cache::set_response( $endpoint, $params1, $response1 );

		// Get cache with params2 (different order, same values).
		$cached = WP_MCP_AI_REST_Cache::get_response( $endpoint, $params2 );

		// Should retrieve the same cache because params are sorted.
		$this->assertSame( $response1, $cached, 'Cache key should be consistent regardless of param order' );
	}

	/**
	 * Test cache deletion.
	 */
	public function test_rest_cache_delete() {
		$endpoint = 'assistants';
		$params   = array( 'status' => 'publish' );
		$response = array( 'data' => 'test' );

		// Set cache.
		WP_MCP_AI_REST_Cache::set_response( $endpoint, $params, $response );

		// Verify it's cached.
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, $params ) );

		// Delete cache.
		$result = WP_MCP_AI_REST_Cache::delete_response( $endpoint, $params );
		$this->assertTrue( $result, 'Cache delete should succeed' );

		// Verify it's deleted.
		$cached = WP_MCP_AI_REST_Cache::get_response( $endpoint, $params );
		$this->assertFalse( $cached, 'Cache should be deleted' );
	}

	/**
	 * Test endpoint invalidation clears all related caches.
	 */
	public function test_invalidate_endpoint() {
		$endpoint = 'assistants';

		// Set multiple caches for the same endpoint with different params.
		WP_MCP_AI_REST_Cache::set_response( $endpoint, array( 'status' => 'publish' ), array( 'data' => '1' ) );
		WP_MCP_AI_REST_Cache::set_response( $endpoint, array( 'status' => 'draft' ), array( 'data' => '2' ) );
		WP_MCP_AI_REST_Cache::set_response( $endpoint, array(), array( 'data' => '3' ) );

		// Set cache for different endpoint.
		WP_MCP_AI_REST_Cache::set_response( 'tools', array(), array( 'data' => '4' ) );

		// Verify all caches are set.
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, array( 'status' => 'publish' ) ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, array( 'status' => 'draft' ) ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, array() ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'tools', array() ) );

		// Invalidate assistants endpoint.
		$deleted = WP_MCP_AI_REST_Cache::invalidate_endpoint( $endpoint );
		$this->assertGreaterThanOrEqual( 3, $deleted, 'Should delete at least 3 caches' );

		// Verify assistants caches are cleared.
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, array( 'status' => 'publish' ) ) );
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, array( 'status' => 'draft' ) ) );
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( $endpoint, array() ) );

		// Verify tools cache still exists.
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'tools', array() ) );
	}

	/**
	 * Test caching is enabled by default.
	 */
	public function test_caching_enabled_by_default() {
		$enabled = WP_MCP_AI_REST_Cache::is_caching_enabled();
		$this->assertTrue( $enabled, 'REST caching should be enabled by default' );
	}

	/**
	 * Test clear all REST caches.
	 */
	public function test_clear_all_caches() {
		// Set multiple caches for different endpoints.
		WP_MCP_AI_REST_Cache::set_response( 'assistants', array(), array( 'data' => '1' ) );
		WP_MCP_AI_REST_Cache::set_response( 'tools', array(), array( 'data' => '2' ) );
		WP_MCP_AI_REST_Cache::set_response( 'prompts', array(), array( 'data' => '3' ) );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'assistants', array() ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'tools', array() ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'prompts', array() ) );

		// Clear all caches.
		$deleted = WP_MCP_AI_REST_Cache::clear_all_caches();
		$this->assertGreaterThanOrEqual( 3, $deleted, 'Should delete at least 3 caches' );

		// Verify all caches are cleared.
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'assistants', array() ) );
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'tools', array() ) );
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'prompts', array() ) );
	}

	/**
	 * Test cache expiration for different endpoints.
	 */
	public function test_get_expiration() {
		// Test assistant list expiration.
		$expiration = WP_MCP_AI_REST_Cache::get_expiration( 'assistants_list' );
		$this->assertEquals( 30 * MINUTE_IN_SECONDS, $expiration, 'Assistant list should have 30 minute expiration' );

		// Test assistant config expiration.
		$expiration = WP_MCP_AI_REST_Cache::get_expiration( 'assistant_config' );
		$this->assertEquals( HOUR_IN_SECONDS, $expiration, 'Assistant config should have 1 hour expiration' );

		// Test default expiration.
		$expiration = WP_MCP_AI_REST_Cache::get_expiration( 'unknown_endpoint' );
		$this->assertEquals( 5 * MINUTE_IN_SECONDS, $expiration, 'Unknown endpoints should use default 5 minute expiration' );
	}

	/**
	 * Test cache headers are added correctly.
	 */
	public function test_add_cache_headers() {
		$data     = array( 'test' => 'data' );
		$response = new WP_REST_Response( $data );

		// Add cache headers.
		$max_age  = 300; // 5 minutes.
		$response = WP_MCP_AI_REST_Cache::add_cache_headers( $response, $max_age );

		// Verify headers are set.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers, 'Should have Cache-Control header' );
		$this->assertStringContainsString( 'max-age=' . $max_age, $headers['Cache-Control'], 'Cache-Control should contain max-age' );
		$this->assertArrayHasKey( 'Expires', $headers, 'Should have Expires header' );
	}

	/**
	 * Test invalidation on assistant save.
	 */
	public function test_invalidate_on_assistant_save() {
		// Create an assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Set caches.
		WP_MCP_AI_REST_Cache::set_response( 'assistants', array(), array( 'data' => 'list' ) );
		WP_MCP_AI_REST_Cache::set_response( 'assistant_' . $assistant_id, array(), array( 'data' => 'config' ) );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'assistants', array() ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'assistant_' . $assistant_id, array() ) );

		// Trigger save hook.
		WP_MCP_AI_REST_Cache::invalidate_on_assistant_save( $assistant_id );

		// Verify caches are invalidated.
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'assistants', array() ) );
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'assistant_' . $assistant_id, array() ) );
	}

	/**
	 * Test invalidation on assistant delete.
	 */
	public function test_invalidate_on_assistant_delete() {
		// Create an assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Set caches.
		WP_MCP_AI_REST_Cache::set_response( 'assistants', array(), array( 'data' => 'list' ) );
		WP_MCP_AI_REST_Cache::set_response( 'assistant_' . $assistant_id, array(), array( 'data' => 'config' ) );

		// Verify caches are set.
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'assistants', array() ) );
		$this->assertNotFalse( WP_MCP_AI_REST_Cache::get_response( 'assistant_' . $assistant_id, array() ) );

		// Trigger delete hook.
		WP_MCP_AI_REST_Cache::invalidate_on_assistant_delete( $assistant_id );

		// Verify caches are invalidated.
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'assistants', array() ) );
		$this->assertFalse( WP_MCP_AI_REST_Cache::get_response( 'assistant_' . $assistant_id, array() ) );
	}
}
