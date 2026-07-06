<?php
/**
 * Tests for save_post tool — security and validation paths.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test save_post tool security and edge-case validation paths.
 */
class Test_Tool_Save_Post_Security extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Save_Post
	 */
	private $tool;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool          = new WP_MCP_AI_Tool_Save_Post();
		$this->editor_id     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'content' => 'Hello world' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * New post without title returns missing_title.
	 */
	public function test_new_post_without_title_returns_error() {
		$result = $this->tool->execute(
			array( 'content' => 'No title here' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );
	}

	/**
	 * Missing content returns missing_content.
	 */
	public function test_missing_content_returns_error() {
		$result = $this->tool->execute(
			array( 'title' => 'Only Title' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_content', $result->get_error_code() );
	}

	/**
	 * Non-existent post_id returns invalid_post.
	 */
	public function test_nonexistent_post_id_returns_error() {
		$result = $this->tool->execute(
			array(
				'post_id' => 999999,
				'content' => 'Content',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_post', $result->get_error_code() );
	}

	/**
	 * Subscriber cannot edit another user's post.
	 */
	public function test_subscriber_cannot_edit_others_post() {
		$post_id = $this->factory->post->create( array( 'post_author' => $this->editor_id ) );

		$result = $this->tool->execute(
			array(
				'post_id' => $post_id,
				'content' => 'Hostile update',
			),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Post type mismatch returns invalid_post_type.
	 */
	public function test_post_type_mismatch_returns_error() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_author' => $this->editor_id,
			)
		);

		$result = $this->tool->execute(
			array(
				'post_id'   => $post_id,
				'post_type' => 'page',
				'content'   => 'Mismatch',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_post_type', $result->get_error_code() );
	}

	/**
	 * Editor successfully updates an existing post.
	 */
	public function test_editor_updates_post_successfully() {
		$post_id = $this->factory->post->create( array( 'post_author' => $this->editor_id ) );

		$result = $this->tool->execute(
			array(
				'post_id' => $post_id,
				'content' => 'Updated content.',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['ID'] );
	}
}
