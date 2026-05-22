<?php
/**
 * Tests for Shipping Box Packer and Shipping Rate Estimator tools.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */
class Test_Shipping_Tools extends WP_UnitTestCase {

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID for permission tests.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin_id );

		// Grant admin user manage_woocommerce capability for testing.
		// (WooCommerce is not fully loaded in the test environment).
		$admin_user = get_user_by( 'id', $this->admin_id );
		$admin_user->add_cap( 'manage_woocommerce' );

		// Enable the ecommerce toolkit feature flag.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_ecommerce_toolkit' => true )
		);

		// Ensure tool classes are loaded.
		$box_packer_path     = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-shipping-box-packer.php'
			: dirname( __DIR__ ) . '/includes/tools/ecommerce/class-wp-mcp-ai-tool-shipping-box-packer.php';
		$rate_estimator_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-shipping-rate-estimator.php'
			: dirname( __DIR__ ) . '/includes/tools/ecommerce/class-wp-mcp-ai-tool-shipping-rate-estimator.php';

		if ( file_exists( $box_packer_path ) ) {
			require_once $box_packer_path;
		}
		if ( file_exists( $rate_estimator_path ) ) {
			require_once $rate_estimator_path;
		}
	}

	// ----------------------------------------------------------------
	// Shipping Box Packer — slug, schema, and metadata.
	// ----------------------------------------------------------------

	/**
	 * Test Box Packer tool slug.
	 */
	public function test_box_packer_slug() {
		$tool = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$this->assertSame( 'shipping_box_packer', $tool->get_slug() );
	}

	/**
	 * Test Box Packer tool name is not empty.
	 */
	public function test_box_packer_name() {
		$tool = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test Box Packer tool description is not empty.
	 */
	public function test_box_packer_description() {
		$tool = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test Box Packer parameters schema is valid.
	 */
	public function test_box_packer_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'items', $schema['properties'] );
		$this->assertArrayHasKey( 'boxes', $schema['properties'] );
		$this->assertArrayHasKey( 'order_id', $schema['properties'] );
		$this->assertContains( 'action', $schema['required'] );
	}

	/**
	 * Test Box Packer capability flags include expected values.
	 */
	public function test_box_packer_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'requires-plugin', $flags );
	}

	// ----------------------------------------------------------------
	// Shipping Box Packer — pack_items action.
	// ----------------------------------------------------------------

	/**
	 * Test packing a single small item into the smallest box.
	 */
	public function test_pack_single_small_item() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Small Widget',
						'length'    => 4,
						'width'     => 3,
						'height'    => 2,
						'weight_oz' => 8,
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['total_items'] );
		$this->assertSame( 1, $result['total_packages'] );
		$this->assertNotEmpty( $result['packages'] );

		$pkg = $result['packages'][0];
		$this->assertSame( 1, $pkg['package_number'] );
		$this->assertSame( 1, $pkg['item_count'] );
		$this->assertArrayHasKey( 'dimensions', $pkg );
		$this->assertArrayHasKey( 'weight_oz', $pkg );
		$this->assertArrayHasKey( 'packing_list', $pkg );
		$this->assertContains( '1x Small Widget', $pkg['packing_list'] );
	}

	/**
	 * Test packing multiple items with quantity expansion.
	 */
	public function test_pack_items_quantity_expansion() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Widget',
						'length'    => 3,
						'width'     => 3,
						'height'    => 3,
						'weight_oz' => 4,
						'quantity'  => 3,
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['total_items'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_packages'] );
	}

	/**
	 * Test that large items that don't fit in any box use fallback.
	 */
	public function test_pack_oversized_item_uses_fallback() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Giant Item',
						'length'    => 50,
						'width'     => 40,
						'height'    => 30,
						'weight_oz' => 100,
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['total_packages'] );

		$pkg = $result['packages'][0];
		// Fallback box dimensions should match or exceed item dimensions.
		$this->assertGreaterThanOrEqual( 50, $pkg['dimensions']['length'] );
		$this->assertGreaterThanOrEqual( 40, $pkg['dimensions']['width'] );
		$this->assertGreaterThanOrEqual( 30, $pkg['dimensions']['height'] );
	}

	/**
	 * Test packing with custom box definitions.
	 */
	public function test_pack_with_custom_boxes() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Item A',
						'length'    => 5,
						'width'     => 5,
						'height'    => 5,
						'weight_oz' => 16,
					),
				),
				'boxes'  => array(
					array(
						'reference'    => 'My Box',
						'outer_length' => 6,
						'outer_width'  => 6,
						'outer_depth'  => 6,
						'inner_length' => 6,
						'inner_width'  => 6,
						'inner_depth'  => 6,
						'empty_weight' => 2,
						'max_weight'   => 10,
						'box_type'     => 'cubic',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'My Box', $result['packages'][0]['box_reference'] );
	}

	/**
	 * Test USPS cubic eligibility calculation.
	 */
	public function test_cubic_eligibility_and_tier() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Small Item',
						'length'    => 4,
						'width'     => 4,
						'height'    => 4,
						'weight_oz' => 8,
					),
				),
				'boxes'  => array(
					array(
						'reference'    => 'Small Cubic',
						'outer_length' => 6,
						'outer_width'  => 6,
						'outer_depth'  => 6,
						'inner_length' => 6,
						'inner_width'  => 6,
						'inner_depth'  => 6,
						'empty_weight' => 2,
						'max_weight'   => 20,
						'box_type'     => 'cubic',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$pkg = $result['packages'][0];
		// 6x6x6 = 216 cubic inches / 1728 = 0.125 cubic feet → tier 0.2, eligible.
		$this->assertTrue( $pkg['cubic_eligible'] );
		$this->assertSame( '0.2', $pkg['cubic_tier'] );
	}

	/**
	 * Test pack_items with no items returns error.
	 */
	public function test_pack_items_empty_items_returns_error() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_items', $result->get_error_code() );
	}

	/**
	 * Test pack_items with invalid item data returns error.
	 */
	public function test_pack_items_invalid_items_returns_error() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Bad Item',
						'length'    => 0,
						'width'     => 0,
						'height'    => 0,
						'weight_oz' => 0,
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_items', $result->get_error_code() );
	}

	// ----------------------------------------------------------------
	// Shipping Box Packer — list_boxes action.
	// ----------------------------------------------------------------

	/**
	 * Test list_boxes returns default box definitions.
	 */
	public function test_list_boxes_defaults() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array( 'action' => 'list_boxes' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThanOrEqual( 6, $result['box_count'] );

		// Each box should have enriched fields.
		$box = $result['boxes'][0];
		$this->assertArrayHasKey( 'volume_cubic_inches', $box );
		$this->assertArrayHasKey( 'volume_cubic_feet', $box );
		$this->assertArrayHasKey( 'cubic_eligible', $box );
		$this->assertArrayHasKey( 'cubic_tier', $box );
	}

	/**
	 * Test list_boxes with custom boxes.
	 */
	public function test_list_boxes_custom() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'list_boxes',
				'boxes'  => array(
					array(
						'reference'    => 'Test Box',
						'outer_length' => 10,
						'outer_width'  => 10,
						'outer_depth'  => 10,
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['box_count'] );
		$this->assertSame( 'Test Box', $result['boxes'][0]['reference'] );
	}

	// ----------------------------------------------------------------
	// Shipping Box Packer — permission checks.
	// ----------------------------------------------------------------

	/**
	 * Test that subscribers cannot use the box packer.
	 */
	public function test_box_packer_permission_denied() {
		// Use map_meta_cap to reliably deny manage_woocommerce.
		add_filter(
			'map_meta_cap',
			function ( $caps, $cap ) {
				if ( 'manage_woocommerce' === $cap ) {
					return array( 'do_not_allow' );
				}
				return $caps;
			},
			10,
			2
		);

		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'list_boxes',
			),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		remove_all_filters( 'map_meta_cap' );
	}

	// ----------------------------------------------------------------
	// Shipping Box Packer — multiple items across multiple boxes.
	// ----------------------------------------------------------------

	/**
	 * Test that items exceeding a single box are split across multiple packages.
	 */
	public function test_pack_items_across_multiple_packages() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array(
				'action' => 'pack_items',
				'items'  => array(
					array(
						'name'      => 'Heavy Item',
						'length'    => 5,
						'width'     => 5,
						'height'    => 5,
						'weight_oz' => 300,
					),
					array(
						'name'      => 'Another Heavy Item',
						'length'    => 5,
						'width'     => 5,
						'height'    => 5,
						'weight_oz' => 300,
					),
				),
				'boxes'  => array(
					array(
						'reference'    => 'Medium Box',
						'outer_length' => 10,
						'outer_width'  => 10,
						'outer_depth'  => 10,
						'inner_length' => 10,
						'inner_width'  => 10,
						'inner_depth'  => 10,
						'empty_weight' => 2,
						'max_weight'   => 20,
						'box_type'     => 'cubic',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		// Each heavy item (300oz = ~18.75 lbs) fits in 20lb box individually.
		// but 600oz total exceeds max_weight so should be 2 packages.
		$this->assertSame( 2, $result['total_packages'] );
	}

	// ----------------------------------------------------------------
	// Shipping Box Packer — invalid action.
	// ----------------------------------------------------------------

	/**
	 * Test that an invalid action returns error.
	 */
	public function test_box_packer_invalid_action() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$result = $tool->execute(
			array( 'action' => 'invalid_action' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_action', $result->get_error_code() );
	}

	// ----------------------------------------------------------------
	// Shipping Rate Estimator — slug, schema, and metadata.
	// ----------------------------------------------------------------

	/**
	 * Test Rate Estimator tool slug.
	 */
	public function test_rate_estimator_slug() {
		$tool = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$this->assertSame( 'shipping_rate_estimator', $tool->get_slug() );
	}

	/**
	 * Test Rate Estimator tool name is not empty.
	 */
	public function test_rate_estimator_name() {
		$tool = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test Rate Estimator tool description is not empty.
	 */
	public function test_rate_estimator_description() {
		$tool = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test Rate Estimator parameters schema is valid.
	 */
	public function test_rate_estimator_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'items', $schema['properties'] );
		$this->assertArrayHasKey( 'ship_to', $schema['properties'] );
		$this->assertArrayHasKey( 'ship_from', $schema['properties'] );
		$this->assertArrayHasKey( 'carrier', $schema['properties'] );
		$this->assertArrayHasKey( 'api_credentials', $schema['properties'] );
		$this->assertContains( 'action', $schema['required'] );
	}

	/**
	 * Test Rate Estimator capability flags include expected values.
	 */
	public function test_rate_estimator_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'network-dependent', $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-plugin', $flags );
		$this->assertNotContains( 'read-only', $flags );
	}

	// ----------------------------------------------------------------
	// Shipping Rate Estimator — validation.
	// ----------------------------------------------------------------

	/**
	 * Test estimate_rates without items returns error.
	 */
	public function test_estimate_rates_missing_items() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array(
				'action'  => 'estimate_rates',
				'ship_to' => array( 'postal_code' => '90210' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_items', $result->get_error_code() );
	}

	/**
	 * Test estimate_rates without ship_to returns error.
	 */
	public function test_estimate_rates_missing_destination() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array(
				'action' => 'estimate_rates',
				'items'  => array(
					array(
						'name'      => 'Widget',
						'length'    => 4,
						'width'     => 4,
						'height'    => 4,
						'weight_oz' => 8,
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_destination', $result->get_error_code() );
	}

	/**
	 * Test estimate_order_rates without order_id returns error.
	 */
	public function test_estimate_order_rates_missing_order_id() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array( 'action' => 'estimate_order_rates' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_order_id', $result->get_error_code() );
	}

	/**
	 * Test Rate Estimator permission denied for subscribers.
	 */
	public function test_rate_estimator_permission_denied() {
		add_filter(
			'map_meta_cap',
			function ( $caps, $cap ) {
				if ( 'manage_woocommerce' === $cap ) {
					return array( 'do_not_allow' );
				}
				return $caps;
			},
			10,
			2
		);

		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array( 'action' => 'test_connection' ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		remove_all_filters( 'map_meta_cap' );
	}

	/**
	 * Test Rate Estimator invalid action returns error.
	 */
	public function test_rate_estimator_invalid_action() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array( 'action' => 'invalid_action' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_action', $result->get_error_code() );
	}

	/**
	 * Test test_connection action with missing ShipEngine credentials.
	 */
	public function test_test_connection_shipengine_missing_credentials() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array(
				'action'  => 'test_connection',
				'carrier' => 'shipengine',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'shipengine', $result['carrier'] );
	}

	/**
	 * Test test_connection action with missing ShipStation credentials.
	 */
	public function test_test_connection_shipstation_missing_credentials() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array(
				'action'  => 'test_connection',
				'carrier' => 'shipstation',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'shipstation', $result['carrier'] );
	}

	// ----------------------------------------------------------------
	// Integration: box packer feeds rate estimator.
	// ----------------------------------------------------------------

	/**
	 * Test that rate estimator packs items before rate-shopping (missing creds = warnings).
	 */
	public function test_estimate_rates_packs_items_then_warns_no_credentials() {
		// We intercept the HTTP request to simulate missing credentials.
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Test: connection refused' );
			}
		);

		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$result = $tool->execute(
			array(
				'action'          => 'estimate_rates',
				'carrier'         => 'shipengine',
				'items'           => array(
					array(
						'name'      => 'Widget',
						'length'    => 4,
						'width'     => 4,
						'height'    => 4,
						'weight_oz' => 8,
					),
				),
				'ship_to'         => array(
					'postal_code' => '90210',
					'state'       => 'CA',
					'city'        => 'Beverly Hills',
				),
				'api_credentials' => array(
					'shipengine_api_key'    => '',
					'shipengine_carrier_id' => '',
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		// Should succeed structurally but with warnings about missing credentials.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'shipengine', $result['carrier'] );
		$this->assertSame( 1, $result['total_packages'] );
		$this->assertNotEmpty( $result['warnings'] );

		remove_all_filters( 'pre_http_request' );
	}

	// ----------------------------------------------------------------
	// Rate Estimator — remote connection credential resolution.
	// ----------------------------------------------------------------

	/**
	 * Test that credentials are resolved from a remote connection when tool args are empty.
	 */
	public function test_credentials_resolve_from_remote_connection() {
		// Only run if remote site manager class exists.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		// Create a ShipEngine remote connection.
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                  => 'Test ShipEngine',
				'url'                   => 'https://api.shipengine.com',
				'connection_type'       => 'shipengine',
				'auth_type'             => 'custom_header',
				'api_key'               => 'test_se_api_key_12345',
				'enabled'               => true,
				'shipengine_carrier_id' => 'se-999999',
			)
		);

		$this->assertIsString( $connection_id, 'save_connection should return a string ID' );

		// Use reflection to test the credential resolution method.
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'resolve_remote_connection_credentials' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $tool, 'shipengine' );

		$this->assertIsArray( $resolved );
		$this->assertNotEmpty( $resolved['api_key'], 'API key should be resolved from remote connection' );
		$this->assertSame( 'se-999999', $resolved['carrier_id'], 'Carrier ID should be resolved from remote connection' );

		// Clean up.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
	}

	/**
	 * Test that credentials fallback to empty when no remote connection exists.
	 */
	public function test_credentials_empty_without_remote_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'resolve_remote_connection_credentials' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $tool, 'shipstation' );

		$this->assertIsArray( $resolved );
		$this->assertEmpty( $resolved['api_key'] );
		$this->assertEmpty( $resolved['api_secret'] );
		$this->assertEmpty( $resolved['carrier_code'] );
	}

	/**
	 * Test that sandbox_mode is propagated from remote connection to credentials.
	 */
	public function test_sandbox_mode_propagated_from_remote_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		// Create a ShipStation connection with sandbox_mode enabled.
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                     => 'Test ShipStation Sandbox',
				'url'                      => 'https://ssapi.shipstation.com',
				'connection_type'          => 'shipstation',
				'auth_type'                => 'basic_auth',
				'username'                 => 'sandbox_test_key',
				'password'                 => 'sandbox_test_secret',
				'api_key'                  => 'sandbox_test_key',
				'api_secret'               => 'sandbox_test_secret',
				'enabled'                  => true,
				'sandbox_mode'             => true,
				'shipstation_carrier_code' => 'stamps_com',
			)
		);

		$this->assertIsString( $connection_id, 'save_connection should return a string ID' );

		// Verify sandbox_mode is resolved via credential resolution.
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'resolve_remote_connection_credentials' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $tool, 'shipstation' );

		$this->assertIsArray( $resolved );
		$this->assertTrue( $resolved['sandbox_mode'], 'sandbox_mode should be true when connection has it enabled' );
		$this->assertNotEmpty( $resolved['api_key'], 'API key should be resolved' );
		$this->assertNotEmpty( $resolved['api_secret'], 'API secret should be resolved' );
		$this->assertSame( 'stamps_com', $resolved['carrier_code'], 'Carrier code should be resolved' );

		// Also verify it propagates through get_credentials.
		$creds_method = new ReflectionMethod( $tool, 'get_credentials' );
		$creds_method->setAccessible( true );

		$creds = $creds_method->invoke( $tool, array(), 'shipstation' );

		$this->assertTrue( $creds['sandbox_mode'], 'sandbox_mode should propagate to get_credentials result' );

		// Clean up.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
	}

	/**
	 * Test that sandbox_mode defaults to false when not set on connection.
	 */
	public function test_sandbox_mode_defaults_to_false() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		// Create a ShipStation connection without sandbox_mode.
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Test ShipStation Production',
				'url'             => 'https://ssapi.shipstation.com',
				'connection_type' => 'shipstation',
				'auth_type'       => 'basic_auth',
				'username'        => 'prod_test_key',
				'password'        => 'prod_test_secret',
				'api_key'         => 'prod_test_key',
				'api_secret'      => 'prod_test_secret',
				'enabled'         => true,
			)
		);

		$this->assertIsString( $connection_id );

		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'resolve_remote_connection_credentials' );
		$method->setAccessible( true );

		$resolved = $method->invoke( $tool, 'shipstation' );

		$this->assertFalse( $resolved['sandbox_mode'], 'sandbox_mode should default to false' );

		// Clean up.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
	}

	/**
	 * Test that ShipEngine sandbox mode is auto-detected from TEST_ key prefix.
	 */
	public function test_shipengine_sandbox_auto_detected_from_test_prefix() {
		$tool         = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$creds_method = new ReflectionMethod( $tool, 'get_credentials' );
		$creds_method->setAccessible( true );

		// Pass a TEST_ prefixed API key via tool arguments.
		$arguments = array(
			'api_credentials' => array(
				'shipengine_api_key'    => 'TEST_abc123sandbox',
				'shipengine_carrier_id' => 'se-111111',
			),
		);

		$creds = $creds_method->invoke( $tool, $arguments, 'shipengine' );

		$this->assertTrue( $creds['sandbox_mode'], 'sandbox_mode should be auto-detected from TEST_ prefixed API key' );
		$this->assertSame( 'TEST_abc123sandbox', $creds['shipengine_api_key'] );
	}

	/**
	 * Test that ShipEngine sandbox mode is NOT auto-detected from production keys.
	 */
	public function test_shipengine_sandbox_not_detected_from_production_key() {
		$tool         = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$creds_method = new ReflectionMethod( $tool, 'get_credentials' );
		$creds_method->setAccessible( true );

		// Pass a production API key (no TEST_ prefix).
		$arguments = array(
			'api_credentials' => array(
				'shipengine_api_key'    => 'dx_prod_real_key_99',
				'shipengine_carrier_id' => 'se-222222',
			),
		);

		$creds = $creds_method->invoke( $tool, $arguments, 'shipengine' );

		$this->assertFalse( $creds['sandbox_mode'], 'sandbox_mode should be false for non-TEST_ keys' );
	}

	/**
	 * Test User-Agent header is generated with plugin version and WordPress version.
	 */
	public function test_user_agent_contains_plugin_and_wp_version() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'get_user_agent' );
		$method->setAccessible( true );

		$user_agent = $method->invoke( $tool );

		$this->assertStringContainsString( 'NV-oOS/', $user_agent, 'User-Agent should start with NV-oOS/' );
		$this->assertStringContainsString( 'WordPress/', $user_agent, 'User-Agent should contain WordPress version' );
	}

	/**
	 * Test parse_api_error extracts ShipEngine structured errors.
	 */
	public function test_parse_api_error_shipengine_structured() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'parse_api_error' );
		$method->setAccessible( true );

		// ShipEngine structured error.
		$body = array(
			'errors' => array(
				array( 'message' => 'Invalid carrier ID' ),
				array( 'message' => 'Rate limit exceeded' ),
			),
		);

		$result = $method->invoke( $tool, $body, 'shipengine', 400 );
		$this->assertSame( 'Invalid carrier ID; Rate limit exceeded', $result );
	}

	/**
	 * Test parse_api_error extracts ShipStation V1 error messages.
	 */
	public function test_parse_api_error_shipstation_message() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'parse_api_error' );
		$method->setAccessible( true );

		// ShipStation V1 error format.
		$body = array( 'Message' => 'Unauthorized access' );

		$result = $method->invoke( $tool, $body, 'shipstation', 401 );
		$this->assertSame( 'Unauthorized access', $result );
	}

	/**
	 * Test parse_api_error falls back to HTTP status code.
	 */
	public function test_parse_api_error_fallback() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'parse_api_error' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, null, 'shipengine', 500 );
		$this->assertStringContainsString( '500', $result );
	}

	/**
	 * Test request_with_retry method exists and is callable.
	 */
	public function test_request_with_retry_is_callable() {
		$tool   = new WP_MCP_AI_Tool_Shipping_Rate_Estimator();
		$method = new ReflectionMethod( $tool, 'request_with_retry' );
		$method->setAccessible( true );

		$this->assertTrue( $method->isProtected(), 'request_with_retry should be a protected method' );
		$this->assertSame( 3, $method->getNumberOfParameters(), 'request_with_retry should accept 3 parameters (url, args, retries)' );
	}
}
