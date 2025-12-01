<?php
/**
 * Tests for Orchestration Settings Enforcement.
 *
 * Verifies that orchestration layer settings actually control whether features are applied.
 *
 * @package WP_MCP_AI
 */

/**
 * Class WP_MCP_AI_Orchestration_Settings_Enforcement_Test
 */
class WP_MCP_AI_Orchestration_Settings_Enforcement_Test extends WP_UnitTestCase {

	/**
	 * Test that budget enforcement service exists.
	 */
	public function test_budget_enforcement_service_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Orchestration_Budget_Enforcement_Service' ) );
	}

	/**
	 * Test that budget management setting is checked for max tokens.
	 */
	public function test_budget_management_controls_max_tokens() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		// Enable budget management - should use tier-based limits.
		update_option( 'wp_mcp_ai_settings', array( 'enable_budget_management' => true ) );
		$max_tokens_enabled = $resource_mgr->get_max_tokens();

		// Disable budget management - should return high default.
		update_option( 'wp_mcp_ai_settings', array( 'enable_budget_management' => false ) );
		$max_tokens_disabled = $resource_mgr->get_max_tokens();

		// Disabled should return much higher value than tier-based.
		$this->assertGreaterThan( $max_tokens_enabled, $max_tokens_disabled );
		$this->assertEquals( 128000, $max_tokens_disabled );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that budget management setting is checked for request timeout.
	 */
	public function test_budget_management_controls_timeout() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		// Force low tier for predictable test.
		add_filter(
			'wp_mcp_ai_workload_tier',
			function () {
				return 'low';
			}
		);

		// Enable budget management - should use low tier timeout (30s).
		update_option( 'wp_mcp_ai_settings', array( 'enable_budget_management' => true ) );
		$timeout_enabled = $resource_mgr->get_request_timeout( true );

		// Disable budget management - should use high tier timeout (120s).
		update_option( 'wp_mcp_ai_settings', array( 'enable_budget_management' => false ) );
		$timeout_disabled = $resource_mgr->get_request_timeout( true );

		// Disabled should return high tier timeout regardless of actual tier.
		$this->assertEquals( 30, $timeout_enabled );
		$this->assertEquals( 120, $timeout_disabled );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_workload_tier' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that budget management disabled lifts max_execution_time cap.
	 *
	 * When budget management is off, timeout should be 120s even for calls
	 * with ignore_execution_time=false (used by OpenAI/Anthropic clients).
	 */
	public function test_budget_management_disabled_lifts_execution_time_cap() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();

		// Force low tier and typical max_execution_time.
		add_filter(
			'wp_mcp_ai_workload_tier',
			function () {
				return 'low';
			}
		);

		// Disable budget management.
		update_option( 'wp_mcp_ai_settings', array( 'enable_budget_management' => false ) );

		// Call with ignore_execution_time=false (like OpenAI/Anthropic clients do).
		// Should still return 120s, NOT clamped to max_execution_time - 5.
		$timeout = $resource_mgr->get_request_timeout( false );

		// Should return high tier timeout (120s) unconditionally when budget management is off.
		$this->assertEquals( 120, $timeout );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_workload_tier' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that cron orchestration setting blocks cron tools when disabled.
	 */
	public function test_cron_orchestration_setting_blocks_tools() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Disable cron orchestration.
		update_option( 'wp_mcp_ai_settings', array( 'enable_cron_orchestration' => false ) );

		// Try to create a cron job - should be blocked.
		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'     => 'test_hook',
				'schedule' => 'single',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_cron_disabled', $result->get_error_code() );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that cron orchestration setting allows cron tools when enabled.
	 */
	public function test_cron_orchestration_setting_allows_tools() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Enable cron orchestration.
		update_option( 'wp_mcp_ai_settings', array( 'enable_cron_orchestration' => true ) );

		// Try to create a cron job - should work.
		$tool   = new WP_MCP_AI_Tool_Create_Cron_Job();
		$result = $tool->execute(
			array(
				'hook'      => 'test_hook',
				'schedule'  => 'single',
				'timestamp' => time() + 3600,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hook', $result );
		$this->assertEquals( 'test_hook', $result['hook'] );

		// Clean up the scheduled event.
		wp_clear_scheduled_hook( 'test_hook' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that helper methods for checking settings work correctly.
	 */
	public function test_orchestration_setting_helper_methods() {
		// Test default values (all enabled by default).
		$this->assertTrue( WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_capability_gating_enabled() );
		$this->assertTrue( WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_cron_orchestration_enabled() );
		$this->assertTrue( WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_predictive_optimization_enabled() );

		// Disable capability gating.
		update_option( 'wp_mcp_ai_settings', array( 'enable_capability_gating' => false ) );
		$this->assertFalse( WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_capability_gating_enabled() );

		// Disable predictive optimization.
		update_option( 'wp_mcp_ai_settings', array( 'enable_predictive_optimization' => false ) );
		$this->assertFalse( WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_predictive_optimization_enabled() );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that orchestration features work for all providers.
	 */
	public function test_orchestration_works_for_all_providers() {
		// This is a documentation test - verifies the concept that orchestration
		// is provider-agnostic by checking that Resource Manager is used by all clients.

		// All provider clients should use Resource Manager for limits.
		$this->assertTrue( class_exists( 'WP_MCP_AI_OpenAI_Client' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Gemini_Client' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Anthropic_Client' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Ollama_Client' ) );

		// Resource Manager provides unified interface for all providers.
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
		$this->assertInstanceOf( 'WP_MCP_AI_Resource_Manager', $resource_mgr );

		// Orchestration enforcement service hooks into resource manager filters.
		$this->assertTrue( has_filter( 'wp_mcp_ai_resource_max_tokens' ) !== false );
		$this->assertTrue( has_filter( 'wp_mcp_ai_resource_request_timeout' ) !== false );
	}
}
