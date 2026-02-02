<?php
/**
 * Test Regulatory Registration Toolkit Agentic Workflow Integration
 *
 * Verifies that the regulatory registration toolkit tools work correctly
 * in agentic chat workflows with multi-step tool execution.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Regulatory Registration Agentic Workflow
 */
class Test_Regulatory_Registration_Agentic_Workflow extends WP_UnitTestCase {

	/**
	 * Test assistant ID
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Test product ID
	 *
	 * @var int
	 */
	private $test_product_id;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable regulatory registration toolkit.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_regulatory_registration_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Regulatory Specialist Assistant',
			)
		);

		// Create admin user.
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// Initialize tool registry.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();

		// Create test product.
		$this->test_product_id = wp_insert_post(
			array(
				'post_title'  => 'Test Cosmetic Product',
				'post_type'   => 'mcp_ai_reg_product',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->test_product_id, 'brand', 'Test Brand' );
		update_post_meta( $this->test_product_id, 'manufacturer', 'Test Manufacturer' );
		update_post_meta( $this->test_product_id, 'origin_country', 'LK' );
		update_post_meta( $this->test_product_id, 'inci_ingredients', 'Aqua, Glycerin, Parfum' );
		update_post_meta( $this->test_product_id, 'hs_code', '3304.99.00' );
	}

	/**
	 * Test that regulatory tools are available in tool registry
	 */
	public function test_regulatory_tools_available_in_registry() {
		$expected_tools = array(
			'create_reg_product',
			'list_reg_products',
			'get_reg_product',
			'update_reg_product',
			'delete_reg_product',
			'search_reg_products',
			'duplicate_reg_product',
			'validate_reg_product',
			'create_registration',
			'list_registrations',
			'get_registration',
			'update_registration_status',
			'list_expiring_registrations',
			'submit_registration',
			'approve_registration',
			'renew_registration',
			'get_registration_timeline',
			'list_registrations_by_country',
			'list_reg_documents',
			'check_document_expiry',
			'upload_reg_document',
			'update_reg_document',
			'get_reg_document',
			'validate_document_checklist',
			'generate_submission_pack',
			'track_document_version',
			'add_regulatory_requirement',
			'get_regulatory_requirements',
			'check_product_compliance',
			'validate_inci_ingredients',
			'check_hs_code',
			'get_regulatory_updates',
		);

		foreach ( $expected_tools as $tool_slug ) {
			$tool = $this->registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool {$tool_slug} should be available in registry" );

			// Verify tool is available.
			$this->assertTrue( $tool->is_available(), "Tool {$tool_slug} should be available when toolkit is enabled" );
		}
	}

	/**
	 * Test agentic workflow: Create and validate product
	 */
	public function test_agentic_workflow_create_and_validate_product() {
		// Simulate agentic workflow steps.

		// Step 1: Create product.
		$create_tool = $this->registry->get_tool( 'create_reg_product' );
		$this->assertNotNull( $create_tool );

		$create_result = $create_tool->execute(
			array(
				'title'            => 'Face Cream',
				'brand'            => 'Beauty Co',
				'manufacturer'     => 'Cosmetics Ltd',
				'origin_country'   => 'LK',
				'inci_ingredients' => 'Aqua, Glycerin, Tocopherol',
				'hs_code'          => '3304.99.00',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $create_result['success'] );
		$this->assertArrayHasKey( 'product_id', $create_result );
		$product_id = $create_result['product_id'];

		// Step 2: Validate product (agentic loop continues).
		$validate_tool = $this->registry->get_tool( 'validate_reg_product' );
		$this->assertNotNull( $validate_tool );

		$validate_result = $validate_tool->execute(
			array(
				'product_id' => $product_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $validate_result['success'] );
		$this->assertArrayHasKey( 'is_valid', $validate_result );
		$this->assertArrayHasKey( 'validation_results', $validate_result );

		// Cleanup.
		wp_delete_post( $product_id, true );
	}

	/**
	 * Test agentic workflow: Search, get, and update product
	 */
	public function test_agentic_workflow_search_get_update_product() {
		// Step 1: Search for products.
		$search_tool = $this->registry->get_tool( 'search_reg_products' );
		$this->assertNotNull( $search_tool );

		$search_result = $search_tool->execute(
			array(
				'brand' => 'Test Brand',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $search_result['success'] );
		$this->assertArrayHasKey( 'products', $search_result );
		$this->assertGreaterThan( 0, count( $search_result['products'] ) );

		$found_product_id = $search_result['products'][0]['product_id'];

		// Step 2: Get detailed product info.
		$get_tool = $this->registry->get_tool( 'get_reg_product' );
		$this->assertNotNull( $get_tool );

		$get_result = $get_tool->execute(
			array(
				'product_id' => $found_product_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $get_result['success'] );
		$this->assertArrayHasKey( 'product', $get_result );

		// Step 3: Update product.
		$update_tool = $this->registry->get_tool( 'update_reg_product' );
		$this->assertNotNull( $update_tool );

		$update_result = $update_tool->execute(
			array(
				'product_id'   => $found_product_id,
				'manufacturer' => 'Updated Manufacturer Co.',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $update_result['success'] );
		$this->assertArrayHasKey( 'updated_fields', $update_result );
	}

	/**
	 * Test agentic workflow: Registration lifecycle
	 */
	public function test_agentic_workflow_registration_lifecycle() {
		// Step 1: Create registration.
		$create_reg_tool = $this->registry->get_tool( 'create_registration' );
		$this->assertNotNull( $create_reg_tool );

		$create_reg_result = $create_reg_tool->execute(
			array(
				'product_id'        => $this->test_product_id,
				'country'           => 'LK',
				'authority'         => 'NMRA',
				'registration_type' => 'new',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $create_reg_result['success'] );
		$registration_id = $create_reg_result['registration_id'];

		// Step 2: Get registration timeline.
		$timeline_tool = $this->registry->get_tool( 'get_registration_timeline' );
		$this->assertNotNull( $timeline_tool );

		$timeline_result = $timeline_tool->execute(
			array(
				'registration_id' => $registration_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $timeline_result['success'] );
		$this->assertArrayHasKey( 'milestones', $timeline_result );
		$this->assertArrayHasKey( 'expected_timeline', $timeline_result );

		// Step 3: Submit registration.
		$submit_tool = $this->registry->get_tool( 'submit_registration' );
		$this->assertNotNull( $submit_tool );

		$submit_result = $submit_tool->execute(
			array(
				'registration_id' => $registration_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $submit_result['success'] );
		$this->assertEquals( 'Submitted', $submit_result['new_status'] );
		$this->assertArrayHasKey( 'submission_date', $submit_result );

		// Step 4: Approve registration.
		$approve_tool = $this->registry->get_tool( 'approve_registration' );
		$this->assertNotNull( $approve_tool );

		$approve_result = $approve_tool->execute(
			array(
				'registration_id' => $registration_id,
				'cos_number'      => 'COS-2024-001',
				'approval_date'   => gmdate( 'Y-m-d' ),
				'expiry_date'     => gmdate( 'Y-m-d', strtotime( '+3 years' ) ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $approve_result['success'] );
		$this->assertEquals( 'Approved', $approve_result['new_status'] );

		// Cleanup.
		wp_delete_post( $registration_id, true );
	}

	/**
	 * Test agentic workflow: Compliance checking
	 */
	public function test_agentic_workflow_compliance_checking() {
		// Step 1: Validate INCI ingredients.
		$inci_tool = $this->registry->get_tool( 'validate_inci_ingredients' );
		$this->assertNotNull( $inci_tool );

		$inci_result = $inci_tool->execute(
			array(
				'ingredients' => 'Aqua, Glycerin, Tocopherol',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $inci_result['success'] );
		$this->assertArrayHasKey( 'is_valid', $inci_result );
		$this->assertArrayHasKey( 'ingredients', $inci_result );

		// Step 2: Check HS code.
		$hs_tool = $this->registry->get_tool( 'check_hs_code' );
		$this->assertNotNull( $hs_tool );

		$hs_result = $hs_tool->execute(
			array(
				'hs_code' => '3304.99.00',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $hs_result['success'] );
		$this->assertArrayHasKey( 'is_valid', $hs_result );

		// Step 3: Check overall product compliance.
		$compliance_tool = $this->registry->get_tool( 'check_product_compliance' );
		$this->assertNotNull( $compliance_tool );

		$compliance_result = $compliance_tool->execute(
			array(
				'product_id' => $this->test_product_id,
				'country'    => 'LK',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $compliance_result['success'] );
		$this->assertArrayHasKey( 'is_compliant', $compliance_result );
		$this->assertArrayHasKey( 'compliance_score', $compliance_result );
	}

	/**
	 * Test agentic workflow: Document management
	 */
	public function test_agentic_workflow_document_management() {
		// Create test registration first.
		$registration_id = wp_insert_post(
			array(
				'post_title'  => 'Test Registration',
				'post_type'   => 'mcp_ai_registration',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $registration_id, 'product_id', $this->test_product_id );
		update_post_meta( $registration_id, 'country', 'LK' );

		// Step 1: Validate document checklist.
		$checklist_tool = $this->registry->get_tool( 'validate_document_checklist' );
		$this->assertNotNull( $checklist_tool );

		$checklist_result = $checklist_tool->execute(
			array(
				'registration_id' => $registration_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $checklist_result['success'] );
		$this->assertArrayHasKey( 'is_compliant', $checklist_result );
		$this->assertArrayHasKey( 'missing_documents', $checklist_result );

		// Step 2: List documents.
		$list_docs_tool = $this->registry->get_tool( 'list_reg_documents' );
		$this->assertNotNull( $list_docs_tool );

		$list_docs_result = $list_docs_tool->execute(
			array(
				'registration_id' => $registration_id,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $list_docs_result['success'] );
		$this->assertArrayHasKey( 'documents', $list_docs_result );

		// Cleanup.
		wp_delete_post( $registration_id, true );
	}

	/**
	 * Test agentic workflow: Multi-country registration
	 */
	public function test_agentic_workflow_multi_country_registration() {
		// Create registrations for multiple countries.
		$countries        = array( 'LK', 'AE', 'SA' );
		$registration_ids = array();

		$create_reg_tool = $this->registry->get_tool( 'create_registration' );

		foreach ( $countries as $country ) {
			$result = $create_reg_tool->execute(
				array(
					'product_id'        => $this->test_product_id,
					'country'           => $country,
					'authority'         => 'Test Authority',
					'registration_type' => 'new',
				),
				array( 'user_id' => $this->user_id )
			);

			$this->assertTrue( $result['success'] );
			$registration_ids[] = $result['registration_id'];
		}

		// List registrations by country.
		$by_country_tool = $this->registry->get_tool( 'list_registrations_by_country' );
		$this->assertNotNull( $by_country_tool );

		$by_country_result = $by_country_tool->execute(
			array(
				'include_stats' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $by_country_result['success'] );
		$this->assertArrayHasKey( 'countries', $by_country_result );
		$this->assertGreaterThanOrEqual( 3, $by_country_result['total_countries'] );

		// Cleanup.
		foreach ( $registration_ids as $reg_id ) {
			wp_delete_post( $reg_id, true );
		}
	}

	/**
	 * Test that tools return proper structure for agentic loop
	 */
	public function test_tools_return_proper_structure_for_agentic_loop() {
		$tools_to_test = array(
			'list_reg_products'         => array(),
			'get_reg_product'           => array( 'product_id' => $this->test_product_id ),
			'validate_reg_product'      => array( 'product_id' => $this->test_product_id ),
			'validate_inci_ingredients' => array( 'ingredients' => 'Aqua, Glycerin' ),
			'check_hs_code'             => array( 'hs_code' => '3304.99.00' ),
		);

		foreach ( $tools_to_test as $tool_slug => $args ) {
			$tool = $this->registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool {$tool_slug} should exist" );

			$result = $tool->execute( $args, array( 'user_id' => $this->user_id ) );

			// Verify standard response structure.
			$this->assertIsArray( $result, "Tool {$tool_slug} should return array" );
			$this->assertArrayHasKey( 'success', $result, "Tool {$tool_slug} should have success key" );

			// Success responses should have data.
			if ( $result['success'] ) {
				$this->assertGreaterThan( 1, count( $result ), "Tool {$tool_slug} should return data on success" );
			} else {
				// Failed responses should have error.
				$this->assertArrayHasKey( 'error', $result, "Tool {$tool_slug} should have error key on failure" );
			}
		}
	}

	/**
	 * Test tool capability flags for agentic workflow filtering
	 */
	public function test_tool_capability_flags_for_agentic_filtering() {
		// Get a destructive tool.
		$delete_tool = $this->registry->get_tool( 'delete_reg_product' );
		$this->assertNotNull( $delete_tool );

		$flags = $delete_tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'destructive', $flags, 'Delete tool should have destructive flag' );

		// Get a read-only tool.
		$list_tool = $this->registry->get_tool( 'list_reg_products' );
		$this->assertNotNull( $list_tool );

		$list_flags = $list_tool->get_capability_flags();
		$this->assertIsArray( $list_flags );
		$this->assertContains( 'read-only', $list_flags, 'List tool should have read-only flag' );
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Clean up test data.
		if ( $this->test_product_id ) {
			wp_delete_post( $this->test_product_id, true );
		}

		parent::tearDown();
	}
}
