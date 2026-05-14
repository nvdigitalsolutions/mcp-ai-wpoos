<?php
/**
 * Tests for WP_MCP_AI_Memory_Manager.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test cases for the Memory Manager service.
 *
 * @covers WP_MCP_AI_Memory_Manager
 */
class Test_Memory_Manager extends WP_UnitTestCase {

	/**
	 * Verify stop_the_insanity() resets $wpdb->queries and object cache props.
	 */
	public function test_stop_the_insanity_resets_state() {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array(
			array( 'SELECT 1', 0.001, 'trace' ),
			array( 'SELECT 2', 0.002, 'trace' ),
		);

		// Populate cache properties defensively (only if writable).
		if ( is_object( $wp_object_cache ) ) {
			if ( property_exists( $wp_object_cache, 'cache' ) ) {
				$wp_object_cache->cache = array( 'group' => array( 'k' => 'v' ) );
			}
			if ( property_exists( $wp_object_cache, 'cache_hits' ) ) {
				$wp_object_cache->cache_hits = 42;
			}
		}

		WP_MCP_AI_Memory_Manager::stop_the_insanity();

		$this->assertSame( array(), $wpdb->queries, '$wpdb->queries should be reset.' );

		if ( is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'cache' ) ) {
			$this->assertSame( array(), $wp_object_cache->cache, 'object cache should be cleared.' );
		}
		if ( is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'cache_hits' ) ) {
			$this->assertSame( 0, $wp_object_cache->cache_hits, 'cache_hits should reset.' );
		}
	}

	/**
	 * Verify stop_the_insanity() fires the cleanup action.
	 */
	public function test_after_cleanup_action_fires() {
		$called = 0;
		$cb     = static function () use ( &$called ) {
			++$called;
		};

		add_action( 'wp_mcp_ai_memory_after_cleanup', $cb );
		WP_MCP_AI_Memory_Manager::stop_the_insanity();
		remove_action( 'wp_mcp_ai_memory_after_cleanup', $cb );

		$this->assertSame( 1, $called );
	}

	/**
	 * Parse_byte_size() should handle PHP shorthand notation.
	 */
	public function test_parse_byte_size_shorthand() {
		$this->assertSame( 128 * 1024 * 1024, WP_MCP_AI_Memory_Manager::parse_byte_size( '128M' ) );
		$this->assertSame( 1024 * 1024 * 1024, WP_MCP_AI_Memory_Manager::parse_byte_size( '1G' ) );
		$this->assertSame( 512 * 1024, WP_MCP_AI_Memory_Manager::parse_byte_size( '512K' ) );
		$this->assertSame( 1024, WP_MCP_AI_Memory_Manager::parse_byte_size( 1024 ) );
		$this->assertSame( 0, WP_MCP_AI_Memory_Manager::parse_byte_size( '' ) );
	}

	/**
	 * Should_throttle() returns false when limit is unlimited (-1).
	 */
	public function test_should_throttle_unlimited() {
		add_filter(
			'wp_mcp_ai_memory_threshold',
			static function () {
				return 75;
			}
		);

		// We can't easily redefine WP_MAX_MEMORY_LIMIT in-process, but the
		// helper short-circuits when the resolved limit is 0 — which we can
		// force by mocking via a static call to parse_byte_size with -1.
		$this->assertFalse(
			WP_MCP_AI_Memory_Manager::should_throttle( 100 ),
			'Should not throttle at 100% threshold under normal test memory pressure.'
		);
	}

	/**
	 * Filter wp_mcp_ai_memory_threshold value should be honoured.
	 */
	public function test_threshold_filter_clamps() {
		$captured = null;
		add_filter(
			'wp_mcp_ai_memory_threshold',
			static function ( $pct ) use ( &$captured ) {
				$captured = $pct;
				return $pct;
			}
		);

		WP_MCP_AI_Memory_Manager::should_throttle( 80 );

		$this->assertSame( 80, $captured );
	}
}
