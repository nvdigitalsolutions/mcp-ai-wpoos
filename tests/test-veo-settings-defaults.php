<?php
/**
 * Tests for Veo video generation settings defaults.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

/**
 * Test class for Veo video generation settings defaults.
 */
class WP_MCP_AI_Veo_Settings_Defaults_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that hardcoded defaults are used when no settings exist.
	 */
	public function test_hardcoded_defaults_when_no_settings() {
		// No settings configured.
		delete_option( 'wp_mcp_ai_settings' );

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_default_video_settings' );
		$method->setAccessible( true );

		$defaults = $method->invoke( $tool );

		// Verify hardcoded defaults.
		$this->assertEquals( 'veo-2.0-generate-001', $defaults['model'], 'Default model should be Veo 2.0' );
		$this->assertEquals( '720p', $defaults['resolution'], 'Default resolution should be 720p' );
		$this->assertEquals( '16:9', $defaults['aspect_ratio'], 'Default aspect ratio should be 16:9' );
		$this->assertEquals( 5, $defaults['duration'], 'Default duration should be 5 seconds' );
	}

	/**
	 * Test that admin settings override hardcoded defaults.
	 */
	public function test_admin_settings_override_defaults() {
		// Configure custom settings.
		$settings = array(
			'gemini_video_model'        => 'veo-3.1-generate-preview',
			'gemini_video_resolution'   => '1080p',
			'gemini_video_aspect_ratio' => '9:16',
			'gemini_video_duration'     => '8',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_default_video_settings' );
		$method->setAccessible( true );

		$defaults = $method->invoke( $tool );

		// Verify settings are used.
		$this->assertEquals( 'veo-3.1-generate-preview', $defaults['model'], 'Should use configured model' );
		$this->assertEquals( '1080p', $defaults['resolution'], 'Should use configured resolution' );
		$this->assertEquals( '9:16', $defaults['aspect_ratio'], 'Should use configured aspect ratio' );
		$this->assertEquals( 8, $defaults['duration'], 'Should use configured duration' );
	}

	/**
	 * Test that invalid duration in settings falls back to hardcoded default.
	 */
	public function test_invalid_duration_uses_hardcoded_default() {
		// Configure invalid duration (out of range).
		$settings = array(
			'gemini_video_duration' => '15', // Invalid - max is 8.
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_default_video_settings' );
		$method->setAccessible( true );

		$defaults = $method->invoke( $tool );

		// Verify fallback to hardcoded default.
		$this->assertEquals( 5, $defaults['duration'], 'Invalid duration should fall back to 5 seconds' );
	}

	/**
	 * Test that filter can override settings.
	 */
	public function test_filter_overrides_settings() {
		// Configure settings.
		$settings = array(
			'gemini_video_model' => 'veo-2.0-generate-001',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Add filter to override.
		add_filter(
			'wp_mcp_ai_veo_default_settings',
			function ( $defaults ) {
				$defaults['model'] = 'veo-3.1-generate-preview';
				return $defaults;
			}
		);

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_default_video_settings' );
		$method->setAccessible( true );

		$defaults = $method->invoke( $tool );

		// Verify filter overrides settings.
		$this->assertEquals( 'veo-3.1-generate-preview', $defaults['model'], 'Filter should override settings' );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_veo_default_settings' );
	}

	/**
	 * Test that all Gemini video settings are properly defined in provider config.
	 */
	public function test_provider_settings_definitions_exist() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-providers.php';

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_fields' );
		$method->setAccessible( true );

		$fields = $method->invoke( $section );

		// Verify all video settings are defined.
		$this->assertArrayHasKey( 'gemini_video_model', $fields, 'gemini_video_model setting should exist' );
		$this->assertArrayHasKey( 'gemini_video_resolution', $fields, 'gemini_video_resolution setting should exist' );
		$this->assertArrayHasKey( 'gemini_video_aspect_ratio', $fields, 'gemini_video_aspect_ratio setting should exist' );
		$this->assertArrayHasKey( 'gemini_video_duration', $fields, 'gemini_video_duration setting should exist' );

		// Verify defaults.
		$this->assertEquals( 'veo-2.0-generate-001', $fields['gemini_video_model']['default'], 'Default model should be Veo 2.0' );
		$this->assertEquals( '720p', $fields['gemini_video_resolution']['default'], 'Default resolution should be 720p' );
		$this->assertEquals( '16:9', $fields['gemini_video_aspect_ratio']['default'], 'Default aspect ratio should be 16:9' );
		$this->assertEquals( '5', $fields['gemini_video_duration']['default'], 'Default duration should be 5' );
	}

	/**
	 * Test that gemini subtab includes all video settings.
	 */
	public function test_gemini_subtab_includes_video_settings() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-providers.php';

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$groups = $method->invoke( $section );

		// Verify gemini subtab exists and includes video settings.
		$this->assertArrayHasKey( 'gemini', $groups, 'Gemini subtab should exist' );

		$gemini_fields = $groups['gemini']['fields'];
		$this->assertContains( 'gemini_video_model', $gemini_fields, 'Gemini subtab should include video model' );
		$this->assertContains( 'gemini_video_resolution', $gemini_fields, 'Gemini subtab should include video resolution' );
		$this->assertContains( 'gemini_video_aspect_ratio', $gemini_fields, 'Gemini subtab should include video aspect ratio' );
		$this->assertContains( 'gemini_video_duration', $gemini_fields, 'Gemini subtab should include video duration' );
	}
}
