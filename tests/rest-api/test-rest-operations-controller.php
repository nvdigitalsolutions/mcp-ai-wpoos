<?php
/**
 * Tests the operations REST surface (2.4.0, Proposal 084 Phase 3):
 * GET /operations/cron, DELETE /operations/cron/{id},
 * GET /operations/dlq, POST retry/dismiss.
 *
 * Covers: manage_options gating, the cron list/delete contract, and the DLQ
 * list envelope with sanitized item shapes.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Operations_Controller_Test extends WP_UnitTestCase {

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Operations_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The cron list wraps the cron-manager service records with a job_id.
	 */
	public function test_cron_list_wraps_service_jobs() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Seed one tracked job through the service.
		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			WP_MCP_AI_Cron_Manager::record_job( 'wp_mcp_ai_test_hook', array( 'a' => 1 ), 'hourly', time() + 3600, $user_id );
		}

		$response = ( new WP_MCP_AI_REST_Operations_Controller() )->get_cron_jobs();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertGreaterThanOrEqual( 1, $data['data']['total'] );
		$jobs = $data['data']['jobs'];
		$this->assertNotEmpty( $jobs );
		$this->assertArrayHasKey( 'job_id', $jobs[0] );
	}

	/**
	 * Deleting an unknown cron job returns 404.
	 */
	public function test_delete_unknown_cron_job_404s() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/operations/cron/does-not-exist' );
		$result  = ( new WP_MCP_AI_REST_Operations_Controller() )->delete_cron_job( $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * The DLQ list maps service items onto the sanitized SPA shape.
	 */
	public function test_dlq_list_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/operations/dlq' );
		$response = ( new WP_MCP_AI_REST_Operations_Controller() )->get_dlq_items( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'items', $data['data'] );
		foreach ( $data['data']['items'] as $item ) {
			$this->assertArrayHasKey( 'item_id', $item );
			$this->assertArrayHasKey( 'type', $item );
			$this->assertArrayHasKey( 'dismissed', $item );
			$this->assertIsBool( $item['dismissed'] );
		}
	}
}
