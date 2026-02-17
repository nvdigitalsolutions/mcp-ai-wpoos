<?php
/**
 * Test AI Peer CPT creation when mesh peers are added.
 *
 * Verifies that ai_peer CPT entries are created when mesh peers are added
 * through settings, regardless of federation directory status.
 *
 * @package WP_MCP_AI
 */

/**
 * Test mesh peer CPT creation.
 */
class Test_Mesh_Peer_CPT_Creation extends WP_UnitTestCase {

	/**
	 * Test that mesh peer sync initializes when only mesh is enabled.
	 */
	public function test_mesh_sync_initializes_without_federation_directory() {
		// Enable mesh but NOT federation directory.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'                => true,
				'enable_federation_directory' => false,
				'mesh_inbound_api_key'       => 'mesh_test123',
			)
		);

		// Reinitialize federation to pick up new settings.
		$federation = new WP_MCP_AI_Federation();

		// Verify that mesh_peer_sync was initialized.
		$reflection = new ReflectionClass( $federation );
		$property   = $reflection->getProperty( 'mesh_peer_sync' );
		$property->setAccessible( true );
		$mesh_sync = $property->getValue( $federation );

		$this->assertInstanceOf(
			'WP_MCP_AI_Mesh_Peer_Sync',
			$mesh_sync,
			'Mesh peer sync should be initialized when mesh is enabled, even without federation directory'
		);

		// Also verify that the CPT handler was initialized.
		$cpt_property = $reflection->getProperty( 'peer_cpt_handler' );
		$cpt_property->setAccessible( true );
		$cpt_handler = $cpt_property->getValue( $federation );

		$this->assertInstanceOf(
			'WP_MCP_AI_AI_Peer_CPT',
			$cpt_handler,
			'AI Peer CPT should be registered when mesh is enabled, even without federation directory'
		);
	}

	/**
	 * Test that CPT is created when mesh peer is added via settings.
	 */
	public function test_cpt_created_when_mesh_peer_added() {
		// Enable mesh and initialize federation.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => 'mesh_test123',
				'mesh_peer_sites'      => array(),
			)
		);

		// Initialize federation and mesh sync.
		$federation = new WP_MCP_AI_Federation();

		// Verify mesh sync was initialized.
		$reflection = new ReflectionClass( $federation );
		$property   = $reflection->getProperty( 'mesh_peer_sync' );
		$property->setAccessible( true );
		$mesh_sync = $property->getValue( $federation );

		$this->assertNotNull( $mesh_sync, 'Mesh sync should be initialized' );

		// Add a mesh peer (this will trigger the update_option hook).
		$test_peer = array(
			'name'    => 'Test Peer Site',
			'url'     => 'https://test-peer.example.com',
			'api_key' => 'mesh_test456',
		);

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => 'mesh_test123',
				'mesh_peer_sites'      => array( $test_peer ),
			)
		);

		// The update_option call above should have triggered the sync_mesh_peers_on_option_update hook.
		// Give it a moment to process.
		wp_cache_flush();

		// Check if CPT was created.
		$peer_id = 'mesh_' . md5( $test_peer['url'] );
		$query   = new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_mesh_peer_id',
						'value' => $peer_id,
					),
				),
			)
		);

		$this->assertTrue(
			$query->have_posts(),
			'AI Peer CPT should be created when mesh peer is added via settings'
		);

		if ( $query->have_posts() ) {
			$post = $query->posts[0];
			$this->assertEquals(
				'Test Peer Site',
				$post->post_title,
				'CPT title should match mesh peer name'
			);
		}

		// Clean up.
		if ( $query->have_posts() ) {
			wp_delete_post( $query->posts[0]->ID, true );
		}
	}

	/**
	 * Test that CPT is still created when both mesh and federation are enabled.
	 */
	public function test_cpt_created_with_both_enabled() {
		// Enable both mesh and federation directory.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'                => true,
				'enable_federation_directory' => true,
				'mesh_inbound_api_key'       => 'mesh_test123',
				'mesh_peer_sites'            => array(),
			)
		);

		// Initialize federation and mesh sync.
		$federation = new WP_MCP_AI_Federation();

		// Verify mesh sync was initialized.
		$reflection = new ReflectionClass( $federation );
		$property   = $reflection->getProperty( 'mesh_peer_sync' );
		$property->setAccessible( true );
		$mesh_sync = $property->getValue( $federation );

		$this->assertNotNull( $mesh_sync, 'Mesh sync should be initialized with both features enabled' );

		// Add a mesh peer (this will trigger the update_option hook).
		$test_peer = array(
			'name'    => 'Test Peer Both Enabled',
			'url'     => 'https://test-both.example.com',
			'api_key' => 'mesh_test789',
		);

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'                => true,
				'enable_federation_directory' => true,
				'mesh_inbound_api_key'       => 'mesh_test123',
				'mesh_peer_sites'            => array( $test_peer ),
			)
		);

		// Flush cache to ensure fresh query.
		wp_cache_flush();

		// Check if CPT was created.
		$peer_id = 'mesh_' . md5( $test_peer['url'] );
		$query   = new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_mesh_peer_id',
						'value' => $peer_id,
					),
				),
			)
		);

		$this->assertTrue(
			$query->have_posts(),
			'AI Peer CPT should still be created when both mesh and federation are enabled'
		);

		// Clean up.
		if ( $query->have_posts() ) {
			wp_delete_post( $query->posts[0]->ID, true );
		}
	}

	/**
	 * Test that CPT is NOT created when mesh is disabled.
	 */
	public function test_cpt_not_created_when_mesh_disabled() {
		// Disable mesh.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => false,
				'mesh_peer_sites' => array(
					array(
						'name'    => 'Should Not Create',
						'url'     => 'https://should-not-create.example.com',
						'api_key' => 'mesh_test000',
					),
				),
			)
		);

		// Initialize federation (mesh sync should NOT initialize).
		$federation = new WP_MCP_AI_Federation();

		// Verify that mesh_peer_sync was NOT initialized.
		$reflection = new ReflectionClass( $federation );
		$property   = $reflection->getProperty( 'mesh_peer_sync' );
		$property->setAccessible( true );
		$mesh_sync = $property->getValue( $federation );

		$this->assertNull(
			$mesh_sync,
			'Mesh peer sync should NOT be initialized when mesh is disabled'
		);
	}
}
