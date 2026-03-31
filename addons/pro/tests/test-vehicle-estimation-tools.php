<?php
/**
 * Tests for Vehicle Estimation Tools (VIN Decode + Vehicle Repair Estimate).
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

		// Enable the vehicle estimation feature flag.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_vehicle_estimation' => true )
		);

		// Ensure tool classes are loaded.
		$vin_path      = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-vin-decode.php'
			: dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-vin-decode.php';
		$estimate_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-vehicle-repair-estimate.php'
			: dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-vehicle-repair-estimate.php';

		if ( file_exists( $vin_path ) ) {
			require_once $vin_path;
		}
		if ( file_exists( $estimate_path ) ) {
			require_once $estimate_path;
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
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
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
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
		// 11111111111111111 has check digit '1' at position 9.
		$result = $tool->validate_check_digit( '11111111111111111' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test VIN check-digit validation with an invalid check digit.
	 */
	public function test_vin_check_digit_invalid() {
		$tool   = new WP_MCP_AI_Tool_VIN_Decode();
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
	 * Test VIN Decode requires edit_posts capability.
	 */
	public function test_vin_decode_requires_capability() {
		$no_role_id = self::factory()->user->create( array( 'role' => '' ) );
		$tool       = new WP_MCP_AI_Tool_VIN_Decode();
		$result     = $tool->execute(
			array( 'vin' => '1HGBH41JXMN109186' ),
			array( 'user_id' => $no_role_id )
		);

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
		$tool   = new WP_MCP_AI_Tool_Vehicle_Repair_Estimate();
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
	 * Test that vehicle estimation tools are in the pro registration array when enabled.
	 */
	public function test_vehicle_tools_registered_when_enabled() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_vehicle_estimation' => true )
		);

		// Check function exists (it's in the pro addon).
		if ( ! function_exists( 'wp_mcp_ai_pro_get_tools' ) ) {
			$this->markTestSkipped( 'Pro addon tool listing function not available.' );
		}

		$tools = wp_mcp_ai_pro_get_tools();
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_VIN_Decode', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Vehicle_Repair_Estimate', $tools );
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
}
