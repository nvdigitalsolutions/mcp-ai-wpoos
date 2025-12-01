<?php
/**
 * Test REST API token manager endpoints.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for token manager REST API.
 */
class WP_MCP_AI_REST_Token_Manager_Test extends WP_UnitTestCase {

	/**
	 * Test getting user tier via REST API.
	 */
	public function test_get_user_tier_endpoint() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/' . $user_id . '/token-tier' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'tier', $data );
		$this->assertEquals( 'pro', $data['tier'] );
		$this->assertArrayHasKey( 'tool_limits', $data );
	}

	/**
	 * Test updating user tier via REST API.
	 */
	public function test_update_user_tier_endpoint() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$user_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/users/' . $user_id . '/token-tier' );
		$request->set_param( 'tier', 'enterprise' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'enterprise', $data['tier'] );

		// Verify tier was actually updated.
		$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$this->assertEquals( 'enterprise', $tier );
	}

	/**
	 * Test permission check for tier updates.
	 */
	public function test_update_tier_requires_admin() {
		$user_id   = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $editor_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/users/' . $user_id . '/token-tier' );
		$request->set_param( 'tier', 'pro' );

		$response = rest_get_server()->dispatch( $request );

		// Editor should not be able to update tiers.
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test getting user usage via REST API.
	 */
	public function test_get_user_usage_endpoint() {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		// Simulate some usage.
		$usage = array(
			'test_tool' => array(
				'total_tokens' => 5000,
				'requests'     => 10,
				'first_used'   => '2025-11-01 12:00:00',
				'last_used'    => '2025-11-11 14:00:00',
				'daily'        => array(
					'2025-11-11' => 2000,
					'2025-11-10' => 3000,
				),
			),
		);
		update_user_meta( $user_id, '_wp_mcp_ai_tool_token_usage', $usage );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/' . $user_id . '/token-usage' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'usage', $data );
		$this->assertCount( 1, $data['usage'] );
		$this->assertEquals( 5000, $data['usage'][0]['total_tokens'] );
		$this->assertEquals( 10, $data['usage'][0]['requests'] );
	}

	/**
	 * Test getting forecast via REST API.
	 */
	public function test_get_forecast_endpoint() {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		// Create sufficient hourly data for forecast.
		$usage = array(
			'test_tool' => array(
				'total_tokens' => 0,
				'requests'     => 0,
				'first_used'   => '',
				'last_used'    => '',
				'daily'        => array(
					gmdate( 'Y-m-d' ) => 10000,
				),
				'hourly'       => array(),
			),
		);

		for ( $i = 0; $i < 48; $i++ ) {
			$hour_key                                  = gmdate( 'Y-m-d-H', strtotime( "-{$i} hours" ) );
			$usage['test_tool']['hourly'][ $hour_key ] = 500;
		}

		update_user_meta( $user_id, '_wp_mcp_ai_tool_token_usage', $usage );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/' . $user_id . '/token-forecast' );
		$request->set_param( 'tool', 'test_tool' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'forecast', $data );

		if ( null !== $data['forecast'] ) {
			$this->assertArrayHasKey( 'will_exceed', $data['forecast'] );
			$this->assertArrayHasKey( 'projected_usage', $data['forecast'] );
			$this->assertArrayHasKey( 'confidence', $data['forecast'] );
		}
	}

	/**
	 * Test user can only access own data.
	 */
	public function test_user_access_restriction() {
		$user1_id = $this->factory->user->create();
		$user2_id = $this->factory->user->create();

		wp_set_current_user( $user1_id );

		// Try to access another user's data.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/' . $user2_id . '/token-tier' );
		$response = rest_get_server()->dispatch( $request );

		// Should be forbidden.
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test admin can access any user's data.
	 */
	public function test_admin_access_all_users() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$user_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/' . $user_id . '/token-tier' );
		$response = rest_get_server()->dispatch( $request );

		// Admin should have access.
		$this->assertEquals( 200, $response->get_status() );
	}
}
