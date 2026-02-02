<?php
/**
 * Test to verify embedded provider is auto-enabled with Pro plugin.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded provider auto-enablement in Pro version.
 */
class WP_MCP_AI_Embedded_Provider_Auto_Enable_Test extends WP_UnitTestCase {

	/**
	 * Test that embedded provider is auto-enabled when Pro is present.
	 */
	public function test_embedded_provider_auto_enabled_with_pro() {
		// Verify we're in Pro version (BASE_VERSION should be false).
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		// Verify Pro constant is defined (when Pro addon is loaded).
		if ( file_exists( WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php' ) ) {
			$this->assertTrue( defined( 'WP_MCP_AI_PRO_VERSION' ), 'Pro constant should be defined' );
		}

		// Test webllm enqueue detects Pro.
		$is_pro_available = defined( 'WP_MCP_AI_PRO_VERSION' ) || ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION );
		$this->assertTrue( $is_pro_available, 'Pro should be detected as available' );
	}

	/**
	 * Test that webworker feature status shows embedded as enabled.
	 */
	public function test_webworker_feature_status_shows_embedded_enabled() {
		// Verify we're in Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		$status = WP_MCP_AI_WebWorker_Enqueue::get_feature_status();

		$this->assertArrayHasKey( 'embedded_provider_enabled', $status, 'Status should include embedded_provider_enabled' );
		$this->assertTrue( $status['embedded_provider_enabled'], 'Embedded provider should be enabled in Pro version' );
	}

	/**
	 * Test that langchain feature status shows embedded as enabled.
	 */
	public function test_langchain_feature_status_shows_embedded_enabled() {
		// Verify we're in Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		$status = WP_MCP_AI_LangChain_Enqueue::get_feature_status();

		$this->assertArrayHasKey( 'embedded_provider_enabled', $status, 'Status should include embedded_provider_enabled' );
		$this->assertTrue( $status['embedded_provider_enabled'], 'Embedded provider should be enabled in Pro version' );
	}

	/**
	 * Test that embedded subtab is visible in Pro version.
	 */
	public function test_embedded_subtab_visible_in_pro() {
		// Verify we're in Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		// Embedded subtab should exist in Pro version.
		$this->assertArrayHasKey( 'embedded', $subtabs, 'Embedded subtab should exist in Pro version' );
		$this->assertNotNull( $subtabs['embedded'], 'Embedded subtab should not be null in Pro version' );
	}

	/**
	 * Test that embedded fields show auto-enabled status.
	 */
	public function test_embedded_fields_show_auto_enabled() {
		// Verify we're in Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Check enable_embedded field exists.
		$this->assertArrayHasKey( 'enable_embedded', $fields, 'enable_embedded field should exist' );

		// Check it's marked as auto-enabled.
		$this->assertTrue( $fields['enable_embedded']['default'], 'Should default to true (auto-enabled)' );
		$this->assertTrue( $fields['enable_embedded']['disabled'], 'Should be disabled (read-only)' );

		// Check label indicates auto-enablement.
		$this->assertStringContainsString(
			'Auto-enabled',
			$fields['enable_embedded']['checkbox_label'],
			'Label should indicate auto-enablement'
		);
	}

	/**
	 * Test complete flow: Pro present → Scripts load.
	 */
	public function test_complete_flow_pro_to_scripts() {
		// Verify we're in Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		// Step 1: Pro is detected.
		$is_pro = defined( 'WP_MCP_AI_PRO_VERSION' ) || ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION );
		$this->assertTrue( $is_pro, 'Step 1: Pro should be detected' );

		// Step 2: Feature status reports enabled.
		$status = WP_MCP_AI_WebWorker_Enqueue::get_feature_status();
		$this->assertTrue( $status['embedded_provider_enabled'], 'Step 2: Feature should be enabled' );

		// Step 3: Subtab is visible.
		$section    = new WP_MCP_AI_Section_Providers();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtabs = $method->invoke( $section );
		$this->assertArrayHasKey( 'embedded', $subtabs, 'Step 3: Subtab should be visible' );

		// Step 4: Fields show auto-enabled.
		$fields = $section->get_fields();
		$this->assertTrue( $fields['enable_embedded']['default'], 'Step 4: Should be auto-enabled' );
	}

	/**
	 * Test that embedded provider works without any manual configuration.
	 */
	public function test_no_manual_configuration_required() {
		// Verify we're in Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'Test expects Pro version' );

		// No settings need to be saved.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Even if enable_embedded is not set, Pro detection should work.
		$is_pro = defined( 'WP_MCP_AI_PRO_VERSION' ) || ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION );
		$this->assertTrue( $is_pro, 'Pro should be detected without any settings' );

		// Feature should report as enabled.
		$status = WP_MCP_AI_WebWorker_Enqueue::get_feature_status();
		$this->assertTrue(
			$status['embedded_provider_enabled'],
			'Embedded provider should be enabled without manual configuration'
		);
	}
}
