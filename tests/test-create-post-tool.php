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

	/**
	 * Test creating post with featured image.
	 */
	public function test_create_with_featured_image() {
		// Create a test attachment.
		$attachment_id = $this->factory->attachment->create_upload_object(
			dirname( __DIR__ ) . '/assets/images/icon-256x256.png'
		);

		$arguments = array(
			'title'             => 'Test Post with Featured Image',
			'content'           => 'Test content',
			'featured_image_id' => $attachment_id,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $result['ID'] ) );
	}

	/**
	 * Test creating post with categories.
	 */
	public function test_create_with_categories() {
		$cat1 = $this->factory->category->create( array( 'name' => 'Test Category 1' ) );
		$cat2 = $this->factory->category->create( array( 'name' => 'Test Category 2' ) );

		$arguments = array(
			'title'      => 'Test Post with Categories',
			'content'    => 'Test content',
			'categories' => array( $cat1, 'Test Category 3' ), // Mix of ID and name.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		$post_categories = wp_get_post_categories( $result['ID'] );
		$this->assertContains( $cat1, $post_categories );
		$this->assertCount( 2, $post_categories ); // Should have created the third category.
	}

	/**
	 * Test creating post with tags.
	 */
	public function test_create_with_tags() {
		$arguments = array(
			'title'   => 'Test Post with Tags',
			'content' => 'Test content',
			'tags'    => array( 'tag1', 'tag2', 'tag3' ),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		$post_tags = wp_get_post_tags( $result['ID'], array( 'fields' => 'names' ) );
		$this->assertContains( 'tag1', $post_tags );
		$this->assertContains( 'tag2', $post_tags );
		$this->assertContains( 'tag3', $post_tags );
	}

	/**
	 * Test creating page with page template.
	 */
	public function test_create_page_with_template() {
		$arguments = array(
			'title'         => 'Test Page',
			'content'       => 'Test page content',
			'post_type'     => 'page',
			'page_template' => 'default',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertEquals( 'page', $result['post_type'] );
		$this->assertEquals( 'default', get_post_meta( $result['ID'], '_wp_page_template', true ) );
	}

	/**
	 * Test creating hierarchical post with parent.
	 */
	public function test_create_with_parent() {
		// Create parent page.
		$parent_id = $this->factory->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Parent Page',
			)
		);

		$arguments = array(
			'title'       => 'Child Page',
			'content'     => 'Test content',
			'post_type'   => 'page',
			'post_parent' => $parent_id,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		$post = get_post( $result['ID'] );
		$this->assertEquals( $parent_id, $post->post_parent );
	}

	/**
	 * Test creating post with custom meta fields.
	 */
	public function test_create_with_meta_input() {
		$arguments = array(
			'title'      => 'Test Post with Meta',
			'content'    => 'Test content',
			'meta_input' => array(
				'custom_field_1' => 'value1',
				'custom_field_2' => 'value2',
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertEquals( 'value1', get_post_meta( $result['ID'], 'custom_field_1', true ) );
		$this->assertEquals( 'value2', get_post_meta( $result['ID'], 'custom_field_2', true ) );
	}

	/**
	 * Test creating post with comment and ping status.
	 */
	public function test_create_with_comment_ping_status() {
		$arguments = array(
			'title'          => 'Test Post',
			'content'        => 'Test content',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		$post = get_post( $result['ID'] );
		$this->assertEquals( 'closed', $post->comment_status );
		$this->assertEquals( 'closed', $post->ping_status );
	}

	/**
	 * Test creating post with menu order.
	 */
	public function test_create_with_menu_order() {
		$arguments = array(
			'title'      => 'Test Post',
			'content'    => 'Test content',
			'post_type'  => 'page',
			'menu_order' => 5,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		$post = get_post( $result['ID'] );
		$this->assertEquals( 5, $post->menu_order );
	}

	/**
	 * Test creating post with excerpt and slug.
	 */
	public function test_create_with_excerpt_and_slug() {
		$arguments = array(
			'title'   => 'Test Post',
			'content' => 'Test content',
			'excerpt' => 'This is a test excerpt',
			'slug'    => 'custom-test-slug',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );

		$post = get_post( $result['ID'] );
		$this->assertEquals( 'This is a test excerpt', $post->post_excerpt );
		$this->assertEquals( 'custom-test-slug', $post->post_name );
	}
}
