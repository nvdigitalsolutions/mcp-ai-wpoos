<?php
/**
 * Tests for WP_MCP_AI_HTTP_Helper class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test suite for HTTP Helper class loopback and private network detection.
 *
 * @group http
 * @group http-helper
 */
class WP_MCP_AI_HTTP_Helper_Tests extends WP_UnitTestCase {

	/**
	 * Test loopback address detection for localhost names.
	 */
	public function test_is_loopback_address_localhost_names() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'localhost' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'localhost.localdomain' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'ip6-localhost' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'ip6-loopback' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'LOCALHOST' ) );
	}

	/**
	 * Test loopback address detection with ports.
	 */
	public function test_is_loopback_address_with_ports() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'localhost:11434' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.0.0.1:11434' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.0.0.1:1234' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.0.0.1:8000' ) );
	}

	/**
	 * Test loopback address detection for IPv4 127.0.0.0/8.
	 */
	public function test_is_loopback_address_ipv4_127() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.0.0.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.0.0.2' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.1.1.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '127.255.255.255' ) );
	}

	/**
	 * Test loopback address detection for IPv6 ::1.
	 */
	public function test_is_loopback_address_ipv6_loopback() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '::1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '0:0:0:0:0:0:0:1' ) );
	}

	/**
	 * Test private IPv4 address detection for 192.168.0.0/16.
	 */
	public function test_is_loopback_address_private_ipv4_192_168() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.0.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.1.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.2.222' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.255.254' ) );
	}

	/**
	 * Test private IPv4 address detection for 10.0.0.0/8.
	 */
	public function test_is_loopback_address_private_ipv4_10() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '10.0.0.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '10.1.1.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '10.255.255.254' ) );
	}

	/**
	 * Test private IPv4 address detection for 172.16.0.0/12.
	 */
	public function test_is_loopback_address_private_ipv4_172_16() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.16.0.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.16.1.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.20.1.1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.31.255.254' ) );
	}

	/**
	 * Test that 172.15.x.x and 172.32.x.x are not detected as private.
	 */
	public function test_is_loopback_address_172_boundary_cases() {
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.15.0.1' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.32.0.1' ) );
	}

	/**
	 * Test private IPv4 addresses with ports.
	 */
	public function test_is_loopback_address_private_ipv4_with_ports() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.2.222:11434' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '10.0.0.50:1234' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '172.16.0.10:8000' ) );
	}

	/**
	 * Test private IPv6 address detection for fc00::/7.
	 */
	public function test_is_loopback_address_private_ipv6_ula() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'fc00::1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'fd00::1' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'fd12:3456:789a:1::1' ) );
	}

	/**
	 * Test that public IPv4 addresses are not detected as loopback/private.
	 */
	public function test_is_loopback_address_public_ipv4() {
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '8.8.8.8' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '1.1.1.1' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '93.184.216.34' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.0.2.1' ) );
	}

	/**
	 * Test that public IPv6 addresses are not detected as loopback/private.
	 */
	public function test_is_loopback_address_public_ipv6() {
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '2001:4860:4860::8888' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '2606:4700:4700::1111' ) );
	}

	/**
	 * Test that invalid inputs return false.
	 */
	public function test_is_loopback_address_invalid_inputs() {
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'invalid.domain' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( 'example.com' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::is_loopback_address( '999.999.999.999' ) );
	}

	/**
	 * Test that the reported IP from the issue is properly detected.
	 */
	public function test_is_loopback_address_issue_192_168_2_222() {
		// This is the specific IP from the reported issue.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.2.222' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::is_loopback_address( '192.168.2.222:11434' ) );
	}

	/**
	 * Test that handle_loopback_requests modifies args for localhost.
	 */
	public function test_handle_loopback_requests_modifies_args_for_localhost() {
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);
		$url  = 'http://localhost:11434/api/tags';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests modifies args for private IPs.
	 */
	public function test_handle_loopback_requests_modifies_args_for_private_ips() {
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);

		// Test 192.168.x.x.
		$url      = 'http://192.168.2.222:11434/api/tags';
		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );
		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );

		// Test 10.x.x.x.
		$url      = 'http://10.0.0.50:1234/v1/chat/completions';
		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );
		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );

		// Test 172.16-31.x.x.
		$url      = 'http://172.16.0.10:8000/api';
		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );
		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests does not modify args for public IPs.
	 */
	public function test_handle_loopback_requests_does_not_modify_args_for_public_ips() {
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);
		$url  = 'https://api.openai.com/v1/chat/completions';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		$this->assertTrue( $modified['sslverify'] );
		$this->assertTrue( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests preserves other args.
	 */
	public function test_handle_loopback_requests_preserves_other_args() {
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'timeout'            => 120,
			'method'             => 'POST',
			'headers'            => array( 'Content-Type' => 'application/json' ),
		);
		$url  = 'http://localhost:11434/api/chat';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		$this->assertEquals( 120, $modified['timeout'] );
		$this->assertEquals( 'POST', $modified['method'] );
		$this->assertArrayHasKey( 'Content-Type', $modified['headers'] );
	}
}
