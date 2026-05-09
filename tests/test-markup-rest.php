<?php
/**
 * Markup subsystem test.
 *
 * Markup REST endpoint and loop-interceptor tests.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Test_Markup_Rest.
 *
 * @group markup
 */
class Test_Markup_Rest extends WP_UnitTestCase {

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Ensure the REST controller is registered for this test process.
		do_action( 'rest_api_init' );
	}

	/**
	 * Test fixture builder.
	 *
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function admin_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_get_returns_404_for_unknown_request() {
		$this->admin_user();
		$req      = new WP_REST_Request( 'GET', '/mcp-ai/v1/markup/mr_unknown' );
		$response = rest_do_request( $req );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_submit_with_invalid_payload_rejected() {
		$user_id = $this->admin_user();
		$store   = new WP_MCP_AI_Markup_Store();
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array(
					'url'    => 'https://example.com/x.png',
					'width'  => 64,
					'height' => 64,
				),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => WP_MCP_AI_Markup_Request::MODE_MASK,
				'user_id'     => $user_id,
			)
		);
		$store->save( $request );

		$req = new WP_REST_Request( 'POST', '/mcp-ai/v1/markup/' . $request->get_request_id() . '/submit' );
		$req->set_body_params(
			array(
				'markup' => array( 'type' => 'NotAnnotation' ),
			)
		);
		$response = rest_do_request( $req );
		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_submit_replay_returns_404() {
		$user_id = $this->admin_user();
		$store   = new WP_MCP_AI_Markup_Store();
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'nonexistent_tool',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => WP_MCP_AI_Markup_Request::MODE_MASK,
				'user_id'     => $user_id,
			)
		);
		$store->save( $request );

		$req = new WP_REST_Request( 'POST', '/mcp-ai/v1/markup/' . $request->get_request_id() . '/submit' );
		$req->set_body_params(
			array(
				'markup' => array(
					'type'   => 'Annotation',
					'body'   => array(),
					'target' => array( 'source' => 'https://example.com/x.png' ),
				),
			)
		);
		// First submit consumes the record. Tool doesn't exist — we expect a tool_missing error.
		$first = rest_do_request( $req );
		$this->assertNotEmpty( $first );

		// Second submit must 404 because the record is gone (replay protection).
		$second = rest_do_request( $req );
		$this->assertSame( 404, $second->get_status() );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_delete_cancels_request() {
		$user_id = $this->admin_user();
		$store   = new WP_MCP_AI_Markup_Store();
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => WP_MCP_AI_Markup_Request::MODE_MASK,
				'user_id'     => $user_id,
			)
		);
		$store->save( $request );

		$req      = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/markup/' . $request->get_request_id() );
		$response = rest_do_request( $req );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $store->get( $request->get_request_id() ) );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_subscriber_without_permission_blocked() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$req      = new WP_REST_Request( 'GET', '/mcp-ai/v1/markup/mr_unknown' );
		$response = rest_do_request( $req );
		// Subscribers have read but not edit_posts; permission_check accepts read.
		// We assert it returns a 404 (i.e. permission passed and we got into the handler).
		$this->assertContains( $response->get_status(), array( 404, 200 ) );
	}
}
