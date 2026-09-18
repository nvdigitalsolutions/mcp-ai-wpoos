<?php
/**
 * Tests for Telegram message auto-chunking in the send_telegram_message tool.
 *
 * Covers the fix for Telegram's 400 "message is too long" on scheduled
 * assistant-run summary deliveries:
 * - messages within the 4,096-character limit keep the single-send behavior,
 * - long messages are split at paragraph boundaries with markup preserved,
 * - hard-split chunks drop parse_mode (mid-tag breaks would be rejected),
 * - chunk=false restores the legacy single-send behavior,
 * - a mid-sequence chunk failure surfaces a precise chunk_error.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-telegram-message.php';

/**
 * Test suite for Telegram message chunking.
 */
class Test_Pro_Telegram_Message_Chunking extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Send_Telegram_Message
	 */
	private $tool;

	/**
	 * Captured HTTP requests (url + args), newest last.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $captured_requests = array();

	/**
	 * When set, the Nth captured request fails with a 400 "message is too long".
	 *
	 * @var int|null
	 */
	private $fail_request_at;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool              = new WP_MCP_AI_Pro_Tool_Send_Telegram_Message();
		$this->captured_requests = array();
		$this->fail_request_at   = null;

		add_filter( 'pre_http_request', array( $this, 'capture_http_request' ), 10, 3 );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_http_request' ), 10 );

		parent::tearDown();
	}

	/**
	 * Intercept Telegram API requests and record their payloads.
	 *
	 * @param false|array|WP_Error $pre  Pre-emptive response.
	 * @param array                $args Request arguments.
	 * @param string               $url  Request URL.
	 * @return array Mocked HTTP response.
	 */
	public function capture_http_request( $pre, $args, $url ) {
		$this->captured_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( null !== $this->fail_request_at && count( $this->captured_requests ) === $this->fail_request_at ) {
			return array(
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'body'     => wp_json_encode(
					array(
						'ok'          => false,
						'description' => 'Bad Request: message is too long',
					)
				),
			);
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'ok'     => true,
					'result' => array( 'message_id' => count( $this->captured_requests ) ),
				)
			),
		);
	}

	/**
	 * Decode the body of a captured request.
	 *
	 * @param int $index Zero-based request index.
	 * @return array Decoded payload.
	 */
	private function decoded_payload( $index ) {
		$body = $this->captured_requests[ $index ]['args']['body'];

		return json_decode( $body, true );
	}

	/**
	 * Build a long multi-paragraph message (fits through paragraph chunking).
	 *
	 * @return string
	 */
	private function long_paragraph_text() {
		$paragraph = str_repeat( 'This is a summary line with enough characters to matter. ', 20 ); // ~1,160 chars.
		return implode( "\n\n", array_fill( 0, 8, $paragraph ) );
	}

	/**
	 * Short messages keep the legacy single-send behavior.
	 */
	public function test_short_message_sends_single_request() {
		$result = $this->tool->execute(
			array(
				'token'   => '123456:test-token',
				'chat_id' => '8355775408',
				'text'    => 'Hello world',
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'Hello world', $this->decoded_payload( 0 )['text'] );
		$this->assertArrayHasKey( 'message_id', $result['result'] );
	}

	/**
	 * Long multi-paragraph messages are split at paragraph boundaries and
	 * keep their parse_mode on every chunk (markup stays intact).
	 */
	public function test_long_message_is_chunked_with_markup_preserved() {
		$text = $this->long_paragraph_text();
		$this->assertGreaterThan( 4096, mb_strlen( $text ) );

		$result = $this->tool->execute(
			array(
				'token'      => '123456:test-token',
				'chat_id'    => '8355775408',
				'text'       => $text,
				'parse_mode' => 'HTML',
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertGreaterThan( 1, $result['messages_sent'] );
		$this->assertSame( count( $this->captured_requests ), $result['messages_sent'] );

		foreach ( $this->captured_requests as $index => $request ) {
			$payload = $this->decoded_payload( $index );
			$this->assertLessThanOrEqual( 4096, mb_strlen( $payload['text'] ) );
			$this->assertSame( 'HTML', $payload['parse_mode'] );
		}

		// Every chunk must be a verbatim, ordered substring of the original
		// text (chunk-boundary separators are cosmetic — each chunk is its
		// own Telegram message).
		$offset = 0;
		foreach ( $this->captured_requests as $index => $request ) {
			$chunk_text = $this->decoded_payload( $index )['text'];
			$position   = strpos( $text, $chunk_text, $offset );
			$this->assertNotFalse( $position );
			$offset = $position + strlen( $chunk_text );
		}
	}

	/**
	 * Chunk=false restores the legacy behavior: one request with the full text.
	 */
	public function test_chunk_disabled_keeps_single_send() {
		$text = $this->long_paragraph_text();

		$result = $this->tool->execute(
			array(
				'token'   => '123456:test-token',
				'chat_id' => '8355775408',
				'text'    => $text,
				'chunk'   => false,
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( trim( $text ), $this->decoded_payload( 0 )['text'] );
	}

	/**
	 * A single oversize block is hard-split, and those chunks drop parse_mode
	 * because a mid-tag split would make Telegram reject the message.
	 */
	public function test_hard_split_strips_parse_mode() {
		$text = implode( ' ', array_fill( 0, 2200, 'word' ) ); // ~11,000 chars, no newlines.
		$this->assertGreaterThan( 4096, mb_strlen( $text ) );

		$result = $this->tool->execute(
			array(
				'token'      => '123456:test-token',
				'chat_id'    => '8355775408',
				'text'       => $text,
				'parse_mode' => 'HTML',
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertGreaterThan( 2, $result['messages_sent'] );

		foreach ( $this->captured_requests as $index => $request ) {
			$payload = $this->decoded_payload( $index );
			$this->assertLessThanOrEqual( 4096, mb_strlen( $payload['text'] ) );
			$this->assertArrayNotHasKey( 'parse_mode', $payload );
		}
	}

	/**
	 * A failed chunk surfaces a precise chunk error identifying the chunk.
	 */
	public function test_failed_chunk_reports_chunk_error() {
		$text                  = $this->long_paragraph_text();
		$this->fail_request_at = 2;

		$result = $this->tool->execute(
			array(
				'token'   => '123456:test-token',
				'chat_id' => '8355775408',
				'text'    => $text,
			),
			array( 'user_id' => 1 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_telegram_chunk_error', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 2, $data['chunk'] );
		$this->assertStringContainsString( 'message is too long', $result->get_error_message() );
	}

	/**
	 * A short message can still fail with the plain API error (legacy path).
	 */
	public function test_short_message_api_error_surfaces_telegram_error() {
		$this->fail_request_at = 1;

		$result = $this->tool->execute(
			array(
				'token'   => '123456:test-token',
				'chat_id' => '8355775408',
				'text'    => 'Short message',
			),
			array( 'user_id' => 1 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_telegram_api_error', $result->get_error_code() );
	}
}
