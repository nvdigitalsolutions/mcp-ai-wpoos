<?php
/**
 * Tests for `WP_MCP_AI_REST_Chat_Memory_Controller::maybe_summarize_content()` — G6 Phase 2.
 *
 * The summarisation helper is a graceful enhancement: every failure path
 * (missing API key, content too short, HTTP error, malformed JSON, blank
 * summary) must return null so the calling code can fall through to verbatim
 * storage and never lose the user's transcript.
 *
 * Mocks the OpenAI HTTP call via the `pre_http_request` filter so tests
 * never touch the network.
 *
 * @package WP_MCP_AI
 * @since 1.1.14
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class.
 */
class Test_Chat_Memory_Summarize extends WP_UnitTestCase {

	/**
	 * Captured request bodies, indexed by call order, for assertions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $captured_requests = array();

	/**
	 * Reset captured state and any leftover http filters / settings.
	 */
	public function tearDown(): void {
		$this->captured_requests = array();
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Reflection-friendly invoker for the protected helper.
	 *
	 * @param string $content Content to summarise.
	 * @return mixed Whatever the helper returned.
	 */
	private function invoke( $content ) {
		$controller = new WP_MCP_AI_REST_Chat_Memory_Controller();
		$method     = new ReflectionMethod( $controller, 'maybe_summarize_content' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $content );
	}

	/**
	 * Long sample content guaranteed to exceed `SUMMARIZE_MIN_INPUT_BYTES`.
	 *
	 * @return string
	 */
	private function long_content() {
		return str_repeat( 'The user discussed updating the homepage hero copy. ', 8 );
	}

	/**
	 * Install a `pre_http_request` filter that captures the body and
	 * returns the supplied stub response.
	 *
	 * @param array|WP_Error $stub Response (as returned by wp_remote_post).
	 */
	private function stub_http( $stub ) {
		$captured_ref = &$this->captured_requests;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $stub, &$captured_ref ) {
				$captured_ref[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $stub;
			},
			10,
			3
		);
	}

	/**
	 * No API key configured → no HTTP call, returns null.
	 */
	public function test_returns_null_when_api_key_missing() {
		// Ensure no key is set.
		delete_option( 'wp_mcp_ai_settings' );

		$this->stub_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array( 'message' => array( 'content' => 'Should not be reached.' ) ),
						),
					)
				),
			)
		);

		$result = $this->invoke( $this->long_content() );

		$this->assertNull( $result );
		$this->assertCount( 0, $this->captured_requests, 'No HTTP request should be made when API key is missing.' );
	}

	/**
	 * Content shorter than the minimum byte threshold → no HTTP call, null.
	 */
	public function test_returns_null_for_trivially_short_content() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'short.' ) ) ) ) ),
			)
		);

		$result = $this->invoke( 'too short to summarise' );

		$this->assertNull( $result );
		$this->assertCount( 0, $this->captured_requests );
	}

	/**
	 * Happy path: 200 + well-formed body → summary text + model returned.
	 */
	public function test_returns_summary_on_success() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'model'   => 'gpt-4o-mini-2024',
						'choices' => array(
							array( 'message' => array( 'content' => '  User wants the hero copy refreshed.  ' ) ),
						),
					)
				),
			)
		);

		$result = $this->invoke( $this->long_content() );

		$this->assertIsArray( $result );
		$this->assertSame( 'User wants the hero copy refreshed.', $result['text'] );
		$this->assertSame( 'gpt-4o-mini-2024', $result['model'] );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $this->captured_requests[0]['url'] );
	}

	/**
	 * Non-200 response → null, no exception.
	 */
	public function test_returns_null_on_http_error_status() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"error":"server boom"}',
			)
		);

		$this->assertNull( $this->invoke( $this->long_content() ) );
	}

	/**
	 * 200 with a payload that doesn't carry a choice → null.
	 */
	public function test_returns_null_on_malformed_body() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"unexpected":true}',
			)
		);

		$this->assertNull( $this->invoke( $this->long_content() ) );
	}

	/**
	 * Content above the hard cap is truncated before being sent.
	 */
	public function test_input_is_capped_before_send() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ),
					)
				),
			)
		);

		$huge   = str_repeat( 'A', 32768 );
		$result = $this->invoke( $huge );
		$this->assertIsArray( $result );

		$this->assertCount( 1, $this->captured_requests );
		$body         = json_decode( $this->captured_requests[0]['args']['body'], true );
		$user_message = end( $body['messages'] );
		$this->assertLessThanOrEqual(
			16384,
			strlen( $user_message['content'] ),
			'Input must be hard-capped at SUMMARIZE_MAX_INPUT_BYTES before being sent to OpenAI.'
		);
	}

	/**
	 * WP_Error from wp_remote_post → null (graceful fallback).
	 */
	public function test_returns_null_on_wp_error_response() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection refused' ) );

		$this->assertNull( $this->invoke( $this->long_content() ) );
	}

	/**
	 * 200 + an empty choice content → null (we never store an empty summary).
	 */
	public function test_returns_null_on_blank_summary() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		$this->stub_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array( array( 'message' => array( 'content' => '   ' ) ) ),
					)
				),
			)
		);

		$this->assertNull( $this->invoke( $this->long_content() ) );
	}
}
