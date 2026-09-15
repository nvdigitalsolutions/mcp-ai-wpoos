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
}
