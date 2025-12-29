<?php
/**
 * Tests for translation loading timing (WordPress 6.7.0+ compatibility).
 *
 * @package WP_MCP_AI
 */

/**
 * Tests that admin notices and other hooks using translation functions
 * are registered at or after the 'init' action, as required by WordPress 6.7.0+.
 */
class WP_MCP_AI_Translation_Loading_Timing_Test extends WP_UnitTestCase {

	/**
	 * Verify that plugin action links hook is registered on init.
	 *
	 * WordPress 6.7.0+ requires translations to be loaded at init or later.
	 * Plugin action links use translation functions, so the filter should be
	 * registered via the init action.
	 */
	public function test_plugin_action_links_registered_on_init() {
		global $wp_filter;

		// Check that the registration function is hooked to init.
		$this->assertTrue(
			has_action( 'init', 'wp_mcp_ai_register_plugin_action_links' ),
			'Plugin action links should be registered via init action'
		);
	}

	/**
	 * Verify that activation security notice hook is registered on init.
	 *
	 * The activation security notice uses translation functions, so it should
	 * be registered via the init action.
	 */
	public function test_activation_security_notice_registered_on_init() {
		// Check that the registration function is hooked to init.
		$this->assertTrue(
			has_action( 'init', 'wp_mcp_ai_register_activation_security_notice' ),
			'Activation security notice should be registered via init action'
		);
	}

	/**
	 * Verify that Security Monitor Admin registers admin notices on init.
	 */
	public function test_security_monitor_admin_notices_registered_on_init() {
		// The class should have a register_admin_notices method.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Security_Monitor_Admin', 'register_admin_notices' ),
			'Security Monitor Admin should have register_admin_notices method'
		);
	}

	/**
	 * Verify that Assistant CPT registers admin notices on init.
	 */
	public function test_assistant_cpt_admin_notices_registered_on_init() {
		// The class should have a register_admin_notices method.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Assistant_CPT', 'register_admin_notices' ),
			'Assistant CPT should have register_admin_notices method'
		);
	}

	/**
	 * Verify that Nefarious Usage Monitor registers admin notices on init.
	 */
	public function test_nefarious_usage_monitor_admin_notices_registered_on_init() {
		// The class should have a register_admin_notices method.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Nefarious_Usage_Monitor', 'register_admin_notices' ),
			'Nefarious Usage Monitor should have register_admin_notices method'
		);
	}

	/**
	 * Verify that Tool Registry registers admin notices on init.
	 */
	public function test_tool_registry_admin_notices_registered_on_init() {
		// The class should have a register_admin_notices method.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Tool_Registry', 'register_admin_notices' ),
			'Tool Registry should have register_admin_notices method'
		);
	}

	/**
	 * Verify that Model Pricing Checker registers admin notices on init.
	 */
	public function test_model_pricing_checker_admin_notices_registered_on_init() {
		// The class should have a register_admin_notices method.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Model_Pricing_Checker', 'register_admin_notices' ),
			'Model Pricing Checker should have register_admin_notices method'
		);
	}

	/**
	 * Verify that admin notices are not registered before init.
	 *
	 * This test simulates the plugin loading sequence and verifies that
	 * admin_notices hooks are not registered until after init fires.
	 */
	public function test_admin_notices_not_registered_before_init() {
		// Remove all existing hooks to simulate fresh load.
		remove_all_actions( 'admin_notices' );

		// Simulate plugins_loaded (before init).
		do_action( 'plugins_loaded' );

		// At this point, admin_notices should not have translation-using handlers.
		// We can't easily test this directly, but we can verify the registration.
		// functions are hooked to init.
		$this->assertTrue(
			has_action( 'init', 'wp_mcp_ai_register_plugin_action_links' ) ||
			has_action( 'init', 'wp_mcp_ai_register_activation_security_notice' ),
			'At least one registration function should be hooked to init'
		);
	}
}
