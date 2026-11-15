<?php
/**
 * Tests for the canonical client-IP resolver.
 *
 * Background: the wp.org T2 review of nvoos-forge-bridge flagged trusting the
 * client-supplied `X-Forwarded-For` header. The base plugin carried the same
 * pattern in 17 duplicated helpers (security-manager IP access checks, REST
 * rate limits, magic-login, OAuth, audit logs). This suite pins the shared
 * resolver: `REMOTE_ADDR` is authoritative, forwarding headers are honored
 * only behind a configured trusted proxy, and the chain is walked from the
 * right so a client-supplied prefix cannot spoof the result.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file; PHPUnit naming convention.

/**
 * Unit tests for wp_mcp_ai_get_client_ip() and its helpers.
 */
class Test_Client_IP extends WP_UnitTestCase {

	/**
	 * Server superglobal backup.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * IP headers reset between scenarios.
	 *
	 * @var string[]
	 */
	private $headers = array(
		'REMOTE_ADDR',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_REAL_IP',
		'HTTP_CLIENT_IP',
	);

	/**
	 * Seed a clean environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->server_backup = $_SERVER;
		foreach ( $this->headers as $header ) {
			unset( $_SERVER[ $header ] );
		}
		remove_all_filters( 'wp_mcp_ai_trusted_proxies' );
	}

	/**
	 * Restore the environment.
	 */
	public function tear_up() {
		$_SERVER = $this->server_backup;
		remove_all_filters( 'wp_mcp_ai_trusted_proxies' );
		parent::tear_up();
	}

	/**
	 * Configure trusted proxies for the current scenario.
	 *
	 * @param string[] $proxies Exact IPs / CIDR ranges.
	 */
	private function trust( array $proxies ) {
		add_filter(
			'wp_mcp_ai_trusted_proxies',
			static function () use ( $proxies ) {
				return $proxies;
			}
		);
	}

	/**
	 * REMOTE_ADDR wins even when forwarding headers are present.
	 */
	public function test_remote_addr_is_authoritative_by_default() {
		$_SERVER['REMOTE_ADDR']           = '192.0.2.10';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.99';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.98';

		$this->assertSame( '192.0.2.10', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * A header from an untrusted peer is ignored.
	 */
	public function test_forwarded_header_ignored_when_peer_is_not_trusted() {
		$_SERVER['REMOTE_ADDR']          = '192.0.2.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
		$this->trust( array( '10.0.0.0/8' ) );

		$this->assertSame( '192.0.2.10', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * HTTP_CLIENT_IP is never trusted (legacy spoofable header).
	 */
	public function test_http_client_ip_is_ignored() {
		$_SERVER['REMOTE_ADDR']    = '192.0.2.10';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.7';

		$this->assertSame( '192.0.2.10', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * Behind a trusted proxy, a proxy-written single-value header is used.
	 */
	public function test_trusted_proxy_uses_single_value_header() {
		$_SERVER['REMOTE_ADDR']           = '10.1.2.3';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.9';
		$this->trust( array( '10.0.0.0/8' ) );

		$this->assertSame( '203.0.113.7', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * The X-Forwarded-For chain is walked right-to-left; a spoofed prefix loses.
	 */
	public function test_trusted_proxy_uses_rightmost_untrusted_hop() {
		$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 203.0.113.7, 10.9.9.9';
		$this->trust( array( '10.0.0.0/8' ) );

		$this->assertSame( '203.0.113.7', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * An exact-IP trusted proxy is honored.
	 */
	public function test_exact_ip_trusted_proxy() {
		$_SERVER['REMOTE_ADDR']    = '10.1.2.3';
		$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.7';
		$this->trust( array( '10.1.2.3' ) );

		$this->assertSame( '203.0.113.7', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * IPv6 peers and CIDR ranges are supported.
	 */
	public function test_ipv6_trusted_cidr() {
		$_SERVER['REMOTE_ADDR']    = '2001:db8::1';
		$_SERVER['HTTP_X_REAL_IP'] = '2606:4700:4700::1111';
		$this->trust( array( '2001:db8::/32' ) );

		$this->assertSame( '2606:4700:4700::1111', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * An invalid REMOTE_ADDR fails closed to the sentinel.
	 */
	public function test_invalid_remote_addr_falls_back_to_sentinel() {
		$_SERVER['REMOTE_ADDR']          = 'not-an-ip';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

		$this->assertSame( '0.0.0.0', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * No REMOTE_ADDR means no trusted peer, so headers are ignored.
	 */
	public function test_missing_remote_addr_ignores_forwarded_headers() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

		$this->assertSame( '0.0.0.0', wp_mcp_ai_get_client_ip() );
	}

	/**
	 * CIDR matching handles boundaries and malformed ranges.
	 */
	public function test_cidr_boundaries() {
		$this->assertTrue( wp_mcp_ai_ip_in_cidr( '10.5.6.7', '10.0.0.0/8' ) );
		$this->assertFalse( wp_mcp_ai_ip_in_cidr( '11.5.6.7', '10.0.0.0/8' ) );
		$this->assertTrue( wp_mcp_ai_ip_in_cidr( '192.168.1.130', '192.168.1.128/25' ) );
		$this->assertFalse( wp_mcp_ai_ip_in_cidr( '192.168.1.127', '192.168.1.128/25' ) );
		$this->assertFalse( wp_mcp_ai_ip_in_cidr( '10.0.0.1', '10.0.0.0/33' ) );
		$this->assertFalse( wp_mcp_ai_ip_in_cidr( '10.0.0.1', 'not-a-cidr' ) );
		$this->assertSame( '', wp_mcp_ai_normalize_ip( 'not-an-ip' ) );
	}
}
