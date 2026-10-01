<?php
/**
 * Tests for the MCP App OAuth metadata discovery chain.
 *
 * Verifies the multi-step discovery chain defined by the MCP Authorization
 * Specification: RFC 8414 well-known metadata (with RFC 8414 §3.2 path
 * insertion), RFC 9728 protected resource metadata (root and path-inserted
 * variants, following every advertised authorization server), the 401
 * WWW-Authenticate probe, the OpenID Connect fallback, and the actionable
 * per-attempt failure diagnostics.
 *
 * @package WP_MCP_AI
 * @since   1.9.5
 */

/**
 * Discovery-chain tests.
 *
 * @since 1.9.5
 */
class Test_MCP_App_OAuth_Discovery_Chain extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php';
		}

		// Allowlist the hosts used by the mocks so any SSRF URL guard passes
		// without a real DNS lookup.
		add_filter(
			'wp_mcp_ai_http_allowed_host',
			function ( $hosts, $host, $url ) {
				unset( $host, $url );
				$hosts[] = 'example.com';
				$hosts[] = 'auth.example.com';
				$hosts[] = 'live.example.com';
				$hosts[] = 'stale.example.com';
				return $hosts;
			},
			10,
			3
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_http_allowed_host' );
		parent::tearDown();
	}

	/**
	 * Install a pre_http_request mock with an exact-URL route map.
	 *
	 * Each route entry is either a response array or a WP_Error. URLs
	 * without an entry get a bare 404 response.
	 *
	 * @param array $routes Route map (URL => response array|WP_Error).
	 * @return void
	 */
	protected function install_route_map( array $routes ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $routes ) {
				unset( $pre, $args );

				if ( isset( $routes[ $url ] ) ) {
					$entry = $routes[ $url ];
					return is_wp_error( $entry ) ? $entry : $entry;
				}

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a JSON HTTP response array for a pre_http_request mock.
	 *
	 * @param array $data    Response body payload.
	 * @param int   $status  HTTP status code.
	 * @param array $headers Response headers.
	 * @return array
	 */
	protected function json_response( array $data, $status = 200, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => wp_json_encode( $data ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
		);
	}

	/**
	 * Build a valid RFC 8414 metadata document.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	protected function as_metadata( array $overrides = array() ) {
		return array_merge(
			array(
				'issuer'                 => 'https://example.com',
				'authorization_endpoint' => 'https://example.com/oauth/authorize',
				'token_endpoint'         => 'https://example.com/oauth/token',
			),
			$overrides
		);
	}

	/**
	 * Well-known RFC 8414 metadata on the MCP origin is discovered directly.
	 */
	public function test_well_known_root_discovery() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-authorization-server' => $this->json_response( $this->as_metadata() ),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://example.com/oauth/authorize', $metadata['authorization_endpoint'] );
		$this->assertSame( 'as_metadata', $client->get_metadata_source() );
	}

	/**
	 * Path-scoped servers (RFC 8414 §3.2) serve metadata behind the
	 * well-known path-insertion URL.
	 */
	public function test_well_known_path_insertion_discovery() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-authorization-server/v1/mcp' => $this->json_response( $this->as_metadata() ),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/v1/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://example.com/oauth/authorize', $metadata['authorization_endpoint'] );
	}

	/**
	 * RFC 9728 protected resource metadata on the well-known root points at
	 * a separate authorization server host.
	 */
	public function test_protected_resource_metadata_follows_authorization_servers() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-protected-resource' => $this->json_response(
					array(
						'resource'              => 'https://example.com/mcp',
						'authorization_servers' => array( 'https://auth.example.com' ),
					)
				),
				'https://auth.example.com/.well-known/oauth-authorization-server' => $this->json_response(
					$this->as_metadata(
						array(
							'issuer'                 => 'https://auth.example.com',
							'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
							'token_endpoint'         => 'https://auth.example.com/oauth/token',
						)
					)
				),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://auth.example.com/oauth/authorize', $metadata['authorization_endpoint'] );
		$this->assertSame( 'protected_resource', $client->get_metadata_source() );
	}

	/**
	 * The path-inserted protected resource variant (e.g.
	 * /.well-known/oauth-protected-resource/mcp) is discovered too — API
	 * gateways commonly serve the document there.
	 */
	public function test_protected_resource_path_inserted_variant() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-protected-resource/mcp' => $this->json_response(
					array(
						'resource'              => 'https://example.com/mcp',
						'authorization_servers' => array( 'https://example.com' ),
					)
				),
				'https://example.com/.well-known/oauth-authorization-server' => $this->json_response( $this->as_metadata() ),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
	}

	/**
	 * Every advertised authorization server is tried, not just the first —
	 * per the MCP Authorization Specification client guidance.
	 */
	public function test_multiple_authorization_servers_tries_all() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-protected-resource' => $this->json_response(
					array(
						'authorization_servers' => array(
							'https://stale.example.com',
							'https://live.example.com',
						),
					)
				),
				'https://live.example.com/.well-known/oauth-authorization-server' => $this->json_response(
					$this->as_metadata(
						array(
							'authorization_endpoint' => 'https://live.example.com/oauth/authorize',
							'token_endpoint'         => 'https://live.example.com/oauth/token',
						)
					)
				),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://live.example.com/oauth/authorize', $metadata['authorization_endpoint'] );
	}

	/**
	 * An authorization server entry that is itself the full RFC 8414
	 * metadata URL is fetched directly instead of being path-inserted.
	 */
	public function test_authorization_servers_entry_with_full_metadata_url() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-protected-resource' => $this->json_response(
					array(
						'authorization_servers' => array( 'https://auth.example.com/.well-known/oauth-authorization-server' ),
					)
				),
				'https://auth.example.com/.well-known/oauth-authorization-server' => $this->json_response(
					$this->as_metadata(
						array(
							'issuer'                 => 'https://auth.example.com',
							'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
							'token_endpoint'         => 'https://auth.example.com/oauth/token',
						)
					)
				),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://auth.example.com/oauth/authorize', $metadata['authorization_endpoint'] );
	}

	/**
	 * OpenID Connect discovery is a compatibility fallback for gateways
	 * fronting Auth0 / Okta / Cognito.
	 */
	public function test_oidc_configuration_fallback() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/openid-configuration' => $this->json_response( $this->as_metadata() ),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'as_metadata', $client->get_metadata_source() );
	}

	/**
	 * The 401 WWW-Authenticate probe still resolves through the
	 * resource_metadata pointer (MCP Authorization Specification).
	 */
	public function test_www_authenticate_probe_resolves() {
		$this->install_route_map(
			array(
				'https://example.com/mcp' => $this->json_response(
					array(
						'error' => array(
							'code'    => -32001,
							'message' => 'Unauthorized',
						),
					),
					401,
					array( 'www-authenticate' => 'Bearer resource_metadata="https://example.com/oauth-protected-resource", scope="mcp:tools"' )
				),
				// The challenge points at a non-well-known location; only the
				// probe follows it (the well-known PRM URLs 404).
				'https://example.com/oauth-protected-resource' => $this->json_response(
					array(
						'authorization_servers' => array( 'https://auth.example.com' ),
					)
				),
				'https://auth.example.com/.well-known/oauth-authorization-server' => $this->json_response(
					$this->as_metadata(
						array(
							'issuer'                 => 'https://auth.example.com',
							'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
							'token_endpoint'         => 'https://auth.example.com/oauth/token',
						)
					)
				),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://auth.example.com/oauth/authorize', $metadata['authorization_endpoint'] );
		$this->assertSame( 'www_authenticate', $client->get_metadata_source() );
	}

	/**
	 * A document that omits the REQUIRED token_endpoint (RFC 8414 §2) is
	 * rejected and the chain keeps going.
	 */
	public function test_metadata_without_token_endpoint_is_rejected() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-authorization-server' => $this->json_response(
					array( 'authorization_endpoint' => 'https://example.com/oauth/authorize' )
				),
				'https://example.com/.well-known/openid-configuration' => $this->json_response( $this->as_metadata() ),
			)
		);

		$client   = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$metadata = $client->discover_metadata();

		$this->assertNotWPError( $metadata );
		$this->assertSame( 'https://example.com/oauth/token', $metadata['token_endpoint'] );
	}

	/**
	 * When every attempt fails, the WP_Error carries per-attempt diagnostics
	 * and the underlying transport error so the admin can tell connectivity
	 * failures from "this server has no OAuth".
	 */
	public function test_failure_surfaces_attempts_and_transport_error() {
		$this->install_route_map(
			array(
				'https://example.com/.well-known/oauth-authorization-server' => new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to example.com port 443' ),
			)
		);

		$client = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$result = $client->discover_metadata();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mcp_app_oauth_no_metadata', $result->get_error_code() );
		$this->assertStringContainsString( 'cURL error 7', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data['attempts'] );
		$this->assertNotEmpty( $data['hint'] );
	}

	/**
	 * Multiple challenges in one WWW-Authenticate header parse correctly:
	 * a leading Basic challenge must not corrupt the Bearer parameters.
	 */
	public function test_www_authenticate_multiple_challenges() {
		$client = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );

		$method = new ReflectionMethod( $client, 'parse_www_authenticate' );
		$method->setAccessible( true );

		$parsed = $method->invoke(
			$client,
			'Basic realm="api", Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource", scope="mcp:tools"'
		);

		$this->assertSame( 'https://example.com/.well-known/oauth-protected-resource', $parsed['resource_metadata'] );
		$this->assertSame( 'mcp:tools', $parsed['scope'] );
		$this->assertSame( 'api', $parsed['realm'] );
	}
}
