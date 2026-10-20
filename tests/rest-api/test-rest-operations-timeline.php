<?php
/**
 * Tests the operations timeline REST surface (2.4.0, Proposal 084 Phase 3):
 * GET /operations/timeline.
 *
 * Covers: manage_options gating and the paged envelope contract the
 * Complete Console Operations page consumes (runs/total/page/per_page).
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Operations_Timeline_Test extends WP_UnitTestCase {

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
	 * An administrator receives the paged timeline envelope, even when no
	 * run data exists yet (the metric store is unprovisioned in tests, so
	 * the post-meta fallback produces an empty list — the shape is what the
	 * console depends on).
	 */
	public function test_timeline_envelope_as_admin() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/operations/timeline' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'assistant_id', 0 );

		$response = ( new WP_MCP_AI_REST_Operations_Controller() )->get_timeline( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'runs', $data['data'] );
		$this->assertArrayHasKey( 'total', $data['data'] );
		$this->assertArrayHasKey( 'page', $data['data'] );
		$this->assertArrayHasKey( 'per_page', $data['data'] );
		$this->assertSame( 1, $data['data']['page'] );
		$this->assertIsArray( $data['data']['runs'] );
		$this->assertIsInt( $data['data']['total'] );
	}

	/**
	 * The page argument is clamped to at least 1.
	 */
	public function test_timeline_clamps_page_floor() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/operations/timeline' );
		$request->set_param( 'page', 0 );

		$response = ( new WP_MCP_AI_REST_Operations_Controller() )->get_timeline( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['data']['page'] );
	}
}
