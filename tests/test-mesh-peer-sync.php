<?php
/**
 * Tests for WP_MCP_AI_Mesh_Peer_Sync class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test mesh peer synchronization with ai_peer CPT.
 */
class Test_Mesh_Peer_Sync extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable mesh computing and directory.
		$settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
			'mesh_peer_sites'             => array(),
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Clean up any existing mesh peer CPT posts.
		$existing_posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		foreach ( $existing_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Test that mesh peer CPT is created when mesh peer is added.
	 */
	public function test_mesh_peer_cpt_created_on_add() {
		$sync = new WP_MCP_AI_Mesh_Peer_Sync();

		$mesh_peers = array(
			array(
				'name'    => 'Test Peer Site',
				'url'     => 'https://example.com',
				'api_key' => 'mesh_test_key_123',
			),
		);

		$sync->sync_mesh_peers( $mesh_peers );

		// Query for mesh peer CPT posts.
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertCount( 1, $posts, 'Should create one mesh peer CPT post' );

		$post = $posts[0];
		$this->assertEquals( 'Test Peer Site', $post->post_title );

		// Check metadata.
		$connection_type = get_post_meta( $post->ID, '_wp_mcp_ai_connection_type', true );
		$this->assertEquals( 'mesh', $connection_type );

		$site_url = get_post_meta( $post->ID, '_wp_mcp_ai_peer_site_url', true );
		$this->assertEquals( 'https://example.com', $site_url );

		$mesh_peer_id = get_post_meta( $post->ID, '_wp_mcp_ai_mesh_peer_id', true );
		$this->assertNotEmpty( $mesh_peer_id );
	}

	/**
	 * Test that mesh peer CPT is updated when mesh peer is modified.
	 */
	public function test_mesh_peer_cpt_updated_on_modify() {
		$sync = new WP_MCP_AI_Mesh_Peer_Sync();

		// Create initial peer.
		$mesh_peers = array(
			array(
				'name'    => 'Test Peer Site',
				'url'     => 'https://example.com',
				'api_key' => 'mesh_test_key_123',
			),
		);

		$sync->sync_mesh_peers( $mesh_peers );

		// Get the created post.
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertCount( 1, $posts );
		$original_post_id = $posts[0]->ID;

		// Update the peer name.
		$mesh_peers = array(
			array(
				'name'    => 'Updated Peer Site',
				'url'     => 'https://example.com',
				'api_key' => 'mesh_test_key_123',
			),
		);

		$sync->sync_mesh_peers( $mesh_peers );

		// Should still have only one post.
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertCount( 1, $posts, 'Should still have only one mesh peer CPT post' );
		$this->assertEquals( $original_post_id, $posts[0]->ID, 'Should be the same post' );
		$this->assertEquals( 'Updated Peer Site', $posts[0]->post_title, 'Title should be updated' );
	}

	/**
	 * Test that mesh peer CPT is deleted when mesh peer is removed.
	 */
	public function test_mesh_peer_cpt_deleted_on_remove() {
		$sync = new WP_MCP_AI_Mesh_Peer_Sync();

		// Create initial peers.
		$mesh_peers = array(
			array(
				'name'    => 'Test Peer Site 1',
				'url'     => 'https://example1.com',
				'api_key' => 'mesh_test_key_1',
			),
			array(
				'name'    => 'Test Peer Site 2',
				'url'     => 'https://example2.com',
				'api_key' => 'mesh_test_key_2',
			),
		);

		$sync->sync_mesh_peers( $mesh_peers );

		// Should have 2 posts.
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertCount( 2, $posts, 'Should have two mesh peer CPT posts' );

		// Remove one peer.
		$mesh_peers = array(
			array(
				'name'    => 'Test Peer Site 1',
				'url'     => 'https://example1.com',
				'api_key' => 'mesh_test_key_1',
			),
		);

		$sync->sync_mesh_peers( $mesh_peers );

		// Should have only 1 post now.
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertCount( 1, $posts, 'Should have only one mesh peer CPT post after removal' );
		$this->assertEquals( 'Test Peer Site 1', $posts[0]->post_title );
	}

	/**
	 * Test validation of mesh peer data.
	 */
	public function test_invalid_mesh_peer_not_synced() {
		$sync = new WP_MCP_AI_Mesh_Peer_Sync();

		// Sync with invalid peers (missing required fields).
		$mesh_peers = array(
			array(
				'name' => 'Valid Peer',
				'url'  => 'https://example.com',
			),
			array(
				'name' => '', // Empty name - invalid.
				'url'  => 'https://example2.com',
			),
			array(
				'name' => 'No URL', // Missing URL - invalid.
			),
			array(
				'name' => 'Invalid URL',
				'url'  => 'not-a-url', // Invalid URL format.
			),
		);

		$sync->sync_mesh_peers( $mesh_peers );

		// Should only create 1 post (the valid one).
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertCount( 1, $posts, 'Should only create CPT for valid peer' );
		$this->assertEquals( 'Valid Peer', $posts[0]->post_title );
	}

	/**
	 * Test that sync is triggered on option update.
	 */
	public function test_sync_triggered_on_option_update() {
		// Simulate updating the option with mesh peers.
		$settings                    = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$settings['mesh_peer_sites'] = array(
			array(
				'name'    => 'Auto Synced Peer',
				'url'     => 'https://autosynced.com',
				'api_key' => 'mesh_auto_key',
			),
		);

		// This should trigger the sync via the update_option hook.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Check if CPT was created.
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_connection_type',
						'value' => 'mesh',
					),
				),
			)
		);

		$this->assertGreaterThan( 0, count( $posts ), 'Should create mesh peer CPT on option update' );
	}
}
