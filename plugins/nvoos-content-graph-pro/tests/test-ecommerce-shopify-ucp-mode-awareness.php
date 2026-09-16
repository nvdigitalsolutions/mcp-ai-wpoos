<?php
/**
 * Shopify UCP catalog mode-awareness characterization tests.
 *
 * Verifies that the Shopify tools treat Storefront Catalog MCP and Global
 * Catalog MCP (keyless UCP, live agent-query) connections correctly:
 *
 * - shopify_products routes list/search/get to the live UCP catalog tools
 *   (search_catalog, lookup_catalog, get_product) and rejects writes.
 * - shopify_orders / shopify_customers / shopify_inventory refuse catalog
 *   connections with an actionable hint instead of failing mid-API-call.
 * - shopify_catalog is mode-aware across all three catalog modes and runs
 *   live UCP queries with context/cursor passthrough and pagination and
 *   not_found surfacing.
 * - remote_shopify_connection tests UCP connections with the MCP tools/list
 *   handshake and annotates list_connections results with supported tools.
 * - Nothing is cached: UCP paths write no transients and no options.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded).
 * - Standalone matrix (base plugin absent): the ported copies in `src/`
 *   are exercised.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Shopify UCP mode-awareness tests.
 */
class Test_Ecommerce_Shopify_UCP_Mode_Awareness extends WP_UnitTestCase {

	/**
	 * Stored test connection IDs.
	 *
	 * @var array
	 */
	protected $connection_ids = array();

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $admin_user;

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

		$this->admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
	 * Create a Shopify connection in the given API mode.
	 *
	 * @param string $api_mode  API mode (admin_api, catalog_api, storefront_catalog, global_catalog).
	 * @param array  $overrides Field overrides.
	 * @return string Connection ID.
	 */
	protected function create_connection( $api_mode, $overrides = array() ) {
		$data = array_merge(
			array(
				'name'             => 'Mode Test Store',
				'url'              => 'https://mode-test.myshopify.com',
				'connection_type'  => 'shopify',
				'shopify_api_mode' => $api_mode,
				'auth_type'        => 'none',
				'api_key'          => 'shpat_test_token_' . wp_generate_password( 16, false ),
				'enabled'          => true,
			),
			$overrides
		);

		if ( 'catalog_api' === $api_mode ) {
			$data['api_secret'] = 'shpss_test_secret_' . wp_generate_password( 16, false );
		}

		$connection_id          = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
		$this->connection_ids[] = $connection_id;

		return $connection_id;
	}

	/**
	 * Execution context for tool calls as the admin user.
	 *
	 * @return array
	 */
	protected function tool_context() {
		return array(
			'user_id'      => $this->admin_user,
			'assistant_id' => 0,
		);
	}

	/**
	 * Mock an HTTP response and capture the request.
	 *
	 * @param array|null $captured Reference receiving url + args.
	 * @param int        $status   HTTP status code.
	 * @param string     $body     Response body.
	 * @return void
	 */
	protected function mock_capturing_http( &$captured, $status = 200, $body = '{}' ) {
		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured, $status, $body ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
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
			3
		);
	}

	/**
	 * Build a UCP search result with a single product.
	 *
	 * @param string $id    Product GID.
	 * @param string $title Product title.
	 * @return string JSON body.
	 */
	protected function ucp_products_body( $id = 'gid://shopify/Product/1001', $title = 'Trail Running Shoes' ) {
		return wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'structuredContent' => array(
						'products'   => array(
							array(
								'id'          => $id,
								'title'       => $title,
								'description' => array( 'plain' => 'Lightweight trail shoes.' ),
								'url'         => 'https://mode-test.myshopify.com/products/trail-shoes',
								'price_range' => array(
									'min' => array(
										'amount'   => 8999,
										'currency' => 'USD',
									),
									'max' => array(
										'amount'   => 12999,
										'currency' => 'USD',
									),
								),
								'media'       => array(
									array(
										'type' => 'image',
										'url'  => 'https://cdn.example.com/trail.jpg',
									),
								),
								'variants'    => array(
									array(
										'id'           => 'gid://shopify/ProductVariant/2001',
										'sku'          => 'TRL-9',
										'title'        => 'Size 9',
										'price'        => array(
											'amount'   => 8999,
											'currency' => 'USD',
										),
										'availability' => array( 'available' => true ),
									),
								),
							),
						),
						'pagination' => array(
							'cursor'        => 'eyJwYWdlIjoxfQ==',
							'has_next_page' => false,
							'total_count'   => 1,
						),
					),
				),
			)
		);
	}

	// ------------------------------------------------------------------ //
	// shopify_products — UCP mode routing                                 //
	// ------------------------------------------------------------------ //

	/**
	 * A storefront_catalog products list/search is a live UCP search_catalog
	 * call against the store's own /api/ucp/mcp endpoint — never the Admin
	 * GraphQL API.
	 */
	public function test_products_storefront_list_uses_live_ucp_search(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'list',
				'query'         => 'trail running shoes',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['live'] );
		$this->assertSame( 'https://mode-test.myshopify.com/api/ucp/mcp', $captured['url'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'tools/call', $payload['method'] );
		$this->assertSame( 'search_catalog', $payload['params']['name'] );
		$this->assertSame( 'trail running shoes', $payload['params']['arguments']['catalog']['query'] );
		$this->assertArrayHasKey( 'profile', $payload['params']['arguments']['meta']['ucp-agent'] );
		$this->assertSame( 10, $payload['params']['arguments']['catalog']['pagination']['limit'] );

		// Normalized product plus the UCP pagination envelope are surfaced.
		$this->assertCount( 1, $result['products'] );
		$this->assertSame( 'Trail Running Shoes', $result['products'][0]['title'] );
		$this->assertSame( 'eyJwYWdlIjoxfQ==', $result['pagination']['cursor'] );
	}

	/**
	 * A global_catalog products list/search targets the fixed cross-merchant
	 * endpoint and clamps the limit to the UCP maximum of 50.
	 */
	public function test_products_global_list_uses_live_ucp_search_with_50_clamp(): void {
		$connection_id = $this->create_connection(
			'global_catalog',
			array( 'url' => 'https://catalog.shopify.com' )
		);
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'running shoes',
				'first'         => 500,
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( WP_MCP_AI_Shopify_Client::UCP_GLOBAL_CATALOG_URL, $captured['url'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 50, $payload['params']['arguments']['catalog']['pagination']['limit'] );
	}

	/**
	 * Product writes are rejected in storefront_catalog mode with an
	 * actionable error.
	 */
	public function test_products_storefront_create_rejected(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'create',
				'title'         => 'Should not be created',
			),
			$this->tool_context()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_shopify_storefront_catalog_read_only', $result->get_error_code() );
	}

	/**
	 * The get action in UCP modes uses the canonical lookup_catalog tool and
	 * surfaces the normalized product.
	 */
	public function test_products_ucp_get_uses_lookup_catalog(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'get',
				'product_id'    => 'gid://shopify/Product/1001',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['live'] );
		$this->assertSame( 'Trail Running Shoes', $result['product']['title'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'lookup_catalog', $payload['params']['name'] );
		$this->assertSame(
			array( 'gid://shopify/Product/1001' ),
			$payload['params']['arguments']['catalog']['ids']
		);
	}

	/**
	 * The get action with option selections uses the canonical get_product
	 * tool so Shopify narrows the variants.
	 */
	public function test_products_ucp_get_with_selected_uses_get_product(): void {
		$connection_id = $this->create_connection( 'global_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$body = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'structuredContent' => array(
						'product' => array(
							'id'          => 'gid://shopify/Product/1001',
							'title'       => 'Trail Running Shoes',
							'description' => array( 'plain' => 'Lightweight trail shoes.' ),
							'price_range' => array(
								'min' => array(
									'amount'   => 8999,
									'currency' => 'USD',
								),
								'max' => array(
									'amount'   => 12999,
									'currency' => 'USD',
								),
							),
							'media'       => array(),
							'variants'    => array(),
							'selected'    => array(
								array(
									'name'  => 'Size',
									'label' => '9',
								),
							),
						),
					),
				),
			)
		);

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $body );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'get',
				'product_id'    => 'gid://shopify/Product/1001',
				'selected'      => array(
					array(
						'name'  => 'Size',
						'label' => '9',
					),
				),
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Trail Running Shoes', $result['product']['title'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'get_product', $payload['params']['name'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'Size',
					'label' => '9',
				),
			),
			$payload['params']['arguments']['catalog']['selected']
		);
	}

	/**
	 * Buyer context and the pagination cursor are passed through to the UCP
	 * search_catalog call.
	 */
	public function test_products_ucp_context_and_cursor_passthrough(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'running shoes',
				'after'         => 'eyJwYWdlIjoyfQ==',
				'context'       => array(
					'address_country' => 'US',
					'intent'          => 'looking for a gift',
					'evil_key'        => 'should be dropped',
				),
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );

		$payload = json_decode( $captured['args']['body'], true );
		$catalog = $payload['params']['arguments']['catalog'];
		$this->assertSame( 'eyJwYWdlIjoyfQ==', $catalog['pagination']['cursor'] );
		$this->assertSame( 'US', $catalog['context']['address_country'] );
		$this->assertSame( 'looking for a gift', $catalog['context']['intent'] );
		$this->assertArrayNotHasKey( 'evil_key', $catalog['context'] );
	}

	/**
	 * Smart search decomposes a zero-result UCP query into live sub-queries.
	 */
	public function test_products_ucp_smart_search_decomposes_live(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$calls ) {
				$calls++;
				$payload = json_decode( $args['body'], true );
				$query   = isset( $payload['params']['arguments']['catalog']['query'] ) ? $payload['params']['arguments']['catalog']['query'] : '';

				$products = array();
				// The decomposition produces bigram sub-queries: the original
				// query returns zero results and one bigram resolves.
				if ( 'running shoes' === $query ) {
					$products = array(
						array(
							'id'          => 'gid://shopify/Product/3001',
							'title'       => 'Road Shoes',
							'description' => array( 'plain' => 'Road shoes.' ),
							'price_range' => array(
								'min' => array(
									'amount'   => 7000,
									'currency' => 'USD',
								),
								'max' => array(
									'amount'   => 7000,
									'currency' => 'USD',
								),
							),
							'media'       => array(),
							'variants'    => array(),
						),
					);
				}

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => array(
								'structuredContent' => array( 'products' => $products ),
							),
						)
					),
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

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'blue running shoes',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['smart_search'] );
		$this->assertCount( 1, $result['products'] );
		$this->assertSame( 'Road Shoes', $result['products'][0]['title'] );
		$this->assertGreaterThan( 1, $calls );
	}

	/**
	 * UCP queries write nothing to the local cache (transients/options).
	 */
	public function test_products_ucp_writes_no_cache(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'list',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );

		$options    = wp_load_alloptions();
		$transients = array();
		foreach ( $options as $key => $value ) {
			if ( false !== strpos( $key, '_transient' ) && false !== strpos( $key, 'shopify' ) ) {
				$transients[] = $key;
			}
		}
		$this->assertEmpty( $transients, 'UCP live queries must not write Shopify transients.' );
	}

	// ------------------------------------------------------------------ //
	// shopify_orders / customers / inventory — catalog-mode guards         //
	// ------------------------------------------------------------------ //

	/**
	 * Admin-only tools refuse UCP catalog connections with an actionable
	 * hint pointing at the live catalog tools.
	 */
	public function test_admin_only_tools_reject_ucp_catalog_modes(): void {
		$tools = array(
			new WP_MCP_AI_Pro_Tool_Shopify_Orders(),
			new WP_MCP_AI_Pro_Tool_Shopify_Customers(),
			new WP_MCP_AI_Pro_Tool_Shopify_Inventory(),
		);

		foreach ( array( 'storefront_catalog', 'global_catalog' ) as $api_mode ) {
			$connection_id = $this->create_connection( $api_mode );

			foreach ( $tools as $tool ) {
				$result = $tool->execute(
					array(
						'connection_id' => $connection_id,
						'action'        => 'list',
					),
					$this->tool_context()
				);

				$this->assertWPError( $result, $tool->get_slug() . ' should refuse ' . $api_mode . ' connections.' );
				$this->assertSame( 'wp_mcp_ai_shopify_catalog_mode_admin_only', $result->get_error_code() );
				$this->assertStringContainsString( 'shopify_catalog', $result->get_error_message() );
			}
		}
	}

	/**
	 * The deprecated REST catalog_api mode is also product-search-only for
	 * the admin tools.
	 */
	public function test_admin_only_tools_reject_catalog_api_mode(): void {
		$connection_id = $this->create_connection( 'catalog_api' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Orders();

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'list',
			),
			$this->tool_context()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_shopify_catalog_mode_admin_only', $result->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// shopify_catalog — mode-aware live catalog tool                      //
	// ------------------------------------------------------------------ //

	/**
	 * The catalog tool runs a live UCP search_catalog call for a
	 * storefront_catalog connection.
	 */
	public function test_catalog_tool_storefront_search_is_live_ucp(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'trail shoes',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['live'] );
		$this->assertSame( 'storefront_catalog', $result['mode'] );
		$this->assertCount( 1, $result['products'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'search_catalog', $payload['params']['name'] );
	}

	/**
	 * The catalog tool clamps Global Catalog searches to the UCP maximum of
	 * 50 results.
	 */
	public function test_catalog_tool_global_search_clamps_50(): void {
		$connection_id = $this->create_connection( 'global_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'trail shoes',
				'limit'         => 500,
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 50, $payload['params']['arguments']['catalog']['pagination']['limit'] );
		$this->assertSame( WP_MCP_AI_Shopify_Client::UCP_GLOBAL_CATALOG_URL, $captured['url'] );
	}

	/**
	 * The catalog tool lookup batches identifiers through lookup_catalog and
	 * surfaces not_found entries per the UCP spec.
	 */
	public function test_catalog_tool_ucp_lookup_batch_and_not_found(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$body = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'structuredContent' => array(
						'products'  => array(
							array(
								'id'          => 'gid://shopify/Product/1001',
								'title'       => 'Trail Running Shoes',
								'description' => array( 'plain' => 'Trail shoes.' ),
								'price_range' => array(
									'min' => array(
										'amount'   => 8999,
										'currency' => 'USD',
									),
									'max' => array(
										'amount'   => 12999,
										'currency' => 'USD',
									),
								),
								'variants'    => array(),
							),
						),
						'not_found' => array( 'gid://shopify/Product/9999' ),
					),
				),
			)
		);

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $body );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'lookup',
				'ids'           => array( 'gid://shopify/Product/1001', 'gid://shopify/Product/9999' ),
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['products'] );
		$this->assertSame( array( 'gid://shopify/Product/9999' ), $result['not_found'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'lookup_catalog', $payload['params']['name'] );
		$this->assertCount( 2, $payload['params']['arguments']['catalog']['ids'] );
	}

	/**
	 * The catalog tool lookup_by_variant resolves a VID through the same
	 * canonical lookup_catalog tool and returns the matched variant.
	 */
	public function test_catalog_tool_ucp_lookup_by_variant(): void {
		$connection_id = $this->create_connection( 'global_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'lookup_by_variant',
				'vid'           => 'gid://shopify/ProductVariant/2001',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'gid://shopify/ProductVariant/2001', $result['variant']['id'] );
		$this->assertSame( 'gid://shopify/Product/1001', $result['variant']['product_id'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'lookup_catalog', $payload['params']['name'] );
	}

	/**
	 * The catalog tool get_product action calls the canonical get_product
	 * tool with optional selections.
	 */
	public function test_catalog_tool_ucp_get_product(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$body = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'structuredContent' => array(
						'product' => array(
							'id'    => 'gid://shopify/Product/1001',
							'title' => 'Trail Running Shoes',
						),
					),
				),
			)
		);

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $body );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'get_product',
				'product_id'    => 'gid://shopify/Product/1001',
				'selected'      => array(
					array(
						'name'  => 'Size',
						'label' => '9',
					),
				),
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Trail Running Shoes', $result['product']['title'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'get_product', $payload['params']['name'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'Size',
					'label' => '9',
				),
			),
			$payload['params']['arguments']['catalog']['selected']
		);
	}

	/**
	 * The catalog tool rejects admin_api connections with a pointer to the
	 * shopify_products tool.
	 */
	public function test_catalog_tool_rejects_admin_api_connection(): void {
		$connection_id = $this->create_connection( 'admin_api' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'shoes',
			),
			$this->tool_context()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_shopify_catalog_wrong_mode', $result->get_error_code() );
		$this->assertStringContainsString( 'shopify_products', $result->get_error_message() );
	}

	/**
	 * The catalog tool auto-resolves a UCP connection from the assistant
	 * context when no connection_id is given and several connections exist.
	 */
	public function test_catalog_tool_resolves_ucp_connection_among_multiple(): void {
		$this->create_connection( 'admin_api' );
		$this->create_connection( 'storefront_catalog' );
		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body() );

		$result = $tool->execute(
			array(
				'action' => 'search',
				'query'  => 'trail shoes',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		// Only the storefront catalog connection is catalog-capable, so it
		// wins the auto-resolution even though two connections exist.
		$this->assertSame( 'https://mode-test.myshopify.com/api/ucp/mcp', $captured['url'] );
	}

	// ------------------------------------------------------------------ //
	// remote_shopify_connection — mode-aware test/list                    //
	// ------------------------------------------------------------------ //

	/**
	 * The test_connection action validates UCP catalog connections with the
	 * MCP tools/list negotiation handshake instead of an Admin GraphQL call.
	 */
	public function test_connection_tool_ucp_test_uses_tools_list_handshake(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Tool_Remote_Shopify_Connection();

		$captured = null;
		$this->mock_capturing_http(
			$captured,
			200,
			'{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"search_catalog"},{"name":"lookup_catalog"},{"name":"get_product"}]}}'
		);

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'test_connection',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'storefront_catalog', $result['api_mode'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'tools/list', $payload['method'] );
	}

	/**
	 * The list_connections action annotates each connection with its mode
	 * label, supported tools, and the live-only flag for catalog modes.
	 */
	public function test_connection_tool_list_annotates_modes(): void {
		$storefront_id = $this->create_connection( 'storefront_catalog' );
		$this->create_connection( 'admin_api' );
		$tool = new WP_MCP_AI_Tool_Remote_Shopify_Connection();

		$result = $tool->execute(
			array( 'action' => 'list_connections' ),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['connections'] );

		$by_id = array();
		foreach ( $result['connections'] as $connection ) {
			$by_id[ $connection['id'] ] = $connection;
		}

		$this->assertStringContainsString( 'Storefront Catalog', $by_id[ $storefront_id ]['api_mode_label'] );
		$this->assertTrue( $by_id[ $storefront_id ]['live_only'] );
		$this->assertSame(
			array( 'shopify_catalog', 'shopify_products' ),
			$by_id[ $storefront_id ]['supported_tools']
		);
	}

	// ------------------------------------------------------------------ //
	// Resolver trait — mode helpers                                       //
	// ------------------------------------------------------------------ //

	/**
	 * The shared trait's mode helpers classify UCP catalog modes.
	 */
	public function test_resolver_mode_helpers(): void {
		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$this->assertTrue( $this->is_protected_true( $tool, 'is_ucp_catalog_api_mode', array( 'storefront_catalog' ) ) );
		$this->assertTrue( $this->is_protected_true( $tool, 'is_ucp_catalog_api_mode', array( 'global_catalog' ) ) );
		$this->assertFalse( $this->is_protected_true( $tool, 'is_ucp_catalog_api_mode', array( 'catalog_api' ) ) );
		$this->assertFalse( $this->is_protected_true( $tool, 'is_ucp_catalog_api_mode', array( 'admin_api' ) ) );
	}

	/**
	 * Invoke a protected bool helper on a tool instance.
	 *
	 * @param object $tool   Tool instance.
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return bool Method result.
	 */
	protected function is_protected_true( $tool, $method, $args ) {
		$reflection = new ReflectionMethod( $tool, $method );
		$reflection->setAccessible( true );
		return (bool) $reflection->invokeArgs( $tool, $args );
	}
}
