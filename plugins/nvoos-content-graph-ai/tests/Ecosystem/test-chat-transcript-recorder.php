<?php
/**
 * Chat Transcript Recorder port tests (Wave D1f).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\ChatTranscriptRecorder`. Assertions pin
 * behaviour against the base plugin's `WP_MCP_AI_Chat_Transcript_Recorder`
 * (ecosystem port plan, principle: behaviour-preserving). Storage is
 * exercised through the `wp_mcp_ai_chat_transcript_handler` filter with a
 * recording handler double.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\ChatTranscriptRecorder;

/**
 * Recording handler double.
 */
class Test_Transcript_Handler_Double {
	public $received = array();
	public $result = 123;

	public function update_item( $record ) {
		$this->received[] = $record;
		return $this->result;
	}
}

/**
 * Subclass exposing protected statics for contract testing.
 */
class Test_Transcript_Recorder_Exposed extends ChatTranscriptRecorder {
	public static function should( $assistant_id, array $messages, array $options, array $response, \WP_REST_Request $request, array $context ): bool {
		return self::should_record( $assistant_id, $messages, $options, $response, $request, $context );
	}

	public static function build( $assistant_id, array $messages, array $options, array $response, \WP_REST_Request $request, $user_id, array $context ): array {
		return self::build_record( $assistant_id, $messages, $options, $response, $request, $user_id, $context );
	}

	public static function normalise( $value ): string {
		return self::normalise_session_key( $value );
	}

	public static function generate( $assistant_id ): string {
		return self::generate_session_key( $assistant_id );
	}

	public static function encode( $data ): string {
		return self::encode_json( $data );
	}

	public static function bool_of( $value ): bool {
		return self::to_bool( $value );
	}

	public static function latency( array $context ) {
		return self::calculate_latency( $context );
	}

	public static function ts( array $context, $key ): int {
		return self::format_timestamp_from_context( $context, $key );
	}

	public static function model( array $options, array $response ): string {
		return self::determine_model( $options, $response );
	}

	public static function meta( array $options, array $response, array $context ): array {
		return self::build_metadata( $options, $response, $context );
	}

	public static function reasons( array $response ): array {
		return self::extract_finish_reasons( $response );
	}
}

/**
 * @group chat
 */
class Test_Chat_Transcript_Recorder extends \WP_UnitTestCase {

	private $handler;

	public function setUp(): void {
		parent::setUp();
		$this->handler = new Test_Transcript_Handler_Double();
	}

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_chat_transcript_handler' );
		remove_all_filters( 'wp_mcp_ai_save_chat_transcript' );
		remove_all_filters( 'wp_mcp_ai_chat_transcript_record' );
		parent::tearDown();
	}

	private function request( array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function response_payload(): array {
		return array(
			'model'    => 'gpt-4o',
			'provider' => 'openai',
			'id'       => 'resp-1',
			'choices'  => array(
				array( 'finish_reason' => 'stop' ),
			),
			'usage'    => array( 'prompt_tokens' => 5 ),
		);
	}

	public function test_record_early_returns(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		// Non-REST request → null.
		$this->assertNull( ChatTranscriptRecorder::record( 1, $messages, array(), $this->response_payload(), 'not-a-request', 1 ) );

		// Empty messages / empty response / invalid assistant id → null.
		$request = $this->request();
		$this->assertNull( ChatTranscriptRecorder::record( 1, array(), array(), $this->response_payload(), $request, 1 ) );
		$this->assertNull( ChatTranscriptRecorder::record( 1, $messages, array(), array(), $request, 1 ) );
		$this->assertNull( ChatTranscriptRecorder::record( 0, $messages, array(), $this->response_payload(), $request, 1 ) );
		$this->assertNull( ChatTranscriptRecorder::record( '', $messages, array(), $this->response_payload(), $request, 1 ) );
	}

	public function test_record_returns_null_without_handler(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		// Standalone: no handler available → graceful no-op.
		$this->assertNull( ChatTranscriptRecorder::record( 5, $messages, array(), $this->response_payload(), $this->request(), 1 ) );
	}

	public function test_record_respects_save_transcript_flag(): void {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', function () {
			return $this->handler;
		} );

		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		$this->assertNull(
			ChatTranscriptRecorder::record( 5, $messages, array(), $this->response_payload(), $this->request( array( 'save_transcript' => false ) ), 1 )
		);
		$this->assertSame( array(), $this->handler->received );

		$session_key = ChatTranscriptRecorder::record( 5, $messages, array(), $this->response_payload(), $this->request(), 1 );
		$this->assertIsString( $session_key );
		$this->assertCount( 1, $this->handler->received );
	}

	public function test_record_uses_handler_and_persists_payload(): void {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', function () {
			return $this->handler;
		} );

		$messages = array( array( 'role' => 'user', 'content' => 'Hello' ) );
		$options  = array( 'provider' => 'openai' );
		$context  = array(
			'session_key'           => 'session-abc',
			'request_started_at'    => 1000.25,
			'response_completed_at' => 1001.75,
		);

		$session_key = ChatTranscriptRecorder::record( 9, $messages, $options, $this->response_payload(), $this->request(), 7, $context );

		$this->assertSame( 'session-abc', $session_key );
		$record = $this->handler->received[0];

		$this->assertSame( 'session-abc', $record['session_key'] );
		$this->assertSame( 7, $record['user_id'] );
		$this->assertSame( 7, $record['cct_author_id'] );
		$this->assertSame( '9', $record['assistant_id'] );
		$this->assertSame( 'gpt-4o', $record['assistant_model'] );
		$this->assertSame( 1500, $record['latency_ms'] );
		$this->assertSame( 1000, $record['request_started_at'] );
		$this->assertSame( 1001, $record['response_completed_at'] );

		$request_payload = json_decode( $record['request_payload'], true );
		$this->assertSame( $messages, $request_payload['messages'] );
		$this->assertSame( $options, $request_payload['options'] );

		$this->assertSame( $this->response_payload(), json_decode( $record['response_payload'], true ) );

		$metadata = json_decode( $record['metadata'], true );
		$this->assertSame( 'openai', $metadata['provider'] );
		$this->assertSame( 'resp-1', $metadata['response_id'] );
		$this->assertSame( array( 'stop' ), $metadata['finish_reasons'] );
	}

	public function test_record_handles_virtual_team_ids(): void {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', function () {
			return $this->handler;
		} );

		$session_key = ChatTranscriptRecorder::record(
			'unified_team_42',
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array(),
			$this->response_payload(),
			$this->request(),
			3
		);

		$this->assertIsString( $session_key );
		$this->assertSame( 'unified_team_42', $this->handler->received[0]['assistant_id'] );
	}

	public function test_record_returns_null_when_handler_fails(): void {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', function () {
			return $this->handler;
		} );
		$this->handler->result = new \WP_Error( 'boom', 'failed' );

		$this->assertNull(
			ChatTranscriptRecorder::record( 5, array( array( 'role' => 'user', 'content' => 'Hi' ) ), array(), $this->response_payload(), $this->request(), 1 )
		);
	}

	public function test_record_returns_null_when_handler_throws(): void {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', function () {
			return new class() {
				public function update_item( $record ) {
					throw new \RuntimeException( 'storage exploded' );
				}
			};
		} );

		$this->assertNull(
			ChatTranscriptRecorder::record( 5, array( array( 'role' => 'user', 'content' => 'Hi' ) ), array(), $this->response_payload(), $this->request(), 1 )
		);
	}

	public function test_should_record_uses_context_then_param_then_filter(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		// Context flag wins over request param.
		$this->assertFalse(
			Test_Transcript_Recorder_Exposed::should( 1, $messages, array(), $this->response_payload(), $this->request( array( 'save_transcript' => true ) ), array( 'save_transcript' => false ) )
		);

		// Filter overrides.
		add_filter( 'wp_mcp_ai_save_chat_transcript', '__return_false' );
		$this->assertFalse(
			Test_Transcript_Recorder_Exposed::should( 1, $messages, array(), $this->response_payload(), $this->request(), array() )
		);
		remove_all_filters( 'wp_mcp_ai_save_chat_transcript' );

		$this->assertTrue(
			Test_Transcript_Recorder_Exposed::should( 1, $messages, array(), $this->response_payload(), $this->request(), array() )
		);
	}

	public function test_to_bool_variants(): void {
		$this->assertTrue( Test_Transcript_Recorder_Exposed::bool_of( true ) );
		$this->assertTrue( Test_Transcript_Recorder_Exposed::bool_of( 'true' ) );
		$this->assertTrue( Test_Transcript_Recorder_Exposed::bool_of( 'yes' ) );
		$this->assertTrue( Test_Transcript_Recorder_Exposed::bool_of( 1 ) );
		$this->assertTrue( Test_Transcript_Recorder_Exposed::bool_of( '1' ) );
		$this->assertFalse( Test_Transcript_Recorder_Exposed::bool_of( false ) );
		$this->assertFalse( Test_Transcript_Recorder_Exposed::bool_of( 'false' ) );
		$this->assertFalse( Test_Transcript_Recorder_Exposed::bool_of( '0' ) );
		$this->assertFalse( Test_Transcript_Recorder_Exposed::bool_of( 0 ) );
		$this->assertFalse( Test_Transcript_Recorder_Exposed::bool_of( '' ) );
	}

	public function test_normalise_session_key(): void {
		$this->assertSame( 'abc-123_XYZ', Test_Transcript_Recorder_Exposed::normalise( ' abc-123_XYZ!! ' ) );
		$this->assertSame( '', Test_Transcript_Recorder_Exposed::normalise( '' ) );
		$this->assertSame( '', Test_Transcript_Recorder_Exposed::normalise( array( 'x' ) ) );
		$this->assertSame( 96, strlen( Test_Transcript_Recorder_Exposed::normalise( str_repeat( 'a', 200 ) ) ) );
	}

	public function test_generate_session_key_prefix(): void {
		$this->assertStringStartsWith( 'wp-mcp-ai-', Test_Transcript_Recorder_Exposed::generate( 3 ) );
	}

	public function test_encode_json(): void {
		$encoded = Test_Transcript_Recorder_Exposed::encode( array( 'a' => 1 ) );
		// Pretty-printed output (JSON_PRETTY_PRINT) round-trips exactly.
		$this->assertSame( array( 'a' => 1 ), json_decode( $encoded, true ) );
		$this->assertStringContainsString( '"a": 1', $encoded );
		// Invalid UTF-8: the pretty-printed pass fails and the plain fallback
		// succeeds after wp_json_encode substitutes the invalid bytes.
		$fallback = Test_Transcript_Recorder_Exposed::encode( "\xB1\x31" );
		$this->assertNotSame( '', $fallback );
		$this->assertStringNotContainsString( "\n", $fallback );
		$this->assertIsString( json_decode( $fallback ) );
	}

	public function test_latency_and_timestamp_helpers(): void {
		$this->assertSame( 1500, Test_Transcript_Recorder_Exposed::latency( array( 'request_started_at' => 10.0, 'response_completed_at' => 11.5 ) ) );
		$this->assertNull( Test_Transcript_Recorder_Exposed::latency( array() ) );
		$this->assertNull( Test_Transcript_Recorder_Exposed::latency( array( 'request_started_at' => 12.0, 'response_completed_at' => 11.0 ) ) );

		$this->assertSame( 0, Test_Transcript_Recorder_Exposed::ts( array(), 'request_started_at' ) );
		$this->assertSame( 0, Test_Transcript_Recorder_Exposed::ts( array( 'request_started_at' => -5.2 ), 'request_started_at' ) );
		$this->assertSame( 1000, Test_Transcript_Recorder_Exposed::ts( array( 'request_started_at' => 1000.9 ), 'request_started_at' ) );
	}

	public function test_determine_model_precedence(): void {
		$this->assertSame( 'gpt-4o', Test_Transcript_Recorder_Exposed::model( array( 'model' => 'gpt-3' ), array( 'model' => 'gpt-4o' ) ) );
		$this->assertSame( 'gpt-3', Test_Transcript_Recorder_Exposed::model( array( 'model' => 'gpt-3' ), array() ) );
		$this->assertSame( 'unknown-model', Test_Transcript_Recorder_Exposed::model( array(), array() ) );
	}

	public function test_build_metadata_and_finish_reasons(): void {
		$response = array(
			'provider' => 'openai',
			'status'   => 'complete',
			'id'       => 'r-1',
			'choices'  => array(
				array( 'finish_reason' => 'stop' ),
				array( 'finish_reason' => 'stop' ),
				array( 'finish_reason' => 'tool_calls' ),
				array( 'not-a-choice' ),
			),
			'usage'    => array( 'prompt_tokens' => 3 ),
		);

		$metadata = Test_Transcript_Recorder_Exposed::meta( array(), $response, array( 'session_key' => 'raw-key!' ) );

		$this->assertSame( 'openai', $metadata['provider'] );
		$this->assertSame( 'complete', $metadata['status'] );
		$this->assertSame( 'r-1', $metadata['response_id'] );
		$this->assertSame( array( 'stop', 'tool_calls' ), $metadata['finish_reasons'] );
		$this->assertSame( array( 'prompt_tokens' => 3 ), $metadata['usage'] );
		$this->assertSame( 'raw-key', $metadata['session_key_raw'] );

		$this->assertSame( array(), Test_Transcript_Recorder_Exposed::reasons( array() ) );
	}

	public function test_build_record_session_key_precedence(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		// Context session key wins.
		$record = Test_Transcript_Recorder_Exposed::build( 4, $messages, array(), array( 'content' => 'x' ), $this->request( array( 'session_key' => 'from-request' ) ), 2, array( 'session_key' => 'from-context' ) );
		$this->assertSame( 'from-context', $record['session_key'] );

		// Request param next.
		$record = Test_Transcript_Recorder_Exposed::build( 4, $messages, array(), array( 'content' => 'x' ), $this->request( array( 'session_key' => 'from-request' ) ), 2, array() );
		$this->assertSame( 'from-request', $record['session_key'] );

		// Generated fallback.
		$record = Test_Transcript_Recorder_Exposed::build( 4, $messages, array(), array( 'content' => 'x' ), $this->request(), 2, array() );
		$this->assertStringStartsWith( 'wp-mcp-ai-', $record['session_key'] );
	}
}
