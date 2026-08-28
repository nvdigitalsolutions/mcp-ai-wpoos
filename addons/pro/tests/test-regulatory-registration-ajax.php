<?php
/**
 * Test Regulatory Registration Toolkit AJAX/REST Integration
 *
 * Verifies that the regulatory registration toolkit tools work correctly
 * via AJAX/REST API endpoints with proper authentication and validation.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Regulatory Registration AJAX/REST functionality
 */
class Test_Regulatory_Registration_AJAX extends WP_Ajax_UnitTestCase {

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Assistant ID used as the tool-execution context.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Product ID for testing
	 *
	 * @var int
	 */
	private $test_product_id;

	/**
	 * Registration ID for testing
	 *
	 * @var int
	 */
	private $test_registration_id;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable regulatory registration toolkit.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_regulatory_registration_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// The bootstrap fires wp_mcp_ai_register_tools before this test can
		// enable the toolkit option, leaving the regulatory tools gated out
		// of the registry (is_available() checks the option). Re-fire the
		// action so the tools register before the REST requests below.
		do_action( 'wp_mcp_ai_register_tools', WP_MCP_AI_Tool_Registry::get_instance() );

		// A preceding suite may have replaced the global REST controller with
		// an instance wired to a mocked tool registry and left its
		// register_routes callback attached to rest_api_init. The shared REST
		// server's /mcp-ai/v1/tools route then points at that controller,
		// whose get_tool() returns null for every slug, so every request
		// fails with a 404 wp_mcp_ai_tool_missing error. Rebuild a controller
		// bound to the real registry and re-fire rest_api_init so this suite's
		// requests always dispatch through it.
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'clean_output_buffer' ), 1 );
		}

		$rest_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$mock_client   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $rest_registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create an assistant that is allowed to run every regulatory tool
		// exercised below. /mcp-ai/v1/tools requires an assistant_id and
		// checks the assistant's allowed-tools list.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Regulatory REST Test Assistant',
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$this->assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOLS,
			array(
				'create_reg_product',
				'list_reg_products',
				'get_reg_product',
				'update_reg_product',
				'search_reg_products',
				'validate_reg_product',
				'delete_reg_product',
				'create_registration',
				'list_registrations',
				'get_registration',
				'update_registration_status',
				'get_registration_timeline',
				'validate_inci_ingredients',
				'check_hs_code',
				'check_product_compliance',
			)
		);

		// Create test product.
		$this->test_product_id = wp_insert_post(
			array(
				'post_title'  => 'Test Product',
				'post_type'   => 'mcp_ai_reg_product',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->test_product_id, 'brand', 'Test Brand' );
		update_post_meta( $this->test_product_id, 'manufacturer', 'Test Manufacturer' );
		update_post_meta( $this->test_product_id, 'origin_country', 'LK' );
		update_post_meta( $this->test_product_id, 'inci_ingredients', 'Aqua, Glycerin' );
		update_post_meta( $this->test_product_id, 'hs_code', '3304.99.00' );

		// Create test registration.
		$this->test_registration_id = wp_insert_post(
			array(
				'post_title'  => 'Test Registration',
				'post_type'   => 'mcp_ai_registration',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->test_registration_id, 'product_id', $this->test_product_id );
		update_post_meta( $this->test_registration_id, 'country', 'LK' );
		update_post_meta( $this->test_registration_id, 'authority', 'NMRA' );
	}

	/**
	 * Build a POST /mcp-ai/v1/tools request bound to the test assistant.
	 *
	 * @param string $tool      Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @return WP_REST_Request
	 */
	private function make_request( $tool, array $arguments = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', $tool );
		$request->set_param( 'arguments', $arguments );
		return $request;
	}

	/**
	 * Test create_reg_product tool via REST API
	 */
	public function test_create_reg_product_via_rest() {
		$request = $this->make_request(
			'create_reg_product',
			array(
				'product_name'      => 'New Test Product',
				'brand'            => 'New Brand',
				'manufacturer'     => 'New Manufacturer',
				'origin_country'   => 'AE',
				'inci_ingredients' => 'Aqua, Glycerin, Parfum',
				'hs_code'          => '3303.00.00',
			)
		);

		// Execute REST request.
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Verify response.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'product_id', $data['result'] );
		$this->assertGreaterThan( 0, $data['result']['product_id'] );

		// Verify product was created.
		$product = get_post( $data['result']['product_id'] );
		$this->assertEquals( 'mcp_ai_reg_product', $product->post_type );
		$this->assertEquals( 'New Test Product', $product->post_title );

		// Clean up the product created by this test.
		wp_delete_post( $data['result']['product_id'], true );
	}

	/**
	 * Test list_reg_products tool via REST API
	 */
	public function test_list_reg_products_via_rest() {
		$request = $this->make_request(
			'list_reg_products',
			array(
				'per_page' => 10,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'products', $data['result'] );
		$this->assertIsArray( $data['result']['products'] );
		$this->assertGreaterThan( 0, count( $data['result']['products'] ) );
	}

	/**
	 * Test get_reg_product tool via REST API
	 */
	public function test_get_reg_product_via_rest() {
		$request = $this->make_request(
			'get_reg_product',
			array(
				'product_id' => $this->test_product_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'product', $data['result'] );
		$this->assertEquals( $this->test_product_id, $data['result']['product']['id'] );
		$this->assertEquals( 'Test Brand', $data['result']['product']['brand'] );
	}

	/**
	 * Test update_reg_product tool via REST API
	 */
	public function test_update_reg_product_via_rest() {
		$request = $this->make_request(
			'update_reg_product',
			array(
				'product_id'   => $this->test_product_id,
				'manufacturer' => 'Updated Manufacturer',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );

		// Verify update.
		$updated_manufacturer = get_post_meta( $this->test_product_id, 'manufacturer', true );
		$this->assertEquals( 'Updated Manufacturer', $updated_manufacturer );
	}

	/**
	 * Test search_reg_products tool via REST API
	 */
	public function test_search_reg_products_via_rest() {
		$request = $this->make_request(
			'search_reg_products',
			array(
				'manufacturer' => 'Test Manufacturer',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'products', $data['result'] );
		$this->assertGreaterThan( 0, count( $data['result']['products'] ) );
	}

	/**
	 * Test validate_reg_product tool via REST API
	 */
	public function test_validate_reg_product_via_rest() {
		$request = $this->make_request(
			'validate_reg_product',
			array(
				'product_id' => $this->test_product_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'is_valid', $data['result'] );
		$this->assertArrayHasKey( 'validation_results', $data['result'] );
	}

	/**
	 * Test create_registration tool via REST API
	 */
	public function test_create_registration_via_rest() {
		$request = $this->make_request(
			'create_registration',
			array(
				'product_id'        => $this->test_product_id,
				'country'           => 'AE',
				'authority'         => 'MOHAP',
				'registration_type' => 'new',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'registration_id', $data['result'] );

		// Verify registration was created.
		$registration = get_post( $data['result']['registration_id'] );
		$this->assertEquals( 'mcp_ai_registration', $registration->post_type );

		// Clean up the registration created by this test.
		wp_delete_post( $data['result']['registration_id'], true );
	}

	/**
	 * Test list_registrations tool via REST API
	 */
	public function test_list_registrations_via_rest() {
		$request = $this->make_request(
			'list_registrations',
			array(
				'per_page' => 10,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'registrations', $data['result'] );
		$this->assertIsArray( $data['result']['registrations'] );
	}

	/**
	 * Test get_registration tool via REST API
	 */
	public function test_get_registration_via_rest() {
		$request = $this->make_request(
			'get_registration',
			array(
				'registration_id' => $this->test_registration_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'registration', $data['result'] );
		$this->assertEquals( $this->test_registration_id, $data['result']['registration']['id'] );
	}

	/**
	 * Test update_registration_status tool via REST API
	 */
	public function test_update_registration_status_via_rest() {
		$request = $this->make_request(
			'update_registration_status',
			array(
				'registration_id' => $this->test_registration_id,
				'status'          => 'Submitted',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'registration', $data['result'] );
		$this->assertEquals( 'Submitted', $data['result']['registration']['status'] );
	}

	/**
	 * Test get_registration_timeline tool via REST API
	 */
	public function test_get_registration_timeline_via_rest() {
		$request = $this->make_request(
			'get_registration_timeline',
			array(
				'registration_id' => $this->test_registration_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'milestones', $data['result'] );
		$this->assertArrayHasKey( 'progress', $data['result'] );
		$this->assertArrayHasKey( 'expected_timeline', $data['result'] );
	}

	/**
	 * Test validate_inci_ingredients tool via REST API
	 */
	public function test_validate_inci_ingredients_via_rest() {
		$request = $this->make_request(
			'validate_inci_ingredients',
			array(
				'ingredients' => 'Aqua, Glycerin, Tocopherol',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'is_valid', $data['result'] );
		$this->assertArrayHasKey( 'ingredients', $data['result'] );
		$this->assertArrayHasKey( 'validation_score', $data['result'] );
	}

	/**
	 * Test check_hs_code tool via REST API
	 */
	public function test_check_hs_code_via_rest() {
		$request = $this->make_request(
			'check_hs_code',
			array(
				'hs_code' => '3304.99.00',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'is_valid', $data['result'] );
		$this->assertArrayHasKey( 'hs_info', $data['result'] );
	}

	/**
	 * Test check_product_compliance tool via REST API
	 */
	public function test_check_product_compliance_via_rest() {
		$request = $this->make_request(
			'check_product_compliance',
			array(
				'product_id' => $this->test_product_id,
				'country'    => 'LK',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['result']['success'] );
		$this->assertArrayHasKey( 'is_compliant', $data['result'] );
		$this->assertArrayHasKey( 'compliance_score', $data['result'] );
	}

	/**
	 * Test tool requires authentication
	 */
	public function test_tool_requires_authentication() {
		// Log out user.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'tool', 'list_reg_products' );
		$request->set_param( 'arguments', array() );

		$response = rest_do_request( $request );

		// Should be unauthorized.
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test tool requires valid nonce
	 */
	public function test_tool_requires_valid_nonce() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce' );
		$request->set_param( 'tool', 'list_reg_products' );
		$request->set_param( 'arguments', array() );

		$response = rest_do_request( $request );

		// Should fail due to invalid nonce.
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test tool with invalid tool name
	 */
	public function test_invalid_tool_name() {
		$request = $this->make_request( 'nonexistent_tool' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// An unknown tool slug is not in the assistant's allowlist, so the
		// handler rejects it as forbidden for this assistant.
		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( 'wp_mcp_ai_tool_forbidden', $data['code'] );
	}

	/**
	 * Test tool with missing required arguments
	 */
	public function test_missing_required_arguments() {
		$request = $this->make_request( 'get_reg_product', array() );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// The tool rejects the call for the missing product_id argument.
		$this->assertNotEquals( 200, $response->get_status() );
		$this->assertEquals( 'wp_mcp_ai_missing_param', $data['code'] );
	}

	/**
	 * Test delete_reg_product tool requires edit_posts capability
	 */
	public function test_delete_requires_edit_posts_capability() {
		// Create subscriber user (no edit_posts capability).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = $this->make_request(
			'delete_reg_product',
			array(
				'product_id' => $this->test_product_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// The REST handler's permission layer rejects the caller before the
		// tool's own capability gate runs.
		$this->assertNotEquals( 200, $response->get_status() );
		$this->assertEquals( 'wp_mcp_ai_insufficient_permissions', $data['code'] );
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Clean up test data.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}
		if ( $this->test_product_id ) {
			wp_delete_post( $this->test_product_id, true );
		}
		if ( $this->test_registration_id ) {
			wp_delete_post( $this->test_registration_id, true );
		}

		parent::tearDown();
	}
}
