<?php
/**
 * Tests for WP_MCP_AI_Request_Context utilities.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * @group request-context
 */
class WP_MCP_AI_Request_Context_Tests extends WP_UnitTestCase {
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

	public function test_normalise_rest_url_returns_original_for_non_loopback_host() {
		$url = 'https://example.com/wp-json/wp/v2';

		$this->assertSame( $url, WP_MCP_AI_Request_Context::normalise_rest_url( $url ) );
	}

	public function test_normalise_rest_url_without_request_host_returns_original() {
		$_SERVER = array();
		$url     = 'https://127.0.0.1/wp-json/wp/v2';

		$this->assertSame( $url, WP_MCP_AI_Request_Context::normalise_rest_url( $url ) );
	}

	public function test_normalise_rest_url_replaces_loopback_host_with_current_host() {
		$_SERVER['HTTP_HOST']              = 'bots.nvdigital.solutions';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$url        = 'https://127.0.0.1/wp-json/jet-form-builder/v1/forms';
		$normalised = WP_MCP_AI_Request_Context::normalise_rest_url( $url );
		$expected   = 'https://bots.nvdigital.solutions/wp-json/jet-form-builder/v1/forms';

		$this->assertSame( $expected, $normalised );
	}

	public function test_normalise_rest_url_uses_forwarded_host_and_port() {
		$_SERVER['HTTP_X_FORWARDED_HOST']  = 'chat.example.test:8443';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$url        = 'https://127.0.0.1/wp-json/wp-mcp-ai/v1/chat';
		$normalised = WP_MCP_AI_Request_Context::normalise_rest_url( $url );
		$expected   = 'https://chat.example.test:8443/wp-json/wp-mcp-ai/v1/chat';

		$this->assertSame( $expected, $normalised );
	}
}
