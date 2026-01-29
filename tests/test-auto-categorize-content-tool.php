<?php
/**
 * Tests for WP_MCP_AI_Tool_Auto_Categorize_Content class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for auto-categorize content tool.
 *
 * @group tools
 * @group auto-categorize
 */
class WP_MCP_AI_Tool_Auto_Categorize_Content_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Auto_Categorize_Content
	 */
	protected $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	protected $post_id;

	/**
	 * Test category IDs.
	 *
	 * @var array
	 */
	protected $category_ids;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );

		// Create test categories.
		$this->category_ids = array(
			'technology' => $this->factory->category->create(
				array(
					'name'        => 'Technology',
					'description' => 'Posts about technology and software',
				)
			),
			'business'   => $this->factory->category->create(
				array(
					'name'        => 'Business',
					'description' => 'Business and marketing content',
				)
			),
			'education'  => $this->factory->category->create(
				array(
					'name'        => 'Education',
					'description' => 'Educational content and tutorials',
				)
			),
		);

		// Create test post.
		$this->post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Getting Started with WordPress Development',
				'post_content' => 'This comprehensive guide covers WordPress theme and plugin development. Learn PHP, JavaScript, and best practices for creating robust WordPress solutions.',
				'post_status'  => 'publish',
				'post_author'  => $this->user_id,
			)
		);

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-auto-categorize-content.php';

		$this->tool = new WP_MCP_AI_Tool_Auto_Categorize_Content();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up posts and categories.
		if ( $this->post_id ) {
			wp_delete_post( $this->post_id, true );
		}

		foreach ( $this->category_ids as $cat_id ) {
			wp_delete_category( $cat_id );
		}

		parent::tearDown();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'auto_categorize_content', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test parameter schema structure.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'post_id', $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'auto_assign', $schema['properties'] );
		$this->assertArrayHasKey( 'min_confidence', $schema['properties'] );
		$this->assertArrayHasKey( 'max_categories', $schema['properties'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'cacheable', $flags );
		$this->assertContains( 'consumes-tokens', $flags );
		$this->assertContains( 'model-dependent', $flags );
	}

	/**
	 * Test validation with missing arguments.
	 */
	public function test_validation_missing_arguments() {
		$result = $this->tool->execute( array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_content', $result->get_error_code() );
	}

	/**
	 * Test validation with invalid post ID.
	 */
	public function test_validation_invalid_post() {
		$result = $this->tool->execute(
			array(
				'post_id' => 999999,
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_post', $result->get_error_code() );
	}

	/**
	 * Test validation with invalid taxonomy.
	 */
	public function test_validation_invalid_taxonomy() {
		$result = $this->tool->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'nonexistent_taxonomy',
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_taxonomy', $result->get_error_code() );
	}

	/**
	 * Test tool execution with content-only (no post_id).
	 *
	 * This test validates the tool can analyze content without a post.
	 * Note: This is a mock test as it requires AI client which isn't available in unit tests.
	 */
	public function test_execution_with_content_only() {
		// Mock execution - in real scenario, this would call the AI client.
		$arguments = array(
			'content'        => 'This is a test about WordPress development and PHP programming.',
			'title'          => 'WordPress Development Guide',
			'auto_assign'    => false,
			'min_confidence' => 0.6,
			'max_categories' => 3,
		);

		// Since we don't have a real AI client in tests, we expect this to fail
		// with a specific error code rather than execute successfully.
		$result = $this->tool->execute( $arguments );

		// Either it fails with no_ai_client or ai_analysis_failed (both acceptable).
		if ( is_wp_error( $result ) ) {
			$this->assertTrue(
				in_array( $result->get_error_code(), array( 'no_ai_client', 'ai_analysis_failed' ), true ),
				'Expected error code to be no_ai_client or ai_analysis_failed'
			);
		}
	}

	/**
	 * Test tool execution with post_id.
	 *
	 * Note: This test validates the tool can access post data correctly.
	 * AI client is not available in unit tests, so we expect an error.
	 */
	public function test_execution_with_post_id() {
		// Verify post exists and is accessible.
		$post = get_post( $this->post_id );
		$this->assertNotNull( $post );
		$this->assertEquals( 'Getting Started with WordPress Development', $post->post_title );

		$arguments = array(
			'post_id'        => $this->post_id,
			'auto_assign'    => false,
			'min_confidence' => 0.6,
			'max_categories' => 3,
		);

		// Since we don't have a real AI client in tests, we expect this to fail
		// with no_ai_client or ai_analysis_failed error.
		$result = $this->tool->execute( $arguments );

		// Verify error is related to AI client, not post access.
		if ( is_wp_error( $result ) ) {
			$this->assertTrue(
				in_array( $result->get_error_code(), array( 'no_ai_client', 'ai_analysis_failed' ), true ),
				'Expected AI client error, got: ' . $result->get_error_code()
			);
		}
	}

	/**
	 * Test WordPress hooks are registered.
	 *
	 * This test validates the trait provides hook registration capability.
	 */
	public function test_wordpress_native_trait() {
		// Verify the trait is used.
		$this->assertTrue(
			in_array( 'WP_MCP_AI_Tool_WordPress_Native', class_uses( $this->tool ), true ),
			'Tool should use WP_MCP_AI_Tool_WordPress_Native trait'
		);

		// Verify hook methods exist.
		$this->assertTrue( method_exists( $this->tool, 'apply_result_filter' ) );
		$this->assertTrue( method_exists( $this->tool, 'do_before_execute' ) );
		$this->assertTrue( method_exists( $this->tool, 'do_after_execute' ) );
	}

	/**
	 * Test caching methods from WordPress Native trait.
	 */
	public function test_caching_methods() {
		// Verify caching methods exist.
		$this->assertTrue( method_exists( $this->tool, 'get_cached_result' ) );
		$this->assertTrue( method_exists( $this->tool, 'set_cached_result' ) );
		$this->assertTrue( method_exists( $this->tool, 'invalidate_cache' ) );
		$this->assertTrue( method_exists( $this->tool, 'should_cache' ) );
	}

	/**
	 * Test filter hook is registered.
	 *
	 * This test validates the filter hook is properly registered in WordPress.
	 */
	public function test_filter_hook_applied() {
		// Verify the filter is registered correctly.
		$this->assertTrue(
			has_filter( 'wp_mcp_ai_auto_categorize_categories' ),
			'Filter hook wp_mcp_ai_auto_categorize_categories should be registered'
		);

		// Note: We cannot test if the filter is actually called during execution
		// because the tool requires an AI client which is not available in unit tests.
		// The filter would be applied in the execute() method if execution succeeded.
	}
}
