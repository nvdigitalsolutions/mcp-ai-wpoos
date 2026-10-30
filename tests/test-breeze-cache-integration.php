<?php
/**
 * Tests for the Breeze cache integration.
 *
 * @package NV_oOS
 */

/**
 * Class Test_Breeze_Cache_Integration
 */
class Test_Breeze_Cache_Integration extends WP_UnitTestCase {

	/**
	 * Test that the default session-bearing paths are appended.
	 */
	public function test_exclude_session_pages_appends_defaults() {
		$result = WP_MCP_AI_Breeze_Cache_Integration::exclude_session_pages( array( '/existing/' ) );

		$this->assertContains( '/existing/', $result );
		$this->assertContains( '/dashboard/', $result );
		$this->assertContains( '/wp-admin/', $result );
	}

	/**
	 * Test that already-excluded paths are not duplicated.
	 */
	public function test_exclude_session_pages_deduplicates() {
		$result = WP_MCP_AI_Breeze_Cache_Integration::exclude_session_pages( array( '/dashboard/' ) );
		$counts = array_count_values( $result );

		$this->assertSame( 1, $counts['/dashboard/'] );
	}

	/**
	 * Test that the exclusion list is filterable.
	 */
	public function test_exclude_session_pages_is_filterable() {
		add_filter(
			'wp_mcp_ai_breeze_cache_excluded_urls',
			static function () {
				return array( '/custom/' );
			}
		);

		$result = WP_MCP_AI_Breeze_Cache_Integration::exclude_session_pages( array() );

		remove_all_filters( 'wp_mcp_ai_breeze_cache_excluded_urls' );

		$this->assertSame( array( '/custom/' ), $result );
	}

	/**
	 * Test that init() installs no hook when Breeze is absent.
	 */
	public function test_init_noop_without_breeze() {
		$this->assertFalse(
			has_filter( 'breeze_exclude_urls', array( 'WP_MCP_AI_Breeze_Cache_Integration', 'exclude_session_pages' ) )
		);
	}
}
