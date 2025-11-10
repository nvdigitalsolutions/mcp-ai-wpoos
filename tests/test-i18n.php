<?php
/**
 * Tests related to internationalisation hooks.
 */
class WP_MCP_AI_I18N_Test extends WP_UnitTestCase {

	/**
	 * Ensure the textdomain loader is registered on init.
	 *
	 * As of WordPress 6.7+, translations must be loaded at the init action or later.
	 * This prevents the "Translation loading... was triggered too early" notice.
	 *
	 * @see https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
	 */
	public function test_textdomain_loader_is_registered() {
		$this->assertNotFalse( has_action( 'init', 'wp_mcp_ai_load_textdomain' ), 'Textdomain loader should be registered on init hook' );
	}

	/**
	 * Ensure the textdomain loader runs early in the init hook.
	 *
	 * The textdomain should be loaded with priority 1 to ensure it's available
	 * before other init hooks that may use translation functions.
	 */
	public function test_textdomain_loads_early() {
		$textdomain_priority = has_action( 'init', 'wp_mcp_ai_load_textdomain' );

		$this->assertNotFalse( $textdomain_priority, 'Textdomain loader should be registered' );
		$this->assertEquals( 1, $textdomain_priority, 'Textdomain should load with priority 1 on init' );
	}
}
