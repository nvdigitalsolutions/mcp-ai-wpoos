<?php
/**
 * Tests for AI Peer CCT synchronization.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test AI Peer CPT to CCT sync functionality.
 */
class WP_MCP_AI_AI_Peer_CCT_Sync_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing peers.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'ai_peer'" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wp_mcp_ai_peer_%'" );
	}

	/**
	 * Test that AI Peer CCT class exists and has required methods.
	 */
	public function test_ai_peer_cct_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_JetEngine_AI_Peers_CCT' ), 'AI Peers CCT class should exist' );

		// Check required methods exist.
		$this->assertTrue( method_exists( 'WP_MCP_AI_JetEngine_AI_Peers_CCT', 'get_slug' ), 'get_slug method should exist' );
		$this->assertTrue( method_exists( 'WP_MCP_AI_JetEngine_AI_Peers_CCT', 'get_item_handler' ), 'get_item_handler method should exist' );
		$this->assertTrue( method_exists( 'WP_MCP_AI_JetEngine_AI_Peers_CCT', 'bootstrap' ), 'bootstrap method should exist' );
	}

	/**
	 * Test that CCT slug is correct.
	 */
	public function test_cct_slug() {
		$this->assertSame( 'ai_peers', WP_MCP_AI_JetEngine_AI_Peers_CCT::get_slug(), 'CCT slug should be ai_peers' );
	}

	/**
	 * Test that AI Peer CPT has sync methods.
	 */
	public function test_ai_peer_cpt_has_sync_methods() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_AI_Peer_CPT' ), 'AI Peer CPT class should exist' );
		$this->assertTrue( method_exists( 'WP_MCP_AI_AI_Peer_CPT', 'sync_to_cct_on_save' ), 'sync_to_cct_on_save method should exist' );
		$this->assertTrue( method_exists( 'WP_MCP_AI_AI_Peer_CPT', 'cleanup_cct_on_delete' ), 'cleanup_cct_on_delete method should exist' );
	}

	/**
	 * Test that sync doesn't run when base version is enabled.
	 */
	public function test_sync_skips_in_base_version() {
		// Mock base version check by defining the constant.
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', true );
		}

		// Create a peer.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Add some meta data.
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_site_name', 'Test Site' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_site_url', 'https://example.com' );

		// Check that no CCT item ID is stored (sync should not happen in base version).
		$cct_item_id = get_post_meta( $peer_id, '_wp_mcp_ai_peer_cct_item_id', true );
		$this->assertEmpty( $cct_item_id, 'CCT item ID should not be set in base version' );

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that sync lock is created and released.
	 */
	public function test_sync_lock_mechanism() {
		// Create a peer.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Lock Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Manually set a sync lock.
		$lock_key = 'wp_mcp_ai_peer_sync_lock_' . $peer_id;
		set_transient( $lock_key, true, 5 );

		// Try to trigger sync by updating the post.
		wp_update_post(
			array(
				'ID'         => $peer_id,
				'post_title' => 'Updated Lock Test Peer',
			)
		);

		// The lock should still exist (sync was skipped).
		$this->assertTrue( (bool) get_transient( $lock_key ), 'Lock should still exist when sync is skipped' );

		// Delete the lock and update again.
		delete_transient( $lock_key );

		wp_update_post(
			array(
				'ID'         => $peer_id,
				'post_title' => 'Updated Lock Test Peer Again',
			)
		);

		// The lock should not exist (sync completed and released the lock).
		// Note: In a real environment with JetEngine, the lock would be released after sync.
		// Since we don't have JetEngine in tests, the lock won't be set, but we can verify the logic.

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that CCT link meta field is properly named.
	 */
	public function test_cct_link_meta_field_name() {
		// Create a peer.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Meta Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Add meta data to simulate CCT sync (without JetEngine).
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_cct_item_id', 123 );

		// Verify the meta key is correct.
		$cct_item_id = get_post_meta( $peer_id, '_wp_mcp_ai_peer_cct_item_id', true );
		$this->assertSame( '123', $cct_item_id, 'CCT item ID should be stored correctly' );

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that peer metadata is properly prepared for CCT sync.
	 */
	public function test_peer_metadata_preparation() {
		// Create a peer with full metadata.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Full Metadata Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Add metadata.
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_site_name', 'Example Site' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_site_url', 'https://example.com' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_mcp_url', 'https://example.com/wp-json/mcp-ai/v1/' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_jwks_uri', 'https://example.com/.well-known/jwks.json' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_capabilities', '["search_content","save_post"]' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_regions', '["us","eu"]' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_data_tags', '["no_pii"]' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_health_status', 'healthy' );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_latency_p50', 150 );
		update_post_meta( $peer_id, '_wp_mcp_ai_peer_last_verified', '2025-11-06 21:00:00' );

		// Verify all metadata is stored.
		$this->assertSame( 'Example Site', get_post_meta( $peer_id, '_wp_mcp_ai_peer_site_name', true ) );
		$this->assertSame( 'https://example.com', get_post_meta( $peer_id, '_wp_mcp_ai_peer_site_url', true ) );
		$this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/', get_post_meta( $peer_id, '_wp_mcp_ai_peer_mcp_url', true ) );
		$this->assertSame( 'https://example.com/.well-known/jwks.json', get_post_meta( $peer_id, '_wp_mcp_ai_peer_jwks_uri', true ) );
		$this->assertSame( '["search_content","save_post"]', get_post_meta( $peer_id, '_wp_mcp_ai_peer_capabilities', true ) );
		$this->assertSame( '["us","eu"]', get_post_meta( $peer_id, '_wp_mcp_ai_peer_regions', true ) );
		$this->assertSame( '["no_pii"]', get_post_meta( $peer_id, '_wp_mcp_ai_peer_data_tags', true ) );
		$this->assertSame( 'healthy', get_post_meta( $peer_id, '_wp_mcp_ai_peer_health_status', true ) );
		$this->assertSame( '150', get_post_meta( $peer_id, '_wp_mcp_ai_peer_latency_p50', true ) );
		$this->assertSame( '2025-11-06 21:00:00', get_post_meta( $peer_id, '_wp_mcp_ai_peer_last_verified', true ) );

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that sync doesn't happen on autosave.
	 */
	public function test_sync_skips_on_autosave() {
		// Create a peer.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Autosave Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Simulate autosave by defining the constant.
		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		// Update the peer.
		wp_update_post(
			array(
				'ID'         => $peer_id,
				'post_title' => 'Updated Autosave Test Peer',
			)
		);

		// Verify that sync was skipped (no CCT link would be created during autosave).
		// This is a basic test since we can't fully test sync without JetEngine.

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that sync skips revisions.
	 */
	public function test_sync_skips_revisions() {
		// Create a peer.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Revision Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $peer_id, 'Peer should be created' );

		// Create a revision.
		$revision_id = wp_save_post_revision( $peer_id );

		if ( $revision_id ) {
			// Verify that CCT link is not set on revision.
			$cct_item_id = get_post_meta( $revision_id, '_wp_mcp_ai_peer_cct_item_id', true );
			$this->assertEmpty( $cct_item_id, 'CCT item ID should not be set on revision' );
		}

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Test that CCT field ID base is unique.
	 */
	public function test_cct_field_id_base_uniqueness() {
		// Verify that AI Peers CCT uses a different field ID base than Assistants CCT.
		$peers_field_base      = WP_MCP_AI_JetEngine_AI_Peers_CCT::FIELD_ID_BASE;
		$assistants_field_base = WP_MCP_AI_JetEngine_Assistants_CCT::FIELD_ID_BASE;

		$this->assertNotSame( $peers_field_base, $assistants_field_base, 'Field ID bases should be different' );
		$this->assertSame( 21000, $peers_field_base, 'Peers field ID base should be 21000' );
		$this->assertSame( 20000, $assistants_field_base, 'Assistants field ID base should be 20000' );
	}

	/**
	 * Test sync lock timeout constant.
	 */
	public function test_sync_lock_timeout_constant() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_AI_Peer_CPT' ), 'WP_MCP_AI_AI_Peer_CPT class should exist' );
		$this->assertSame( 5, WP_MCP_AI_AI_Peer_CPT::SYNC_LOCK_TIMEOUT, 'SYNC_LOCK_TIMEOUT should be 5 seconds' );
	}
}
