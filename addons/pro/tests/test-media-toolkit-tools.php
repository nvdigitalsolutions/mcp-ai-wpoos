<?php
/**
 * Tests for Media Toolkit Tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Media Toolkit Tools functionality.
 */
class Test_Media_Toolkit_Tools extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-template-cpt.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Media_Collection_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-collection-cpt.php';
		}

		// Register CPTs.
		WP_MCP_AI_Media_Template_CPT::register_post_type();
		WP_MCP_AI_Media_Template_CPT::register_taxonomy();
		WP_MCP_AI_Media_Collection_CPT::register_post_type();
		WP_MCP_AI_Media_Collection_CPT::register_taxonomy();

		// Load tool classes.
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-list-media-templates.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-create-media-template.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-apply-media-template.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-process-collection.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-apply-collection-template.php';

		// Set up user with required capabilities.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test list_media_templates tool returns empty array when no templates exist.
	 */
	public function test_list_media_templates_empty() {
		$tool   = new WP_MCP_AI_Tool_List_Media_Templates();
		$result = $tool->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertIsArray( $result['templates'] );
		$this->assertEmpty( $result['templates'] );
		$this->assertEquals( 0, $result['pagination']['total'] );
	}

	/**
	 * Test list_media_templates tool returns templates.
	 */
	public function test_list_media_templates_returns_templates() {
		// Create test templates.
		$template_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_media_tpl',
				'post_title'  => 'Test Template',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $template_id, '_mcp_ai_template_operation', 'resize_graphic' );
		update_post_meta(
			$template_id,
			'_mcp_ai_template_parameters',
			wp_json_encode(
				array(
					'target_width'  => 1080,
					'target_height' => 1080,
				)
			)
		);

		$tool   = new WP_MCP_AI_Tool_List_Media_Templates();
		$result = $tool->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['templates'] );
		$this->assertEquals( 'Test Template', $result['templates'][0]['title'] );
		$this->assertEquals( 'resize_graphic', $result['templates'][0]['operation'] );
		$this->assertEquals( 1080, $result['templates'][0]['parameters']['target_width'] );
	}

	/**
	 * Test list_media_templates tool filters by operation.
	 */
	public function test_list_media_templates_filters_by_operation() {
		// Create templates with different operations.
		$resize_template = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_media_tpl',
				'post_title'  => 'Resize Template',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $resize_template, '_mcp_ai_template_operation', 'resize_graphic' );

		$logo_template = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_media_tpl',
				'post_title'  => 'Logo Template',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $logo_template, '_mcp_ai_template_operation', 'add_logo' );

		$tool   = new WP_MCP_AI_Tool_List_Media_Templates();
		$result = $tool->execute( array( 'operation' => 'resize_graphic' ), array() );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['templates'] );
		$this->assertEquals( 'Resize Template', $result['templates'][0]['title'] );
	}

	/**
	 * Test create_media_template tool creates a template.
	 */
	public function test_create_media_template() {
		$tool   = new WP_MCP_AI_Tool_Create_Media_Template();
		$result = $tool->execute(
			array(
				'title'       => 'New Template',
				'description' => 'Test description',
				'operation'   => 'resize_graphic',
				'parameters'  => array(
					'target_width'  => 800,
					'target_height' => 600,
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'template_id', $result );
		$this->assertGreaterThan( 0, $result['template_id'] );
		$this->assertEquals( 'New Template', $result['template']['title'] );
		$this->assertEquals( 'resize_graphic', $result['template']['operation'] );

		// Verify template was created.
		$template = get_post( $result['template_id'] );
		$this->assertNotNull( $template );
		$this->assertEquals( 'mcp_ai_media_tpl', $template->post_type );
	}

	/**
	 * Test create_media_template tool validates required fields.
	 */
	public function test_create_media_template_validates_required_fields() {
		$tool = new WP_MCP_AI_Tool_Create_Media_Template();

		// Test missing title.
		$result = $tool->execute(
			array(
				'operation'  => 'resize_graphic',
				'parameters' => array(),
			),
			array()
		);
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'title', strtolower( $result['error'] ) );

		// Test missing operation.
		$result = $tool->execute(
			array(
				'title'      => 'Test',
				'parameters' => array(),
			),
			array()
		);
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'operation', strtolower( $result['error'] ) );
	}

	/**
	 * Test create_media_template tool validates operation type.
	 */
	public function test_create_media_template_validates_operation_type() {
		$tool   = new WP_MCP_AI_Tool_Create_Media_Template();
		$result = $tool->execute(
			array(
				'title'      => 'Test',
				'operation'  => 'invalid_operation',
				'parameters' => array(),
			),
			array()
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'invalid operation', strtolower( $result['error'] ) );
	}

	/**
	 * Test apply_media_template tool requires media toolkit to be enabled.
	 */
	public function test_tools_require_media_toolkit_enabled() {
		// Disable media toolkit.
		update_option( 'wp_mcp_ai_settings', array() );

		$tools = array(
			new WP_MCP_AI_Tool_List_Media_Templates(),
			new WP_MCP_AI_Tool_Create_Media_Template(),
			new WP_MCP_AI_Tool_Apply_Media_Template(),
			new WP_MCP_AI_Tool_Process_Collection(),
			new WP_MCP_AI_Tool_Apply_Collection_Template(),
		);

		foreach ( $tools as $tool ) {
			$result = $tool->execute( array(), array() );
			$this->assertFalse( $result['success'], 'Tool ' . $tool->get_slug() . ' should fail when toolkit disabled' );
			$this->assertStringContainsString( 'not enabled', strtolower( $result['error'] ) );
		}
	}

	/**
	 * Test tool slugs and names are correct.
	 */
	public function test_tool_metadata() {
		$tools = array(
			array(
				'class'    => 'WP_MCP_AI_Tool_List_Media_Templates',
				'slug'     => 'list_media_templates',
				'contains' => 'List',
			),
			array(
				'class'    => 'WP_MCP_AI_Tool_Create_Media_Template',
				'slug'     => 'create_media_template',
				'contains' => 'Create',
			),
			array(
				'class'    => 'WP_MCP_AI_Tool_Apply_Media_Template',
				'slug'     => 'apply_media_template',
				'contains' => 'Apply',
			),
			array(
				'class'    => 'WP_MCP_AI_Tool_Process_Collection',
				'slug'     => 'process_collection',
				'contains' => 'Process',
			),
			array(
				'class'    => 'WP_MCP_AI_Tool_Apply_Collection_Template',
				'slug'     => 'apply_collection_template',
				'contains' => 'Apply',
			),
		);

		foreach ( $tools as $tool_data ) {
			$tool = new $tool_data['class']();
			$this->assertEquals( $tool_data['slug'], $tool->get_slug() );
			$this->assertStringContainsString( $tool_data['contains'], $tool->get_name() );
			$this->assertNotEmpty( $tool->get_description() );
			$this->assertIsArray( $tool->get_parameters_schema() );
		}
	}

	/**
	 * Test all tools require upload_files capability.
	 */
	public function test_tools_require_upload_files_capability() {
		$tools = array(
			new WP_MCP_AI_Tool_List_Media_Templates(),
			new WP_MCP_AI_Tool_Create_Media_Template(),
			new WP_MCP_AI_Tool_Apply_Media_Template(),
			new WP_MCP_AI_Tool_Process_Collection(),
			new WP_MCP_AI_Tool_Apply_Collection_Template(),
		);

		foreach ( $tools as $tool ) {
			$this->assertEquals( 'upload_files', $tool->get_required_capability() );
		}
	}

	/**
	 * Test all tools require base_pro flag.
	 */
	public function test_tools_require_base_pro() {
		$tools = array(
			new WP_MCP_AI_Tool_List_Media_Templates(),
			new WP_MCP_AI_Tool_Create_Media_Template(),
			new WP_MCP_AI_Tool_Apply_Media_Template(),
			new WP_MCP_AI_Tool_Process_Collection(),
			new WP_MCP_AI_Tool_Apply_Collection_Template(),
		);

		foreach ( $tools as $tool ) {
			$this->assertTrue( $tool->requires_base_pro() );
		}
	}

	/**
	 * Test create_media_template assigns categories.
	 */
	public function test_create_media_template_assigns_categories() {
		$tool   = new WP_MCP_AI_Tool_Create_Media_Template();
		$result = $tool->execute(
			array(
				'title'      => 'Social Media Template',
				'operation'  => 'resize_graphic',
				'parameters' => array(
					'target_width'  => 1080,
					'target_height' => 1080,
				),
				'categories' => array( 'social-media', 'custom-category' ),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['template']['categories'] );

		// Verify categories exist.
		$social_media = get_term_by( 'slug', 'social-media', 'mcp_ai_tpl_category' );
		$this->assertNotFalse( $social_media );

		$custom = get_term_by( 'slug', 'custom-category', 'mcp_ai_tpl_category' );
		$this->assertNotFalse( $custom );
	}
}
