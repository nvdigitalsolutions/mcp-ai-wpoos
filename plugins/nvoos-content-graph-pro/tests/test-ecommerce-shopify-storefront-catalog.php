<?php
/**
 * Characterization tests for the F2-E Shopify Storefront Catalog (UCP MCP)
 * cluster — the keyless `storefront_catalog` connection mode, the client's
 * UCP JSON-RPC helpers, and the site-hosted UCP agent profile controller.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   client/manager/admin/controller classes (classmap-autoloaded); the
 *   byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the Plugin::register() controller boot.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Shopify Storefront Catalog (UCP MCP) characterization tests.
 */
class Test_Ecommerce_Shopify_Storefront_Catalog extends WP_UnitTestCase {

	/**
	 * Stored test connection ID.
	 *
	 * @var string|null
	 */
	protected $connection_id;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$file = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php'
				: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-remote-site-manager.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			$file = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php'
				: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-shopify-client.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Create a keyless Storefront Catalog connection via the manager.
	 *
	 * @param array $overrides Field overrides.
	 * @return string|WP_Error Connection ID or error.
	 */
	protected function create_storefront_connection( $overrides = array() ) {
		$data = array_merge(
			array(
				'name'             => 'Storefront Test Store',
				'url'              => 'https://test-store.myshopify.com',
				'connection_type'  => 'shopify',
				'shopify_api_mode' => 'storefront_catalog',
				'auth_type'        => 'none',
				'enabled'          => true,
			),
			$overrides
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
	}

	/**
	 * Mock an HTTP response for wp_safe_remote_* calls.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 * @return void
	 */
	protected function mock_http_response( $status = 200, $body = '{}' ) {
		add_filter(
			'pre_http_request',
			static function () use ( $status, $body ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => $body,
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Error',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			0
		);
	}

	// ------------------------------------------------------------------ //
	// Serving sources                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Shopify_Client'               => 'class-wp-mcp-ai-shopify-client.php',
			'WP_MCP_AI_Pro_Remote_Site_Manager'      => 'class-wp-mcp-ai-pro-remote-site-manager.php',
			'WP_MCP_AI_Pro_Remote_Sites_Admin'       => 'admin/class-wp-mcp-ai-pro-remote-sites-admin.php',
			'WP_MCP_AI_UCP_Agent_Profile_Controller' => 'rest/class-wp-mcp-ai-ucp-agent-profile-controller.php',
		);

		foreach ( $symbols as $class => $file ) {
			if ( ! class_exists( $class ) ) {
				// The admin page class is only defined when the admin slice loads.
				if ( 'WP_MCP_AI_Pro_Remote_Sites_Admin' === $class ) {
					continue;
				}
				$this->fail( $class . ' is not loaded in this matrix.' );
			}
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	// ------------------------------------------------------------------ //
	// Client UCP surface                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * The UCP constants must be byte-identical.
	 */
	public function test_client_ucp_constants(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shopify_Client' );
		$constants  = $reflection->getConstants();
		$this->assertSame( '/api/ucp/mcp', $constants['UCP_MCP_PATH'] );
		$this->assertSame(
			'https://shopify.dev/ucp/agent-profiles/2026-08-25/valid-with-capabilities.json',
			$constants['UCP_DEFAULT_AGENT_PROFILE']
		);
	}

	/**
	 * The client detects the storefront_catalog mode and resolves the endpoint.
	 */
	public function test_client_storefront_mode(): void {
		$connection_id = $this->create_storefront_connection();
		$this->assertIsString( $connection_id );

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );
		$this->assertSame( 'storefront_catalog', $client->get_api_mode() );
		$this->assertSame(
			'https://test-store.myshopify.com/api/ucp/mcp',
			$client->get_storefront_catalog_endpoint()
		);
	}

	/**
	 * The search_catalog call builds the UCP JSON-RPC envelope byte-identically.
	 */
	public function test_search_builds_ucp_rpc_envelope(): void {
		$connection_id = $this->create_storefront_connection();
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);
				return array(
					'headers'  => array(),
					'body'     => '{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"products":[]}}}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		$result = $client->storefront_catalog_search( 'organic coffee', 500 );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://test-store.myshopify.com/api/ucp/mcp', $captured['url'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( '2.0', $payload['jsonrpc'] );
		$this->assertSame( 'tools/call', $payload['method'] );
		$this->assertSame( 'search_catalog', $payload['params']['name'] );
		$this->assertSame(
			WP_MCP_AI_Shopify_Client::UCP_DEFAULT_AGENT_PROFILE,
			$payload['params']['arguments']['meta']['ucp-agent']['profile']
		);
		$this->assertSame( 250, $payload['params']['arguments']['catalog']['pagination']['limit'] );
	}

	/**
	 * JSON-RPC error objects map onto the byte-identical WP_Error code.
	 */
	public function test_rpc_error_object_maps_to_wp_error(): void {
		$connection_id = $this->create_storefront_connection();
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$this->mock_http_response(
			200,
			'{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}'
		);

		$result = $client->storefront_catalog_search( 'test' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_shopify_ucp_rpc_error', $result->get_error_code() );
	}

	/**
	 * A profile_unreachable RPC error names the profile URL Shopify tried to
	 * fetch and explains the public-HTTPS requirement.
	 */
	public function test_profile_unreachable_error_includes_profile_url(): void {
		$profile       = 'https://example.com/ucp/agent.json';
		$connection_id = $this->create_storefront_connection(
			array( 'shopify_ucp_agent_profile' => $profile )
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$this->mock_http_response(
			200,
			'{"jsonrpc":"2.0","id":1,"error":{"code":-32001,"message":"UCP discovery failed","data":{"code":"profile_unreachable","content":"Unable to fetch agent profile: Connection timeout"}}}'
		);

		$result = $client->storefront_catalog_search( 'test' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_shopify_ucp_rpc_error', $result->get_error_code() );
		$this->assertStringContainsString( $profile, $result->get_error_message() );
		$this->assertStringContainsString( 'publicly reachable', $result->get_error_message() );
	}

	// ------------------------------------------------------------------ //
	// Connection validation + test flow                                   //
	// ------------------------------------------------------------------ //

	/**
	 * A keyless Storefront Catalog connection saves without credentials.
	 */
	public function test_save_connection_accepts_keyless_storefront_catalog(): void {
		$connection_id = $this->create_storefront_connection();

		$this->assertIsString( $connection_id );

		$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotNull( $stored );
		$this->assertSame( 'storefront_catalog', $stored['shopify_api_mode'] );
		$this->assertEmpty( $stored['api_key'] );
	}

	/**
	 * Non-public (localhost / private network) profile URLs are dropped so
	 * the client falls back to Shopify's hosted example profile.
	 */
	public function test_save_connection_drops_non_public_profile_url(): void {
		$connection_id = $this->create_storefront_connection(
			array( 'shopify_ucp_agent_profile' => 'https://localhost/wp-json/mcp-ai/v1/ucp/agent-profile' )
		);

		$this->assertIsString( $connection_id );

		$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertSame( '', $stored['shopify_ucp_agent_profile'] );
	}

	/**
	 * The public-URL guard accepts public HTTPS hosts and rejects localhost,
	 * private/loopback IP literals, and non-HTTPS schemes.
	 */
	public function test_is_public_https_url_guard(): void {
		$cases = array(
			'https://example.com/ucp/agent.json'          => true,
			'https://agent.myshop.example.io/profile'     => true,
			'https://8.8.8.8/profile.json'                => true,
			'https://localhost/wp-json/ucp/agent-profile' => false,
			'https://store.local/profile.json'            => false,
			'https://store.internal/profile.json'         => false,
			'https://127.0.0.1/profile.json'              => false,
			'https://10.0.0.5/profile.json'               => false,
			'https://192.168.1.20/profile.json'           => false,
			'http://example.com/ucp/agent.json'           => false,
			'not-a-url'                                   => false,
		);

		foreach ( $cases as $url => $expected ) {
			$this->assertSame(
				$expected,
				WP_MCP_AI_Pro_Remote_Site_Manager::is_public_https_url( $url ),
				$url
			);
		}
	}

	/**
	 * A store domain is required for Storefront Catalog connections.
	 */
	public function test_save_connection_requires_store_domain(): void {
		$result = $this->create_storefront_connection( array( 'url' => '' ) );

		$this->assertWPError( $result );
		$this->assertTrue(
			in_array( $result->get_error_code(), array( 'wp_mcp_ai_pro_missing_url', 'wp_mcp_ai_pro_missing_shopify_domain' ), true )
		);
	}

	/**
	 * The connection test performs a UCP tools/list handshake.
	 */
	public function test_connection_test_uses_ucp_tools_list(): void {
		$connection_id = $this->create_storefront_connection();

		$this->mock_http_response(
			200,
			'{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"search_catalog"}]}}'
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['shopify'] );
	}

	// ------------------------------------------------------------------ //
	// Global Catalog (keyless UCP cross-merchant search)                  //
	// ------------------------------------------------------------------ //

	/**
	 * The Global Catalog mode is keyless: the save path accepts a connection
	 * with no API credentials.
	 */
	public function test_save_connection_accepts_keyless_global_catalog(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'shopify_api_mode' => 'global_catalog',
				'url'              => 'https://catalog.shopify.com',
			)
		);

		$this->assertIsString( $connection_id );

		$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertSame( 'global_catalog', $stored['shopify_api_mode'] );
		$this->assertEmpty( $stored['api_key'] );
	}

	/**
	 * The client exposes the fixed Global Catalog endpoint and the mode.
	 */
	public function test_client_detects_global_catalog_mode_and_endpoint(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'shopify_api_mode' => 'global_catalog',
				'url'              => 'https://catalog.shopify.com',
			)
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$this->assertSame( 'global_catalog', $client->get_api_mode() );
		$this->assertSame(
			WP_MCP_AI_Shopify_Client::UCP_GLOBAL_CATALOG_URL,
			$client->get_global_catalog_endpoint()
		);
	}

	/**
	 * The global search_catalog call posts a UCP envelope to the Global
	 * Catalog endpoint with a 50-result clamp and passes filters through.
	 */
	public function test_global_catalog_search_builds_ucp_rpc_envelope(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'shopify_api_mode' => 'global_catalog',
				'url'              => 'https://catalog.shopify.com',
			)
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);
				return array(
					'headers'  => array(),
					'body'     => '{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"products":[]}}}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		$result = $client->global_catalog_search(
			'trail running shoes',
			500,
			array( 'address_country' => 'US' ),
			array( 'available' => true )
		);

		$this->assertIsArray( $result );
		$this->assertSame( WP_MCP_AI_Shopify_Client::UCP_GLOBAL_CATALOG_URL, $captured['url'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( '2.0', $payload['jsonrpc'] );
		$this->assertSame( 'tools/call', $payload['method'] );
		$this->assertSame( 'search_catalog', $payload['params']['name'] );
		$this->assertSame(
			WP_MCP_AI_Shopify_Client::UCP_DEFAULT_AGENT_PROFILE,
			$payload['params']['arguments']['meta']['ucp-agent']['profile']
		);
		$this->assertSame( 'trail running shoes', $payload['params']['arguments']['catalog']['query'] );
		$this->assertSame( 50, $payload['params']['arguments']['catalog']['pagination']['limit'] );
		$this->assertSame( 'US', $payload['params']['arguments']['catalog']['context']['address_country'] );
		$this->assertSame( array( 'available' => true ), $payload['params']['arguments']['catalog']['filters'] );
	}

	/**
	 * The global lookup_catalog tool caps identifiers at the UCP limit of 50.
	 */
	public function test_global_catalog_lookup_caps_ids_at_fifty(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'shopify_api_mode' => 'global_catalog',
				'url'              => 'https://catalog.shopify.com',
			)
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$captured ) {
				$captured = $args;
				return array(
					'headers'  => array(),
					'body'     => '{"jsonrpc":"2.0","id":1,"result":{}}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			2
		);

		$ids = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$ids[] = 'gid://shopify/ProductVariant/' . $i;
		}

		$client->global_catalog_lookup( $ids );

		$payload = json_decode( $captured['body'], true );
		$this->assertSame( 'lookup_catalog', $payload['params']['name'] );
		$this->assertCount( 50, $payload['params']['arguments']['catalog']['ids'] );
		$this->assertSame( 'gid://shopify/ProductVariant/50', end( $payload['params']['arguments']['catalog']['ids'] ) );
	}

	/**
	 * The connection test performs a UCP tools/list handshake against the
	 * Global Catalog endpoint.
	 */
	public function test_connection_test_uses_global_ucp_tools_list(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'shopify_api_mode' => 'global_catalog',
				'url'              => 'https://catalog.shopify.com',
			)
		);

		$this->mock_http_response(
			200,
			'{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"search_catalog"}]}}'
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['shopify'] );
	}

	// ------------------------------------------------------------------ //
	// Hosted UCP agent profile controller                                 //
	// ------------------------------------------------------------------ //

	/**
	 * The site serves its UCP platform profile at /mcp-ai/v1/ucp/agent-profile.
	 */
	public function test_ucp_agent_profile_route_serves_profile(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-ucp-agent-profile-controller.php';
		} else {
			$file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-ucp-agent-profile-controller.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_UCP_Agent_Profile_Controller' ) && file_exists( $file ) ) {
			require_once $file;
		}
		new WP_MCP_AI_UCP_Agent_Profile_Controller();

		// Force a fresh REST server so rest_api_init re-fires and registers the route.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/ucp/agent-profile' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( '2026-08-25', $data['ucp']['version'] );
		$this->assertArrayHasKey( 'dev.ucp.shopping.catalog.search', $data['ucp']['capabilities'] );
		$this->assertArrayHasKey( 'dev.shopify.catalog', $data['ucp']['capabilities'] );
		$this->assertArrayHasKey( 'dev.shopify.catalog.global', $data['ucp']['capabilities'] );
	}

	/**
	 * Standalone only: Plugin::register() boots the UCP profile controller.
	 */
	public function test_plugin_register_boots_ucp_controller_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base Pro addon boots the controller.' );
		}

		$this->assertFileExists(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-ucp-agent-profile-controller.php'
		);

		// The register() boot path requires the controller file probe to
		// resolve; assert the wiring directly (no double registration).
		\NvoosContentGraphPro\Plugin::instance()->register();

		$this->assertTrue( class_exists( 'WP_MCP_AI_UCP_Agent_Profile_Controller' ) );
	}

	// ------------------------------------------------------------------ //
	// Catalog API (global search) token scope gate                        //
	// ------------------------------------------------------------------ //

	/**
	 * Build a fake JWT whose payload carries the given scopes string.
	 *
	 * @param string $scopes Scopes claim value.
	 * @return string Three-part JWT-shaped token.
	 */
	protected function build_test_jwt( string $scopes ): string {
		$header  = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a fake JWT for scope-gate tests.
			wp_json_encode(
				array(
					'alg' => 'ES256',
					'typ' => 'JWT',
				)
			)
		);
		$payload = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a fake JWT for scope-gate tests.
			wp_json_encode( array( 'scopes' => $scopes ) )
		);
		return rtrim( $header, '=' ) . '.' . rtrim( $payload, '=' ) . '.signature';
	}

	/**
	 * Tokens whose JWT scopes claim lacks read_global_api_catalog_search are
	 * rejected with the actionable scope error instead of being cached.
	 */
	public function test_catalog_token_rejected_when_jwt_lacks_catalog_scope(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'api_key'    => 'test-catalog-client-id',
				'api_secret' => 'shpss_test_secret',
			)
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$this->mock_http_response(
			200,
			wp_json_encode(
				array(
					'access_token' => $this->build_test_jwt( 'write_global_api_app_events' ),
					'token_type'   => 'Bearer',
				)
			)
		);

		$result = $client->get_catalog_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_shopify_catalog_scope_missing', $result->get_error_code() );
		$this->assertStringContainsString( 'read_global_api_catalog_search', $result->get_error_message() );

		// The bad token must not have been cached.
		$this->assertFalse( get_transient( WP_MCP_AI_Shopify_Client::get_catalog_token_transient_key( 'test-catalog-client-id' ) ) );
	}

	/**
	 * Tokens whose JWT scopes claim includes read_global_api_catalog_search
	 * are accepted and cached.
	 */
	public function test_catalog_token_accepted_when_jwt_has_catalog_scope(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'api_key'    => 'test-catalog-client-id',
				'api_secret' => 'shpss_test_secret',
			)
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$token = $this->build_test_jwt( 'read_global_api_catalog_search' );

		$this->mock_http_response(
			200,
			wp_json_encode(
				array(
					'access_token' => $token,
					'token_type'   => 'Bearer',
				)
			)
		);

		$result = $client->get_catalog_token();

		$this->assertSame( $token, $result );
		$this->assertSame( $token, get_transient( WP_MCP_AI_Shopify_Client::get_catalog_token_transient_key( 'test-catalog-client-id' ) ) );
	}

	/**
	 * A legacy top-level scope field on the token response is still honoured.
	 */
	public function test_catalog_token_top_level_scope_field_accepted(): void {
		$connection_id = $this->create_storefront_connection(
			array(
				'api_key'    => 'test-catalog-client-id',
				'api_secret' => 'shpss_test_secret',
			)
		);
		$client        = new WP_MCP_AI_Shopify_Client( $connection_id );

		$token = $this->build_test_jwt( '' );

		$this->mock_http_response(
			200,
			wp_json_encode(
				array(
					'access_token' => $token,
					'token_type'   => 'Bearer',
					'scope'        => 'read_global_api_catalog_search',
				)
			)
		);

		$result = $client->get_catalog_token();

		$this->assertSame( $token, $result );
	}
}
