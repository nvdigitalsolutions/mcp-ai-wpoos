<?php
/**
 * Tests for Create Post Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Create_Post_Validated
 *
 * Tests for the validated create_post tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Create_Post_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Post_Validated
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

		// Skip if PHP < 8.0 (Symfony Validator attributes require PHP 8.0+).
		if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
			$this->markTestSkipped( 'Symfony Validator requires PHP 8.0+' );
		}

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-create-post-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/validators/constraints/class-wp-capability-constraint.php';
		require_once dirname( __DIR__ ) . '/includes/validators/constraints/class-wp-capability-validator.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-post-validated.php';

		// Create test user with edit_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Create_Post_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'create_post_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
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
	 * Test validation fails when title is missing.
	 */
	public function test_validation_fails_without_title() {
		$arguments = array(
			'content'   => 'Test content',
			'post_type' => 'post',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for missing title' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
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
	 * Test validation accepts valid statuses.
	 */
	public function test_validation_accepts_valid_statuses() {
		$valid_statuses = array( 'draft', 'publish', 'pending', 'private' );

		foreach ( $valid_statuses as $status ) {
			$arguments = array(
				'title'   => "Test $status Post",
				'content' => 'Test content',
				'status'  => $status,
			);

			$context = array( 'user_id' => $this->user_id );
			$result  = $this->tool->execute( $arguments, $context );

			$this->assertIsArray( $result, "Should accept status: $status" );
			$this->assertEquals( $status, $result['status'] );
		}
	}

	/**
	 * Test validation fails with title exceeding max length.
	 */
	public function test_validation_fails_with_long_title() {
		$long_title = str_repeat( 'a', 201 ); // Exceeds 200 character limit.

		$arguments = array(
			'title'   => $long_title,
			'content' => 'Test content',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for title too long' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid post type format.
	 */
	public function test_validation_fails_with_invalid_post_type() {
		$arguments = array(
			'title'     => 'Test Post',
			'content'   => 'Test content',
			'post_type' => 'Invalid-Post-Type!', // Contains invalid characters.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid post type format' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test creating post with custom author.
	 */
	public function test_create_with_custom_author() {
		// Create another user with edit_posts capability.
		$author_id = $this->factory->user->create(
			array(
				'role' => 'author',
			)
		);

		$arguments = array(
			'title'   => 'Test Post',
			'content' => 'Test content',
			'user_id' => $author_id,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Should create post with custom author' );
		$this->assertEquals( $author_id, $result['author_id'] );
	}

	/**
	 * Test capability flags.
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
	 * Test that parameters schema matches original tool.
	 */
	public function test_parameters_schema_matches_original() {
		// Load original tool.
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-post.php';
		$original_tool = new WP_MCP_AI_Tool_Create_Post();

		$validated_schema = $this->tool->get_parameters_schema();
		$original_schema  = $original_tool->get_parameters_schema();

		$this->assertEquals( $original_schema, $validated_schema, 'Schemas should match' );
	}
}
