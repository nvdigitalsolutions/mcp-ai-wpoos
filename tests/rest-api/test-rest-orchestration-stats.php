<?php
/**
 * Tests the orchestration stats REST surface (2.4.0, Proposal 084 Phase 3):
 * GET /orchestration/stats.
 *
 * Covers: manage_options gating and the consolidated sections the Complete
 * Console Orchestration page consumes (multi_agent, orchestration,
 * recent_workflows), plus the public-shape contract (no raw agent config
 * leaks, role/subsystem keys present).
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Orchestration_Stats_Controller_Test extends WP_UnitTestCase {

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Orchestration_Stats_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * An administrator receives every consolidated section.
	 */
	public function test_stats_envelope_as_admin() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Orchestration_Stats_Controller() )->get_stats();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		foreach ( array( 'multi_agent', 'orchestration', 'recent_workflows' ) as $section ) {
			$this->assertArrayHasKey( $section, $data['data'], "Missing section: {$section}" );
		}
	}

	/**
	 * The multi-agent section carries the public counters and never the raw
	 * assistant config blocks.
	 */
	public function test_multi_agent_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response    = ( new WP_MCP_AI_REST_Orchestration_Stats_Controller() )->get_stats();
		$multi_agent = $response->get_data()['data']['multi_agent'];

		foreach ( array( 'installed', 'total_agents', 'active_agents', 'total_tools', 'is_pro_active', 'patterns', 'agents' ) as $key ) {
			$this->assertArrayHasKey( $key, $multi_agent, "Missing multi-agent key: {$key}" );
		}
		$this->assertIsBool( $multi_agent['installed'] );
		$this->assertIsArray( $multi_agent['agents'] );

		foreach ( $multi_agent['agents'] as $agent ) {
			$this->assertArrayNotHasKey( 'config', $agent );
			$this->assertArrayHasKey( 'title', $agent );
			$this->assertArrayHasKey( 'workflow_pattern', $agent );
			$this->assertArrayHasKey( 'tool_count', $agent );
		}
	}

	/**
	 * The orchestration section carries the role distribution and the four
	 * subsystem status blocks.
	 */
	public function test_orchestration_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response      = ( new WP_MCP_AI_REST_Orchestration_Stats_Controller() )->get_stats();
		$orchestration = $response->get_data()['data']['orchestration'];

		foreach ( array( 'total_professions', 'seeded_professions', 'with_task_patterns', 'seeder_version', 'roles', 'system_status' ) as $key ) {
			$this->assertArrayHasKey( $key, $orchestration, "Missing orchestration key: {$key}" );
		}

		foreach ( array( 'planner', 'executor', 'critic', 'specialist', 'generalist' ) as $role ) {
			$this->assertArrayHasKey( $role, $orchestration['roles'], "Missing role count: {$role}" );
		}

		foreach ( array( 'cron', 'async', 'sse', 'health' ) as $subsystem ) {
			$this->assertArrayHasKey( $subsystem, $orchestration['system_status'], "Missing subsystem: {$subsystem}" );
		}
	}

	/**
	 * Recent workflows is always a list (empty on fresh installs).
	 */
	public function test_recent_workflows_is_list() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Orchestration_Stats_Controller() )->get_stats();

		$this->assertIsArray( $response->get_data()['data']['recent_workflows'] );
	}
}
