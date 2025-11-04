<?php
/**
 * Tests related to internationalisation hooks.
 */
class WP_MCP_AI_I18N_Test extends WP_UnitTestCase {

	/**
	 * Ensure the textdomain loader is registered on plugins_loaded.
	 *
	 * As of WordPress 6.7+, translations must be loaded before any translation
	 * functions are called. The plugins_loaded hook ensures the textdomain is
	 * available before plugin initialization and post type registration.
	 */
	public function test_textdomain_loader_is_registered() {
		$this->assertNotFalse( has_action( 'plugins_loaded', 'wp_mcp_ai_load_textdomain' ), 'Textdomain loader should be registered on plugins_loaded hook' );
	}

	/**
	 * Ensure the textdomain loader runs before plugin bootstrap.
	 *
	 * The textdomain must be loaded (priority 1) before the plugin bootstrap
	 * (priority 20) to prevent WordPress 6.7+ deprecation notices.
	 */
	public function test_textdomain_loads_before_bootstrap() {
		$textdomain_priority = has_action( 'plugins_loaded', 'wp_mcp_ai_load_textdomain' );
		$bootstrap_priority  = has_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap' );

		$this->assertNotFalse( $textdomain_priority, 'Textdomain loader should be registered' );
		$this->assertNotFalse( $bootstrap_priority, 'Bootstrap function should be registered' );
		$this->assertLessThan( $bootstrap_priority, $textdomain_priority, 'Textdomain should load before bootstrap' );
	}
}
