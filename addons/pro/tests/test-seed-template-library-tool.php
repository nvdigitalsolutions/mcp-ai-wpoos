<?php
/**
 * Tests for Seed Template Library Tool.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test Seed Template Library Tool functionality.
 */
class Test_Seed_Template_Library_Tool extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Define Pro constants if not already defined.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			define( 'WP_MCP_AI_PRO_VERSION', '1.0.0' );
		}
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		// Register the mcp_task_template post type for testing.
		register_post_type(
			'mcp_task_template',
			array(
				'public'       => true,
				'has_archive'  => false,
				'show_in_rest' => true,
			)
		);

		// Clean up any existing templates.
		$existing = get_posts(
			array(
				'post_type'   => 'mcp_task_template',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		foreach ( $existing as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up templates.
		$templates = get_posts(
			array(
				'post_type'   => 'mcp_task_template',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		foreach ( $templates as $template ) {
			wp_delete_post( $template->ID, true );
		}

		parent::tearDown();
	}

	/**
	 * Test that the seed template library tool can be instantiated.
	 */
	public function test_tool_instantiation() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library', $tool );
	}

	/**
	 * Test that the tool has correct slug.
	 */
	public function test_tool_slug() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
		$this->assertEquals( 'seed_template_library', $tool->get_slug() );
	}

	/**
	 * Test that the tool definition is correct.
	 */
	public function test_tool_definition() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool       = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
		$definition = $tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'input_schema', $definition );
		$this->assertEquals( 'seed_template_library', $definition['name'] );
	}

	/**
	 * Test that the tool can execute and create templates using CPT.
	 */
	public function test_execute_creates_templates_cpt() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
		$result = $tool->execute(
			array( 'overwrite' => false ),
			array( 'user_id' => 1 )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'templates_created', $result );
		$this->assertArrayHasKey( 'templates_skipped', $result );
		$this->assertArrayHasKey( 'templates_errors', $result );
		$this->assertGreaterThan( 0, $result['templates_created'] );

		// Verify templates were created.
		$templates = get_posts(
			array(
				'post_type'   => 'mcp_task_template',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$this->assertGreaterThan( 0, count( $templates ) );
	}

	/**
	 * Test that the tool skips existing templates when overwrite is false.
	 */
	public function test_execute_skips_existing_templates() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();

		// First execution should create templates.
		$result1 = $tool->execute(
			array( 'overwrite' => false ),
			array( 'user_id' => 1 )
		);
		$this->assertTrue( $result1['success'] );
		$created_count = $result1['templates_created'];

		// Second execution should skip all templates.
		$result2 = $tool->execute(
			array( 'overwrite' => false ),
			array( 'user_id' => 1 )
		);
		$this->assertTrue( $result2['success'] );
		$this->assertEquals( 0, $result2['templates_created'] );
		$this->assertEquals( $created_count, $result2['templates_skipped'] );
	}

	/**
	 * Test that created templates are published.
	 */
	public function test_templates_are_published() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
		$tool->execute(
			array( 'overwrite' => false ),
			array( 'user_id' => 1 )
		);

		// Check that templates are published.
		$templates = get_posts(
			array(
				'post_type'   => 'mcp_task_template',
				'numberposts' => 1,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, count( $templates ) );
	}

	/**
	 * Test that pre-built templates have correct metadata.
	 */
	public function test_templates_have_metadata() {
		// Load the tool class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php';
		}

		$tool   = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
		$result = $tool->execute(
			array( 'overwrite' => false ),
			array( 'user_id' => 1 )
		);

		$this->assertTrue( $result['success'] );

		// Get one template and verify metadata.
		$templates = get_posts(
			array(
				'post_type'   => 'mcp_task_template',
				'numberposts' => 1,
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, count( $templates ) );
		$template = $templates[0];

		// Check metadata exists.
		$category       = get_post_meta( $template->ID, 'category', true );
		$default_config = get_post_meta( $template->ID, 'default_config', true );
		$tags           = get_post_meta( $template->ID, 'tags', true );

		$this->assertNotEmpty( $category );
		$this->assertIsArray( $default_config );
		$this->assertIsArray( $tags );
	}
}
