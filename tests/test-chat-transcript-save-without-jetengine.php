<?php
/**
 * Test that save endpoint returns proper error when JetEngine is not available.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for chat transcript save validation.
 */
class Test_Chat_Transcript_Save_Without_JetEngine extends WP_UnitTestCase {
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
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// WooCommerce Blocks hooks non-idempotent init callbacks (payment method
		// integrations and block types) — re-firing init in the harness
		// re-registers them and raises _doing_it_wrong notices from Woo's own
		// code. WP 6.9 also re-registers the breadcrumbs block during
		// rest_api_init, which the block-registry whitelist covers.
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Package' ) ) {
			$this->setExpectedIncorrectUsage( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry::register' );
			$this->setExpectedIncorrectUsage( 'WP_Block_Type_Registry::register' );
		} elseif ( version_compare( $GLOBALS['wp_version'], '7.1', '<' ) ) {
			$this->setExpectedIncorrectUsage( 'WP_Block_Type_Registry::register' );
		}

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Save Validation',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		WP_MCP_AI_REST::get_instance();
		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Return null handler to simulate JetEngine not being available.
	 *
	 * @return null
	 */
	public function return_null_handler() {
		return null;
	}

	/**
	 * Test that save endpoint returns success with warning when recorder fails.
	 *
	 * This simulates the case where JetEngine is not available or not properly configured.
	 * The endpoint should return success (transcripts stored in browser) with a warning.
	 */
	public function test_save_returns_success_with_warning_when_recorder_fails() {
		// Install a filter that returns null handler (simulates JetEngine not available).
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		// Prepare test data.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, this is a test message.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi! How can I help you today?',
			),
		);

		// Attempt to save the conversation.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
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

		// Verify that save returns success (200) with warning.
		$this->assertEquals( 200, $save_response->get_status(), 'Save should succeed with 200 status' );

		$save_data = $save_response->get_data();
		$this->assertArrayHasKey( 'success', $save_data, 'Response should have success flag' );
		$this->assertTrue( $save_data['success'], 'Success flag should be true' );
		$this->assertArrayHasKey( 'saved_to_database', $save_data, 'Response should indicate database save status' );
		$this->assertFalse( $save_data['saved_to_database'], 'Should not be saved to database' );
		$this->assertArrayHasKey( 'saved_to_browser', $save_data, 'Response should indicate browser save status' );
		$this->assertTrue( $save_data['saved_to_browser'], 'Should be saved to browser' );
		$this->assertArrayHasKey( 'warning', $save_data, 'Response should have warning message' );
		$this->assertStringContainsString( 'JetEngine', $save_data['warning'], 'Warning should mention JetEngine' );
		$this->assertArrayHasKey( 'persistence_details', $save_data, 'Response should have diagnostic details' );
	}

	/**
	 * Test that the warning message is helpful for diagnosing the issue.
	 */
	public function test_warning_message_is_helpful() {
		// Install a filter that returns null handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		// Prepare test data.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		// Attempt to save.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
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
		$save_data     = $save_response->get_data();

		// Verify warning message provides actionable information. The exact
		// wording depends on which persistence prerequisite is missing in this
		// environment (JetEngine absent vs. a JetEngine module disabled), so
		// assert the branch that matches the current environment.
		$warning = $save_data['warning'];
		$this->assertNotEmpty( $warning, 'Warning should be provided' );
		if ( function_exists( 'jet_engine' ) ) {
			$this->assertStringContainsString( 'JetEngine', $warning, 'Warning should mention JetEngine' );
			$this->assertStringContainsString( 'module', $warning, 'Warning should point at the missing JetEngine module' );
		} else {
			$this->assertStringContainsString( 'Permanent transcript storage', $warning, 'Warning should indicate permanent storage issue' );
			$this->assertStringContainsString( 'JetEngine', $warning, 'Warning should mention JetEngine' );
		}

		// Verify main message indicates browser-only storage.
		$message = $save_data['message'];
		$this->assertStringContainsString( 'browser only', $message, 'Message should indicate browser-only storage' );
		$this->assertStringContainsString( 'Permanent storage unavailable', $message, 'Message should indicate unavailable persistence' );
	}

	/**
	 * Test that success with database vs browser-only responses have different structures.
	 */
	public function test_database_vs_browser_only_response_structure() {
		// First, test browser-only case (no handler).
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);

		$browser_only_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$browser_only_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$browser_only_request->set_header( 'Content-Type', 'application/json' );
		$browser_only_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$browser_only_response = rest_get_server()->dispatch( $browser_only_request );
		$browser_only_data     = $browser_only_response->get_data();

		// Verify browser-only structure.
		$this->assertArrayHasKey( 'success', $browser_only_data, 'Browser-only should have success flag' );
		$this->assertTrue( $browser_only_data['success'], 'Success flag should be true' );
		$this->assertArrayHasKey( 'saved_to_database', $browser_only_data, 'Should indicate database status' );
		$this->assertFalse( $browser_only_data['saved_to_database'], 'Should not be saved to database' );
		$this->assertArrayHasKey( 'saved_to_browser', $browser_only_data, 'Should indicate browser status' );
		$this->assertTrue( $browser_only_data['saved_to_browser'], 'Should be saved to browser' );
		$this->assertArrayHasKey( 'warning', $browser_only_data, 'Should have warning message' );
		$this->assertArrayHasKey( 'persistence_details', $browser_only_data, 'Should have diagnostic details' );

		// Now test database success case with a mock handler.
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		add_filter(
			'wp_mcp_ai_chat_transcript_handler',
			function () {
				return new class() {
					/**
					 * Update a transcript item.
					 *
					 * @param array $record Transcript record.
					 * @return bool Always true for the mock handler.
					 */
					public function update_item( $record ) {
						return true;
					}
				};
			},
			10
		);

		$database_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$database_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$database_request->set_header( 'Content-Type', 'application/json' );
		$database_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$database_response = rest_get_server()->dispatch( $database_request );
		$database_data     = $database_response->get_data();

		// Verify database success structure.
		$this->assertArrayHasKey( 'success', $database_data, 'Success should have success flag' );
		$this->assertTrue( $database_data['success'], 'Success flag should be true' );
		$this->assertArrayHasKey( 'session_key', $database_data, 'Success should have session_key' );
		$this->assertArrayHasKey( 'message', $database_data, 'Success should have message' );
		$this->assertArrayNotHasKey( 'warning', $database_data, 'Database success should not have warning' );
		$this->assertArrayNotHasKey( 'saved_to_database', $database_data, 'Standard success doesn\'t include save flags' );
	}
}
