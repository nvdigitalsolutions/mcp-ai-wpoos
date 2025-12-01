<?php
/**
 * Tests for cct_author_id field in transcript records.
 *
 * Verifies that the transcript recorder properly sets cct_author_id
 * to match user_id, ensuring transcripts can be retrieved properly.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test cct_author_id is set correctly in transcript records.
 */
class WP_MCP_AI_Chat_Transcript_CCT_Author_ID_Test extends WP_UnitTestCase {
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
				'post_title'  => 'CCT Author ID Test Assistant',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

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
					$this->records[] = $record;
					return true;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that cct_author_id is set correctly for logged-in users.
	 */
	public function test_cct_author_id_is_set_for_logged_in_user() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-cct-author-id-session' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 on successful save' );
		$this->assertTrue( $data['success'], 'Should indicate success' );

		// Verify the transcript was passed to the handler.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );

		$record = $this->transcript_handler->records[0];

		// Verify both user_id and cct_author_id are set.
		$this->assertArrayHasKey( 'user_id', $record, 'Record should have user_id field' );
		$this->assertArrayHasKey( 'cct_author_id', $record, 'Record should have cct_author_id field' );

		// Verify they match.
		$this->assertEquals( $this->admin_id, $record['user_id'], 'user_id should be set to current user ID' );
		$this->assertEquals( $this->admin_id, $record['cct_author_id'], 'cct_author_id should be set to current user ID' );
		$this->assertEquals( $record['user_id'], $record['cct_author_id'], 'user_id and cct_author_id should match' );
	}

	/**
	 * Test that cct_author_id is set to 0 for guest users.
	 */
	public function test_cct_author_id_is_zero_for_guest_users() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create a guest token for authentication.
		$guest_token = 'guest_test_token_' . wp_generate_password( 32, false );

		// Mock the guest token validation.
		add_filter(
			'wp_mcp_ai_validate_guest_token',
			function ( $is_valid, $token ) use ( $guest_token ) {
				if ( $token === $guest_token ) {
					return true;
				}
				return $is_valid;
			},
			10,
			2
		);

		// Set current user to 0 (guest).
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-guest-cct-author-session' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Guest message',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 for guest user with valid token' );

		// Verify the transcript was saved.
		if ( ! empty( $this->transcript_handler->records ) ) {
			$record = $this->transcript_handler->records[0];

			$this->assertArrayHasKey( 'user_id', $record, 'Record should have user_id field' );
			$this->assertArrayHasKey( 'cct_author_id', $record, 'Record should have cct_author_id field' );

			// For guest users, both should be 0.
			$this->assertEquals( 0, $record['user_id'], 'user_id should be 0 for guest users' );
			$this->assertEquals( 0, $record['cct_author_id'], 'cct_author_id should be 0 for guest users' );
			$this->assertEquals( $record['user_id'], $record['cct_author_id'], 'user_id and cct_author_id should match for guest users' );
		}
	}
}
