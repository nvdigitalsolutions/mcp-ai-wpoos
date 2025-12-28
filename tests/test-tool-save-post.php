<?php
/**
 * Tests for the save_post assistant tool.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Tool_Save_Post_Test extends WP_UnitTestCase {

	/**
	 * Ensure new posts created via the tool use paragraph blocks for plain text content.
	 */
	public function test_execute_wraps_plain_text_content_in_paragraph_blocks() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Block Post',
				'content'   => "First paragraph.\n\nSecond paragraph.",
				'status'    => 'publish',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ID', $result );

		$post = get_post( $result['ID'] );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $post->post_content );
		$this->assertStringContainsString( 'First paragraph.', $post->post_content );
		$this->assertStringContainsString( 'Second paragraph.', $post->post_content );

		wp_delete_post( $post->ID, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Ensure HTML content is preserved within an HTML block when creating standard posts.
	 */
	public function test_execute_wraps_html_content_in_html_block() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_type' => 'post',
				'title'     => 'HTML Block Post',
				'content'   => '<p>Content with <strong>formatting</strong>.</p>',
				'status'    => 'draft',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$post = get_post( $result['ID'] );

		$this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
		$this->assertStringContainsString( '<strong>formatting</strong>', $post->post_content );

		wp_delete_post( $post->ID, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Ensure non-standard post types do not force block conversion.
	 */
	public function test_execute_leaves_non_post_content_unchanged() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Page Content',
				'content'   => 'Simple page content.',
				'status'    => 'draft',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$post = get_post( $result['ID'] );

		$this->assertSame( 'Simple page content.', $post->post_content );

		wp_delete_post( $post->ID, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Test updating post with categories and tags.
	 */
	public function test_execute_updates_taxonomies() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a post first.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content',
			)
		);

		$cat_id = $this->factory->category->create( array( 'name' => 'Test Category' ) );

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_id'    => $post_id,
				'content'    => 'Updated content',
				'categories' => array( $cat_id ),
				'tags'       => array( 'tag1', 'tag2' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $post_id, $result['ID'] );

		// Verify categories.
		$post_categories = wp_get_post_categories( $post_id );
		$this->assertContains( $cat_id, $post_categories );

		// Verify tags.
		$post_tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
		$this->assertContains( 'tag1', $post_tags );
		$this->assertContains( 'tag2', $post_tags );

		wp_delete_post( $post_id, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Test updating post with featured image.
	 */
	public function test_execute_sets_featured_image() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'Test content',
			)
		);

		// Create an attachment.
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => 'test-image.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_id'           => $post_id,
				'content'           => 'Updated content',
				'featured_image_id' => $attachment_id,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $post_id ) );

		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Test updating post with custom meta fields.
	 */
	public function test_execute_updates_meta_fields() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'Test content',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_id'    => $post_id,
				'content'    => 'Updated content',
				'meta_input' => array(
					'custom_key_1' => 'custom_value_1',
					'custom_key_2' => 'custom_value_2',
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'custom_value_1', get_post_meta( $post_id, 'custom_key_1', true ) );
		$this->assertEquals( 'custom_value_2', get_post_meta( $post_id, 'custom_key_2', true ) );

		wp_delete_post( $post_id, true );
		wp_set_current_user( 0 );
	}

	/**
	 * Test creating post with page parent.
	 */
	public function test_execute_sets_post_parent() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create parent page.
		$parent_id = $this->factory->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Parent Page',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Save_Post();
		$result = $tool->execute(
			array(
				'post_type'   => 'page',
				'title'       => 'Child Page',
				'content'     => 'Child content',
				'post_parent' => $parent_id,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$post = get_post( $result['ID'] );
		$this->assertEquals( $parent_id, $post->post_parent );

		wp_delete_post( $result['ID'], true );
		wp_delete_post( $parent_id, true );
		wp_set_current_user( 0 );
	}
}
