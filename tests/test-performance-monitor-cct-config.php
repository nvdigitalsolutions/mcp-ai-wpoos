<?php
/**
 * Tests for JetEngine Performance Metrics Custom Content Type registration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for JetEngine Performance Metrics CCT.
 */
class WP_MCP_AI_Performance_Monitor_CCT_Test extends WP_UnitTestCase {

	/**
	 * Test that the CCT slug is returned correctly.
	 */
	public function test_get_slug_returns_correct_value() {
		$this->assertSame( 'plugin_performance_monitor', WP_MCP_AI_Performance_Monitor_CCT::get_slug() );
	}

	/**
	 * Test that CCT args are properly configured.
	 *
	 * This test verifies that REST API endpoints are enabled for the
	 * Performance Monitor CCT, which is critical for store_test_result() functionality.
	 */
	public function test_cct_args_configuration() {
		$reflection = new ReflectionMethod( WP_MCP_AI_Performance_Monitor_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'Performance Metrics' );

		$this->assertIsArray( $args );
		$this->assertArrayHasKey( 'slug', $args );
		$this->assertArrayHasKey( 'icon', $args );
		$this->assertArrayHasKey( 'capability', $args );
		$this->assertArrayHasKey( 'rest_get_enabled', $args );
		$this->assertArrayHasKey( 'rest_post_enabled', $args );
		$this->assertArrayHasKey( 'rest_put_enabled', $args );
		$this->assertArrayHasKey( 'rest_delete_enabled', $args );

		$this->assertSame( 'plugin_performance_monitor', $args['slug'] );
		$this->assertSame( 'dashicons-performance', $args['icon'] );
		$this->assertSame( 'manage_options', $args['capability'] );

		// These assertions are critical - REST endpoints must be enabled
		// for store_test_result() to work properly with update_item().
		$this->assertTrue( $args['rest_get_enabled'], 'REST GET must be enabled to retrieve metrics' );
		$this->assertTrue( $args['rest_post_enabled'], 'REST POST must be enabled to create metrics' );
		$this->assertTrue( $args['rest_put_enabled'], 'REST PUT must be enabled for update_item() calls' );
		$this->assertFalse( $args['rest_delete_enabled'], 'REST DELETE should remain disabled for safety' );
	}

	/**
	 * Test that REST access permissions are properly configured.
	 */
	public function test_rest_access_permissions() {
		$reflection = new ReflectionMethod( WP_MCP_AI_Performance_Monitor_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'Performance Metrics' );

		$this->assertArrayHasKey( 'rest_get_access', $args );
		$this->assertArrayHasKey( 'rest_post_access', $args );
		$this->assertArrayHasKey( 'rest_put_access', $args );

		// Performance metrics should be admin-only for all operations.
		$this->assertSame( 'manage_options', $args['rest_get_access'] );
		$this->assertSame( 'manage_options', $args['rest_post_access'] );
		$this->assertSame( 'manage_options', $args['rest_put_access'] );
	}

	/**
	 * Test that create_index is enabled for better query performance.
	 */
	public function test_create_index_is_enabled() {
		$reflection = new ReflectionMethod( WP_MCP_AI_Performance_Monitor_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'Performance Metrics' );

		$this->assertArrayHasKey( 'create_index', $args );
		$this->assertTrue( $args['create_index'], 'Database indexing should be enabled for performance' );
	}

	/**
	 * Test that store_test_result method exists and is callable.
	 */
	public function test_store_test_result_method_exists() {
		$this->assertTrue(
			method_exists( WP_MCP_AI_Performance_Monitor_CCT::class, 'store_test_result' ),
			'store_test_result method should exist'
		);

		$reflection = new ReflectionMethod( WP_MCP_AI_Performance_Monitor_CCT::class, 'store_test_result' );
		$this->assertTrue( $reflection->isPublic(), 'store_test_result should be public' );
		$this->assertTrue( $reflection->isStatic(), 'store_test_result should be static' );
	}
}
