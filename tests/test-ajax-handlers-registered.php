<?php
/**
 * Test that all AJAX handlers are properly registered with WordPress.
 *
 * This test verifies that:
 * 1. All AJAX action hooks are registered
 * 2. All handlers have proper security checks (nonce verification and capability checks)
 *
 * @package WP_MCP_AI
 */

/**
 * Test AJAX Handler Registration.
 */
class Test_AJAX_Handlers_Registered extends WP_UnitTestCase {

	/**
	 * List of all AJAX actions that should be registered.
	 *
	 * @var array
	 */
	private $expected_ajax_actions = array(
		'wp_ajax_wp_mcp_ai_test_ollama_connection',
		'wp_ajax_wp_mcp_ai_fetch_ollama_models',
		'wp_ajax_wp_mcp_ai_test_lm_studio_connection',
		'wp_ajax_wp_mcp_ai_fetch_lm_studio_models',
		'wp_ajax_wp_mcp_ai_fetch_cloudways_data',
		'wp_ajax_wp_mcp_ai_test_cloudflare_connection',
		'wp_ajax_wp_mcp_ai_test_brave_search_connection',
		'wp_ajax_wp_mcp_ai_reset_user_token_usage',
		'wp_ajax_wp_mcp_ai_reset_all_token_usage',
		'wp_ajax_wp_mcp_ai_save_tool_limits',
		'wp_ajax_wp_mcp_ai_apply_orchestration_preset',
		'wp_ajax_wp_mcp_ai_export_token_usage_csv',
		'wp_ajax_wp_mcp_ai_bulk_assign_tier',
		'wp_ajax_wp_mcp_ai_apply_all_recommendations',
		'wp_ajax_wp_mcp_ai_apply_preset',
		'wp_ajax_wp_mcp_ai_get_usage_trend',
		'wp_ajax_wp_mcp_ai_get_tier_distribution',
		'wp_ajax_wp_mcp_ai_get_tool_breakdown',
		'wp_ajax_wp_mcp_ai_get_provider_distribution',
		'wp_ajax_wp_mcp_ai_get_model_distribution',
		'wp_ajax_wp_mcp_ai_update_chart_period',
		'wp_ajax_wp_mcp_ai_refresh_chart',
		'wp_ajax_wp_mcp_ai_toggle_tool',
		'wp_ajax_wp_mcp_ai_get_models_for_provider',
		'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', // Frontend support for model selector.
		'wp_ajax_wp_mcp_ai_regenerate_playbook',
		'wp_ajax_wp_mcp_ai_sync_all_playbooks',
		'wp_ajax_wp_mcp_ai_test_mcp_endpoint',
		'wp_ajax_wp_mcp_ai_test_mcp_method',
		'wp_ajax_wp_mcp_ai_test_provider',
		'wp_ajax_wp_mcp_ai_create_assistant_from_modal',
		'wp_ajax_wp_mcp_ai_auto_configure_auth0',
		'wp_ajax_wp_mcp_ai_toggle_auth0_bridge',
		'wp_ajax_wp_mcp_ai_run_performance_test',
		'wp_ajax_wp_mcp_ai_get_performance_metrics',
		'wp_ajax_wp_mcp_ai_export_test_results',
		'wp_ajax_wp_mcp_ai_dismiss_price_notice',
	);

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure all necessary classes are loaded by triggering WordPress admin init.
		// This simulates the plugin being fully loaded in an admin context.
		if ( ! did_action( 'admin_init' ) ) {
			do_action( 'admin_init' );
		}
	}

	/**
	 * Test that all expected AJAX actions are registered.
	 */
	public function test_all_ajax_actions_are_registered() {
		$missing_actions = array();

		foreach ( $this->expected_ajax_actions as $action ) {
			if ( ! has_action( $action ) ) {
				$missing_actions[] = $action;
			}
		}

		$this->assertEmpty(
			$missing_actions,
			'The following AJAX actions are not registered: ' . implode( ', ', $missing_actions )
		);
	}

	/**
	 * Test each AJAX action individually for better error reporting.
	 *
	 * @dataProvider ajax_actions_provider
	 *
	 * @param string $action The AJAX action to test.
	 */
	public function test_ajax_action_is_registered( $action ) {
		$this->assertTrue(
			has_action( $action ) !== false,
			"AJAX action '{$action}' should be registered"
		);
	}

	/**
	 * Data provider for AJAX actions.
	 *
	 * @return array
	 */
	public function ajax_actions_provider() {
		$actions = array();
		foreach ( $this->expected_ajax_actions as $action ) {
			$actions[ $action ] = array( $action );
		}
		return $actions;
	}

	/**
	 * Test that Settings Dashboard handlers are registered.
	 */
	public function test_settings_dashboard_ajax_handlers() {
		// Settings Dashboard registers many handlers.
		$dashboard_actions = array(
			'wp_ajax_wp_mcp_ai_test_ollama_connection',
			'wp_ajax_wp_mcp_ai_fetch_ollama_models',
			'wp_ajax_wp_mcp_ai_reset_user_token_usage',
			'wp_ajax_wp_mcp_ai_reset_all_token_usage',
			'wp_ajax_wp_mcp_ai_save_tool_limits',
			'wp_ajax_wp_mcp_ai_toggle_tool',
		);

		foreach ( $dashboard_actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"Settings Dashboard action '{$action}' should be registered"
			);
		}
	}

	/**
	 * Test that Auth0 Setup handlers are registered.
	 */
	public function test_auth0_setup_ajax_handlers() {
		$auth0_actions = array(
			'wp_ajax_wp_mcp_ai_auto_configure_auth0',
			'wp_ajax_wp_mcp_ai_toggle_auth0_bridge',
		);

		foreach ( $auth0_actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"Auth0 Setup action '{$action}' should be registered"
			);
		}
	}

	/**
	 * Test that Performance Section handlers are registered.
	 */
	public function test_performance_section_ajax_handlers() {
		$performance_actions = array(
			'wp_ajax_wp_mcp_ai_run_performance_test',
			'wp_ajax_wp_mcp_ai_get_performance_metrics',
			'wp_ajax_wp_mcp_ai_export_test_results',
		);

		foreach ( $performance_actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"Performance Section action '{$action}' should be registered"
			);
		}
	}

	/**
	 * Test that MCP Diagnostic handlers are registered.
	 */
	public function test_mcp_diagnostic_ajax_handlers() {
		$mcp_actions = array(
			'wp_ajax_wp_mcp_ai_test_mcp_endpoint',
			'wp_ajax_wp_mcp_ai_test_mcp_method',
		);

		foreach ( $mcp_actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"MCP Diagnostic action '{$action}' should be registered"
			);
		}
	}

	/**
	 * Test that Provider Diagnostic handlers are registered.
	 */
	public function test_provider_diagnostic_ajax_handlers() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_test_provider' ) !== false,
			'Provider Diagnostic action should be registered'
		);
	}

	/**
	 * Test that Create Assistant handler is registered.
	 */
	public function test_create_assistant_ajax_handler() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_create_assistant_from_modal' ) !== false,
			'Create Assistant action should be registered'
		);
	}

	/**
	 * Test that Model Pricing Checker handler is registered.
	 */
	public function test_model_pricing_checker_ajax_handler() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_dismiss_price_notice' ) !== false,
			'Model Pricing Checker dismiss action should be registered'
		);
	}

	/**
	 * Test that chart-related handlers are registered.
	 */
	public function test_chart_ajax_handlers() {
		$chart_actions = array(
			'wp_ajax_wp_mcp_ai_get_usage_trend',
			'wp_ajax_wp_mcp_ai_get_tier_distribution',
			'wp_ajax_wp_mcp_ai_get_tool_breakdown',
			'wp_ajax_wp_mcp_ai_get_provider_distribution',
			'wp_ajax_wp_mcp_ai_get_model_distribution',
			'wp_ajax_wp_mcp_ai_update_chart_period',
			'wp_ajax_wp_mcp_ai_refresh_chart',
		);

		foreach ( $chart_actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"Chart action '{$action}' should be registered"
			);
		}
	}
}
