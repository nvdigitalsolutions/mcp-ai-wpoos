<?php
/**
 * Tests for the evolved-role registry (v1.1.98).
 *
 * Covers the wp_mcp_ai_agent_roles filter's registry-first resolution, the
 * legacy LIKE-scan fallback with lazy registry build, and the idempotent
 * registry helper — the replacement for the unbounded options-table scan.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test evolved role registry resolution.
 *
 * @covers WP_MCP_AI_Agent_Harness_Evolver::register_evolved_roles
 */
class Test_Evolved_Role_Registry extends WP_UnitTestCase {

	/**
	 * Build a harness instance with the evolved-roles filter registered.
	 *
	 * WP-PHPUnit restores $wp_filter between tests, so the filter must be
	 * re-registered per test.
	 *
	 * @return WP_MCP_AI_Agent_Harness_Evolver Instance.
	 */
	private function build_evolver() {
		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'registry-test-session', 1 );
		$method  = new ReflectionMethod( WP_MCP_AI_Agent_Harness_Evolver::class, 'register_evolved_roles' );
		$method->setAccessible( true );
		$method->invoke( $evolver );
		return $evolver;
	}

	/**
	 * The registry path resolves roles by reading each tracked option
	 * directly — no options-table LIKE scan required.
	 */
	public function test_registry_path_resolves_roles() {
		$this->build_evolver();

		update_option(
			WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_OPTION_PREFIX . 'advisor',
			array(
				'type'                => 'advisor',
				'system_instructions' => 'Advise.',
				'evolved'             => true,
			),
			false
		);
		update_option( WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_REGISTRY_OPTION, array( WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_OPTION_PREFIX . 'advisor' ), false );

		$roles = apply_filters( 'wp_mcp_ai_agent_roles', array() );

		$this->assertArrayHasKey( 'advisor', $roles );
	}

	/**
	 * Pre-registry installs (no registry option) fall back to the bounded
	 * LIKE scan and rebuild the registry lazily for the next request.
	 */
	public function test_legacy_scan_falls_back_and_builds_registry() {
		$this->build_evolver();

		update_option(
			WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_OPTION_PREFIX . 'advisor',
			array(
				'type'                => 'advisor',
				'system_instructions' => 'Advise.',
				'evolved'             => true,
			),
			false
		);

		$roles = apply_filters( 'wp_mcp_ai_agent_roles', array() );

		$this->assertArrayHasKey( 'advisor', $roles );

		$registry = get_option( WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_REGISTRY_OPTION, null );
		$this->assertIsArray( $registry );
		$this->assertContains( WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_OPTION_PREFIX . 'advisor', $registry );
	}

	/**
	 * The add_role_to_registry() helper is idempotent — repeated registration
	 * never duplicates the option name.
	 */
	public function test_add_role_to_registry_is_idempotent() {
		$evolver = $this->build_evolver();
		$method  = new ReflectionMethod( WP_MCP_AI_Agent_Harness_Evolver::class, 'add_role_to_registry' );
		$method->setAccessible( true );

		$method->invoke( $evolver, 'advisor' );
		$method->invoke( $evolver, 'advisor' );

		$registry = get_option( WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_REGISTRY_OPTION, array() );
		$this->assertCount( 1, $registry );
		$this->assertContains( WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_ROLE_OPTION_PREFIX . 'advisor', $registry );
	}
}
