<?php
/**
 * Tests for connected-app discovery (Proposal 090-i Phase 4 / 090-p W6).
 *
 * The registry is the WordPress twin of Laravel's
 * `App\Support\ConnectedApps\ConnectedAppRegistry`: it probes configured
 * product bases and reports the ones that answered. Nothing here touches the
 * network — HTTP is stubbed with `pre_http_request` and the hostname is
 * whitelisted so DNS never runs, which keeps the suite deterministic.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.2.22
 */
class WP_MCP_AI_Connected_App_Registry_Test extends WP_UnitTestCase {

	/**
	 * Descriptors applied to every case unless a test replaces them.
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * Recorded outbound requests.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $requests = array();

	/**
	 * Reset state between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->config   = array();
		$this->requests = array();

		delete_option( WP_MCP_AI_Connected_App_Registry::OPTION );
		WP_MCP_AI_Connected_App_Registry::reset_instance();

		// The whitelist short-circuits resolve_for_pin(), so no real DNS runs.
		add_filter( 'wp_mcp_ai_http_allowed_host', array( $this, 'allow_test_hosts' ) );

		add_filter(
			WP_MCP_AI_Connected_App_Registry::FILTER,
			function () {
				return $this->config;
			}
		);
	}

	/**
	 * Remove the stubs.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_http_allowed_host', array( $this, 'allow_test_hosts' ) );
		remove_all_filters( WP_MCP_AI_Connected_App_Registry::FILTER );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( WP_MCP_AI_Connected_App_Registry::FILTER_TTL );
		WP_MCP_AI_Connected_App_Registry::reset_instance();

		parent::tearDown();
	}

	/**
	 * Whitelist the fixture host so DNS is never consulted.
	 *
	 * @param array $hosts Allowed hosts.
	 * @return array
	 */
	public function allow_test_hosts( $hosts ) {
		$hosts[] = 'products.example.test';
		return $hosts;
	}

	/**
	 * Stub HTTP so a probe resolves to a fixed status.
	 *
	 * @param int $status Status code to return for every request.
	 * @return void
	 */
	private function stub_http( $status ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $status ) {
				unset( $preempt );
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a registry with a stubbed resolver.
	 *
	 * @param array<string>|false $ips Addresses the host resolves to.
	 * @return WP_MCP_AI_Connected_App_Registry
	 */
	private function registry( $ips = array( '203.0.114.10' ) ) {
		return new WP_MCP_AI_Connected_App_Registry(
			static function () use ( $ips ) {
				return $ips;
			}
		);
	}

	/**
	 * A descriptor for one app.
	 *
	 * @param string $path Base path.
	 * @return array
	 */
	private function descriptor( $path = '/wp-json/nvoos-docs/v1' ) {
		return array( 'base' => 'https://products.example.test' . $path );
	}

	/**
	 * Nothing configured means no probe and no URLs.
	 */
	public function test_unconfigured_returns_empty_without_a_request() {
		$this->stub_http( 200 );

		$this->assertSame( array(), $this->registry()->available() );
		$this->assertSame( array(), $this->requests, 'an empty config must not make a request' );
	}

	/**
	 * Unknown product keys are ignored: the fixed list bounds the surface.
	 */
	public function test_unknown_keys_are_ignored() {
		$this->config = array(
			'evilProduct' => $this->descriptor(),
		);
		$this->stub_http( 200 );

		$this->assertSame( array(), $this->registry()->available() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A descriptor without a usable base is dropped, not fatal.
	 */
	public function test_malformed_descriptors_are_dropped() {
		$this->config = array(
			'docsHub'     => 'not-an-array',
			'mediaStudio' => array( 'base' => '' ),
		);
		$this->stub_http( 200 );

		$this->assertSame( array(), $this->registry()->available() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A 2xx answer marks the app available and publishes its base.
	 */
	public function test_reachable_app_is_published() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 200 );

		$urls = $this->registry()->available();

		$this->assertSame(
			array( 'docsHub' => 'https://products.example.test/wp-json/nvoos-docs/v1' ),
			$urls
		);
		$this->assertSame(
			'https://products.example.test/wp-json/nvoos-docs/v1/health',
			$this->requests[0]['url'],
			'the default probe path is appended to the base'
		);
	}

	/**
	 * An auth-required answer still means the product is present.
	 *
	 * @dataProvider provide_reached_statuses
	 * @param int $status Status code.
	 */
	public function test_auth_required_counts_as_reached( $status ) {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( $status );

		$this->assertArrayHasKey( 'docsHub', $this->registry()->available() );
	}

	/**
	 * Statuses that mean "reached".
	 *
	 * @return array<string,array{int}>
	 */
	public static function provide_reached_statuses() {
		return array(
			'ok'           => array( 200 ),
			'no content'   => array( 204 ),
			'redirect'     => array( 302 ),
			'unauthorised' => array( 401 ),
			'forbidden'    => array( 403 ),
		);
	}

	/**
	 * Statuses that mean "not there".
	 *
	 * @dataProvider provide_absent_statuses
	 * @param int $status Status code.
	 */
	public function test_absent_statuses_are_unavailable( $status ) {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( $status );

		$this->assertSame( array(), $this->registry()->available() );
	}

	/**
	 * Statuses that mean "not there".
	 *
	 * @return array<string,array{int}>
	 */
	public static function provide_absent_statuses() {
		return array(
			'not found'    => array( 404 ),
			'server error' => array( 500 ),
			'bad gateway'  => array( 502 ),
			'bad request'  => array( 400 ),
		);
	}

	/**
	 * A transport failure is unavailable, not an exception.
	 */
	public function test_transport_error_is_unavailable() {
		$this->config = array( 'docsHub' => $this->descriptor() );

		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'down' );
			}
		);

		$this->assertSame( array(), $this->registry()->available() );
	}

	/**
	 * The probe never follows a redirect.
	 */
	public function test_redirects_are_not_followed() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 200 );

		$this->registry()->available();

		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
	}

	/**
	 * A configured token is sent as a bearer credential and never published.
	 *
	 * The descriptor `token` is the documented fallback for programmatic
	 * callers; the key store is the preferred home (next test).
	 */
	public function test_token_is_sent_but_never_published() {
		$this->config = array(
			'docsHub' => array(
				'base'  => 'https://products.example.test/wp-json/nvoos-docs/v1',
				'token' => 'super-secret',
			),
		);
		$this->stub_http( 200 );

		$urls = $this->registry()->available();

		$this->assertSame( 'Bearer super-secret', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'super-secret', (string) wp_json_encode( $urls ) );
	}

	/**
	 * The encrypted key store wins over a descriptor token.
	 *
	 * Rotating a credential in the store must take effect without editing the
	 * option, so the store is authoritative and the descriptor is only a
	 * fallback.
	 */
	public function test_key_store_token_wins_over_the_descriptor() {
		$suffix = WP_MCP_AI_Connected_App_Registry::token_suffix( 'docsHub' );
		$this->assertSame( 'connected_app_docs_hub_token', $suffix );

		WP_MCP_AI_Api_Key_Store::set( $suffix, 'from-the-store' );

		$this->config = array(
			'docsHub' => array(
				'base'  => 'https://products.example.test/wp-json/nvoos-docs/v1',
				'token' => 'from-the-option',
			),
		);
		$this->stub_http( 200 );

		try {
			$this->assertSame(
				array( 'docsHub' => 'https://products.example.test/wp-json/nvoos-docs/v1' ),
				$this->registry()->available()
			);

			$this->assertSame( 'Bearer from-the-store', $this->requests[0]['args']['headers']['Authorization'] );
			$this->assertStringNotContainsString(
				'from-the-store',
				get_option( WP_MCP_AI_Api_Key_Store::OPTION_PREFIX . $suffix, '' ),
				'the credential is not stored in the clear'
			);
		} finally {
			WP_MCP_AI_Api_Key_Store::delete( $suffix );
		}
	}

	/**
	 * Every console key maps to a key-store suffix.
	 */
	public function test_every_app_has_a_token_suffix() {
		foreach ( array_keys( WP_MCP_AI_Connected_App_Registry::DEFAULTS ) as $key ) {
			$this->assertNotSame( '', WP_MCP_AI_Connected_App_Registry::token_suffix( $key ) );
		}

		$this->assertSame( '', WP_MCP_AI_Connected_App_Registry::token_suffix( 'evilProduct' ) );
	}

	/**
	 * With no token anywhere, the probe sends no Authorization header.
	 */
	public function test_no_token_means_no_authorization_header() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 200 );

		$this->registry()->available();

		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );
	}

	/**
	 * The result is cached: a second call probes nothing.
	 */
	public function test_result_is_cached_across_calls() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 200 );

		$first  = $this->registry()->available();
		$second = $this->registry()->available();

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->requests, 'the second call must be served from the transient' );
	}

	/**
	 * Failures are cached too, so a dead product cannot slow every boot.
	 */
	public function test_failures_are_cached() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 500 );

		$this->assertSame( array(), $this->registry()->available() );
		$this->assertSame( array(), $this->registry()->available() );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Plain HTTP is refused for a non-loopback host.
	 */
	public function test_plain_http_is_refused_for_a_remote_host() {
		$this->config = array(
			'docsHub' => array( 'base' => 'http://products.example.test/wp-json/nvoos-docs/v1' ),
		);
		$this->stub_http( 200 );

		$this->assertSame( array(), $this->registry()->available() );
		$this->assertSame( array(), $this->requests, 'an insecure base must not be probed' );
	}

	/**
	 * A private answer is refused before any request is made.
	 */
	public function test_private_resolution_is_refused() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 200 );

		// Drop the whitelist so resolve_for_pin() uses the injected resolver.
		remove_filter( 'wp_mcp_ai_http_allowed_host', array( $this, 'allow_test_hosts' ) );

		foreach ( array( '10.0.0.5', '127.0.0.1', '169.254.169.254', '100.64.1.1', '192.168.1.10' ) as $ip ) {
			$this->assertSame(
				array(),
				$this->registry( array( $ip ) )->available(),
				"{$ip} must be refused"
			);
		}

		$this->assertSame( array(), $this->requests, 'a blocked address must not be requested' );
	}

	/**
	 * A hostname that will not resolve is unavailable, not an exception.
	 */
	public function test_unresolvable_host_is_unavailable() {
		$this->config = array( 'docsHub' => $this->descriptor() );
		$this->stub_http( 200 );
		remove_filter( 'wp_mcp_ai_http_allowed_host', array( $this, 'allow_test_hosts' ) );

		$this->assertSame( array(), $this->registry( false )->available() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A per-app probe path override is honoured.
	 */
	public function test_probe_path_override() {
		$this->config = array(
			'contentGraph' => array(
				'base'  => 'https://products.example.test/wp-json/nvoos-content-graph/v1',
				'probe' => 'graph/health',
			),
		);
		$this->stub_http( 200 );

		$this->registry()->available();

		$this->assertSame(
			'https://products.example.test/wp-json/nvoos-content-graph/v1/graph/health',
			$this->requests[0]['url']
		);
	}

	/**
	 * A reached app flips its feature flag, so the two maps agree.
	 */
	public function test_apply_feature_flags_flips_the_matching_key() {
		$flags = WP_MCP_AI_Connected_App_Registry::apply_feature_flags(
			array(
				'docs_hub'        => false,
				'knowledge_graph' => false,
				'pro'             => false,
			),
			array(
				'docsHub'      => 'https://docs.example.test',
				'contentGraph' => 'https://cg.example.test',
			)
		);

		$this->assertTrue( $flags['docs_hub'] );
		$this->assertTrue( $flags['knowledge_graph'] );
		$this->assertFalse( $flags['pro'], 'unrelated flags are untouched' );
	}

	/**
	 * An empty URL map leaves the flags exactly as they were.
	 */
	public function test_apply_feature_flags_is_a_no_op_when_empty() {
		$before = array( 'docs_hub' => false );
		$this->assertSame(
			$before,
			WP_MCP_AI_Connected_App_Registry::apply_feature_flags( $before, array() )
		);
	}
}
