<?php
/**
 * Tests the GET /mcp-ai/v1/features REST surface — the availability map the
 * Complete Console fetches at boot to gate its navigation (Proposal 084
 * Phase 0).
 *
 * Covers: logged-in access for any role, 401 for anonymous requests, and
 * the envelope shape.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Features_Controller_Test extends WP_UnitTestCase {

	/**
	 * Anonymous requests are refused.
	 */
	public function test_anonymous_is_forbidden() {
		wp_set_current_user( 0 );

		$result = ( new WP_MCP_AI_REST_Features_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Any logged-in role (subscriber included) may read the map — presence
	 * is not secret; each surface enforces its own capability separately.
	 */
	public function test_logged_in_subscriber_can_read() {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( ( new WP_MCP_AI_REST_Features_Controller() )->permissions_check() );
	}

	/**
	 * The response carries the canonical envelope with the boolean map.
	 */
	public function test_get_features_envelope() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Features_Controller() )->get_features();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'features', $data['data'] );
		$this->assertIsArray( $data['data']['features'] );
		foreach ( $data['data']['features'] as $value ) {
			$this->assertIsBool( $value );
		}
	}
}
