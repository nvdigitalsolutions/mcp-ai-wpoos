<?php
/**
 * Tests for Veo video generation chat client response formatting.
 *
 * Ensures async and sync results include proper messages and metadata
 * for display in chat interfaces without bloating LLM context.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';

/**
 * Test class for Veo video generation chat client responses.
 */
class WP_MCP_AI_Veo_Chat_Response_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that sync response includes message for chat client.
	 */
	public function test_sync_response_includes_message() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Mock HTTP requests for sync video generation.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-op',
								'done' => false,
							)
						),
					);
				}

				if ( strpos( $url, 'operations/test-op' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name'     => 'operations/test-op',
								'done'     => true,
								'response' => array(
									'generateVideoResponse' => array(
										'generatedSamples' => array(
											array(
												'video' => array(
													'uri' => 'https://example.com/video.mp4',
												),
											),
										),
									),
								),
							)
						),
					);
				}

				// Mock video download.
				if ( strpos( $url, 'example.com/video.mp4' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => 'fake-video-data',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Create a test user with upload permissions.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Execute tool.
		$result = $tool->execute(
			array(
				'prompt'        => 'A cat playing piano',
				'duration'      => 5,
				'save_to_media' => true,
			),
			array( 'user_id' => $user_id )
		);

		// Verify response structure for chat client.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( $result['success'], 'Result should indicate success' );
		$this->assertArrayHasKey( 'message', $result, 'Result should have message for chat client' );
		$this->assertArrayHasKey( 'url', $result, 'Result should have video URL' );
		$this->assertArrayHasKey( 'attachment_id', $result, 'Result should have attachment ID' );
		$this->assertNotEmpty( $result['message'], 'Message should not be empty' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that async response includes message for chat client.
	 */
	public function test_async_response_includes_message() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Mock HTTP request for async video generation.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'predictLongRunning' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name' => 'operations/test-async-op',
								'done' => false,
							)
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Create a test user with upload permissions.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Execute tool with async mode.
		$result = $tool->execute(
			array(
				'prompt'   => 'A cat playing piano',
				'duration' => 5,
			),
			array(
				'user_id'           => $user_id,
				'in_async_executor' => false, // Allow tool-level async.
			)
		);

		// Verify async response structure for chat client.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( $result['async'], 'Result should indicate async mode' );
		$this->assertArrayHasKey( 'message', $result, 'Async result should have message for chat client' );
		$this->assertArrayHasKey( 'job_id', $result, 'Async result should have job ID' );
		$this->assertArrayHasKey( 'status', $result, 'Async result should have status' );
		$this->assertNotEmpty( $result['message'], 'Async message should not be empty' );
		$this->assertEquals( 'pending', $result['status'], 'Initial status should be pending' );

		// Clean up.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that sanitize_for_llm preserves message but strips base64 data.
	 */
	public function test_sanitize_for_llm_preserves_message() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// Test with media library result.
		$media_result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'prompt'        => 'Test prompt',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-2.0-generate-001',
			'provider'      => 'gemini',
			'message'       => 'Video generated successfully',
		);

		$sanitized = $tool->sanitize_for_llm( $media_result );

		// Verify message is preserved.
		$this->assertArrayHasKey( 'message', $sanitized, 'Message should be preserved for LLM' );
		$this->assertEquals( 'Video generated successfully', $sanitized['message'], 'Message content should be unchanged' );
		$this->assertArrayHasKey( 'url', $sanitized, 'URL should be preserved for LLM' );
		$this->assertArrayHasKey( 'attachment_id', $sanitized, 'Attachment ID should be preserved for LLM' );

		// Test with base64 data URL result.
		$data_url_result = array(
			'success'      => true,
			'video_url'    => 'data:video/mp4;base64,AAABBBCCC...', // Large base64 string.
			'prompt'       => 'Test prompt',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-2.0-generate-001',
			'provider'     => 'gemini',
			'message'      => 'Video generated successfully (temporary)',
		);

		$sanitized_data = $tool->sanitize_for_llm( $data_url_result );

		// Verify message is preserved but base64 is stripped.
		$this->assertArrayHasKey( 'message', $sanitized_data, 'Message should be preserved even when base64 is stripped' );
		$this->assertEquals( 'Video generated successfully (temporary)', $sanitized_data['message'], 'Message should be unchanged' );
		$this->assertArrayNotHasKey( 'video_url', $sanitized_data, 'Base64 video URL should be stripped from LLM context' );
		$this->assertArrayHasKey( 'video_data_stripped', $sanitized_data, 'Should indicate data was stripped' );
	}

	/**
	 * Test that async completion includes message in result.
	 */
	public function test_async_completion_includes_message_in_result() {
		// Set up API key.
		$settings = array(
			'gemini_api_key' => 'test-api-key-12345',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create fake async metadata.
		$job_id   = 'veo_test_' . uniqid();
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'model'          => 'veo-2.0-generate-001',
			'args'           => array(
				'prompt'        => 'Test video',
				'duration'      => 5,
				'save_to_media' => true,
				'user_id'       => 1,
			),
			'status'         => 'polling',
			'queued_at'      => time(),
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Mock HTTP requests for completion.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'operations/test-op' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'name'     => 'operations/test-op',
								'done'     => true,
								'response' => array(
									'generateVideoResponse' => array(
										'generatedSamples' => array(
											array(
												'video' => array(
													'uri' => 'https://example.com/completed-video.mp4',
												),
											),
										),
									),
								),
							)
						),
					);
				}

				// Mock video download.
				if ( strpos( $url, 'completed-video.mp4' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => 'fake-completed-video-data',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		// Trigger async polling (simulates cron callback).
		$service->poll_video_async( $job_id );

		// Retrieve completed metadata.
		$completed_metadata = get_transient( 'wp_mcp_ai_veo_async_' . $job_id );

		// Verify completed result includes message.
		$this->assertIsArray( $completed_metadata, 'Metadata should exist' );
		$this->assertEquals( 'completed', $completed_metadata['status'], 'Status should be completed' );
		$this->assertArrayHasKey( 'result', $completed_metadata, 'Should have result data' );
		$this->assertArrayHasKey( 'message', $completed_metadata['result'], 'Result should have message for chat client' );
		$this->assertArrayHasKey( 'url', $completed_metadata['result'], 'Result should have video URL' );
		$this->assertArrayHasKey( 'success', $completed_metadata['result'], 'Result should have success flag' );
		$this->assertNotEmpty( $completed_metadata['result']['message'], 'Message should not be empty' );

		// Verify message format includes metadata.
		$this->assertStringContainsString( '5s', $completed_metadata['result']['message'], 'Message should include duration' );
		$this->assertStringContainsString( '720p', $completed_metadata['result']['message'], 'Message should include resolution' );
		$this->assertStringContainsString( '16:9', $completed_metadata['result']['message'], 'Message should include aspect ratio' );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
		remove_all_filters( 'pre_http_request' );
	}
}
