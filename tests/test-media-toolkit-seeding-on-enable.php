<?php
/**
 * Tests for media toolkit preset seeding when checkbox is enabled.
 *
 * @package WP_MCP_AI
 */

/**
 * Test media toolkit preset seeding on checkbox enable.
 */
class Test_Media_Toolkit_Seeding_On_Enable extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Media Template Presets class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
			$preset_file = WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-media-template-presets.php';
			if ( file_exists( $preset_file ) ) {
				require_once $preset_file;
			}
		}

		// Ensure Media Template CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			$cpt_file = WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-media-template-cpt.php';
			if ( file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			}
		}

		// Register the post type and taxonomy if class exists.
		if ( class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			WP_MCP_AI_Media_Template_CPT::register_post_type();
			WP_MCP_AI_Media_Template_CPT::register_taxonomy();
		}

		// Reset seeded version to allow re-seeding.
		if ( class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
			delete_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );
		}

		// Clean up any existing preset templates.
		if ( class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			$existing = get_posts(
				array(
					'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
					'numberposts' => -1,
					'post_status' => 'any',
				)
			);
			foreach ( $existing as $post ) {
				wp_delete_post( $post->ID, true );
			}
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		if ( class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
			delete_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );
		}

		parent::tearDown();
	}

	/**
	 * Test that presets are seeded when media toolkit is enabled.
	 */
	public function test_presets_seeded_when_toolkit_enabled() {
		// Skip test if Pro addon classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_Presets' ) || ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			$this->markTestSkipped( 'Pro addon classes not available' );
		}

		// Start with toolkit disabled.
		$existing_settings = array(
			'enable_media_toolkit' => false,
		);
		update_option( 'wp_mcp_ai_settings', $existing_settings );

		// Simulate enabling the toolkit (like the settings dashboard does).
		$merged_settings = array(
			'enable_media_toolkit' => true,
		);

		// Check if media toolkit was just enabled (simulating the dashboard logic).
		$was_toolkit_disabled = empty( $existing_settings['enable_media_toolkit'] );
		$is_toolkit_enabled   = ! empty( $merged_settings['enable_media_toolkit'] );

		$this->assertTrue( $was_toolkit_disabled, 'Toolkit should start disabled' );
		$this->assertTrue( $is_toolkit_enabled, 'Toolkit should be enabled in merged settings' );

		if ( $was_toolkit_disabled && $is_toolkit_enabled ) {
			// Media toolkit was just enabled, seed the template presets.
			WP_MCP_AI_Media_Template_Presets::seed_presets();
		}

		// Update the settings.
		update_option( 'wp_mcp_ai_settings', $merged_settings );

		// Verify presets were seeded.
		$templates = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'numberposts' => -1,
			)
		);

		$this->assertGreaterThan( 0, count( $templates ), 'Presets should be seeded' );

		// Check seeded version was set.
		$seeded_version = get_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );
		$this->assertEquals( WP_MCP_AI_Media_Template_Presets::PRESET_VERSION, $seeded_version );
	}

	/**
	 * Test that presets are NOT seeded when toolkit remains disabled.
	 */
	public function test_presets_not_seeded_when_toolkit_disabled() {
		// Skip test if Pro addon classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_Presets' ) || ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			$this->markTestSkipped( 'Pro addon classes not available' );
		}

		// Start with toolkit disabled.
		$existing_settings = array(
			'enable_media_toolkit' => false,
		);
		update_option( 'wp_mcp_ai_settings', $existing_settings );

		// Keep toolkit disabled.
		$merged_settings = array(
			'enable_media_toolkit' => false,
		);

		// Check if media toolkit was just enabled (simulating the dashboard logic).
		$was_toolkit_disabled = empty( $existing_settings['enable_media_toolkit'] );
		$is_toolkit_enabled   = ! empty( $merged_settings['enable_media_toolkit'] );

		$this->assertTrue( $was_toolkit_disabled, 'Toolkit should start disabled' );
		$this->assertFalse( $is_toolkit_enabled, 'Toolkit should remain disabled' );

		if ( $was_toolkit_disabled && $is_toolkit_enabled ) {
			// This should NOT execute.
			WP_MCP_AI_Media_Template_Presets::seed_presets();
		}

		// Update the settings.
		update_option( 'wp_mcp_ai_settings', $merged_settings );

		// Verify presets were NOT seeded.
		$templates = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Media_Template_CPT::POST_TYPE,
				'numberposts' => -1,
			)
		);

		$this->assertEquals( 0, count( $templates ), 'Presets should NOT be seeded' );

		// Check seeded version was NOT set.
		$seeded_version = get_option( WP_MCP_AI_Media_Template_Presets::SEEDED_VERSION_KEY );
		$this->assertEmpty( $seeded_version );
	}

	/**
	 * Test that presets are NOT seeded again when toolkit remains enabled.
	 */
	public function test_presets_not_duplicated_when_toolkit_remains_enabled() {
		// Skip test if Pro addon classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_Presets' ) || ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			$this->markTestSkipped( 'Pro addon classes not available' );
		}

		// Start with toolkit already enabled and presets seeded.
		$existing_settings = array(
			'enable_media_toolkit' => true,
		);
		update_option( 'wp_mcp_ai_settings', $existing_settings );
		WP_MCP_AI_Media_Template_Presets::seed_presets();

		$count_after_first_seed = wp_count_posts( WP_MCP_AI_Media_Template_CPT::POST_TYPE )->publish;

		// Keep toolkit enabled.
		$merged_settings = array(
			'enable_media_toolkit' => true,
		);

		// Check if media toolkit was just enabled (simulating the dashboard logic).
		$was_toolkit_disabled = empty( $existing_settings['enable_media_toolkit'] );
		$is_toolkit_enabled   = ! empty( $merged_settings['enable_media_toolkit'] );

		$this->assertFalse( $was_toolkit_disabled, 'Toolkit should start enabled' );
		$this->assertTrue( $is_toolkit_enabled, 'Toolkit should remain enabled' );

		if ( $was_toolkit_disabled && $is_toolkit_enabled ) {
			// This should NOT execute because toolkit was already enabled.
			WP_MCP_AI_Media_Template_Presets::seed_presets();
		}

		// Update the settings.
		update_option( 'wp_mcp_ai_settings', $merged_settings );

		// Verify presets were NOT duplicated.
		$count_after_second_save = wp_count_posts( WP_MCP_AI_Media_Template_CPT::POST_TYPE )->publish;
		$this->assertEquals( $count_after_first_seed, $count_after_second_save, 'Preset count should not change' );
	}
}
