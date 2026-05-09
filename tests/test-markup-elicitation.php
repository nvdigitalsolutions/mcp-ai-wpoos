<?php
/**
 * Markup subsystem test.
 *
 * Markup elicitation envelope tests.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Test_Markup_Elicitation.
 *
 * @group markup
 */
class Test_Markup_Elicitation extends WP_UnitTestCase {

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_request_round_trip() {
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'      => 'image_inpainting',
				'target'         => array(
					'attachment_id' => 123,
					'width'         => 512,
					'height'        => 512,
				),
				'target_type'    => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'           => WP_MCP_AI_Markup_Request::MODE_MASK,
				'instructions'   => 'Paint over the logo.',
				'tool_arguments' => array( 'prompt' => 'Replace logo' ),
				'assistant_id'   => 17,
				'user_id'        => 5,
			)
		);

		$arr   = $request->to_array();
		$round = WP_MCP_AI_Markup_Request::from_array( $arr );

		$this->assertNotInstanceOf( 'WP_Error', $round );
		$this->assertSame( $request->get_request_id(), $round->get_request_id() );
		$this->assertSame( 'image_inpainting', $round->get_tool_slug() );
		$this->assertSame( 'mask', $round->get_mode() );
		$this->assertSame( 512, $round->get_target()['width'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_invalid_mode_throws() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array( 'attachment_id' => 1 ),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => 'definitely-not-a-mode',
			)
		);
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_widget_payload_shape() {
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array( 'attachment_id' => 9 ),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => WP_MCP_AI_Markup_Request::MODE_MASK,
			)
		);
		$payload = WP_MCP_AI_Markup_Elicitation::to_widget_payload( $request );

		$this->assertSame( 'markup_elicitation', $payload['type'] );
		$this->assertSame( $request->get_request_id(), $payload['request_id'] );
		$this->assertArrayHasKey( 'submit_url', $payload );
		$this->assertArrayHasKey( 'fallback_url', $payload );
		$this->assertStringContainsString( '/markup/' . $request->get_request_id() . '/submit', $payload['submit_url'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_mcp_envelope_includes_url_mode() {
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'    => 'image_inpainting',
				'target'       => array( 'attachment_id' => 9 ),
				'target_type'  => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'         => WP_MCP_AI_Markup_Request::MODE_MASK,
				'instructions' => 'Paint over the area.',
			)
		);
		$mcp     = WP_MCP_AI_Markup_Elicitation::to_mcp_elicitation( $request );

		$this->assertSame( 'elicitation/create', $mcp['method'] );
		$this->assertSame( 'Paint over the area.', $mcp['params']['message'] );
		$this->assertArrayHasKey( 'urlMode', $mcp['params'] );
		$this->assertArrayHasKey( 'markup', $mcp['params']['requestedSchema']['properties'] );
		$this->assertContains( 'markup', $mcp['params']['requestedSchema']['required'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_motivation_mapping() {
		$this->assertSame( 'editing', WP_MCP_AI_Markup_Elicitation::motivation_for_mode( WP_MCP_AI_Markup_Request::MODE_MASK ) );
		$this->assertSame( 'moderating', WP_MCP_AI_Markup_Elicitation::motivation_for_mode( WP_MCP_AI_Markup_Request::MODE_REDACT ) );
		$this->assertSame( 'highlighting', WP_MCP_AI_Markup_Elicitation::motivation_for_mode( WP_MCP_AI_Markup_Request::MODE_TEXT_RANGE ) );
	}
}
