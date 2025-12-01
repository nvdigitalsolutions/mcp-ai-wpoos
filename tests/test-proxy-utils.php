<?php
/**
 * Tests for WP_MCP_AI_Proxy_Utils class.
 *
 * @package WP_MCP_AI
 */

/**
 * @group proxy
 */
class WP_MCP_AI_Proxy_Utils_Tests extends WP_UnitTestCase {

	/**
	 * Backup of the $_SERVER superglobal.
	 *
	 * @var array
	 */
	protected $server_backup = array();

	public function setUp(): void {
		parent::setUp();
		$this->server_backup = $_SERVER;
	}

	public function tearDown(): void {
		$_SERVER = $this->server_backup;
		parent::tearDown();
	}

	/**
	 * Test building REST URL with basic namespace and route.
	 */
	public function test_build_rest_url_basic() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		$this->assertStringContainsString( 'wp-json/wp/v2/posts', $url );
	}

	/**
	 * Test building REST URL with only namespace.
	 */
	public function test_build_rest_url_namespace_only() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', '' );

		$this->assertStringContainsString( 'wp-json/wp/v2', $url );
		$this->assertStringNotContainsString( 'wp-json/wp/v2/', $url );
	}

	/**
	 * Test building REST URL with leading/trailing slashes.
	 */
	public function test_build_rest_url_normalizes_slashes() {
		$url1 = WP_MCP_AI_Proxy_Utils::build_rest_url( '/wp/v2/', '/posts/' );
		$url2 = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		$this->assertEquals( $url2, $url1 );
	}

	/**
	 * Test building REST URL with external host (should not be modified).
	 */
	public function test_build_rest_url_with_external_host() {
		// Set up WordPress to use an external host.
		add_filter(
			'rest_url',
			function ( $url ) {
				return str_replace( '://127.0.0.1', '://example.com', $url );
			}
		);

		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		$this->assertStringContainsString( 'example.com', $url );
		$this->assertStringNotContainsString( '127.0.0.1', $url );

		remove_all_filters( 'rest_url' );
	}

	/**
	 * Test building REST URL with internal host and no forwarded headers.
	 */
	public function test_build_rest_url_with_internal_host_no_forwarding() {
		// Clear any forwarding headers.
		unset( $_SERVER['HTTP_X_FORWARDED_HOST'] );
		unset( $_SERVER['HTTP_HOST'] );

		add_filter(
			'rest_url',
			function ( $url ) {
				return str_replace( parse_url( $url, PHP_URL_HOST ), '127.0.0.1', $url );
			}
		);

		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		// Should still contain loopback since no forwarding is configured.
		$this->assertStringContainsString( '127.0.0.1', $url );

		remove_all_filters( 'rest_url' );
	}

	/**
	 * Test URL building with empty namespace.
	 */
	public function test_build_rest_url_with_empty_namespace() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( '', 'custom-route' );

		$this->assertStringContainsString( 'wp-json/custom-route', $url );
	}

	/**
	 * Test URL building with complex route.
	 */
	public function test_build_rest_url_with_complex_route() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp-mcp-ai/v1', 'assistants/123/chat' );

		$this->assertStringContainsString( 'wp-json/wp-mcp-ai/v1/assistants/123/chat', $url );
	}

	/**
	 * Test URL building handles query parameters.
	 */
	public function test_build_rest_url_preserves_query_params() {
		add_filter(
			'rest_url',
			function ( $url ) {
				return add_query_arg( 'test', 'value', $url );
			}
		);

		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		$this->assertStringContainsString( 'test=value', $url );

		remove_all_filters( 'rest_url' );
	}

	/**
	 * Test URL building with special characters in namespace.
	 */
	public function test_build_rest_url_with_special_characters() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'custom-plugin/v1', 'items' );

		$this->assertStringContainsString( 'wp-json/custom-plugin/v1/items', $url );
	}

	/**
	 * Test URL path construction.
	 */
	public function test_build_rest_url_path_construction() {
		$url1 = WP_MCP_AI_Proxy_Utils::build_rest_url( 'ns', 'route' );
		$url2 = WP_MCP_AI_Proxy_Utils::build_rest_url( 'ns/', '/route' );
		$url3 = WP_MCP_AI_Proxy_Utils::build_rest_url( '/ns/', 'route/' );

		// All should normalize to the same path.
		$path1 = wp_parse_url( $url1, PHP_URL_PATH );
		$path2 = wp_parse_url( $url2, PHP_URL_PATH );
		$path3 = wp_parse_url( $url3, PHP_URL_PATH );

		$this->assertEquals( $path1, $path2 );
		$this->assertEquals( $path2, $path3 );
	}

	/**
	 * Test URL building with numeric namespace.
	 */
	public function test_build_rest_url_with_numeric_namespace() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( '123', 'route' );

		$this->assertStringContainsString( 'wp-json/123/route', $url );
	}

	/**
	 * Test URL building with deeply nested route.
	 */
	public function test_build_rest_url_with_nested_route() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url(
			'wp-mcp-ai/v1',
			'assistants/123/tools/456/execute'
		);

		$this->assertStringContainsString(
			'wp-json/wp-mcp-ai/v1/assistants/123/tools/456/execute',
			$url
		);
	}

	/**
	 * Test URL returns valid HTTP URL.
	 */
	public function test_build_rest_url_returns_valid_url() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		$this->assertNotFalse( filter_var( $url, FILTER_VALIDATE_URL ) );
		$this->assertStringStartsWith( 'http', $url );
	}

	/**
	 * Test URL building with empty route and namespace.
	 */
	public function test_build_rest_url_with_empty_both() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( '', '' );

		// Should still return a valid URL pointing to wp-json root.
		$this->assertStringContainsString( 'wp-json', $url );
	}

	/**
	 * Test URL building maintains scheme.
	 */
	public function test_build_rest_url_maintains_scheme() {
		$url = WP_MCP_AI_Proxy_Utils::build_rest_url( 'wp/v2', 'posts' );

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$this->assertContains( $scheme, array( 'http', 'https' ) );
	}
}
