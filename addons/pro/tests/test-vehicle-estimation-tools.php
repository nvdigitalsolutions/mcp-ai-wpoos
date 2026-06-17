<?php
/**
 * Tests for Vehicle Estimation Tools (VIN Decode + Vehicle Repair Estimate + Vehicle Cleaning Estimate).
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 */
class Test_Vehicle_Estimation_Tools extends WP_UnitTestCase {

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Ensure tool classes are loaded.
		$vin_path      = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/automotive/class-wp-mcp-ai-tool-vin-decode.php'
			: dirname( __DIR__ ) . '/includes/tools/automotive/class-wp-mcp-ai-tool-vin-decode.php';
		$estimate_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/automotive/class-wp-mcp-ai-tool-vehicle-repair-estimate.php'
			: dirname( __DIR__ ) . '/includes/tools/automotive/class-wp-mcp-ai-tool-vehicle-repair-estimate.php';

		if ( file_exists( $vin_path ) ) {
			require_once $vin_path;
		}
		if ( file_exists( $estimate_path ) ) {
			require_once $estimate_path;
		}

		$cleaning_path = defined( 'WP_MCP_AI_PRO_PATH' )
					? WP_MCP_AI_PRO_PATH . 'includes/tools/automotive/class-wp-mcp-ai-tool-vehicle-cleaning-estimate.php'
					: dirname( __DIR__ ) . '/includes/tools/automotive/class-wp-mcp-ai-tool-vehicle-cleaning-estimate.php';
		if ( file_exists( $cleaning_path ) ) {
			require_once $cleaning_path;
		}
	}

	// ----------------------------------------------------------------
	// VIN Decode Tool — slug, schema, and validation.
	// ----------------------------------------------------------------

	/**
	 * Test VIN Decode tool slug.
	 */
	public function test_vin_decode_slug() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		$this->assertSame( 'vin_decode', $tool->get_slug() );
	}

	/**
	 * Test VIN Decode tool name is not empty.
	 */
	public function test_vin_decode_name() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test VIN Decode tool description is not empty.
	 */
	public function test_vin_decode_description() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test VIN Decode parameters schema is valid JSON schema.
	 */
	public function test_vin_decode_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'vin', $schema['properties'] );
		$this->assertArrayHasKey( 'model_year', $schema['properties'] );
		$this->assertContains( 'vin', $schema['required'] );
	}

	/**
	 * Test VIN Decode capability flags include expected values.
	 */
	public function test_vin_decode_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_VIN_Decode();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Test VIN Decode definition metadata.
	 */
	public function test_vin_decode_definition() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		$def  = $tool->get_definition();

		$this->assertSame( 'vehicle_estimation', $def['toolkit'] );
		$this->assertContains( 'automotive_mechanic', $def['profession_tags'] );
	}

	/**
	 * Test VIN Decode rejects empty VIN.
	 */
	public function test_vin_decode_rejects_empty_vin() {
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
		$result = $tool->execute(
			array( 'vin' => '' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_vin', $result->get_error_code() );
	}

	/**
	 * Test VIN Decode rejects short VIN.
	 */
	public function test_vin_decode_rejects_short_vin() {
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
		$result = $tool->execute(
			array( 'vin' => 'ABC123' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_vin', $result->get_error_code() );
	}

	/**
	 * Test VIN Decode rejects VIN with invalid characters (I, O, Q).
	 */
	public function test_vin_decode_rejects_invalid_characters() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		// VIN with 'I' (invalid per standard).
		$result = $tool->execute(
			array( 'vin' => '1HGBH41JXIN109186' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_vin', $result->get_error_code() );
	}

	/**
	 * Test VIN check-digit validation with a known-good VIN.
	 */
	public function test_vin_check_digit_valid() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		// 11111111111111111 has check digit '1' at position 9.
		$result = $tool->validate_check_digit( '11111111111111111' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test VIN check-digit validation with an invalid check digit.
	 */
	public function test_vin_check_digit_invalid() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		// Modify check digit position to force mismatch.
		$result = $tool->validate_check_digit( '11111111011111111' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test VIN Decode requires authenticated user.
	 */
	public function test_vin_decode_requires_auth() {
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
		$result = $tool->execute(
			array( 'vin' => '1HGBH41JXMN109186' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test VIN Decode verifies user capability via user_can().
	 *
	 * Uses the map_meta_cap filter to strip edit_posts from a
	 * specific user, ensuring the tool's capability gate works.
	 */
	public function test_vin_decode_requires_capability() {
		$test_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Temporarily deny edit_posts for this specific user.
		$deny_filter = function ( $caps, $cap, $uid ) use ( $test_user_id ) {
			if ( 'edit_posts' === $cap && $uid === $test_user_id ) {
				return array( 'do_not_allow' );
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $deny_filter, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
		$result = $tool->execute(
			array( 'vin' => '1HGBH41JXMN109186' ),
			array( 'user_id' => $test_user_id )
		);

		remove_filter( 'map_meta_cap', $deny_filter, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test is_available returns true.
	 */
	public function test_vin_decode_is_available() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		$this->assertTrue( $tool->is_available() );
	}

	/**
	 * Test requires_base_pro returns true.
	 */
	public function test_vin_decode_requires_base_pro() {
		$tool = new WP_MCP_AI_Tool_VIN_Decode();
		$this->assertTrue( $tool->requires_base_pro() );
	}

	// ----------------------------------------------------------------
	// Vehicle Repair Estimate Tool — slug, schema, and validation.
	// ----------------------------------------------------------------

	/**
	 * Test Vehicle Repair Estimate tool slug.
	 */
	public function test_estimate_slug() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$this->assertSame( 'vehicle_repair_estimate', $tool->get_slug() );
	}

	/**
	 * Test Vehicle Repair Estimate tool name.
	 */
	public function test_estimate_name() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test Vehicle Repair Estimate tool description.
	 */
	public function test_estimate_description() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test Vehicle Repair Estimate parameters schema.
	 */
	public function test_estimate_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'image_attachment_ids', $schema['properties'] );
		$this->assertArrayHasKey( 'price_sheet_attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'vin', $schema['properties'] );
		$this->assertArrayHasKey( 'vin_image_attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'vehicle_overrides', $schema['properties'] );
		$this->assertArrayHasKey( 'labor_rate_profile', $schema['properties'] );
		$this->assertArrayHasKey( 'output_detail_level', $schema['properties'] );
		$this->assertContains( 'image_attachment_ids', $schema['required'] );
	}

	/**
	 * Test Vehicle Repair Estimate capability flags.
	 */
	public function test_estimate_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-vision-model', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'long-running', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
	}

	/**
	 * Test Vehicle Repair Estimate tool rules.
	 */
	public function test_estimate_tool_rules() {
		$tool  = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$rules = $tool->get_tool_rules();

		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout', $rules );
		$this->assertArrayHasKey( 'dependencies', $rules );
		$this->assertArrayHasKey( 'cache', $rules );
		$this->assertContains( 'extract_image_text', $rules['dependencies']['required'] );
		$this->assertContains( 'analyze_image', $rules['dependencies']['required'] );
		$this->assertContains( 'vin_decode', $rules['dependencies']['optional'] );
	}

	/**
	 * Test Vehicle Repair Estimate definition metadata.
	 */
	public function test_estimate_definition() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$def  = $tool->get_definition();

		$this->assertSame( 'vehicle_estimation', $def['toolkit'] );
		$this->assertContains( 'automotive_mechanic', $def['profession_tags'] );
		$this->assertSame( 'medium', $def['risk_level'] );
	}

	/**
	 * Test estimate rejects empty image list.
	 */
	public function test_estimate_rejects_empty_images() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$result = $tool->execute(
			array( 'image_attachment_ids' => array() ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	/**
	 * Test estimate rejects missing images key.
	 */
	public function test_estimate_rejects_missing_images() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	/**
	 * Test estimate requires authentication.
	 */
	public function test_estimate_requires_auth() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$result = $tool->execute(
			array( 'image_attachment_ids' => array( 1 ) ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test estimate rejects non-image attachments.
	 */
	public function test_estimate_rejects_invalid_attachments() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		// Non-existent attachment IDs.
		$result = $tool->execute(
			array( 'image_attachment_ids' => array( 999999 ) ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_input', $result->get_error_code() );
	}

	/**
	 * Test is_available returns true.
	 */
	public function test_estimate_is_available() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$this->assertTrue( $tool->is_available() );
	}

	/**
	 * Test requires_base_pro returns true.
	 */
	public function test_estimate_requires_base_pro() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
		$this->assertTrue( $tool->requires_base_pro() );
	}

	// ----------------------------------------------------------------
	// Tool registration — verify tools appear in pro tools list.
	// ----------------------------------------------------------------

	/**
	 * Test that vehicle estimation tools are always in the pro registration array.
	 */
	public function test_vehicle_tools_always_registered() {
		// Check function exists (it's in the pro addon).
		if ( ! function_exists( 'wp_mcp_ai_pro_get_tools' ) ) {
			$this->markTestSkipped( 'Pro addon tool listing function not available.' );
		}

		$tools = wp_mcp_ai_pro_get_tools();
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_VIN_Decode', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Vehicle_Repair_Estimate', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate', $tools );
	}

	// ----------------------------------------------------------------
	// Damage type and part classification helpers.
	// ----------------------------------------------------------------

	/**
	 * Test REQUIRED_VIEWS constant has expected views.
	 */
	public function test_required_views_constant() {
		$this->assertContains( 'front', WP_MCP_AI_Tool_Vehicle_Repair_Estimate::REQUIRED_VIEWS );
		$this->assertContains( 'rear', WP_MCP_AI_Tool_Vehicle_Repair_Estimate::REQUIRED_VIEWS );
		$this->assertContains( 'left_side', WP_MCP_AI_Tool_Vehicle_Repair_Estimate::REQUIRED_VIEWS );
		$this->assertContains( 'right_side', WP_MCP_AI_Tool_Vehicle_Repair_Estimate::REQUIRED_VIEWS );
	}

	/**
	 * Test DAMAGE_TYPES constant includes known types.
	 */
	public function test_damage_types_constant() {
		$types = WP_MCP_AI_Tool_Vehicle_Repair_Estimate::DAMAGE_TYPES;
		$this->assertContains( 'scratch', $types );
		$this->assertContains( 'dent', $types );
		$this->assertContains( 'crack', $types );
		$this->assertContains( 'broken', $types );
	}

	/**
	 * Test REPAIR_OPERATIONS constant includes known operations.
	 */
	public function test_repair_operations_constant() {
		$ops = WP_MCP_AI_Tool_Vehicle_Repair_Estimate::REPAIR_OPERATIONS;
		$this->assertContains( 'replace', $ops );
		$this->assertContains( 'repair', $ops );
		$this->assertContains( 'refinish', $ops );
		$this->assertContains( 'calibration', $ops );
	}

	/**
	 * Test CONFIDENCE_THRESHOLDS are set.
	 */
	public function test_confidence_thresholds() {
		$thresholds = WP_MCP_AI_Tool_Vehicle_Repair_Estimate::CONFIDENCE_THRESHOLDS;
		$this->assertArrayHasKey( 'vehicle_id', $thresholds );
		$this->assertArrayHasKey( 'part_detect', $thresholds );
		$this->assertArrayHasKey( 'damage_type', $thresholds );
		$this->assertArrayHasKey( 'coverage', $thresholds );
		$this->assertGreaterThan( 0, $thresholds['vehicle_id'] );
	}

	// ================================================================
	// Vehicle Cleaning Estimate Tool tests.
	// ================================================================

	/**
	 * Test cleaning estimate slug.
	 */
	public function test_cleaning_estimate_slug() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$this->assertSame( 'vehicle_cleaning_estimate', $tool->get_slug() );
	}

	/**
	 * Test cleaning estimate name.
	 */
	public function test_cleaning_estimate_name() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$this->assertSame( 'Vehicle Cleaning Estimate', $tool->get_name() );
	}

	/**
	 * Test cleaning estimate description.
	 */
	public function test_cleaning_estimate_description() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$this->assertStringContainsString( 'car-wash', $tool->get_description() );
		$this->assertStringContainsString( 'No VIN required', $tool->get_description() );
	}

	/**
	 * Test cleaning estimate schema has required parameters.
	 */
	public function test_cleaning_estimate_schema() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'image_attachment_ids', $schema['properties'] );
		$this->assertArrayHasKey( 'package', $schema['properties'] );
		$this->assertArrayHasKey( 'add_ons', $schema['properties'] );
		$this->assertArrayHasKey( 'size_override', $schema['properties'] );
		$this->assertArrayHasKey( 'menu_config_id', $schema['properties'] );
		$this->assertArrayHasKey( 'tax_rate', $schema['properties'] );
		$this->assertArrayHasKey( 'currency', $schema['properties'] );

		// Package is required.
		$this->assertContains( 'package', $schema['required'] );

		// Package enum matches PACKAGE_TIERS.
		$this->assertSame(
			WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate::PACKAGE_TIERS,
			$schema['properties']['package']['enum']
		);

		// Size override enum matches SIZE_TIERS.
		$this->assertSame(
			WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate::SIZE_TIERS,
			$schema['properties']['size_override']['enum']
		);
	}

	/**
	 * Test cleaning estimate capability flags include expected entries.
	 */
	public function test_cleaning_estimate_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'requires-vision-model', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Test cleaning estimate tool rules.
	 */
	public function test_cleaning_estimate_tool_rules() {
		$tool  = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$rules = $tool->get_tool_rules();

		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'cache', $rules );
		$this->assertSame( 15, $rules['rate_limits']['requests_per_minute'] );
	}

	/**
	 * Test cleaning estimate definition metadata.
	 */
	public function test_cleaning_estimate_definition() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$def  = $tool->get_definition();

		$this->assertSame( 'vehicle_estimation', $def['toolkit'] );
		$this->assertSame( 'info', $def['risk_level'] );
		$this->assertContains( 'car_wash_operator', $def['profession_tags'] );
	}

	/**
	 * Test cleaning estimate is_available returns true.
	 */
	public function test_cleaning_estimate_is_available() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$this->assertTrue( $tool->is_available() );
	}

	/**
	 * Test cleaning estimate requires base pro.
	 */
	public function test_cleaning_estimate_requires_base_pro() {
		$tool = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$this->assertTrue( $tool->requires_base_pro() );
	}

	/**
	 * Test cleaning estimate requires auth.
	 */
	public function test_cleaning_estimate_requires_auth() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array( 'package' => 'premium_exterior_express' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test cleaning estimate rejects invalid package.
	 */
	public function test_cleaning_estimate_rejects_invalid_package() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array( 'package' => 'super_deluxe_99' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_package', $result->get_error_code() );
	}

	/**
	 * Test cleaning estimate rejects missing package.
	 */
	public function test_cleaning_estimate_rejects_missing_package() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_package', $result->get_error_code() );
	}

	/**
	 * Test cleaning estimate produces correct output with size override and no images.
	 */
	public function test_cleaning_estimate_basic_output() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'car',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'estimate_id', $result );
		$data = $result;

		$this->assertNotEmpty( $data['estimate_id'] );
		$this->assertSame( 'car', $data['vehicle_size']['tier'] );
		$this->assertSame( 1.0, $data['vehicle_size']['confidence'] );
		$this->assertSame( 'manual_override', $data['vehicle_size']['source'] );
		$this->assertSame( 'premium_exterior_express', $data['package']['code'] );
		$this->assertSame( 'Premium Exterior Express', $data['package']['name'] );

		// Line items.
		$this->assertCount( 1, $data['line_items'] );
		$this->assertSame( 'package', $data['line_items'][0]['type'] );
		$this->assertSame( 29.99, $data['line_items'][0]['unit_price'] );

		// Totals.
		$this->assertSame( 29.99, $data['totals']['subtotal'] );
		$this->assertSame( 0.0, $data['totals']['tax'] );
		$this->assertSame( 29.99, $data['totals']['total'] );
		$this->assertSame( 'CAD', $data['totals']['currency'] );
	}

	/**
	 * Test cleaning estimate with oversize truck pricing.
	 */
	public function test_cleaning_estimate_oversize_pricing() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'prestige_interior_express',
				'size_override' => 'oversize_truck_suv',
			),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		$this->assertSame( 'oversize_truck_suv', $data['vehicle_size']['tier'] );
		$this->assertSame( 159.99, $data['line_items'][0]['unit_price'] );
		$this->assertSame( 159.99, $data['totals']['subtotal'] );
	}

	/**
	 * Test cleaning estimate with tax rate applied.
	 */
	public function test_cleaning_estimate_with_tax() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'practical_interior_express',
				'size_override' => 'car',
				'tax_rate'      => 0.13,
			),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		$this->assertSame( 59.99, $data['totals']['subtotal'] );
		$this->assertSame( 0.13, $data['totals']['tax_rate'] );
		$this->assertSame( round( 59.99 * 0.13, 2 ), $data['totals']['tax'] );
		$this->assertSame( round( 59.99 + 59.99 * 0.13, 2 ), $data['totals']['total'] );
	}

	/**
	 * Test cleaning estimate with severity-priced add-on.
	 */
	public function test_cleaning_estimate_severity_addon() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'car',
				'add_ons'       => array(
					array(
						'code'     => 'pet_hair_removal',
						'severity' => 'moderate',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		// Package + 1 add-on.
		$this->assertCount( 2, $data['line_items'] );

		// Add-on line.
		$addon = $data['line_items'][1];
		$this->assertSame( 'pet_hair_removal', $addon['code'] );
		$this->assertSame( 'moderate', $addon['severity'] );
		$this->assertSame( 75.00, $addon['unit_price'] );

		// Totals: 29.99 + 75.00.
		$this->assertSame( round( 29.99 + 75.00, 2 ), $data['totals']['subtotal'] );
	}

	/**
	 * Test cleaning estimate with size-based add-on.
	 */
	public function test_cleaning_estimate_size_based_addon() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'small_truck_suv',
				'add_ons'       => array(
					array( 'code' => 'premium_hand_wash_upgrade' ),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$data  = $result;
		$addon = $data['line_items'][1];
		$this->assertSame( 'premium_hand_wash_upgrade', $addon['code'] );
		$this->assertSame( 20.00, $addon['unit_price'] );

		// Package $35.99 + upgrade $20.00.
		$this->assertSame( round( 35.99 + 20.00, 2 ), $data['totals']['subtotal'] );
	}

	/**
	 * Test cleaning estimate with flat-price add-on.
	 */
	public function test_cleaning_estimate_flat_addon() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'popular_interior_express',
				'size_override' => 'car',
				'add_ons'       => array(
					array( 'code' => 'carpet_seat_deodorizer' ),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$data  = $result;
		$addon = $data['line_items'][1];
		$this->assertSame( 30.00, $addon['unit_price'] );
		$this->assertSame( round( 79.99 + 30.00, 2 ), $data['totals']['subtotal'] );
	}

	/**
	 * Test cleaning estimate deduplicates add-ons.
	 */
	public function test_cleaning_estimate_deduplicate_addons() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'car',
				'add_ons'       => array(
					array( 'code' => 'rims_tire_dressing' ),
					array( 'code' => 'rims_tire_dressing' ),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		// Package + 1 add-on (deduplicated).
		$this->assertCount( 2, $data['line_items'] );
	}

	/**
	 * Test cleaning estimate ignores unknown add-on codes.
	 */
	public function test_cleaning_estimate_ignores_unknown_addons() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'car',
				'add_ons'       => array(
					array( 'code' => 'nonexistent_addon' ),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		$this->assertCount( 1, $data['line_items'] );
	}

	/**
	 * Test cleaning estimate defaults to car tier with warning when no images and no override.
	 */
	public function test_cleaning_estimate_defaults_to_car() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array( 'package' => 'premium_exterior_express' ),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		$this->assertSame( 'car', $data['vehicle_size']['tier'] );
		$this->assertSame( 0.0, $data['vehicle_size']['confidence'] );
		$this->assertSame( 'default', $data['vehicle_size']['source'] );
		$this->assertNotEmpty( $data['warnings'] );
	}

	/**
	 * Test SIZE_TIERS constant contains expected values.
	 */
	public function test_size_tiers_constant() {
		$tiers = WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate::SIZE_TIERS;
		$this->assertContains( 'car', $tiers );
		$this->assertContains( 'small_truck_suv', $tiers );
		$this->assertContains( 'oversize_truck_suv', $tiers );
		$this->assertCount( 3, $tiers );
	}

	/**
	 * Test PACKAGE_TIERS matches London Prestige Car Wash menu.
	 */
	public function test_package_tiers_constant() {
		$tiers = WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate::PACKAGE_TIERS;
		$this->assertContains( 'premium_exterior_express', $tiers );
		$this->assertContains( 'practical_interior_express', $tiers );
		$this->assertContains( 'popular_interior_express', $tiers );
		$this->assertContains( 'prestige_interior_express', $tiers );
		$this->assertCount( 4, $tiers );
	}

	/**
	 * Test all four package prices for car tier match expected values.
	 */
	public function test_all_package_car_prices() {
		$tool     = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$context  = array( 'user_id' => $this->admin_id );
		$packages = array(
			'premium_exterior_express'   => 29.99,
			'practical_interior_express' => 59.99,
			'popular_interior_express'   => 79.99,
			'prestige_interior_express'  => 129.99,
		);

		foreach ( $packages as $pkg => $expected ) {
			$result = $tool->execute(
				array(
					'package'       => $pkg,
					'size_override' => 'car',
				),
				$context
			);
			$this->assertSame(
				$expected,
				$result['totals']['subtotal'],
				"Package $pkg car price mismatch"
			);
		}
	}

	/**
	 * Test soil/mud/sap/oil add-on severe pricing.
	 */
	public function test_soil_addon_severe_pricing() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'car',
				'add_ons'       => array(
					array(
						'code'     => 'soil_mud_sap_oil',
						'severity' => 'severe',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$addon = $result['line_items'][1];
		$this->assertSame( 75.00, $addon['unit_price'] );
	}

	/**
	 * Test cleaning estimate with multiple add-ons and tax.
	 */
	public function test_cleaning_estimate_full_quote() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'prestige_interior_express',
				'size_override' => 'small_truck_suv',
				'tax_rate'      => 0.13,
				'currency'      => 'CAD',
				'add_ons'       => array(
					array(
						'code'     => 'pet_hair_removal',
						'severity' => 'light',
					),
					array( 'code' => 'trunk_bed_shampoo' ),
					array( 'code' => 'carpet_seat_deodorizer' ),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$data = $result;
		$this->assertCount( 4, $data['line_items'] );

		// Package: $139.99, pet hair light: $45, trunk: $15, deodorizer: $30.
		$expected_subtotal = round( 139.99 + 45.00 + 15.00 + 30.00, 2 );
		$this->assertSame( $expected_subtotal, $data['totals']['subtotal'] );

		$expected_tax = round( $expected_subtotal * 0.13, 2 );
		$this->assertSame( $expected_tax, $data['totals']['tax'] );
		$this->assertSame( round( $expected_subtotal + $expected_tax, 2 ), $data['totals']['total'] );
		$this->assertSame( 'CAD', $data['totals']['currency'] );
	}

	/**
	 * Test cleaning estimate included_services are in package line item.
	 */
	public function test_cleaning_estimate_included_services() {
		$tool   = new WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate();
		$result = $tool->execute(
			array(
				'package'       => 'premium_exterior_express',
				'size_override' => 'car',
			),
			array( 'user_id' => $this->admin_id )
		);

		$pkg_item = $result['line_items'][0];
		$this->assertArrayHasKey( 'included_services', $pkg_item );
		$this->assertContains( 'Cotton towel hand dry', $pkg_item['included_services'] );
		$this->assertContains( 'Spray foam soap', $pkg_item['included_services'] );
	}
}
