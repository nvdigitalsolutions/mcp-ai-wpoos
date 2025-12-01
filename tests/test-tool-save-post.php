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
}
