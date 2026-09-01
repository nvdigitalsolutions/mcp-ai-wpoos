<?php
/**
 * URL guard port tests (Wave D4e).
 *
 * Characterization suite for `UrlGuard`. Assertions mirror the base
 * plugin's SSRF guard: invalid inputs, scheme enforcement, blocked
 * metadata hostnames, private/loopback CIDR blocking, IPv6 loopback /
 * link-local / ULA blocking, operator host whitelisting, filterable
 * blocked lists, and the DNS-failure error shape.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\UrlGuard;

/**
 * @group security
 */
class Test_Url_Guard extends \WP_UnitTestCase {

	public function tearDown(): void {
		\remove_all_filters( UrlGuard::FILTER_ALLOWED_HOSTS );
		\remove_all_filters( UrlGuard::FILTER_BLOCKED_HOSTNAMES );
		\remove_all_filters( UrlGuard::FILTER_BLOCKED_RANGES );

		parent::tearDown();
	}

	public function test_constants_match_base(): void {
		$this->assertContains( '10.0.0.0/8', UrlGuard::BLOCKED_IPV4_RANGES );
		$this->assertContains( '127.0.0.0/8', UrlGuard::BLOCKED_IPV4_RANGES );
		$this->assertContains( '169.254.169.254', UrlGuard::BLOCKED_HOSTNAMES );
		$this->assertSame( 'wp_mcp_ai_url_guard_blocked_ranges', UrlGuard::FILTER_BLOCKED_RANGES );
		$this->assertSame( 'wp_mcp_ai_url_guard_blocked_hostnames', UrlGuard::FILTER_BLOCKED_HOSTNAMES );
		$this->assertSame( 'wp_mcp_ai_http_allowed_host', UrlGuard::FILTER_ALLOWED_HOSTS );
	}

	public function test_invalid_inputs_are_rejected(): void {
		$empty = UrlGuard::validate( '' );
		$this->assertWPError( $empty );
		$this->assertSame( 'url_guard_invalid_url', $empty->get_error_code() );

		$non_string = UrlGuard::validate( array( 'http://x' ) );
		$this->assertWPError( $non_string );

		$ftp = UrlGuard::validate( 'ftp://example.com/file' );
		$this->assertWPError( $ftp );
		$this->assertSame( 'url_guard_invalid_scheme', $ftp->get_error_code() );
	}

	public function test_metadata_hostnames_are_blocked(): void {
		$check = UrlGuard::validate( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertWPError( $check );
		$this->assertSame( 'url_guard_blocked_hostname', $check->get_error_code() );

		$gcp = UrlGuard::validate( 'http://metadata.google.internal/' );
		$this->assertWPError( $gcp );
		$this->assertSame( 'url_guard_blocked_hostname', $gcp->get_error_code() );
	}

	public function test_private_ip_literals_are_blocked(): void {
		// Monolith: the base HTTP helper rejects private/loopback literals
		// as known-private hostnames. Standalone: the CIDR checks reject
		// them after resolution. Both block — error codes differ.
		$expected_code = defined( 'WP_MCP_AI_PATH' ) ? 'url_guard_blocked_hostname' : 'url_guard_blocked_ip';

		$loopback = UrlGuard::validate( 'http://127.0.0.1/admin' );
		$this->assertWPError( $loopback );
		$this->assertSame( $expected_code, $loopback->get_error_code() );

		$private = UrlGuard::validate( 'http://192.168.1.10/' );
		$this->assertWPError( $private );
		$this->assertSame( $expected_code, $private->get_error_code() );

		$ten = UrlGuard::validate( 'http://10.0.0.5/' );
		$this->assertWPError( $ten );
		$this->assertSame( $expected_code, $ten->get_error_code() );
	}

	public function test_ipv6_loopback_and_link_local_are_blocked(): void {
		$loopback = UrlGuard::validate( 'http://[::1]/' );
		$this->assertWPError( $loopback );
		$this->assertSame( 'url_guard_blocked_ipv6_loopback', $loopback->get_error_code() );

		$link_local = UrlGuard::validate( 'http://[fe80::1]/' );
		$this->assertWPError( $link_local );
		$this->assertSame( 'url_guard_blocked_ipv6_link_local', $link_local->get_error_code() );

		$ula = UrlGuard::validate( 'http://[fd00::1]/' );
		$this->assertWPError( $ula );
		$this->assertSame( 'url_guard_blocked_ipv6_ula', $ula->get_error_code() );
	}

	public function test_localhost_is_blocked_in_both_modes(): void {
		$check = UrlGuard::validate( 'http://localhost:8080/' );

		$this->assertWPError( $check );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base HTTP helper rejects the hostname outright.
			$this->assertSame( 'url_guard_blocked_hostname', $check->get_error_code() );
		} else {
			// Standalone: DNS resolution returns 127.0.0.1 → CIDR block.
			$this->assertSame( 'url_guard_blocked_ip', $check->get_error_code() );
		}
	}

	public function test_allowed_host_whitelist_bypasses_blocking(): void {
		\add_filter(
			UrlGuard::FILTER_ALLOWED_HOSTS,
			static function ( $hosts ) {
				$hosts[] = '192.168.99.99';
				return $hosts;
			}
		);

		$this->assertTrue( UrlGuard::validate( 'http://192.168.99.99/' ) );
	}

	public function test_blocked_hostnames_filter_is_honoured(): void {
		\add_filter(
			UrlGuard::FILTER_BLOCKED_HOSTNAMES,
			static function ( $blocked ) {
				$blocked[] = 'example.com';
				return $blocked;
			}
		);

		$check = UrlGuard::validate( 'https://example.com/' );
		$this->assertWPError( $check );
		$this->assertSame( 'url_guard_blocked_hostname', $check->get_error_code() );
	}

	public function test_blocked_ranges_filter_is_honoured(): void {
		\add_filter(
			UrlGuard::FILTER_BLOCKED_RANGES,
			static function ( $ranges ) {
				$ranges[] = '203.0.113.0/24'; // TEST-NET-3.
				return $ranges;
			}
		);

		$check = UrlGuard::validate( 'http://203.0.113.7/' );
		$this->assertWPError( $check );
		$this->assertSame( 'url_guard_blocked_ip', $check->get_error_code() );
	}

	public function test_public_host_passes(): void {
		$this->assertTrue( UrlGuard::validate( 'https://example.com/' ) );
	}

	public function test_dns_failure_returns_generic_error(): void {
		$check = UrlGuard::validate( 'http://nonexistent-host-zz9.invalid/' );

		$this->assertWPError( $check );
		$this->assertSame( 'url_guard_dns_failed', $check->get_error_code() );
		$data = $check->get_error_data();
		$this->assertSame( 'dns_failed', $data['reason'] );

		// The hostname is only exposed when WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertArrayHasKey( 'host', $data );
		} else {
			$this->assertArrayNotHasKey( 'host', $data );
		}
	}
}
