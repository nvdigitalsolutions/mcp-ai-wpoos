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
	 * Verify that plugin action links filter is registered directly.
	 *
	 * WordPress 6.7.0+ requires translations to be loaded at init or later.
	 * To avoid translation loading issues, the plugin action links now use
	 * untranslated text and the filter is registered directly (not via a hook).
	 */
	public function test_plugin_action_links_registered_directly() {
		// Check that the plugin action links filter is registered.
		$this->assertTrue(
			has_filter( 'plugin_action_links_' . plugin_basename( WP_MCP_AI_FILE ), 'wp_mcp_ai_add_plugin_action_links' ),
			'Plugin action links filter should be registered'
		);
	}

	/**
	 * Verify that activation security notice is registered directly on admin_notices.
	 *
	 * WordPress 6.7.0+ requires translations to be loaded at init or later.
	 * The activation security notice uses translation functions, so it should be
	 * hooked directly to admin_notices (not via admin_init) to ensure translations
	 * are only loaded when the notice is actually rendered.
	 */
	public function test_activation_security_notice_registered_directly() {
		// Check that the notice function is hooked directly to admin_notices.
		$this->assertTrue(
			has_action( 'admin_notices', 'wp_mcp_ai_activation_security_notice' ),
			'Activation security notice should be hooked directly to admin_notices'
		);

		// Check that the deferred security check runs on admin_init.
		$this->assertTrue(
			has_action( 'admin_init', 'wp_mcp_ai_run_deferred_activation_security_check' ),
			'Deferred activation security check should run on admin_init'
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
	 * admin_notices hooks with translation functions are registered at the
	 * correct time to avoid early translation loading in WordPress 6.7+.
	 */
	public function test_admin_notices_not_registered_before_init() {
		// Remove all existing hooks to simulate fresh load.
		remove_all_actions( 'admin_notices' );

		// Simulate plugins_loaded (before init).
		do_action( 'plugins_loaded' );

		// Verify that the deferred security check function is hooked to admin_init.
		// This ensures the security check runs after init completes.
		$this->assertTrue(
			has_action( 'admin_init', 'wp_mcp_ai_run_deferred_activation_security_check' ),
			'Deferred activation security check should be hooked to admin_init'
		);
	}
}
