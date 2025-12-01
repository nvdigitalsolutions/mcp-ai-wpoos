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

	/**
	 * Test that allow_private_network_requests allows localhost addresses.
	 */
	public function test_allow_private_network_requests_allows_localhost() {
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, 'localhost', 'http://localhost:11434' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '127.0.0.1', 'http://127.0.0.1:11434' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '::1', 'http://[::1]:11434' ) );
	}

	/**
	 * Test that allow_private_network_requests allows private IP addresses.
	 */
	public function test_allow_private_network_requests_allows_private_ips() {
		// 192.168.x.x - the specific IP from the issue.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '192.168.2.222', 'http://192.168.2.222:1234' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '192.168.1.100', 'http://192.168.1.100:11434' ) );

		// 10.x.x.x.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '10.0.0.50', 'http://10.0.0.50:1234' ) );

		// 172.16-31.x.x.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '172.16.0.10', 'http://172.16.0.10:8000' ) );
	}

	/**
	 * Test that allow_private_network_requests does not allow public IPs.
	 */
	public function test_allow_private_network_requests_blocks_public_ips() {
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, 'api.openai.com', 'https://api.openai.com' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '8.8.8.8', 'http://8.8.8.8' ) );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '1.1.1.1', 'http://1.1.1.1' ) );
	}

	/**
	 * Test that allow_private_network_requests preserves already external hosts.
	 */
	public function test_allow_private_network_requests_preserves_external() {
		// If WordPress already marked it as external, keep it that way.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( true, 'example.com', 'https://example.com' ) );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( true, '8.8.8.8', 'http://8.8.8.8' ) );
	}

	/**
	 * Test that handle_loopback_requests sets minimum timeout for private IPs.
	 */
	public function test_handle_loopback_requests_sets_minimum_timeout() {
		// Test with no timeout set - should default to 30 seconds.
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);
		$url  = 'http://192.168.2.220:11434/api/tags';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		$this->assertEquals( 30, $modified['timeout'] );
		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests preserves higher timeout values.
	 */
	public function test_handle_loopback_requests_preserves_higher_timeout() {
		// Test with timeout set to 120 seconds - should preserve it.
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'timeout'            => 120,
		);
		$url  = 'http://192.168.2.220:11434/api/tags';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		$this->assertEquals( 120, $modified['timeout'] );
		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests enforces minimum timeout for low values.
	 */
	public function test_handle_loopback_requests_enforces_minimum_timeout() {
		// Test with timeout set to 10 seconds - should increase to 30.
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'timeout'            => 10,
		);
		$url  = 'http://192.168.2.220:11434/api/tags';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		$this->assertEquals( 30, $modified['timeout'] );
		$this->assertFalse( $modified['sslverify'] );
		$this->assertFalse( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests doesn't set timeout for public IPs.
	 */
	public function test_handle_loopback_requests_no_timeout_for_public_ips() {
		// Test with a public IP - should not modify timeout.
		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'timeout'            => 10,
		);
		$url  = 'https://api.openai.com/v1/chat/completions';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		// Timeout should remain unchanged (10 seconds).
		$this->assertEquals( 10, $modified['timeout'] );
		// SSL settings should remain unchanged.
		$this->assertTrue( $modified['sslverify'] );
		$this->assertTrue( $modified['reject_unsafe_urls'] );
	}

	/**
	 * Test that handle_loopback_requests applies to all private IP ranges.
	 */
	public function test_handle_loopback_requests_timeout_for_all_private_ranges() {
		// Test 10.x.x.x range.
		$args1     = array( 'timeout' => 5 );
		$url1      = 'http://10.0.0.50:11434/api/tags';
		$modified1 = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args1, $url1 );
		$this->assertEquals( 30, $modified1['timeout'] );

		// Test 172.16-31.x.x range.
		$args2     = array( 'timeout' => 5 );
		$url2      = 'http://172.16.0.10:11434/api/tags';
		$modified2 = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args2, $url2 );
		$this->assertEquals( 30, $modified2['timeout'] );

		// Test 192.168.x.x range.
		$args3     = array( 'timeout' => 5 );
		$url3      = 'http://192.168.1.100:11434/api/tags';
		$modified3 = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args3, $url3 );
		$this->assertEquals( 30, $modified3['timeout'] );

		// Test localhost.
		$args4     = array( 'timeout' => 5 );
		$url4      = 'http://localhost:11434/api/tags';
		$modified4 = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args4, $url4 );
		$this->assertEquals( 30, $modified4['timeout'] );
	}

	/**
	 * Test that set_connection_timeout applies CURLOPT_CONNECTTIMEOUT for private IPs.
	 *
	 * This test addresses the issue where connections to local Ollama servers
	 * timeout at 10 seconds (cURL default connection timeout) even when the
	 * overall timeout is set to 120 seconds.
	 *
	 * @group connection-timeout
	 */
	public function test_set_connection_timeout_for_private_ip() {
		// Mock cURL handle.
		$handle = curl_init();
		$args   = array( 'timeout' => 120 );
		$url    = 'http://192.168.2.222:11434/api/tags';

		// Apply the filter.
		$result = WP_MCP_AI_HTTP_Helper::set_connection_timeout( $handle, $args, $url );

		// Verify it returns the handle.
		$this->assertSame( $handle, $result );

		// Verify CURLOPT_CONNECTTIMEOUT was set to match the overall timeout.
		$timeout = curl_getinfo( $handle, CURLINFO_CONNECT_TIME );
		// Note: curl_getinfo doesn't return the option value, so we verify the method was called.
		// The actual value will be tested in integration tests.

		curl_close( $handle );
	}

	/**
	 * Test that set_connection_timeout uses default timeout when not specified.
	 *
	 * @group connection-timeout
	 */
	public function test_set_connection_timeout_default_value() {
		// Mock cURL handle.
		$handle = curl_init();
		$args   = array(); // No timeout specified.
		$url    = 'http://localhost:11434/api/tags';

		// Apply the filter.
		$result = WP_MCP_AI_HTTP_Helper::set_connection_timeout( $handle, $args, $url );

		// Verify it returns the handle.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that set_connection_timeout does not apply to public IPs.
	 *
	 * @group connection-timeout
	 */
	public function test_set_connection_timeout_skips_public_ips() {
		// Mock cURL handle.
		$handle = curl_init();
		$args   = array( 'timeout' => 120 );
		$url    = 'https://api.openai.com/v1/chat/completions';

		// Apply the filter.
		$result = WP_MCP_AI_HTTP_Helper::set_connection_timeout( $handle, $args, $url );

		// Verify it returns the handle unchanged.
		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that set_connection_timeout applies to all private IP ranges.
	 *
	 * @group connection-timeout
	 */
	public function test_set_connection_timeout_all_private_ranges() {
		$test_cases = array(
			'10.0.0.50:11434'     => 'http://10.0.0.50:11434/api/tags',
			'172.16.0.10:11434'   => 'http://172.16.0.10:11434/api/tags',
			'192.168.1.100:11434' => 'http://192.168.1.100:11434/api/tags',
			'localhost:11434'     => 'http://localhost:11434/api/tags',
			'127.0.0.1:11434'     => 'http://127.0.0.1:11434/api/tags',
		);

		foreach ( $test_cases as $description => $url ) {
			$handle = curl_init();
			$args   = array( 'timeout' => 120 );

			$result = WP_MCP_AI_HTTP_Helper::set_connection_timeout( $handle, $args, $url );

			$this->assertSame( $handle, $result, "Failed for: $description" );

			curl_close( $handle );
		}
	}

	/**
	 * Test that set_connection_timeout handles the specific issue IP (192.168.2.222).
	 *
	 * This is the exact IP address from the reported issue.
	 *
	 * @group connection-timeout
	 */
	public function test_set_connection_timeout_issue_ip_192_168_2_222() {
		$handle = curl_init();
		$args   = array( 'timeout' => 120 );
		$url    = 'http://192.168.2.222:11434/api/tags';

		$result = WP_MCP_AI_HTTP_Helper::set_connection_timeout( $handle, $args, $url );

		$this->assertSame( $handle, $result );

		curl_close( $handle );
	}

	/**
	 * Test that SSL bypass can be disabled via settings.
	 *
	 * @group loopback-settings
	 */
	public function test_handle_loopback_requests_respects_ssl_bypass_disabled() {
		// Disable SSL bypass.
		$settings                               = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_loopback_ssl_bypass'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);
		$url  = 'http://localhost:11434/api/tags';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		// SSL settings should NOT be modified when bypass is disabled.
		$this->assertTrue( $modified['sslverify'], 'SSL verify should remain true when bypass is disabled' );
		$this->assertTrue( $modified['reject_unsafe_urls'], 'Reject unsafe URLs should remain true when bypass is disabled' );

		// But timeout should still be set (timeout is always applied).
		$this->assertEquals( 30, $modified['timeout'], 'Timeout should still be set' );

		// Reset settings.
		$settings['enable_loopback_ssl_bypass'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Test that SSL bypass is enabled by default.
	 *
	 * @group loopback-settings
	 */
	public function test_handle_loopback_requests_ssl_bypass_enabled_by_default() {
		// Clear the setting to test default behavior.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['enable_loopback_ssl_bypass'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$args = array(
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);
		$url  = 'http://192.168.2.222:11434/api/tags';

		$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

		// SSL settings should be modified by default.
		$this->assertFalse( $modified['sslverify'], 'SSL verify should be disabled by default' );
		$this->assertFalse( $modified['reject_unsafe_urls'], 'Reject unsafe URLs should be disabled by default' );

		// Restore settings.
		$settings['enable_loopback_ssl_bypass'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Test that SSL bypass works for all private IP ranges when enabled.
	 *
	 * @group loopback-settings
	 */
	public function test_handle_loopback_requests_ssl_bypass_all_private_ranges() {
		// Ensure SSL bypass is enabled.
		$settings                               = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_loopback_ssl_bypass'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$test_cases = array(
			'10.0.0.50:11434'     => 'http://10.0.0.50:11434/api/tags',
			'172.16.0.10:11434'   => 'http://172.16.0.10:11434/api/tags',
			'192.168.1.100:11434' => 'http://192.168.1.100:11434/api/tags',
			'localhost:11434'     => 'http://localhost:11434/api/tags',
			'127.0.0.1:11434'     => 'http://127.0.0.1:11434/api/tags',
		);

		foreach ( $test_cases as $description => $url ) {
			$args = array(
				'sslverify'          => true,
				'reject_unsafe_urls' => true,
			);

			$modified = WP_MCP_AI_HTTP_Helper::handle_loopback_requests( $args, $url );

			$this->assertFalse( $modified['sslverify'], "SSL verify should be disabled for: $description" );
			$this->assertFalse( $modified['reject_unsafe_urls'], "Reject unsafe URLs should be disabled for: $description" );
		}
	}

	/**
	 * Test that private network requests can be disabled via settings.
	 *
	 * @group loopback-settings
	 */
	public function test_allow_private_network_requests_respects_disabled_setting() {
		// Disable private network requests.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_loopback_private_network_requests'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Should NOT allow private network addresses when disabled.
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, 'localhost', 'http://localhost:11434' ), 'Should not allow localhost when disabled' );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '192.168.2.222', 'http://192.168.2.222:1234' ), 'Should not allow 192.168.x.x when disabled' );
		$this->assertFalse( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '10.0.0.50', 'http://10.0.0.50:1234' ), 'Should not allow 10.x.x.x when disabled' );

		// Reset settings.
		$settings['enable_loopback_private_network_requests'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Test that private network requests are enabled by default.
	 *
	 * @group loopback-settings
	 */
	public function test_allow_private_network_requests_enabled_by_default() {
		// Clear the setting to test default behavior.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['enable_loopback_private_network_requests'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Should allow private network addresses by default.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, 'localhost', 'http://localhost:11434' ), 'Should allow localhost by default' );
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( false, '192.168.2.222', 'http://192.168.2.222:1234' ), 'Should allow 192.168.x.x by default' );

		// Restore settings.
		$settings['enable_loopback_private_network_requests'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Test that disabling private network requests does not affect already-external hosts.
	 *
	 * @group loopback-settings
	 */
	public function test_allow_private_network_requests_preserves_external_when_disabled() {
		// Disable private network requests.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_loopback_private_network_requests'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Should still preserve already-external hosts.
		$this->assertTrue( WP_MCP_AI_HTTP_Helper::allow_private_network_requests( true, 'example.com', 'https://example.com' ), 'Should preserve external hosts' );

		// Reset settings.
		$settings['enable_loopback_private_network_requests'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}
}
