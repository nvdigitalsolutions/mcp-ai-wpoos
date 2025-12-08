<?php
/**
 * Tests for Get Recent Posts Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Get_Recent_Posts_Validated
 *
 * Tests for the validated get_recent_posts tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Get_Recent_Posts_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_Recent_Posts_Validated
	 */
	private $tool;

	/**
	 * Test user ID with read capability.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-get-recent-posts-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-get-recent-posts-validated.php';

		// Create test user with read capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Get_Recent_Posts_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'get_recent_posts_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Symfony Validator', $this->tool->get_description() );
	}

	/**
	 * Test getting recent posts with default arguments.
	 */
	public function test_get_recent_posts_with_defaults() {
		// Create some test posts.
		$this->factory->post->create_many( 3 );

		$arguments = array();
		$context   = array( 'user_id' => $this->user_id );
		$result    = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertIsArray( $result['posts'] );
	}

	/**
	 * Test getting recent posts with custom limit.
	 */
	public function test_get_recent_posts_with_custom_limit() {
		// Create 10 test posts.
		$this->factory->post->create_many( 10 );

		$arguments = array(
			'limit' => 3,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertLessThanOrEqual( 3, count( $result['posts'] ) );
	}

	/**
	 * Test validation fails with limit below minimum.
	 */
	public function test_validation_fails_with_limit_below_minimum() {
		$arguments = array(
			'limit' => 0,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for limit below minimum' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with limit above maximum.
	 */
	public function test_validation_fails_with_limit_above_maximum() {
		$arguments = array(
			'limit' => 51, // Exceeds maximum of 50.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for limit above maximum' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test getting posts with custom post type.
	 */
	public function test_get_posts_with_custom_post_type() {
		// Create some pages (different post type).
		$this->factory->post->create_many( 3, array( 'post_type' => 'page' ) );

		$arguments = array(
			'post_type' => 'page',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
	}

	/**
	 * Test validation fails with empty post type.
	 */
	public function test_validation_fails_with_empty_post_type() {
		$arguments = array(
			'post_type' => '',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for empty post type' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test tool capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read', $flags );
		$this->assertContains( 'local-only', $flags );
	}
}
