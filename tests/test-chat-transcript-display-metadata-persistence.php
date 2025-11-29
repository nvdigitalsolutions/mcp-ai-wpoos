<?php
/**
 * Test for display metadata persistence in chat transcripts.
 *
 * This test verifies that display metadata (including video attachments, bubble type,
 * usage/cost badges) is properly saved and restored from chat transcripts.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for display metadata persistence in chat transcripts.
 */
class Test_Chat_Transcript_Display_Metadata_Persistence extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Mock transcript handler that captures stored records.
	 *
	 * @var object
	 */
	protected $transcript_handler;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Display Metadata',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Provide a mock handler that captures transcript records without requiring JetEngine.
	 *
	 * @return object Mock handler instance.
	 */
	public function provide_transcript_handler() {
		if ( ! $this->transcript_handler ) {
			$this->transcript_handler = new class() {
				public $records = array();

				public function update_item( $record ) {
					// Store records indexed by session_key and cct_author_id for retrieval.
					$session_key = isset( $record['session_key'] ) ? $record['session_key'] : '';
					$user_id     = isset( $record['cct_author_id'] ) ? $record['cct_author_id'] : 0;

					if ( '' === $session_key || 0 === $user_id ) {
						return new WP_Error( 'invalid_record', 'Invalid session_key or user_id' );
					}

					// Create a unique key for storage.
					$key = $session_key . '_' . $user_id;

					// Store the record.
					$this->records[ $key ] = $record;

					return true;
				}

				/**
				 * Get records for a session_key and user_id.
				 *
				 * @param string $session_key Session key.
				 * @param int    $user_id     User ID.
				 * @return array Array of records (may be empty).
				 */
				public function get_records( $session_key, $user_id ) {
					$key = $session_key . '_' . $user_id;
					if ( isset( $this->records[ $key ] ) ) {
						return array( $this->records[ $key ] );
					}
					return array();
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that display metadata is preserved when saving and retrieving transcripts.
	 *
	 * This test verifies that the display field (with video attachments) is properly
	 * saved to the transcript and can be retrieved for UI restoration.
	 */
	public function test_display_metadata_preserved_in_transcript() {
		// Install the mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Prepare test data with display metadata (simulating async video generation result).
		$session_key = 'test-video-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Generate a video of a sunset.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I will generate a video of a sunset for you.',
				'display' => array(
					'bubbleType' => 'assistant',
					'text'       => 'I will generate a video of a sunset for you.',
				),
			),
			array(
				'role'         => 'tool',
				'content'      => '{"success":true,"url":"https://example.com/wp-content/uploads/2024/01/veo-video-async_123.mp4","file_name":"veo-video-async_123.mp4","attachment_id":123}',
				'name'         => 'generate_veo_video',
				'tool_call_id' => 'call_abc123',
				'display'      => array(
					'bubbleType'  => 'tool',
					'text'        => '✓ Video generated successfully and saved to the Media Library.',
					'attachments' => array(
						array(
							'url'          => 'https://example.com/wp-content/uploads/2024/01/veo-video-async_123.mp4',
							'label'        => 'veo-video-async_123.mp4',
							'downloadName' => 'veo-video-async_123.mp4',
							'meta'         => '5s • 16:9 • 720p • Veo 3.1',
						),
					),
				),
			),
			array(
				'role'    => 'assistant',
				'content' => 'Your sunset video has been generated and saved.',
				'display' => array(
					'bubbleType' => 'assistant',
					'text'       => 'Your sunset video has been generated and saved.',
					'usage'      => array(
						'prompt_tokens'     => 100,
						'completion_tokens' => 50,
						'total_tokens'      => 150,
					),
				),
			),
		);

		// Step 1: Save the conversation via POST /chat-transcripts.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		// Verify save succeeded.
		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );
		$save_data = $save_response->get_data();
		$this->assertTrue( $save_data['success'], 'Save response should indicate success' );

		// Verify record was stored in mock handler.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records, 'Mock handler should have stored the record' );

		// Verify request_payload contains messages with display metadata.
		$stored_record   = $stored_records[0];
		$request_payload = json_decode( $stored_record['request_payload'], true );
		$this->assertIsArray( $request_payload, 'request_payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_payload, 'request_payload should have messages' );

		// Check that display metadata is preserved in the tool message.
		$tool_message = null;
		foreach ( $request_payload['messages'] as $msg ) {
			if ( 'tool' === $msg['role'] && 'generate_veo_video' === ( $msg['name'] ?? '' ) ) {
				$tool_message = $msg;
				break;
			}
		}

		$this->assertNotNull( $tool_message, 'Tool message should be present' );
		$this->assertArrayHasKey( 'display', $tool_message, 'Tool message should have display metadata' );
		$this->assertArrayHasKey( 'attachments', $tool_message['display'], 'Display should have attachments' );
		$this->assertCount( 1, $tool_message['display']['attachments'], 'Should have one video attachment' );

		// Verify the video attachment structure.
		$attachment = $tool_message['display']['attachments'][0];
		$this->assertEquals( 'https://example.com/wp-content/uploads/2024/01/veo-video-async_123.mp4', $attachment['url'] );
		$this->assertEquals( 'veo-video-async_123.mp4', $attachment['label'] );
		$this->assertStringContainsString( 'Veo 3.1', $attachment['meta'] );

		// Step 2: Retrieve the conversation and verify display metadata is included.
		$retrieve_request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$retrieve_request->set_param( 'session_key', $session_key );
		$retrieve_request->set_param( 'user_id', $this->admin_id );
		$retrieve_request->set_param( 'assistant_id', $this->assistant_id );

		$retrieve_response = rest_get_server()->dispatch( $retrieve_request );

		$this->assertEquals( 200, $retrieve_response->get_status(), 'Retrieve request should succeed' );
		$retrieve_data = $retrieve_response->get_data();

		$this->assertArrayHasKey( 'session', $retrieve_data, 'Response should have session data' );
		$this->assertArrayHasKey( 'messages', $retrieve_data['session'], 'Session should have messages' );

		// Find the tool message in retrieved messages and verify display metadata.
		$retrieved_tool_message = null;
		foreach ( $retrieve_data['session']['messages'] as $msg ) {
			if ( 'tool' === $msg['role'] ) {
				$retrieved_tool_message = $msg;
				break;
			}
		}

		$this->assertNotNull( $retrieved_tool_message, 'Retrieved tool message should be present' );
		$this->assertArrayHasKey( 'display', $retrieved_tool_message, 'Retrieved tool message should have display metadata' );
		$this->assertArrayHasKey( 'attachments', $retrieved_tool_message['display'], 'Retrieved display should have attachments' );
	}

	/**
	 * Test that video attachment with .mp4 URL is properly identified.
	 */
	public function test_video_attachment_url_preserved() {
		// Install the mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-video-url-' . wp_generate_uuid4();
		$video_url   = 'https://example.com/wp-content/uploads/2024/01/veo-video-async_123.mp4';

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Show me the video.',
			),
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode( array( 'url' => $video_url ) ),
				'name'         => 'generate_veo_video',
				'tool_call_id' => 'call_xyz789',
				'display'      => array(
					'text'        => 'Video ready',
					'attachments' => array(
						array(
							'url'          => $video_url,
							'label'        => 'veo-video-async_123.mp4',
							'downloadName' => 'veo-video-async_123.mp4',
							'meta'         => '5s • 16:9',
						),
					),
				),
			),
		);

		// Save.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );
		$this->assertEquals( 200, $save_response->get_status() );

		// Retrieve and verify.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records );

		$stored_record   = $stored_records[0];
		$request_payload = json_decode( $stored_record['request_payload'], true );

		// Find tool message.
		$tool_message = null;
		foreach ( $request_payload['messages'] as $msg ) {
			if ( 'tool' === $msg['role'] ) {
				$tool_message = $msg;
				break;
			}
		}

		$this->assertNotNull( $tool_message );
		$this->assertEquals( $video_url, $tool_message['display']['attachments'][0]['url'] );
	}
}
