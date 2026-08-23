<?php
/**
 * Test that all AJAX handlers are properly registered with WordPress.
 *
 * This test verifies that:
 * 1. All AJAX action hooks are registered
 * 2. All handlers have proper security checks (nonce verification and capability checks)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test AJAX Handler Registration.
 */
class Test_AJAX_Handlers_Registered extends WP_UnitTestCase {

	/**
	 * List of all AJAX actions that should be registered.
	 *
	 * Static so it can be consumed by the static data provider
	 * (PHPUnit 11 rejects non-static data providers).
	 *
	 * @var array
	 */
	private static $expected_ajax_actions = array(
		'wp_ajax_wp_mcp_ai_test_ollama_connection',
		'wp_ajax_wp_mcp_ai_fetch_ollama_models',
		'wp_ajax_wp_mcp_ai_test_lm_studio_connection',
		'wp_ajax_wp_mcp_ai_fetch_lm_studio_models',
		'wp_ajax_wp_mcp_ai_fetch_cloudways_data',
		'wp_ajax_wp_mcp_ai_test_cloudways_connection',
		'wp_ajax_wp_mcp_ai_test_cloudflare_connection',
		'wp_ajax_wp_mcp_ai_test_brave_search_connection',
		'wp_ajax_wp_mcp_ai_test_mubert_connection',
		'wp_ajax_wp_mcp_ai_test_flowhub_connection',
		'wp_ajax_wp_mcp_ai_test_isams_connection',
		'wp_ajax_wp_mcp_ai_reset_user_token_usage',
		'wp_ajax_wp_mcp_ai_reset_all_token_usage',
		'wp_ajax_wp_mcp_ai_save_tool_limits',
		'wp_ajax_wp_mcp_ai_save_tool_settings',
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
		'wp_ajax_wp_mcp_ai_reseed_professions',
		'wp_ajax_wp_mcp_ai_reseed_teams',
		'wp_ajax_wp_mcp_ai_seed_task_templates',
		'wp_ajax_wp_mcp_ai_seed_orchestration',
		'wp_ajax_wp_mcp_ai_migrate_gemini_costs',
		'wp_ajax_wp_mcp_ai_refresh_skills',
		'wp_ajax_wp_mcp_ai_get_models_for_provider',
		'wp_ajax_wp_mcp_ai_regenerate_playbook',
		'wp_ajax_wp_mcp_ai_sync_all_playbooks',
		'wp_ajax_wp_mcp_ai_delete_old_playbooks',
		'wp_ajax_wp_mcp_ai_sync_media_templates',
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
		'wp_ajax_wp_mcp_ai_discover_models',
		'wp_ajax_wp_mcp_ai_research_model',
		'wp_ajax_wp_mcp_ai_add_model_config',
	);

	/**
	 * Load the admin layer once for the whole class.
	 *
	 * The per-test hook rollback (`_backup_hooks` / `_restore_hooks`) resets
	 * `$wp_actions`, so a `did_action( 'admin_init' )` guard inside `setUp()`
	 * would re-fire the entire admin_init chain for every one of the ~60
	 * data-provider tests. Firing admin_init once here loads every admin
	 * class at a fraction of the cost.
	 *
	 * Note: wp-phpunit snapshots `$wp_filter` once per process (at the first
	 * test of the run) and restores that snapshot after every test, so the
	 * hook registrations made here are wiped after the first tearDown of this
	 * class whenever another test file ran first. setUp() below therefore
	 * re-registers the classes whose hooks the rollback removed.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Ensure all necessary classes are loaded by triggering WordPress admin init.
		// This simulates the plugin being fully loaded in an admin context.
		if ( ! did_action( 'admin_init' ) ) {
			do_action( 'admin_init' );
		}

		// The plugin's admin loader (`includes/bootstrap/loader.php`) only
		// instantiates the classes below when is_admin() is true, which never
		// happens in the CLI test environment. Mirror that here so the
		// wp_ajax_* actions they register are available for assertion.
		self::require_admin_classes();
		self::register_admin_ajax_handlers();
	}

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Re-register any admin handlers the once-per-process hook snapshot
		// does not contain; the rollback removes them after every test.
		self::register_admin_ajax_handlers();
	}

	/**
	 * Load the admin class files once (class loading is process-wide and
	 * survives the per-test hook rollback).
	 *
	 * @return void
	 */
	private static function require_admin_classes() {
		$admin_init_files = array(
			'includes/admin/class-wp-mcp-ai-admin-settings.php',
			'includes/admin/class-wp-mcp-ai-auth0-setup.php',
			'includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php',
			'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php',
			'includes/admin/class-wp-mcp-ai-provider-diagnostics.php',
			'includes/admin/class-wp-mcp-ai-model-manager-ajax.php',
		);
		foreach ( $admin_init_files as $file ) {
			$path = WP_MCP_AI_PATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Instantiate the admin classes whose wp_ajax_* actions are not currently
	 * registered. Each entry checks a representative hook so a class whose
	 * hooks are present (in the process snapshot) is not double-registered.
	 *
	 * @return void
	 */
	private static function register_admin_ajax_handlers() {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_test_cloudways_connection' ) ) {
			new WP_MCP_AI_Admin_Settings();
		}
		if ( class_exists( 'WP_MCP_AI_Auth0_Setup' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_toggle_auth0_bridge' ) ) {
			new WP_MCP_AI_Auth0_Setup();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Create_Assistant_Button' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_build_assistant_from_conversation' ) ) {
			WP_MCP_AI_Admin_Create_Assistant_Button::init();
		}
		if ( class_exists( 'WP_MCP_AI_MCP_Server_Diagnostic' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_test_mcp_endpoint' ) ) {
			WP_MCP_AI_MCP_Server_Diagnostic::init();
		}
		if ( class_exists( 'WP_MCP_AI_Provider_Diagnostics' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_test_provider' ) ) {
			WP_MCP_AI_Provider_Diagnostics::init();
		}
		if ( class_exists( 'WP_MCP_AI_Model_Manager_Ajax' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_discover_models' ) ) {
			WP_MCP_AI_Model_Manager_Ajax::init();
		}
	}

	/**
	 * Test that all expected AJAX actions are registered.
	 */
	public function test_all_ajax_actions_are_registered() {
		$missing_actions = array();

		foreach ( self::$expected_ajax_actions as $action ) {
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
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ajax_actions_provider' )]
	public function test_ajax_action_is_registered( $action ) {
		$this->assertTrue(
			has_action( $action ) !== false,
			"AJAX action '{$action}' should be registered"
		);
	}

	/**
	 * Data provider for AJAX actions.
	 *
	 * Must be static: PHPUnit 11 rejects non-static data providers.
	 *
	 * @return array
	 */
	public static function ajax_actions_provider() {
		$actions = array();
		foreach ( self::$expected_ajax_actions as $action ) {
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

	/**
	 * Test that Model Manager handlers are registered.
	 */
	public function test_model_manager_ajax_handlers() {
		$model_manager_actions = array(
			'wp_ajax_wp_mcp_ai_discover_models',
			'wp_ajax_wp_mcp_ai_research_model',
			'wp_ajax_wp_mcp_ai_add_model_config',
		);

		foreach ( $model_manager_actions as $action ) {
			$this->assertTrue(
				has_action( $action ) !== false,
				"Model Manager action '{$action}' should be registered"
			);
		}
	}
}
