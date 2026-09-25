<?php
/**
 * Test suite for the Higgsfield request lifecycle tools
 * (check_higgsfield_request / cancel_higgsfield_request).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the Higgsfield request tools.
 */
class Test_Higgsfield_Request_Tools extends WP_UnitTestCase {

	/**
	 * Configure test credentials.
	 */
	public function set_up() {
		parent::set_up();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'higgsfield_api_key_id'     => 'key-1',
				'higgsfield_api_key_secret' => 'secret-1',
			)
		);

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Clean up HTTP mocks.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Test check tool instantiation and slug.
	 */
	public function test_check_tool_instantiation() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Check_Higgsfield_Request();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Check_Higgsfield_Request', $tool );
		$this->assertSame( 'check_higgsfield_request', $tool->get_slug() );
	}

	/**
	 * Test cancel tool instantiation and slug.
	 */
	public function test_cancel_tool_instantiation() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cancel-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Cancel_Higgsfield_Request();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Cancel_Higgsfield_Request', $tool );
		$this->assertSame( 'cancel_higgsfield_request', $tool->get_slug() );
	}

	/**
	 * Test check tool requires a request ID.
	 */
	public function test_check_requires_request_id() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Check_Higgsfield_Request();

		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_request_id', $result->get_error_code() );
	}

	/**
	 * Test cancel tool requires a request ID.
	 */
	public function test_cancel_requires_request_id() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cancel-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Cancel_Higgsfield_Request();

		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_request_id', $result->get_error_code() );
	}

	/**
	 * Test check tool reports completed state with outputs.
	 */
	public function test_check_completed_request() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Check_Higgsfield_Request();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'status'     => 'completed',
							'request_id' => 'req-1',
							'video'      => array( 'url' => 'https://cdn.example.com/video.mp4' ),
						)
					),
				);
			},
			10,
			3
		);

		$result = $tool->execute( array( 'request_id' => 'req-1' ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['data']['status'] );
		$this->assertTrue( $result['data']['terminal'] );
		$this->assertSame( 'https://cdn.example.com/video.mp4', $result['data']['video_url'] );
	}

	/**
	 * Test check tool reports an active state without outputs.
	 */
	public function test_check_in_progress_request() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Check_Higgsfield_Request();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'status' => 'in_progress', 'request_id' => 'req-1' ) ),
				);
			},
			10,
			3
		);

		$result = $tool->execute( array( 'request_id' => 'req-1' ), array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'in_progress', $result['data']['status'] );
		$this->assertFalse( $result['data']['terminal'] );
		$this->assertArrayNotHasKey( 'video_url', $result['data'] );
	}

	/**
	 * Test cancel tool succeeds on a 202.
	 */
	public function test_cancel_success() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cancel-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Cancel_Higgsfield_Request();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 202 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		$result = $tool->execute( array( 'request_id' => 'req-1' ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'canceled', $result['data']['status'] );
	}

	/**
	 * Test cancel tool surfaces the provider's 400 (already started).
	 */
	public function test_cancel_already_started() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cancel-higgsfield-request.php';
		$tool = new WP_MCP_AI_Tool_Cancel_Higgsfield_Request();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 400 ),
					'body'     => wp_json_encode( array( 'detail' => 'The request has already started processing and can no longer be canceled.' ) ),
				);
			},
			10,
			3
		);

		$result = $tool->execute( array( 'request_id' => 'req-1' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_higgsfield_cancel_started', $result->get_error_code() );
	}

	/**
	 * Test the check tool schema requires request_id.
	 */
	public function test_check_schema() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-higgsfield-request.php';
		$tool   = new WP_MCP_AI_Tool_Check_Higgsfield_Request();
		$schema = $tool->get_parameters_schema();

		$this->assertContains( 'request_id', $schema['required'] );
		$this->assertArrayHasKey( 'request_id', $schema['properties'] );
	}

	/**
	 * Test the cancel tool capability flags mark it destructive.
	 */
	public function test_cancel_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cancel-higgsfield-request.php';
		$tool  = new WP_MCP_AI_Tool_Cancel_Higgsfield_Request();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'destructive', $flags );
		$this->assertContains( 'requires-credentials', $flags );
	}
}
