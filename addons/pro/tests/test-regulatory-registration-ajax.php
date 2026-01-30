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

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

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
	 * Test create_reg_product tool via REST API
	 */
	public function test_create_reg_product_via_rest() {
		// Set up REST request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'create_reg_product' );
		$request->set_param(
			'arguments',
			array(
				'title'              => 'New Test Product',
				'brand'              => 'New Brand',
				'manufacturer'       => 'New Manufacturer',
				'origin_country'     => 'AE',
				'inci_ingredients'   => 'Aqua, Glycerin, Parfum',
				'hs_code'            => '3303.00.00',
			)
		);

		// Execute REST request.
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Verify response.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'product_id', $data );
		$this->assertGreaterThan( 0, $data['product_id'] );

		// Verify product was created.
		$product = get_post( $data['product_id'] );
		$this->assertEquals( 'mcp_ai_reg_product', $product->post_type );
		$this->assertEquals( 'New Test Product', $product->post_title );
	}

	/**
	 * Test list_reg_products tool via REST API
	 */
	public function test_list_reg_products_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'list_reg_products' );
		$request->set_param(
			'arguments',
			array(
				'per_page' => 10,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'products', $data );
		$this->assertIsArray( $data['products'] );
		$this->assertGreaterThan( 0, count( $data['products'] ) );
	}

	/**
	 * Test get_reg_product tool via REST API
	 */
	public function test_get_reg_product_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'get_reg_product' );
		$request->set_param(
			'arguments',
			array(
				'product_id' => $this->test_product_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'product', $data );
		$this->assertEquals( $this->test_product_id, $data['product']['product_id'] );
		$this->assertEquals( 'Test Brand', $data['product']['brand'] );
	}

	/**
	 * Test update_reg_product tool via REST API
	 */
	public function test_update_reg_product_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'update_reg_product' );
		$request->set_param(
			'arguments',
			array(
				'product_id'   => $this->test_product_id,
				'manufacturer' => 'Updated Manufacturer',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify update.
		$updated_manufacturer = get_post_meta( $this->test_product_id, 'manufacturer', true );
		$this->assertEquals( 'Updated Manufacturer', $updated_manufacturer );
	}

	/**
	 * Test search_reg_products tool via REST API
	 */
	public function test_search_reg_products_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'search_reg_products' );
		$request->set_param(
			'arguments',
			array(
				'brand' => 'Test Brand',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'products', $data );
		$this->assertGreaterThan( 0, count( $data['products'] ) );
	}

	/**
	 * Test validate_reg_product tool via REST API
	 */
	public function test_validate_reg_product_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'validate_reg_product' );
		$request->set_param(
			'arguments',
			array(
				'product_id' => $this->test_product_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'is_valid', $data );
		$this->assertArrayHasKey( 'validation_results', $data );
	}

	/**
	 * Test create_registration tool via REST API
	 */
	public function test_create_registration_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'create_registration' );
		$request->set_param(
			'arguments',
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
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'registration_id', $data );

		// Verify registration was created.
		$registration = get_post( $data['registration_id'] );
		$this->assertEquals( 'mcp_ai_registration', $registration->post_type );
	}

	/**
	 * Test list_registrations tool via REST API
	 */
	public function test_list_registrations_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'list_registrations' );
		$request->set_param(
			'arguments',
			array(
				'per_page' => 10,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'registrations', $data );
		$this->assertIsArray( $data['registrations'] );
	}

	/**
	 * Test get_registration tool via REST API
	 */
	public function test_get_registration_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'get_registration' );
		$request->set_param(
			'arguments',
			array(
				'registration_id' => $this->test_registration_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'registration', $data );
		$this->assertEquals( $this->test_registration_id, $data['registration']['registration_id'] );
	}

	/**
	 * Test update_registration_status tool via REST API
	 */
	public function test_update_registration_status_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'update_registration_status' );
		$request->set_param(
			'arguments',
			array(
				'registration_id' => $this->test_registration_id,
				'status'          => 'Submitted',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Submitted', $data['new_status'] );
	}

	/**
	 * Test get_registration_timeline tool via REST API
	 */
	public function test_get_registration_timeline_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'get_registration_timeline' );
		$request->set_param(
			'arguments',
			array(
				'registration_id' => $this->test_registration_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'milestones', $data );
		$this->assertArrayHasKey( 'progress', $data );
		$this->assertArrayHasKey( 'expected_timeline', $data );
	}

	/**
	 * Test validate_inci_ingredients tool via REST API
	 */
	public function test_validate_inci_ingredients_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'validate_inci_ingredients' );
		$request->set_param(
			'arguments',
			array(
				'ingredients' => 'Aqua, Glycerin, Tocopherol',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'is_valid', $data );
		$this->assertArrayHasKey( 'ingredients', $data );
		$this->assertArrayHasKey( 'validation_score', $data );
	}

	/**
	 * Test check_hs_code tool via REST API
	 */
	public function test_check_hs_code_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'check_hs_code' );
		$request->set_param(
			'arguments',
			array(
				'hs_code' => '3304.99.00',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'is_valid', $data );
		$this->assertArrayHasKey( 'hs_info', $data );
	}

	/**
	 * Test check_product_compliance tool via REST API
	 */
	public function test_check_product_compliance_via_rest() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'check_product_compliance' );
		$request->set_param(
			'arguments',
			array(
				'product_id' => $this->test_product_id,
				'country'    => 'LK',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'is_compliant', $data );
		$this->assertArrayHasKey( 'compliance_score', $data );
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
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'nonexistent_tool' );
		$request->set_param( 'arguments', array() );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Should return error for invalid tool.
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test tool with missing required arguments
	 */
	public function test_missing_required_arguments() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'get_reg_product' );
		$request->set_param( 'arguments', array() ); // Missing product_id.

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Should return error for missing argument.
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test delete_reg_product tool requires destructive capability
	 */
	public function test_delete_requires_destructive_capability() {
		// Create editor user (no destructive operations).
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'tool', 'delete_reg_product' );
		$request->set_param(
			'arguments',
			array(
				'product_id' => $this->test_product_id,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Should fail due to missing capability.
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Clean up test data.
		if ( $this->test_product_id ) {
			wp_delete_post( $this->test_product_id, true );
		}
		if ( $this->test_registration_id ) {
			wp_delete_post( $this->test_registration_id, true );
		}

		parent::tearDown();
	}
}
