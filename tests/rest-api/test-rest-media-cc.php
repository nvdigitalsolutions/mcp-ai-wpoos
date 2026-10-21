<?php
/**
 * Tests the Pro Media Command Center REST bridge (2.4.0, Proposal 084
 * Phase 4, W14): GET /mcp-ai-pro/v1/media-cc/health +
 * POST /mcp-ai-pro/v1/media-cc/compression-sweep for the console
 * Media Ops page.
 *
 * Covers: manage_options gating, the zeroed snapshot envelope (empty test
 * library — zero network), and the sweep trigger's graceful degradation
 * when Action Scheduler is unavailable. Skipped when the Pro addon classes
 * are not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Media_CC_REST_Controller_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Media_CC_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Media_CC_REST_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Admins pass the permission check.
	 */
	public function test_admin_is_allowed() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Media_CC_REST_Controller() )->permissions_check();

		$this->assertTrue( $result );
	}

	/**
	 * The health route returns the zeroed snapshot envelope on an empty
	 * test library (no attachments, no queue, no schedules — zero network).
	 */
	public function test_health_zeroed_snapshot() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Media_CC_REST_Controller() )->get_health();

		if ( is_wp_error( $result ) && 'media_cc_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Media command-center page unavailable in this bootstrap.' );
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		foreach ( array( 'attachments', 'storage', 'alt_pct', 'nextgen_pct', 'compression', 'queue', 'schedule' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing snapshot key: {$key}" );
		}

		$this->assertSame( 0, $data['attachments']['total'] );
		$this->assertSame( 0, $data['attachments']['image_count'] );
		$this->assertSame( 0, $data['storage'] );
		$this->assertSame( 0, $data['compression'] );
		$this->assertSame( 0, $data['queue']['pending'] );
		$this->assertSame( 0, $data['schedule']['active'] );
	}

	/**
	 * The sweep trigger degrades gracefully: either a readable "Action
	 * Scheduler unavailable" 503 (the test env ships no AS) or a queued /
	 * already-queued result array when AS is present.
	 */
	public function test_sweep_trigger_degrades_gracefully() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_Pro_Media_CC_REST_Controller();
		$result     = $controller->run_compression_sweep( new WP_REST_Request() );

		if ( is_wp_error( $result ) && 'media_cc_provider_unavailable' === $result->get_error_code() ) {
			$this->markTestSkipped( 'Media command-center page unavailable in this bootstrap.' );
		}

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'media_cc_as_unavailable', $result->get_error_code() );
			$this->assertSame( 503, $result->get_error_data()['status'] );
			return;
		}

		$data = $result->get_data();
		$this->assertArrayHasKey( 'queued', $data );
		$this->assertArrayHasKey( 'message', $data );
		$this->assertNotEmpty( $data['message'] );
	}
}
