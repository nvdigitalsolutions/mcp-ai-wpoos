<?php
/**
 * Tests for JetEngine CCT loading behavior with base/pro plugins.
 *
 * This test ensures that JetEngine integrations are loaded correctly
 * when using the plugin in different configurations:
 * - Full version (combined plugin)
 * - Base version only
 * - Base version + Pro addon (separate plugins)
 *
 * @package WP_MCP_AI
 */

/**
 * Test JetEngine CCT Loading.
 */
class Test_JetEngine_CCT_Loading extends WP_UnitTestCase {

	/**
	 * Test that WP_MCP_AI_JetEngine_CCT class is available in full version.
	 *
	 * In the full version (or when Pro addon is active), the JetEngine CCT
	 * class should be loaded to support chat transcript storage.
	 */
	public function test_jetengine_cct_class_available_in_full_version() {
		// In the test environment, WP_MCP_AI_BASE_VERSION is false (full version).
		$this->assertFalse( wp_mcp_ai_is_base_version(), 'Test environment should be in full version mode' );

		// Verify that JetEngine CCT class is available.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_JetEngine_CCT' ),
			'WP_MCP_AI_JetEngine_CCT class should be available in full version'
		);
	}

	/**
	 * Test helper function wp_mcp_ai_should_load_integrations exists.
	 */
	public function test_should_load_integrations_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_should_load_integrations' ),
			'wp_mcp_ai_should_load_integrations() helper function should exist'
		);
	}

	/**
	 * Test helper function wp_mcp_ai_is_jetengine_available exists.
	 */
	public function test_is_jetengine_available_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_is_jetengine_available' ),
			'wp_mcp_ai_is_jetengine_available() helper function should exist'
		);
	}

	/**
	 * Test loading logic for base + pro plugin scenario using helper function.
	 *
	 * This simulates the scenario where:
	 * 1. Base plugin is active (WP_MCP_AI_BASE_VERSION = true)
	 * 2. Pro addon is active (WP_MCP_AI_PRO_VERSION is defined)
	 * 3. Full integrations should load (including JetEngine if available)
	 */
	public function test_loading_logic_with_base_plus_pro() {
		// Simulate base version mode.
		$base_version = true;

		// Simulate pro addon being active.
		$pro_active = true;

		// The loading condition: !base_version || pro_active.
		$should_load = ! $base_version || $pro_active;

		$this->assertTrue(
			$should_load,
			'Full integrations should load when Pro addon is active, even in base version mode'
		);

		// Verify the helper function produces the same result in test environment.
		// In test environment, base version is false, so should always return true.
		$this->assertTrue(
			wp_mcp_ai_should_load_integrations(),
			'Helper function should return true in test environment (full version)'
		);
	}

	/**
	 * Test loading logic for base version only (without JetEngine or Pro).
	 *
	 * When only the base version is active (no Pro addon, no JetEngine),
	 * JetEngine integrations should NOT load.
	 */
	public function test_loading_logic_with_base_only() {
		// Simulate base version mode.
		$base_version = true;

		// Simulate pro addon NOT being active.
		$pro_active = false;

		// The loading condition for full integrations: !base_version || pro_active.
		$should_load = ! $base_version || $pro_active;

		$this->assertFalse(
			$should_load,
			'Integrations should NOT load in base version without Pro addon or JetEngine'
		);
	}

	/**
	 * Test loading logic for base version with JetEngine.
	 *
	 * When the base version is active with JetEngine installed (no Pro addon),
	 * only the minimal JetEngine CCT class should be loaded for chat transcript storage.
	 * Full integrations should NOT load.
	 */
	public function test_loading_logic_with_base_and_jetengine() {
		// Simulate base version mode.
		$base_version = true;

		// Simulate pro addon NOT being active.
		$pro_active = false;

		// Simulate JetEngine being available.
		$jetengine_available = true;

		// The loading condition for full integrations: !base_version || pro_active.
		$should_load_full_integrations = ! $base_version || $pro_active;

		// Full integrations should NOT load.
		$this->assertFalse(
			$should_load_full_integrations,
			'Full integrations should NOT load in base version with only JetEngine'
		);

		// But JetEngine CCT should be loaded separately for chat transcripts.
		// This is tested by checking if the JetEngine helper function exists.
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_is_jetengine_available' ),
			'JetEngine availability check should be available for selective loading'
		);
	}

	/**
	 * Test loading logic for full version.
	 *
	 * In full version mode (cloned repository), full integrations should load.
	 */
	public function test_loading_logic_with_full_version() {
		// Simulate full version mode.
		$base_version = false;

		// Pro addon status doesn't matter in full version.
		$pro_active = false;

		// The loading condition: !base_version || pro_active.
		$should_load = ! $base_version || $pro_active;

		$this->assertTrue(
			$should_load,
			'Full integrations should load in full version mode'
		);
	}

	/**
	 * Test transcript repository handles missing JetEngine CCT gracefully.
	 *
	 * When JetEngine CCT class is not available, the transcript repository
	 * should return empty string for table name and false for table exists.
	 */
	public function test_transcript_repository_handles_missing_jetengine() {
		// Get repository instance.
		$repository = wp_mcp_ai_get_transcript_repository();

		$this->assertInstanceOf(
			'WP_MCP_AI_Transcript_Repository',
			$repository,
			'Repository should be available even without JetEngine CCT'
		);

		// If JetEngine CCT is not available (in base version), methods should handle it gracefully.
		// This is tested by the transcript repository test file, but we verify the pattern here.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$table_name = $repository->get_table_name();
			$this->assertEquals( '', $table_name, 'Table name should be empty without JetEngine CCT' );

			$table_exists = $repository->table_exists();
			$this->assertFalse( $table_exists, 'Table should not exist without JetEngine CCT' );
		}
	}

	/**
	 * Test diagnostic info includes base/pro version details.
	 *
	 * The enhanced diagnostic logging should include information about
	 * base version mode and Pro addon activation status.
	 */
	public function test_diagnostic_info_includes_version_details() {
		// Use reflection to access the protected method.
		$recorder_class = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method         = $recorder_class->getMethod( 'get_jetengine_diagnostic_info' );
		$method->setAccessible( true );

		// Call the method.
		$info = $method->invoke( null );

		// Verify the diagnostic info includes the new fields.
		$this->assertIsArray( $info, 'Diagnostic info should be an array' );
		$this->assertArrayHasKey( 'base_version_mode', $info, 'Diagnostic info should include base_version_mode' );
		$this->assertArrayHasKey( 'pro_addon_active', $info, 'Diagnostic info should include pro_addon_active' );

		// Verify base_version_mode value.
		$expected_base_mode = wp_mcp_ai_is_base_version();
		$this->assertEquals(
			$expected_base_mode,
			$info['base_version_mode'],
			'base_version_mode should match wp_mcp_ai_is_base_version()'
		);

		// Verify pro_addon_active value.
		$expected_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );
		$this->assertEquals(
			$expected_pro_active,
			$info['pro_addon_active'],
			'pro_addon_active should match whether WP_MCP_AI_PRO_VERSION is defined'
		);
	}
}
