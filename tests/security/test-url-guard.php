<?php
/**
 * Tests for WP_MCP_AI_Url_Guard — SSRF protection.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * URL Guard test suite.
 *
 * @group security
 * @group ssrf
 * @group url-guard
 */
class WP_MCP_AI_Url_Guard_Tests extends WP_UnitTestCase {

	/**
	 * Test that localhost IP (127.0.0.1) is blocked.
	 */
	public function test_blocks_localhost_ip() {
		$result = WP_MCP_AI_Url_Guard::validate( 'http://127.0.0.1/api' );
		$this->assertWPError( $result, '127.0.0.1 should be blocked.' );
	}

	/**
	 * Test that localhost hostname resolves and is blocked.
	 */
	public function test_blocks_localhost_hostname() {
		// localhost typically resolves to 127.0.0.1 or ::1.
		$result = WP_MCP_AI_Url_Guard::validate( 'http://localhost/wp-json/' );
		$this->assertWPError( $result, 'localhost should be blocked.' );
	}

	/**
	 * Test that private RFC 1918 addresses are blocked.
	 */
	public function test_blocks_private_ip_ranges() {
		$blocked = array(
			'http://10.0.0.1/',
			'http://172.16.0.1/',
			'http://192.168.1.1/',
		);

		foreach ( $blocked as $url ) {
			$result = WP_MCP_AI_Url_Guard::validate( $url );
			$this->assertWPError( $result, "{$url} should be blocked." );
		}
	}

	/**
	 * Test that link-local (169.254.x.x) is blocked (cloud metadata endpoint).
	 */
	public function test_blocks_link_local() {
		$result = WP_MCP_AI_Url_Guard::validate( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertWPError( $result, 'AWS metadata endpoint should be blocked.' );
	}

	/**
	 * Test that cloud metadata hostnames are blocked.
	 */
	public function test_blocks_cloud_metadata_hostnames() {
		$blocked = array(
			'http://metadata.google.internal/',
			'http://instance-data.ec2.internal/',
		);

		foreach ( $blocked as $url ) {
			$result = WP_MCP_AI_Url_Guard::validate( $url );
			$this->assertWPError( $result, "{$url} should be blocked." );
		}
	}

	/**
	 * Test that valid public URLs are allowed.
	 */
	public function test_allows_public_url() {
		$result = WP_MCP_AI_Url_Guard::validate( 'https://api.openai.com/v1/models' );
		$this->assertTrue( $result, 'Public URL should be allowed.' );
	}

	/**
	 * Test that invalid/malformed URLs are rejected.
	 */
	public function test_rejects_invalid_url() {
		$result = WP_MCP_AI_Url_Guard::validate( 'not-a-valid-url' );
		$this->assertWPError( $result, 'Invalid URL should be rejected.' );
	}

	/**
	 * Test that empty URL is rejected.
	 */
	public function test_rejects_empty_url() {
		$result = WP_MCP_AI_Url_Guard::validate( '' );
		$this->assertWPError( $result, 'Empty URL should be rejected.' );
	}

	/**
	 * Test that CIDR matching works correctly for edge cases.
	 */
	public function test_cidr_edge_cases() {
		// 10.255.255.255 should be in 10.0.0.0/8.
		$result = WP_MCP_AI_Url_Guard::validate( 'http://10.255.255.255/' );
		$this->assertWPError( $result, '10.255.255.255 should be in blocked range.' );

		// 172.31.255.255 should be in 172.16.0.0/12.
		$result = WP_MCP_AI_Url_Guard::validate( 'http://172.31.255.255/' );
		$this->assertWPError( $result, '172.31.255.255 should be in blocked range.' );

		// 172.32.0.1 is outside 172.16.0.0/12 — should be allowed (if DNS resolves).
		// Note: 172.32.0.1 won't resolve to a real host, so it'll fail DNS check.
	}
}
