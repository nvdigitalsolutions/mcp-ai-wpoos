<?php
/**
 * Tests for OpenAI API Phase 4 Tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test cases for Phase 4 OpenAI API integration tools.
 */
class Test_OpenAI_Phase_4_Tools extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
	}

	/**
	 * Test that Phase 4 tools are registered.
	 */
	public function test_phase_4_tools_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$phase_4_tools = array(
			'edit_openai_image',
			'create_image_variation',
			'analyze_file_suitability',
			'openai_usage_analytics',
		);

		foreach ( $phase_4_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool $tool_slug should be registered" );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		}
	}

	/**
	 * Test edit_openai_image tool structure.
	 */
	public function test_edit_openai_image_tool_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'edit_openai_image' );

		$this->assertNotNull( $tool );
		$this->assertEquals( 'edit_openai_image', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'image_id', $schema['required'] );
		$this->assertContains( 'prompt', $schema['required'] );
	}

	/**
	 * Test create_image_variation tool structure.
	 */
	public function test_create_image_variation_tool_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'create_image_variation' );

		$this->assertNotNull( $tool );
		$this->assertEquals( 'create_image_variation', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'image_id', $schema['required'] );
	}

	/**
	 * Test edit_openai_image requires image_id parameter.
	 */
	public function test_edit_openai_image_requires_image_id() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'edit_openai_image' );

		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test edit_openai_image requires prompt parameter.
	 */
	public function test_edit_openai_image_requires_prompt() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'edit_openai_image' );

		$result = $tool->execute( array( 'image_id' => 123 ), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test create_image_variation requires image_id parameter.
	 */
	public function test_create_image_variation_requires_image_id() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'create_image_variation' );

		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test Phase 4 tools implement capability flags interface.
	 */
	public function test_phase_4_tools_implement_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$phase_4_tools = array(
			'edit_openai_image',
			'create_image_variation',
			'analyze_file_suitability',
			'openai_usage_analytics',
		);

		foreach ( $phase_4_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );

			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertNotEmpty( $flags );
		}
	}

	/**
	 * Test Phase 4 tools require appropriate capabilities.
	 */
	public function test_phase_4_tools_require_capabilities() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools_capabilities = array(
			'edit_openai_image'        => 'upload_files',
			'create_image_variation'   => 'upload_files',
			'analyze_file_suitability' => 'upload_files',
			'openai_usage_analytics'   => 'manage_options',
		);

		foreach ( $tools_capabilities as $tool_slug => $expected_capability ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotEmpty( $tool->get_required_capability() );
			$this->assertEquals( $expected_capability, $tool->get_required_capability(), "Tool $tool_slug should require $expected_capability capability" );
		}
	}

	/**
	 * Test OpenAI client has edit_image method.
	 */
	public function test_openai_client_has_edit_image_method() {
		$client = new WP_MCP_AI_OpenAI_Client();
		$this->assertTrue( method_exists( $client, 'edit_image' ) );
	}

	/**
	 * Test OpenAI client has create_image_variation method.
	 */
	public function test_openai_client_has_create_image_variation_method() {
		$client = new WP_MCP_AI_OpenAI_Client();
		$this->assertTrue( method_exists( $client, 'create_image_variation' ) );
	}

	/**
	 * Test Phase 4 tools are in external-tools group.
	 */
	public function test_phase_4_tools_in_correct_group() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'edit_openai_image', $tool_map );
		$this->assertEquals( 'external-tools', $tool_map['edit_openai_image'] );

		$this->assertArrayHasKey( 'create_image_variation', $tool_map );
		$this->assertEquals( 'external-tools', $tool_map['create_image_variation'] );

		$this->assertArrayHasKey( 'analyze_file_suitability', $tool_map );
		$this->assertEquals( 'external-tools', $tool_map['analyze_file_suitability'] );

		$this->assertArrayHasKey( 'openai_usage_analytics', $tool_map );
		$this->assertEquals( 'external-tools', $tool_map['openai_usage_analytics'] );
	}

	/**
	 * Test analyze_file_suitability tool structure.
	 */
	public function test_analyze_file_suitability_tool_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'analyze_file_suitability' );

		$this->assertNotNull( $tool );
		$this->assertEquals( 'analyze_file_suitability', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'file_id', $schema['required'] );
		$this->assertContains( 'purpose', $schema['required'] );
	}

	/**
	 * Test openai_usage_analytics tool structure.
	 */
	public function test_openai_usage_analytics_tool_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'openai_usage_analytics' );

		$this->assertNotNull( $tool );
		$this->assertEquals( 'openai_usage_analytics', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
	}

	/**
	 * Test OpenAI client has image editing endpoints defined.
	 */
	public function test_openai_client_has_image_endpoints() {
		$this->assertTrue( defined( 'WP_MCP_AI_OpenAI_Client::IMAGES_EDITS_ENDPOINT' ) );
		$this->assertTrue( defined( 'WP_MCP_AI_OpenAI_Client::IMAGES_VARIATIONS_ENDPOINT' ) );

		$this->assertEquals(
			'https://api.openai.com/v1/images/edits',
			WP_MCP_AI_OpenAI_Client::IMAGES_EDITS_ENDPOINT
		);

		$this->assertEquals(
			'https://api.openai.com/v1/images/variations',
			WP_MCP_AI_OpenAI_Client::IMAGES_VARIATIONS_ENDPOINT
		);
	}
}
