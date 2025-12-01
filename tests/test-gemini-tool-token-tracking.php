<?php
/**
 * Test that Gemini tools are properly tracked in the token usage manager.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

/**
 * Class Test_Gemini_Tool_Token_Tracking
 */
class Test_Gemini_Tool_Token_Tracking extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize enhanced token tracking.
		WP_MCP_AI_Enhanced_Token_Tracking::init();

		// Initialize the database table.
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data.
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Test that generate_gemini_image tool properly tracks token usage.
	 */
	public function test_generate_gemini_image_tracks_usage() {
		// Set up Gemini API key.
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'gsk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create a test user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		// Mock Gemini API response with usage metadata.
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$http_stub = function ( $preempt, $args, $url ) use ( $png_base64 ) {
			$payload = array(
				'candidates'    => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'text' => 'Generated image successfully.',
								),
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
					),
				),
				// Include usage metadata - this is what we're testing.
				'usageMetadata' => array(
					'promptTokenCount'     => 150,
					'candidatesTokenCount' => 50,
					'totalTokenCount'      => 200,
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Execute the tool.
		$result = $tool->execute(
			array(
				'prompt'    => 'A friendly otter in a teacup',
				'mime_type' => 'image/png',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Verify the tool executed successfully.
		$this->assertIsArray( $result, 'Tool execution should return an array' );
		$this->assertArrayNotHasKey( 'code', $result, 'Result should not be a WP_Error' );

		// Verify the result contains usage metadata.
		$this->assertArrayHasKey( 'usage', $result, 'Result should contain usage data' );
		$this->assertEquals( 150, $result['usage']['prompt_tokens'], 'Prompt tokens should match' );
		$this->assertEquals( 50, $result['usage']['completion_tokens'], 'Completion tokens should match' );
		$this->assertEquals( 200, $result['usage']['total_tokens'], 'Total tokens should match' );

		// Verify the result contains provider information.
		$this->assertArrayHasKey( 'provider', $result, 'Result should contain provider' );
		$this->assertEquals( 'gemini', $result['provider'], 'Provider should be gemini' );

		// Verify the result contains model information.
		$this->assertArrayHasKey( 'model', $result, 'Result should contain model' );

		// Simulate the tool execution hook being fired.
		// In real execution, this is done by the REST controller.
		do_action(
			'wp_mcp_ai_after_tool_execution',
			'generate_gemini_image',
			array( 'prompt' => 'A friendly otter in a teacup' ),
			array( 'user_id' => $user_id ),
			$result
		);

		// Verify the usage was tracked in the database.
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 usage record in the database' );
		$this->assertEquals( 'generate_gemini_image', $records[0]['tool'], 'Tool name should match' );
		$this->assertEquals( 'gemini', $records[0]['provider'], 'Provider should be gemini' );
		$this->assertEquals( 150, $records[0]['input_tokens'], 'Input tokens should be tracked' );
		$this->assertEquals( 50, $records[0]['output_tokens'], 'Output tokens should be tracked' );
		$this->assertEquals( 0, $records[0]['is_estimated'], 'Should NOT be estimated (actual data from tool)' );

		// Clean up attachment.
		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that edit_gemini_image tool properly tracks token usage.
	 */
	public function test_edit_gemini_image_tracks_usage() {
		// Set up Gemini API key.
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'gsk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create a test user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Create a test attachment to edit.
		$attachment_id = $this->factory->attachment->create_upload_object(
			WP_MCP_AI_PATH . 'tests/fixtures/sample-image.png'
		);

		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Mock Gemini API response with usage metadata.
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$http_stub = function ( $preempt, $args, $url ) use ( $png_base64 ) {
			$payload = array(
				'candidates'    => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'text' => 'Edited image successfully.',
								),
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
					),
				),
				// Include usage metadata - this is what we're testing.
				'usageMetadata' => array(
					'promptTokenCount'     => 200,
					'candidatesTokenCount' => 75,
					'totalTokenCount'      => 275,
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Execute the tool.
		$result = $tool->execute(
			array(
				'prompt'        => 'Remove background',
				'attachment_id' => $attachment_id,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Verify the tool executed successfully.
		$this->assertIsArray( $result, 'Tool execution should return an array' );
		$this->assertArrayNotHasKey( 'code', $result, 'Result should not be a WP_Error' );

		// Verify the result contains usage metadata.
		$this->assertArrayHasKey( 'usage', $result, 'Result should contain usage data' );
		$this->assertEquals( 200, $result['usage']['prompt_tokens'], 'Prompt tokens should match' );
		$this->assertEquals( 75, $result['usage']['completion_tokens'], 'Completion tokens should match' );
		$this->assertEquals( 275, $result['usage']['total_tokens'], 'Total tokens should match' );

		// Verify the result contains provider information.
		$this->assertArrayHasKey( 'provider', $result, 'Result should contain provider' );
		$this->assertEquals( 'gemini', $result['provider'], 'Provider should be gemini' );

		// Simulate the tool execution hook being fired.
		do_action(
			'wp_mcp_ai_after_tool_execution',
			'edit_gemini_image',
			array( 'prompt' => 'Remove background' ),
			array( 'user_id' => $user_id ),
			$result
		);

		// Verify the usage was tracked in the database.
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 usage record in the database' );
		$this->assertEquals( 'edit_gemini_image', $records[0]['tool'], 'Tool name should match' );
		$this->assertEquals( 'gemini', $records[0]['provider'], 'Provider should be gemini' );
		$this->assertEquals( 200, $records[0]['input_tokens'], 'Input tokens should be tracked' );
		$this->assertEquals( 75, $records[0]['output_tokens'], 'Output tokens should be tracked' );
		$this->assertEquals( 0, $records[0]['is_estimated'], 'Should NOT be estimated (actual data from tool)' );

		// Clean up attachments.
		wp_delete_attachment( $attachment_id, true );
		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that Gemini usage appears in cost tracking service data.
	 */
	public function test_gemini_usage_appears_in_cost_breakdown() {
		// Set up Gemini API key.
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_api_key'] = 'gsk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create a test user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Simulate Gemini tool usage being recorded.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'generate_gemini_image',
			'gemini',
			'gemini-2.5-flash-image',
			150, // input_tokens.
			50,  // output_tokens.
			0.01, // cost_usd.
			false // is_estimated.
		);

		// Get cost breakdown from the service.
		$start_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d' );
		$breakdown  = WP_MCP_AI_Cost_Tracking_Service::get_site_cost_breakdown( $start_date, $end_date );

		// Verify Gemini appears in the breakdown.
		$this->assertArrayHasKey( 'by_provider', $breakdown, 'Breakdown should have by_provider data' );
		$this->assertArrayHasKey( 'gemini', $breakdown['by_provider'], 'Gemini should appear in provider breakdown' );

		$this->assertArrayHasKey( 'by_tool', $breakdown, 'Breakdown should have by_tool data' );
		$this->assertArrayHasKey( 'generate_gemini_image', $breakdown['by_tool'], 'Gemini image tool should appear in tool breakdown' );

		$this->assertArrayHasKey( 'by_model', $breakdown, 'Breakdown should have by_model data' );
		$model_key = 'gemini|gemini-2.5-flash-image';
		$this->assertArrayHasKey( $model_key, $breakdown['by_model'], 'Gemini model should appear in model breakdown' );
	}
}
