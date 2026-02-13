<?php
/**
 * Test CDN Loader functionality
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test class for WP_MCP_AI_Pro_CDN_Loader
 */
class Test_WP_MCP_AI_Pro_CDN_Loader extends WP_UnitTestCase {

	/**
	 * Test that CDN loader class exists
	 */
	public function test_cdn_loader_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_CDN_Loader' ) );
	}

	/**
	 * Test library configuration retrieval
	 */
	public function test_get_library_config() {
		$config = WP_MCP_AI_Pro_CDN_Loader::get_library_config( 'katex' );
		
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'cdn_url', $config );
		$this->assertArrayHasKey( 'fallback_url', $config );
		$this->assertArrayHasKey( 'version', $config );
		$this->assertArrayHasKey( 'handle', $config );
	}

	/**
	 * Test invalid library returns null
	 */
	public function test_get_library_config_invalid() {
		$config = WP_MCP_AI_Pro_CDN_Loader::get_library_config( 'nonexistent' );
		$this->assertNull( $config );
	}

	/**
	 * Test library availability check
	 */
	public function test_is_available() {
		// KaTeX should be available (either CDN or local)
		$this->assertTrue( WP_MCP_AI_Pro_CDN_Loader::is_available( 'katex' ) );
		
		// Nonexistent library should not be available
		$this->assertFalse( WP_MCP_AI_Pro_CDN_Loader::is_available( 'nonexistent' ) );
	}

	/**
	 * Test library handles retrieval
	 */
	public function test_get_library_handles() {
		$handles = WP_MCP_AI_Pro_CDN_Loader::get_library_handles();
		
		$this->assertIsArray( $handles );
		$this->assertContains( 'katex', $handles );
		$this->assertContains( 'd3', $handles );
		$this->assertContains( 'mathjs', $handles );
	}

	/**
	 * Test CDN can be disabled via filter
	 */
	public function test_cdn_disabled_via_filter() {
		// Disable CDN via filter
		add_filter( 'wp_mcp_ai_pro_use_cdn', '__return_false' );
		
		// Re-register libraries to pick up filter
		do_action( 'wp_enqueue_scripts' );
		
		// Check if script is registered
		$this->assertTrue( wp_script_is( 'katex', 'registered' ) );
		
		// Clean up
		remove_filter( 'wp_mcp_ai_pro_use_cdn', '__return_false' );
	}

	/**
	 * Test that all CDN libraries have required configuration
	 */
	public function test_all_libraries_have_required_config() {
		$libraries = array( 'chart.js', 'katex', 'd3', 'axios', 'mathjs', 'prettier' );
		
		foreach ( $libraries as $library ) {
			$config = WP_MCP_AI_Pro_CDN_Loader::get_library_config( $library );
			
			// Required keys
			$this->assertArrayHasKey( 'cdn_url', $config, "Library {$library} missing cdn_url" );
			$this->assertArrayHasKey( 'fallback_url', $config, "Library {$library} missing fallback_url" );
			$this->assertArrayHasKey( 'version', $config, "Library {$library} missing version" );
			$this->assertArrayHasKey( 'handle', $config, "Library {$library} missing handle" );
			
			// CDN URL should be HTTPS
			$this->assertStringStartsWith( 'https://', $config['cdn_url'], "Library {$library} CDN URL should use HTTPS" );
		}
	}

	/**
	 * Test library enqueue
	 */
	public function test_enqueue_library() {
		// Enqueue a library
		$result = WP_MCP_AI_Pro_CDN_Loader::enqueue( 'katex' );
		
		$this->assertTrue( $result );
		$this->assertTrue( wp_script_is( 'katex', 'enqueued' ) );
		
		// Also check CSS if available
		$this->assertTrue( wp_style_is( 'katex-css', 'enqueued' ) );
	}

	/**
	 * Test enqueue invalid library returns false
	 */
	public function test_enqueue_invalid_library() {
		$result = WP_MCP_AI_Pro_CDN_Loader::enqueue( 'nonexistent' );
		$this->assertFalse( $result );
	}

	/**
	 * Test CDN URLs point to jsDelivr
	 */
	public function test_cdn_urls_use_jsdelivr() {
		$libraries = array( 'chart.js', 'katex', 'd3', 'axios', 'mathjs', 'prettier' );
		
		foreach ( $libraries as $library ) {
			$config = WP_MCP_AI_Pro_CDN_Loader::get_library_config( $library );
			$this->assertStringContainsString( 'jsdelivr.net', $config['cdn_url'], "Library {$library} should use jsDelivr" );
		}
	}
}
