<?php
/**
 * Tests for the JetEngine MCP Bridge Tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
class Test_JetEngine_MCP_Bridge_Tool extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Load tool classes.
		$tools_dir = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/'
			: dirname( __DIR__ ) . '/includes/tools/jetengine/';

		$files = array(
			'class-wp-mcp-ai-pro-tool-jetengine-mcp-bridge.php',
			'class-wp-mcp-ai-pro-tool-jetengine-create-post-type.php',
			'class-wp-mcp-ai-pro-tool-jetengine-create-taxonomy.php',
			'class-wp-mcp-ai-pro-tool-jetengine-create-meta-field.php',
			'class-wp-mcp-ai-pro-tool-jetengine-manage-relations.php',
			'class-wp-mcp-ai-pro-tool-jetengine-site-context.php',
			'class-wp-mcp-ai-pro-tool-jetengine-prompts.php',
		);

		foreach ( $files as $file ) {
			$path = $tools_dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge' ) );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge();
		$this->assertEquals( 'jetengine_mcp', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_has_required_actions() {
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );

		$enum = $schema['properties']['action']['enum'];
		$this->assertContains( 'discover_tools', $enum );
		$this->assertContains( 'call_tool', $enum );
		$this->assertContains( 'get_site_context', $enum );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_requires_admin() {
		wp_set_current_user( $this->subscriber_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge();
		$result = $tool->execute( array( 'action' => 'discover_tools' ), array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_invalid_action() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge();
		$result = $tool->execute( array( 'action' => 'invalid' ), array() );

		$this->assertWPError( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'requires-plugin', $flags );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_bridge_tool_definition() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge();
		$def  = $tool->get_definition();

		$this->assertArrayHasKey( 'name', $def );
		$this->assertArrayHasKey( 'toolkit', $def );
		$this->assertEquals( 'jetengine_mcp_bridge', $def['toolkit'] );
		$this->assertEquals( 'elevated', $def['risk_level'] );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_post_type_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type();
		$this->assertEquals( 'jetengine_create_post_type', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_post_type_validates_slug_length() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type();
		$result = $tool->execute(
			array(
				'slug'          => 'this_slug_is_way_too_long_for_wordpress',
				'singular_name' => 'Test',
				'plural_name'   => 'Tests',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_slug', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_post_type_requires_params() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type();
		$result = $tool->execute( array( 'slug' => 'test' ), array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_params', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_post_type_detects_existing() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type();
		$result = $tool->execute(
			array(
				'slug'          => 'post',
				'singular_name' => 'Post',
				'plural_name'   => 'Posts',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'post_type_exists', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_taxonomy_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Taxonomy();
		$this->assertEquals( 'jetengine_create_taxonomy', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_taxonomy_validates_slug_length() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Taxonomy();
		$result = $tool->execute(
			array(
				'slug'          => 'this_taxonomy_slug_is_way_too_long_for_wordpress',
				'singular_name' => 'Test',
				'plural_name'   => 'Tests',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_slug', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_taxonomy_detects_existing() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Taxonomy();
		$result = $tool->execute(
			array(
				'slug'          => 'category',
				'singular_name' => 'Category',
				'plural_name'   => 'Categories',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'taxonomy_exists', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_meta_field_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Meta_Field();
		$this->assertEquals( 'jetengine_create_meta_field', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_create_meta_field_requires_all_params() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Create_Meta_Field();
		$result = $tool->execute( array( 'name' => 'test_field' ), array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_params', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_manage_relations_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations();
		$this->assertEquals( 'jetengine_manage_relations', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_manage_relations_invalid_action() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations();
		$result = $tool->execute( array( 'action' => 'invalid' ), array() );

		$this->assertWPError( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_manage_relations_create_requires_params() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations();
		$result = $tool->execute(
			array(
				'action' => 'create',
				'name'   => 'test',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_params', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_site_context_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_Site_Context();
		$this->assertEquals( 'jetengine_site_context', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_site_context_read_only_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_JetEngine_Site_Context();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertNotContains( 'write', $flags );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_prompts_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_JetEngine_Prompts();
		$this->assertEquals( 'jetengine_prompts', $tool->get_slug() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_prompts_tool_invalid_action() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Prompts();
		$result = $tool->execute( array( 'action' => 'invalid' ), array() );

		$this->assertWPError( $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_prompts_tool_get_requires_name() {
		wp_set_current_user( $this->admin_id );
		$tool   = new WP_MCP_AI_Pro_Tool_JetEngine_Prompts();
		$result = $tool->execute( array( 'action' => 'get' ), array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_name', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_all_tools_implement_interface() {
		$tool_classes = array(
			'WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge',
			'WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type',
			'WP_MCP_AI_Pro_Tool_JetEngine_Create_Taxonomy',
			'WP_MCP_AI_Pro_Tool_JetEngine_Create_Meta_Field',
			'WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations',
			'WP_MCP_AI_Pro_Tool_JetEngine_Site_Context',
			'WP_MCP_AI_Pro_Tool_JetEngine_Prompts',
		);

		foreach ( $tool_classes as $class ) {
			if ( class_exists( $class ) ) {
				$tool = new $class();
				$this->assertNotEmpty( $tool->get_slug(), "$class should have a slug" );
				$this->assertNotEmpty( $tool->get_name(), "$class should have a name" );
				$this->assertNotEmpty( $tool->get_description(), "$class should have a description" );
				$this->assertIsArray( $tool->get_parameters_schema(), "$class should return array schema" );
				$this->assertIsArray( $tool->get_capability_flags(), "$class should return array flags" );
				$this->assertIsArray( $tool->get_definition(), "$class should return array definition" );
			}
		}
	}
}
