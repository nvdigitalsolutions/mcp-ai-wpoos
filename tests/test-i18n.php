<?php
/**
 * Tests related to internationalisation hooks.
 */
class WP_MCP_AI_I18N_Test extends WP_UnitTestCase {

	/**
	 * Ensure the textdomain loader is registered on init.
	 *
	 * As of WordPress 6.7.0, translations must be loaded at the init action or later.
	 * This prevents the "Translation loading was triggered too early" notice.
	 */
	public function test_textdomain_loader_is_registered() {
		$this->assertNotFalse( has_action( 'init', 'wp_mcp_ai_load_textdomain' ), 'Textdomain loader should be registered on init hook' );
	}

	/**
	 * Ensure the textdomain loader runs with high priority.
	 *
	 * The textdomain should be loaded early in the init hook (priority 1) to ensure
	 * translations are available when needed by plugin components.
	 */
	public function test_textdomain_loads_with_high_priority() {
		$textdomain_priority = has_action( 'init', 'wp_mcp_ai_load_textdomain' );

		$this->assertNotFalse( $textdomain_priority, 'Textdomain loader should be registered on init hook' );
		$this->assertEquals( 1, $textdomain_priority, 'Textdomain should load with priority 1 on init hook' );
	}
}
