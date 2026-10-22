<?php
/**
 * Tests the Pro Agent Command Center overview REST surface (Proposal 084
 * Phase 5d): GET /mcp-ai-pro/v1/command-center/overview — manage_options-
 * gated, reusing the page class's promoted static collectors (zero-network;
 * the collectors read options/transients/assistant posts only).
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Command_Center_REST_Test extends WP_UnitTestCase {

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_Pro_Command_Center_REST_Controller();
		$result     = $controller->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The overview envelope carries every KPI + the status/activity payloads.
	 */
	public function test_overview_envelope_as_admin() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_Pro_Command_Center_REST_Controller();
		$response   = $controller->get_overview();
		$data       = $response->get_data();

		$this->assertTrue( $data['success'] );
		foreach (
			array(
				'total_agents',
				'agents_online',
				'active_tasks',
				'pending_approvals',
				'tokens_today',
				'uptime',
			) as $kpi
		) {
			$this->assertArrayHasKey( $kpi, $data['data']['kpis'], "Missing KPI: {$kpi}" );
		}
		$this->assertArrayHasKey( 'restriction_count', $data['data'] );
		$this->assertArrayHasKey( 'agent_statuses', $data['data'] );
		$this->assertArrayHasKey( 'recent_events', $data['data'] );
		$this->assertIsArray( $data['data']['agent_statuses'] );
		$this->assertIsArray( $data['data']['recent_events'] );
		$this->assertIsInt( $data['data']['kpis']['tokens_today'] );
		$this->assertIsFloat( $data['data']['kpis']['uptime'] );
	}

	/**
	 * The agent-status map aligns with the assistant inventory.
	 */
	public function test_agent_statuses_align_with_inventory() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Console Assistant',
			)
		);

		$controller = new WP_MCP_AI_Pro_Command_Center_REST_Controller();
		$data       = $controller->get_overview()->get_data();

		$this->assertSame(
			$data['data']['kpis']['total_agents'],
			count( $data['data']['agent_statuses'] ),
			'Every inventory assistant must carry a status entry.'
		);
		foreach ( $data['data']['agent_statuses'] as $status ) {
			$this->assertArrayHasKey( 'status', $status );
			$this->assertArrayHasKey( 'last_active', $status );
			$this->assertContains( $status['status'], array( 'online', 'idle', 'offline' ), true );
		}
	}

	/**
	 * The route is registered under the Pro namespace.
	 */
	public function test_route_is_registered() {
		$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );

		if ( ! isset( $routes['/mcp-ai-pro/v1/command-center/overview'] ) ) {
			add_action(
				'rest_api_init',
				function () {
					( new WP_MCP_AI_Pro_Command_Center_REST_Controller() )->register_routes();
				}
			);
			$GLOBALS['wp_rest_server'] = null;
			rest_get_server();
			$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );
		}

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/command-center/overview', $routes );
	}
}
