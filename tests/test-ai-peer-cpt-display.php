<?php
/**
 * Tests for AI Peer CPT Display in Advanced Section
 *
 * Ensures that AI Peer links are only shown when the directory service is enabled.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test AI Peer CPT Display in Advanced Section.
 */
class Test_AI_Peer_CPT_Display extends WP_UnitTestCase {

	/**
	 * Set up test environment and fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Guard: Ensure section classes are loaded (may be gated behind is_admin()).
		if ( ! class_exists( 'WP_MCP_AI_Section_Advanced' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-advanced.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
		}
	}

	/**
	 * Initialize federation and register the AI Peer CPT.
	 *
	 * Calling do_action('init') may trigger core block re-registration notices
	 * in the test environment — those are expected and harmless.
	 */
	private function init_federation_and_register_cpt() {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
			}
			$registry = WP_MCP_AI_Tool_Registry::get_instance();

			if ( ! class_exists( 'WP_MCP_AI_Federation' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php';
			}
			$federation = new WP_MCP_AI_Federation( $registry );
			$federation->maybe_load_federation_features();

			// Directly register the CPT if it hasn't been registered yet.
			// Avoids do_action('init') which triggers core block re-registration notices.
			if ( ! post_type_exists( 'ai_peer' ) && class_exists( 'WP_MCP_AI_AI_Peer_CPT' ) ) {
				WP_MCP_AI_AI_Peer_CPT::register_post_type();
			}
		}

	/**
	 * Test that AI Peer links are NOT shown when directory service is disabled.
	 */
	public function test_ai_peer_links_hidden_when_directory_disabled() {
		$_GET['subtab'] = 'federation_mesh';

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_federation' => false )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$section = new WP_MCP_AI_Section_Advanced();
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'post-new.php?post_type=ai_peer', $output );
		$this->assertStringContainsString( 'Directory Service Disabled', $output );
		$this->assertStringContainsString( 'Enable it in', $output );
	}

	/**
	 * Test that AI Peer links ARE shown when directory service is enabled.
	 */
	public function test_ai_peer_links_shown_when_directory_enabled() {
		$_GET['subtab'] = 'federation_mesh';

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_federation_directory' => true )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Initialize federation to register the CPT.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		}
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->init_federation_and_register_cpt();

		$this->assertTrue( post_type_exists( 'ai_peer' ), 'AI Peer post type should be registered when directory is enabled' );

		$section = new WP_MCP_AI_Section_Advanced();
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'post-new.php?post_type=ai_peer', $output, 'Add New AI Peer link should be present' );
		$this->assertStringContainsString( 'Add New AI Peer', $output, 'Add New AI Peer text should be visible' );
		$this->assertStringNotContainsString( 'Directory Service Disabled', $output, 'Disabled message should not appear' );
	}

	/**
	 * Test that post type check prevents errors when post type doesn't exist.
	 */
	public function test_no_error_when_post_type_not_registered() {
		$_GET['subtab'] = 'federation_mesh';

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_federation' => false )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		if ( post_type_exists( 'ai_peer' ) ) {
			unregister_post_type( 'ai_peer' );
		}

		$section = new WP_MCP_AI_Section_Advanced();
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output, 'Rendered output should not be empty' );
		$this->assertStringContainsString( 'Federation &amp; Mesh Computing', $output, 'Federation section title should be present' );
		$this->assertStringContainsString( 'Directory Service Disabled', $output, 'Disabled notice should appear' );
	}

	/**
	 * Test that AI Peers are counted correctly when directory is enabled.
	 */
	public function test_ai_peers_counted_correctly_when_enabled() {
		$_GET['subtab'] = 'federation_mesh';

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_federation_directory' => true )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Initialize federation to register the CPT.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		}
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->init_federation_and_register_cpt();

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
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'View All AI Peers', $output, 'View All AI Peers link should appear' );
		$this->assertStringContainsString( '(1)', $output, 'Peer count should show (1)' );
		$this->assertStringContainsString( 'Add New AI Peer', $output, 'Add New AI Peer button should be present' );

		wp_delete_post( $peer_id, true );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		if ( post_type_exists( 'ai_peer' ) ) {
			unregister_post_type( 'ai_peer' );
		}
	}
}
