<?php
/**
 * Tests the Pro ops REST surface (2.4.0, Proposal 084 Phase 3, gap P11):
 * GET /mcp-ai-pro/v1/ops/overview.
 *
 * Covers: manage_options gating and the consolidated sections the Complete
 * Console Pro Ops page consumes (status, incidents, maintenance, webhooks).
 * Skipped when the Pro addon is not loaded (standalone/base matrices).
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_REST_Ops_Controller_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_REST_Ops_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_REST_Ops_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * An administrator receives every consolidated section.
	 */
	public function test_overview_envelope_as_admin() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_Pro_REST_Ops_Controller() )->get_overview();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		foreach ( array( 'status', 'incidents', 'maintenance', 'webhooks' ) as $section ) {
			$this->assertArrayHasKey( $section, $data['data'], "Missing section: {$section}" );
		}
	}

	/**
	 * Maintenance windows surface the public CPT+meta shape.
	 */
	public function test_maintenance_shape() {
		if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
			$this->markTestSkipped( 'Maintenance CPT not available.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$window_id = $this->factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Maintenance_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'DB upgrade window',
			)
		);
		update_post_meta( $window_id, '_mcp_ai_maintenance_status', WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED );
		update_post_meta( $window_id, '_mcp_ai_maintenance_start', '2026-02-01 02:00:00' );
		update_post_meta( $window_id, '_mcp_ai_maintenance_end', '2026-02-01 03:00:00' );
		update_post_meta( $window_id, '_mcp_ai_maintenance_banner_enabled', '1' );

		$response = ( new WP_MCP_AI_Pro_REST_Ops_Controller() )->get_overview();
		$windows  = $response->get_data()['data']['maintenance'];

		$this->assertCount( 1, $windows );
		$this->assertSame( $window_id, $windows[0]['id'] );
		$this->assertSame( 'DB upgrade window', $windows[0]['title'] );
		$this->assertSame( WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED, $windows[0]['status'] );
		$this->assertTrue( $windows[0]['banner_enabled'] );
	}

	/**
	 * Incidents surface the public CPT+meta shape.
	 */
	public function test_incidents_shape() {
		if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
			$this->markTestSkipped( 'Incident CPT not available.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$incident_id = $this->factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Degraded API',
			)
		);
		update_post_meta( $incident_id, '_mcp_ai_incident_phase', WP_MCP_AI_Incident_CPT::PHASE_RESOLVED );
		update_post_meta( $incident_id, '_mcp_ai_incident_severity', 'major' );
		update_post_meta( $incident_id, '_mcp_ai_incident_services', array( 'api', 'chat' ) );

		$response  = ( new WP_MCP_AI_Pro_REST_Ops_Controller() )->get_overview();
		$incidents = $response->get_data()['data']['incidents'];

		$this->assertCount( 1, $incidents );
		$this->assertSame( $incident_id, $incidents[0]['id'] );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED, $incidents[0]['phase'] );
		$this->assertSame( array( 'api', 'chat' ), $incidents[0]['services'] );
	}

	/**
	 * The webhooks section is always a list (no network checks).
	 */
	public function test_webhooks_is_list() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_Pro_REST_Ops_Controller() )->get_overview();

		$this->assertIsArray( $response->get_data()['data']['webhooks'] );
	}
}
