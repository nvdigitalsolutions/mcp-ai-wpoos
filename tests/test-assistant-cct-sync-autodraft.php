<?php
/**
 * Tests for assistant CPT to CCT sync behavior with auto-drafts.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for assistant CCT sync auto-draft handling.
 */
class WP_MCP_AI_Assistant_CCT_Sync_AutoDraft_Test extends WP_UnitTestCase {

	/**
	 * Test that auto-draft posts are not synced to CCT.
	 */
	public function test_auto_draft_not_synced_to_cct() {
		// Create an auto-draft assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'auto-draft',
				'post_title'  => 'Auto Draft Assistant',
			)
		);

		$this->assertGreaterThan( 0, $post_id, 'Auto-draft assistant should be created' );

		// Verify no CCT item ID is linked.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'Auto-draft should not have a CCT item linked' );
	}

	/**
	 * Test that draft posts are not synced to CCT.
	 */
	public function test_draft_not_synced_to_cct() {
		// Create a draft assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft Assistant',
			)
		);

		$this->assertGreaterThan( 0, $post_id, 'Draft assistant should be created' );

		// Verify no CCT item ID is linked.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'Draft should not have a CCT item linked' );
	}

	/**
	 * Test that published posts would be synced to CCT (if JetEngine available).
	 */
	public function test_published_post_sync_behavior() {
		// Create a published assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Published Assistant',
			)
		);

		$this->assertGreaterThan( 0, $post_id, 'Published assistant should be created' );

		// Note: Without JetEngine available in test environment, we can't test actual CCT sync.
		// This test validates that the post is created with correct status.
		$post = get_post( $post_id );
		$this->assertSame( 'publish', $post->post_status, 'Post status should be publish' );
	}

	/**
	 * Test that transitioning from published to draft removes CCT item.
	 */
	public function test_unpublishing_removes_cct_item() {
		// Create a published assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Published Assistant',
			)
		);

		$this->assertGreaterThan( 0, $post_id, 'Published assistant should be created' );

		// Simulate having a CCT item ID (normally set during sync).
		update_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', 123 );

		// Verify CCT item ID exists.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertSame( '123', $cct_item_id, 'CCT item ID should be set' );

		// Transition to draft.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		// Verify CCT item ID is removed.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'CCT item ID should be removed when unpublished' );
	}

	/**
	 * Test that transitioning from auto-draft to draft does not create CCT item.
	 */
	public function test_auto_draft_to_draft_no_cct_sync() {
		// Create an auto-draft assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'auto-draft',
				'post_title'  => 'Auto Draft Assistant',
			)
		);

		// Transition to draft.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		// Verify no CCT item ID is linked.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'Draft (from auto-draft) should not have a CCT item linked' );
	}

	/**
	 * Test that trashing a published post removes CCT item.
	 */
	public function test_trashing_published_removes_cct_item() {
		// Create a published assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Published Assistant',
			)
		);

		// Simulate having a CCT item ID.
		update_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', 456 );

		// Trash the post.
		wp_trash_post( $post_id );

		// Verify CCT item ID is removed.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'CCT item ID should be removed when trashed' );
	}

	/**
	 * Test that restoring from trash to published status allows sync.
	 */
	public function test_restoring_from_trash_allows_sync() {
		// Create a published assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Published Assistant',
			)
		);

		// Trash the post.
		wp_trash_post( $post_id );

		// Restore from trash.
		wp_untrash_post( $post_id );

		// Verify post is published again.
		$post = get_post( $post_id );
		$this->assertSame( 'publish', $post->post_status, 'Post should be published after restore' );
	}

	/**
	 * Test that pending posts are not synced to CCT.
	 */
	public function test_pending_not_synced_to_cct() {
		// Create a pending assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'pending',
				'post_title'  => 'Pending Assistant',
			)
		);

		$this->assertGreaterThan( 0, $post_id, 'Pending assistant should be created' );

		// Verify no CCT item ID is linked.
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'Pending post should not have a CCT item linked' );
	}

	/**
	 * Test sync_to_cct method is protected and exists.
	 */
	public function test_sync_to_cct_method_exists() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Assistant_CPT' );
		$this->assertTrue( $reflection->hasMethod( 'sync_to_cct' ), 'sync_to_cct method should exist' );

		$method = $reflection->getMethod( 'sync_to_cct' );
		$this->assertTrue( $method->isProtected(), 'sync_to_cct should be protected' );
	}

	/**
	 * Test handle_post_status_transition method exists and is public.
	 */
	public function test_handle_post_status_transition_method_exists() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Assistant_CPT' );
		$this->assertTrue( $reflection->hasMethod( 'handle_post_status_transition' ), 'handle_post_status_transition method should exist' );

		$method = $reflection->getMethod( 'handle_post_status_transition' );
		$this->assertTrue( $method->isPublic(), 'handle_post_status_transition should be public' );
	}

	/**
	 * Test that the transition_post_status hook is registered.
	 */
	public function test_transition_post_status_hook_registered() {
		global $wp_filter;

		$has_hook = false;
		if ( isset( $wp_filter['transition_post_status'] ) ) {
			foreach ( $wp_filter['transition_post_status']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) &&
						 isset( $callback['function'][1] ) &&
						 'handle_post_status_transition' === $callback['function'][1] ) {
						$has_hook = true;
						break 2;
					}
				}
			}
		}

		$this->assertTrue( $has_hook, 'transition_post_status hook should be registered for handle_post_status_transition' );
	}
}
