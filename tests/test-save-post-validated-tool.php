<?php
/**
 * Tests for Save Post Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Save_Post_Validated
 *
 * Tests for the validated save_post tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Save_Post_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Save_Post_Validated
	 */
	private $tool;

	/**
	 * Test user ID.
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
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-save-post-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/validators/constraints/class-wp-post-exists-constraint.php';
		require_once dirname( __DIR__ ) . '/includes/validators/constraints/class-wp-post-exists-validator.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-save-post-validated.php';

		// Create test user with edit_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Save_Post_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'save_post_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test creating a new post with valid data.
	 */
	public function test_create_post_with_valid_data() {
		$arguments = array(
			'title'     => 'Test Post Title',
			'content'   => 'Test post content with some text.',
			'post_type' => 'post',
			'status'    => 'draft',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertEquals( 'Test Post Title', $result['title'] );
		$this->assertEquals( 'draft', $result['status'] );
	}

	/**
	 * Test validation fails when content is missing.
	 */
	public function test_validation_fails_without_content() {
		$arguments = array(
			'title'     => 'Test Post',
			'post_type' => 'post',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for missing content' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid post status.
	 */
	public function test_validation_fails_with_invalid_status() {
		$arguments = array(
			'title'   => 'Test Post',
			'content' => 'Test content',
			'status'  => 'invalid_status',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid status' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test updating an existing post.
	 */
	public function test_update_existing_post() {
		// Create a post first.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content',
				'post_status'  => 'draft',
				'post_author'  => $this->user_id,
			)
		);

		$arguments = array(
			'post_id' => $post_id,
			'title'   => 'Updated Title',
			'content' => 'Updated content',
			'status'  => 'publish',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertEquals( $post_id, $result['ID'] );
		$this->assertEquals( 'Updated Title', $result['title'] );
		$this->assertEquals( 'publish', $result['status'] );
	}

	/**
	 * Test validation fails with non-existent post ID.
	 */
	public function test_validation_fails_with_nonexistent_post() {
		$arguments = array(
			'post_id' => 99999999,
			'title'   => 'Test',
			'content' => 'Test content',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test creating post with excerpt and slug.
	 */
	public function test_create_post_with_excerpt_and_slug() {
		$arguments = array(
			'title'   => 'Full Featured Post',
			'content' => 'Post content here.',
			'excerpt' => 'This is an excerpt.',
			'slug'    => 'full-featured-post',
			'status'  => 'draft',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ID', $result );

		$post = get_post( $result['ID'] );
		$this->assertEquals( 'This is an excerpt.', $post->post_excerpt );
		$this->assertEquals( 'full-featured-post', $post->post_name );
	}

	/**
	 * Test validation ensures slug format.
	 */
	public function test_validation_fails_with_invalid_slug() {
		$arguments = array(
			'title'   => 'Test Post',
			'content' => 'Test content',
			'slug'    => 'Invalid Slug With Spaces',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test capability flags are returned correctly.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'reversible', $flags );
	}

	/**
	 * Test block content conversion for plain text.
	 */
	public function test_block_content_conversion() {
		$arguments = array(
			'title'     => 'Plain Text Post',
			'content'   => 'This is plain text content.',
			'post_type' => 'post',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$post = get_post( $result['ID'] );

		// Should be wrapped in paragraph blocks.
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $post->post_content );
	}
}
