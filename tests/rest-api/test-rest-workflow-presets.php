<?php
/**
 * Tests the workflow presets REST bridge (2.4.0, Proposal 084 Phase 3b,
 * W10.2c): GET /mcp-ai-pro/v1/workflow-presets and
 * POST /workflow-presets/{id}/install.
 *
 * Covers: manage_options gating, the category/summary list envelope, the
 * full preset install payload (nodes/edges preserved), and 404 handling.
 * Skipped when the Pro addon is not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Workflow_Presets_REST_Controller_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Workflow_Presets_REST_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The list envelope carries categories and summaries (no graphs).
	 */
	public function test_list_envelope_as_admin() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			$this->markTestSkipped( 'Presets class not available.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_Pro_Workflow_Presets_REST_Controller() )->list_presets();
		$data     = $response->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertArrayHasKey( 'presets', $data );
		foreach ( $data['presets'] as $preset ) {
			$this->assertArrayHasKey( 'id', $preset );
			$this->assertArrayHasKey( 'category', $preset );
			$this->assertArrayNotHasKey( 'nodes', $preset, 'Summaries must not carry graphs.' );
		}
	}

	/**
	 * Install returns the full preset graph with nodes/edges preserved.
	 */
	public function test_install_returns_full_graph() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			$this->markTestSkipped( 'Presets class not available.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$preset_ids = array_keys( WP_MCP_AI_Pro_Workflow_Presets::get_presets() );
		$this->assertNotEmpty( $preset_ids );

		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/workflow-presets/x/install' );
		$request->set_url_params( array( 'id' => $preset_ids[0] ) );

		$response = ( new WP_MCP_AI_Pro_Workflow_Presets_REST_Controller() )->install_preset( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['ok'] );
		$this->assertSame( $preset_ids[0], $data['preset']['id'] );
		$this->assertIsArray( $data['preset']['nodes'] );
		$this->assertIsArray( $data['preset']['edges'] );

		foreach ( $data['preset']['nodes'] as $node ) {
			$this->assertArrayHasKey( 'id', $node );
			$this->assertArrayHasKey( 'type', $node );
			$this->assertArrayHasKey( 'data', $node );
		}
	}

	/**
	 * Unknown presets surface as readable 404 errors.
	 */
	public function test_unknown_preset_is_404() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			$this->markTestSkipped( 'Presets class not available.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/workflow-presets/x/install' );
		$request->set_url_params( array( 'id' => 'no_such_preset' ) );

		$result = ( new WP_MCP_AI_Pro_Workflow_Presets_REST_Controller() )->install_preset( $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
