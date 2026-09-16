<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Shopify product image-card characterization tests.
 *
 * Verifies that every Shopify product-returning tool path includes the
 * product image:
 *
 * - shopify_products catalog_api get renders a card message with the
 *   product image markdown.
 * - shopify_products UCP get renders a card message with the image.
 * - shopify_catalog REST search/lookup render card messages with images.
 * - shopify_catalog UCP search/get_product/lookup_by_variant render card
 *   messages with images (live only — nothing cached).
 * - List/search card messages are capped at MAX_CARD_COUNT cards while the
 *   structured payload keeps the full result set.
 * - The shared normalizers trait maps media → images[] for both the UCP
 *   and the deprecated REST Catalog API shapes.
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

// The normalizers trait is base-Pro-owned monolith; the addon's `src/`
// copy serves standalone (the helper class below uses it at compile time).
if ( ! trait_exists( 'WP_MCP_AI_Shopify_Product_Normalizers' ) ) {
	$nvoos_content_graph_pro_normalizers_trait = defined( 'WP_MCP_AI_PATH' )
		? WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-product-normalizers.php'
		: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/trait-wp-mcp-ai-shopify-product-normalizers.php';
	if ( file_exists( $nvoos_content_graph_pro_normalizers_trait ) ) {
		require_once $nvoos_content_graph_pro_normalizers_trait;
	}
}

/**
 * Concrete class that uses the normalizers trait so its protected methods
 * can be unit-tested directly.
 *
 * @phpcs:ignore Universal.Files.OneObjectStructurePerFile.MultipleFound
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper; mirrors the base Pro suite's helper convention.
class Shopify_Product_Normalizers_Test_Helper {
	use WP_MCP_AI_Shopify_Product_Normalizers;

	/**
	 * Expose the protected normalize_ucp_product method.
	 *
	 * @param array $item Raw UCP product object.
	 * @return array Normalized product array.
	 */
	public function test_normalize_ucp( array $item ) {
		return $this->normalize_ucp_product( $item );
	}

	/**
	 * Expose the protected normalize_catalog_product method.
	 *
	 * @param array $item Raw Catalog API product object.
	 * @return array Normalized product array.
	 */
	public function test_normalize_catalog( array $item ) {
		return $this->normalize_catalog_product( $item );
	}
}

/**
 * Shopify product image-card tests.
 */
class Test_Ecommerce_Shopify_Product_Image_Cards extends WP_UnitTestCase {

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

		if ( ! trait_exists( 'WP_MCP_AI_Tool_Product_Card' ) ) {
			$file = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-product-card.php'
				: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-product-card.php';
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
				'name'             => 'Image Card Test Store',
				'url'              => 'https://image-cards.myshopify.com',
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
	 * Build a single UCP product fixture.
	 *
	 * @param string $id        Product GID.
	 * @param string $title     Product title.
	 * @param string $media_url Product image URL.
	 * @return array Product array.
	 */
	protected function ucp_product( $id, $title, $media_url ) {
		return array(
			'id'          => $id,
			'title'       => $title,
			'description' => array( 'plain' => 'Lightweight trail shoes.' ),
			'url'         => 'https://image-cards.myshopify.com/products/' . sanitize_title( $title ),
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
					'url'  => $media_url,
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
		);
	}

	/**
	 * Build a UCP search/lookup response body containing the given products.
	 *
	 * @param array $products Product fixture arrays.
	 * @return string JSON body.
	 */
	protected function ucp_products_body( array $products ) {
		return wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'structuredContent' => array(
						'products'   => $products,
						'pagination' => array(
							'cursor'        => 'eyJwYWdlIjoxfQ==',
							'has_next_page' => false,
							'total_count'   => count( $products ),
						),
					),
				),
			)
		);
	}

	/**
	 * Build a UCP get_product response body containing a single product.
	 *
	 * @param array $product Product fixture array.
	 * @return string JSON body.
	 */
	protected function ucp_single_product_body( array $product ) {
		return wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'structuredContent' => array(
						'product' => $product,
					),
				),
			)
		);
	}

	/**
	 * Build a deprecated REST Catalog API product item.
	 *
	 * @param string $upid      Universal Product ID.
	 * @param string $title     Display name.
	 * @param string $media_url Product image URL.
	 * @return array Catalog product item.
	 */
	protected function catalog_item( $upid, $title, $media_url ) {
		return array(
			'upid'             => $upid,
			'displayname'      => $title,
			'media'            => array(
				array(
					'type' => 'image',
					'url'  => $media_url,
				),
			),
			'pricerange'       => array(
				'minvariantprice' => array(
					'amount'       => 8999,
					'currencycode' => 'USD',
				),
				'maxvariantprice' => array(
					'amount'       => 12999,
					'currencycode' => 'USD',
				),
			),
			'availableforsale' => true,
			'lookupurl'        => 'https://image-cards.myshopify.com/products/' . sanitize_title( $title ),
		);
	}

	/**
	 * Seed the Catalog API token transient so REST-mode tests skip the
	 * shpss_ token exchange and hit the catalog endpoint directly.
	 *
	 * @param string $client_id Plaintext Catalog API client ID (the connection's api_key).
	 * @return void
	 */
	protected function seed_catalog_token( $client_id ) {
		set_transient(
			WP_MCP_AI_Shopify_Client::get_catalog_token_transient_key( $client_id ),
			'fake.jwt.token',
			HOUR_IN_SECONDS
		);
	}

	// ------------------------------------------------------------------ //
	// shopify_products — single-product cards                              //
	// ------------------------------------------------------------------ //

	/**
	 * catalog_api get renders a card message with the product image.
	 */
	public function test_products_catalog_api_get_renders_image_card(): void {
		$connection_id = $this->create_connection( 'catalog_api', array( 'api_key' => 'shpat_image_card_client' ) );
		$this->seed_catalog_token( 'shpat_image_card_client' );
		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$captured = null;
		$this->mock_capturing_http(
			$captured,
			200,
			wp_json_encode( $this->catalog_item( 'upid_123', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' ) )
		);

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'get',
				'product_id'    => 'upid_123',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '![Trail Shoes](https://cdn.example.com/trail.jpg)', $result['message'] );
		$this->assertSame( 'https://cdn.example.com/trail.jpg', $result['product']['images'][0]['url'] );
	}

	/**
	 * Storefront UCP get renders a card message with the product image and
	 * keeps the live flag.
	 */
	public function test_products_storefront_get_renders_image_card(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Products();

		$product  = $this->ucp_product( 'gid://shopify/Product/1001', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' );
		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body( array( $product ) ) );

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
		$this->assertStringContainsString( '![Trail Shoes](https://cdn.example.com/trail.jpg)', $result['message'] );
		$this->assertSame( 'https://cdn.example.com/trail.jpg', $result['product']['images'][0]['url'] );
	}

	// ------------------------------------------------------------------ //
	// shopify_catalog — REST Catalog API cards                             //
	// ------------------------------------------------------------------ //

	/**
	 * REST catalog_api search renders image cards in the message while the
	 * structured payload keeps the raw products.
	 */
	public function test_catalog_rest_search_renders_image_cards(): void {
		$connection_id = $this->create_connection( 'catalog_api', array( 'api_key' => 'shpat_image_card_client' ) );
		$this->seed_catalog_token( 'shpat_image_card_client' );
		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$captured = null;
		$this->mock_capturing_http(
			$captured,
			200,
			wp_json_encode(
				array(
					'products' => array(
						$this->catalog_item( 'upid_123', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' ),
						$this->catalog_item( 'upid_456', 'Road Shoes', 'https://cdn.example.com/road.jpg' ),
					),
				)
			)
		);

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'search',
				'query'         => 'shoes',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertStringContainsString( '![Trail Shoes](https://cdn.example.com/trail.jpg)', $result['message'] );
		$this->assertStringContainsString( '![Road Shoes](https://cdn.example.com/road.jpg)', $result['message'] );
		$this->assertSame( 'upid_123', $result['products'][0]['upid'] );
	}

	/**
	 * REST catalog_api lookup renders a card message with the product image.
	 */
	public function test_catalog_rest_lookup_renders_image_card(): void {
		$connection_id = $this->create_connection( 'catalog_api', array( 'api_key' => 'shpat_image_card_client' ) );
		$this->seed_catalog_token( 'shpat_image_card_client' );
		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$captured = null;
		$this->mock_capturing_http(
			$captured,
			200,
			wp_json_encode( $this->catalog_item( 'upid_123', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' ) )
		);

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'lookup',
				'upid'          => 'upid_123',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '![Trail Shoes](https://cdn.example.com/trail.jpg)', $result['message'] );
		$this->assertSame( 'upid_123', $result['product']['upid'] );
	}

	// ------------------------------------------------------------------ //
	// shopify_catalog — UCP cards                                          //
	// ------------------------------------------------------------------ //

	/**
	 * UCP search renders capped image cards (MAX_CARD_COUNT) and keeps the
	 * full product set in the structured payload.
	 */
	public function test_catalog_ucp_search_renders_capped_image_cards(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$products = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$products[] = $this->ucp_product(
				'gid://shopify/Product/' . ( 1000 + $i ),
				'Trail Shoes ' . $i,
				'https://cdn.example.com/trail-' . $i . '.jpg'
			);
		}

		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body( $products ) );

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
		$this->assertSame( 12, $result['count'] );
		$this->assertSame( 12, count( $result['products'] ) );

		// Exactly MAX_CARD_COUNT (10) image cards, with a truncation note.
		$this->assertSame( 10, substr_count( $result['message'], '![' ) );
		$this->assertStringContainsString( 'more product(s) in the structured results', $result['message'] );

		// The raw UCP payload stays available for the agent.
		$this->assertSame( 'https://cdn.example.com/trail-12.jpg', $result['products'][11]['media'][0]['url'] );
	}

	/**
	 * UCP get_product renders a card message with the product image.
	 */
	public function test_catalog_ucp_get_product_renders_image_card(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$product  = $this->ucp_product( 'gid://shopify/Product/1001', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' );
		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_single_product_body( $product ) );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'get_product',
				'product_id'    => 'gid://shopify/Product/1001',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['live'] );
		$this->assertStringContainsString( '![Trail Shoes](https://cdn.example.com/trail.jpg)', $result['message'] );
		$this->assertSame( 'gid://shopify/Product/1001', $result['product']['id'] );
	}

	/**
	 * UCP lookup_by_variant renders a card message from the parent product.
	 */
	public function test_catalog_ucp_lookup_by_variant_renders_parent_image_card(): void {
		$connection_id = $this->create_connection( 'storefront_catalog' );
		$tool          = new WP_MCP_AI_Pro_Tool_Shopify_Catalog();

		$product  = $this->ucp_product( 'gid://shopify/Product/1001', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' );
		$captured = null;
		$this->mock_capturing_http( $captured, 200, $this->ucp_products_body( array( $product ) ) );

		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'action'        => 'lookup_by_variant',
				'vid'           => 'gid://shopify/ProductVariant/2001',
			),
			$this->tool_context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '![Trail Shoes](https://cdn.example.com/trail.jpg)', $result['message'] );
		$this->assertSame( 'gid://shopify/Product/1001', $result['variant']['product_id'] );
	}

	// ------------------------------------------------------------------ //
	// Shared normalizers trait                                            //
	// ------------------------------------------------------------------ //

	/**
	 * The shared trait maps media → images[] for both product shapes.
	 */
	public function test_normalizers_trait_maps_media_to_images(): void {
		$helper = new Shopify_Product_Normalizers_Test_Helper();

		$ucp = $helper->test_normalize_ucp(
			$this->ucp_product( 'gid://shopify/Product/1001', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' )
		);
		$this->assertSame( 'Trail Shoes', $ucp['title'] );
		$this->assertSame( 'https://cdn.example.com/trail.jpg', $ucp['images'][0]['url'] );
		$this->assertSame( 89.99, $ucp['price_range']['minVariantPrice']['amount'] );

		$catalog = $helper->test_normalize_catalog(
			$this->catalog_item( 'upid_123', 'Trail Shoes', 'https://cdn.example.com/trail.jpg' )
		);
		$this->assertSame( 'Trail Shoes', $catalog['title'] );
		$this->assertSame( 'https://cdn.example.com/trail.jpg', $catalog['images'][0]['url'] );
		$this->assertSame( 89.99, $catalog['price_range']['minVariantPrice']['amount'] );
	}
}
