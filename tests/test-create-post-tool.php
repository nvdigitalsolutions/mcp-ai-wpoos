<?php
/**
 * Tests for Create Post Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Create_Post
 *
 * Tests for the create_post tool.
 */
class Test_WP_MCP_AI_Tool_Create_Post extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Post
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

		// Load tool.
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-post.php';

		// Create test user with edit_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Create_Post();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'create_post', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Creates a new', $this->tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required fields.
		$this->assertContains( 'title', $schema['required'] );
		$this->assertContains( 'content', $schema['required'] );

		// Check properties exist.
		$this->assertArrayHasKey( 'title', $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );
	}

	/**
	 * Test creating a post with valid data.
	 */
	public function test_create_post_with_valid_data() {
		$arguments = array(
			'title'   => 'Test Post Title',
			'content' => 'Test post content with some text.',
			'status'  => 'draft',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'author_id', $result );
		$this->assertEquals( 'Test Post Title', $result['title'] );
		$this->assertEquals( 'draft', $result['status'] );
		$this->assertEquals( $this->user_id, $result['author_id'] );

		// Verify post was created.
		$post = get_post( $result['ID'] );
		$this->assertNotNull( $post );
		$this->assertEquals( 'Test Post Title', $post->post_title );
	}

	/**
	 * Test fails without title.
	 */
	public function test_fails_without_title() {
		$arguments = array(
			'content' => 'Test content',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_title', $result->get_error_code() );
	}

	/**
	 * Test fails without content.
	 */
	public function test_fails_without_content() {
		$arguments = array(
			'title' => 'Test Title',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_content', $result->get_error_code() );
	}

	/**
	 * Test creating a post with custom post type.
	 */
	public function test_create_with_custom_post_type() {
		// Register a custom post type.
		register_post_type( 'test_type', array( 'public' => true ) );

		$arguments = array(
			'title'     => 'Test Custom Type',
			'content'   => 'Test content',
			'post_type' => 'test_type',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertEquals( 'test_type', $result['post_type'] );

		// Clean up.
		unregister_post_type( 'test_type' );
	}

	/**
	 * Test creating a post with different status values.
	 */
	public function test_create_with_different_statuses() {
		$statuses = array( 'draft', 'publish', 'pending', 'private' );

		foreach ( $statuses as $status ) {
			$arguments = array(
				'title'   => "Test $status Post",
				'content' => 'Test content',
				'status'  => $status,
			);

			$context = array( 'user_id' => $this->user_id );
			$result  = $this->tool->execute( $arguments, $context );

			$this->assertIsArray( $result, "Should create post with status: $status" );
			$this->assertEquals( $status, $result['status'] );
		}
	}

	/**
	 * Test creating a post with custom author.
	 */
	public function test_create_with_custom_author() {
		// Create another user.
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

		$this->assertIsArray( $result );
		$this->assertEquals( $author_id, $result['author_id'] );
	}

	/**
	 * Test invalid author user ID.
	 */
	public function test_fails_with_invalid_author() {
		$arguments = array(
			'title'   => 'Test Post',
			'content' => 'Test content',
			'user_id' => 99999, // Non-existent user.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_user', $result->get_error_code() );
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
	 * Test block content conversion for plain text.
	 */
	public function test_block_content_conversion() {
		$arguments = array(
			'title'   => 'Test Block Conversion',
			'content' => "First paragraph.\n\nSecond paragraph.",
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		// Verify post content has block markup.
		$post = get_post( $result['ID'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $post->post_content );
	}
}
