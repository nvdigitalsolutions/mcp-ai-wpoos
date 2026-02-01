<?php
/**
 * Tests for AI Peer CPT Display in Advanced Section
 *
 * Ensures that AI Peer links are only shown when the directory service is enabled.
 *
 * @package WP_MCP_AI
 */

/**
 * Test AI Peer CPT Display in Advanced Section.
 */
class Test_AI_Peer_CPT_Display extends WP_UnitTestCase {

	/**
	 * Test that AI Peer links are NOT shown when directory service is disabled.
	 */
	public function test_ai_peer_links_hidden_when_directory_disabled() {
		// Set directory service to disabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation_directory' => false,
			)
		);

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$section = new WP_MCP_AI_Section_Advanced();

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The "Add New AI Peer" button should NOT be present when directory is disabled.
		$this->assertStringNotContainsString( 'post-new.php?post_type=ai_peer', $output );

		// Should show a message about directory being disabled.
		$this->assertStringContainsString( 'Directory Service Disabled', $output );
		$this->assertStringContainsString( 'Enable it in', $output );
	}

	/**
	 * Test that AI Peer links ARE shown when directory service is enabled.
	 */
	public function test_ai_peer_links_shown_when_directory_enabled() {
		// Set directory service to enabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation_directory'           => true,
				'enable_federation_directory' => true,
			)
		);

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Initialize federation to register the CPT.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		}
		$registry = new WP_MCP_AI_Tool_Registry();

		if ( ! class_exists( 'WP_MCP_AI_Federation' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php';
		}
		$federation = new WP_MCP_AI_Federation( $registry );
		$federation->maybe_load_federation_features();

		// Ensure the post type is registered.
		$this->assertTrue( post_type_exists( 'ai_peer' ), 'AI Peer post type should be registered when directory is enabled' );

		$section = new WP_MCP_AI_Section_Advanced();

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The "Add New AI Peer" button SHOULD be present when directory is enabled.
		$this->assertStringContainsString( 'post-new.php?post_type=ai_peer', $output );
		$this->assertStringContainsString( 'Add New AI Peer', $output );

		// Should NOT show the disabled message.
		$this->assertStringNotContainsString( 'Directory Service Disabled', $output );
	}

	/**
	 * Test that post type check prevents errors when post type doesn't exist.
	 */
	public function test_no_error_when_post_type_not_registered() {
		// Set directory service to disabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation_directory'           => false,
				'enable_federation_directory' => false,
			)
		);

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Unregister the post type if it exists.
		if ( post_type_exists( 'ai_peer' ) ) {
			unregister_post_type( 'ai_peer' );
		}

		$section = new WP_MCP_AI_Section_Advanced();

		// This should not throw any errors.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Should complete successfully without errors.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'Federation & Mesh Computing', $output );
	}

	/**
	 * Test that AI Peers are counted correctly when directory is enabled.
	 */
	public function test_ai_peers_counted_correctly_when_enabled() {
		// Set directory service to enabled.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation_directory'           => true,
				'enable_federation_directory' => true,
			)
		);

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Initialize federation to register the CPT.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		}
		$registry = new WP_MCP_AI_Tool_Registry();

		if ( ! class_exists( 'WP_MCP_AI_Federation' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php';
		}
		$federation = new WP_MCP_AI_Federation( $registry );
		$federation->maybe_load_federation_features();

		// Create a test AI peer.
		$peer_id = wp_insert_post(
			array(
				'post_type'   => 'ai_peer',
				'post_title'  => 'Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $peer_id );
		$this->assertGreaterThan( 0, $peer_id );

		$section = new WP_MCP_AI_Section_Advanced();

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Should show the peer in the count.
		$this->assertStringContainsString( 'View All AI Peers', $output );
		$this->assertStringContainsString( '(1)', $output );

		// Clean up.
		wp_delete_post( $peer_id, true );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clear the settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Unregister the post type to ensure clean state.
		if ( post_type_exists( 'ai_peer' ) ) {
			unregister_post_type( 'ai_peer' );
		}
	}
}
