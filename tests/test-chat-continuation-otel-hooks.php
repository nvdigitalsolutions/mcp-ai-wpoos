<?php
/**
 * Tests for the OTel continuation hook listeners in WP_MCP_AI_Otel_Span_Exporter.
 *
 * These tests verify that the five continuation lifecycle hooks are registered
 * and that they buffer the expected span names and attributes when fired.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Chat_Continuation_Otel_Hooks
 */
class Test_Chat_Continuation_Otel_Hooks extends WP_UnitTestCase {

	// ── Fixtures ───────────────────────────────────────────────────────────────

	/**
	 * Sample snapshot used across tests.
	 *
	 * @var array
	 */
	private static $snapshot = array(
		'job_id'          => 'test_job_otel_001',
		'chat_session_id' => 'sess_otel_abc',
		'assistant_id'    => 42,
		'user_id'         => 7,
		'tool_name'       => 'generate_veo_video',
		'messages'        => array(
			array( 'role' => 'user', 'content' => 'Make a video' ),
		),
	);

	public function setUp(): void {
		parent::setUp();

		// Reset exporter state before each test.
		WP_MCP_AI_Otel_Span_Exporter::reset_for_tests();

		// Force an OTLP endpoint so is_enabled() returns true.
		update_option( 'wp_mcp_ai_otel_endpoint', 'https://otel.example.com/v1/traces' );

		// Re-register hooks now that endpoint is present.
		WP_MCP_AI_Otel_Span_Exporter::register();
	}

	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_otel_endpoint' );
		WP_MCP_AI_Otel_Span_Exporter::reset_for_tests();
		parent::tearDown();
	}

	// ── Hook registration ──────────────────────────────────────────────────────

	/** @test */
	public function test_continuation_stored_hook_is_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'wp_mcp_ai_chat_continuation_stored', array( 'WP_MCP_AI_Otel_Span_Exporter', 'on_chat_continuation_stored' ) )
		);
	}

	/** @test */
	public function test_continuation_ready_hook_is_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'wp_mcp_ai_chat_continuation_ready', array( 'WP_MCP_AI_Otel_Span_Exporter', 'on_chat_continuation_ready' ) )
		);
	}

	/** @test */
	public function test_continuation_dispatched_hook_is_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'wp_mcp_ai_chat_continuation_dispatched', array( 'WP_MCP_AI_Otel_Span_Exporter', 'on_chat_continuation_dispatched' ) )
		);
	}

	/** @test */
	public function test_continuation_resumed_hook_is_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'wp_mcp_ai_chat_continuation_resumed', array( 'WP_MCP_AI_Otel_Span_Exporter', 'on_chat_continuation_resumed' ) )
		);
	}

	/** @test */
	public function test_continuation_errored_hook_is_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'wp_mcp_ai_chat_continuation_errored', array( 'WP_MCP_AI_Otel_Span_Exporter', 'on_chat_continuation_errored' ) )
		);
	}

	// ── Span buffering: stored ─────────────────────────────────────────────────

	/** @test */
	public function test_stored_action_buffers_span_with_correct_name() {
		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_stored( 'job_store_001', self::$snapshot );

		$buffer = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$this->assertNotEmpty( $buffer );

		$span = end( $buffer );
		$this->assertSame( 'nvoos.chat.continuation.stored', $span['name'] );
	}

	/** @test */
	public function test_stored_action_records_job_and_session_attributes() {
		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_stored( 'job_store_002', self::$snapshot );

		$buffer = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$span   = end( $buffer );

		$attr_map = $this->decode_span_attributes( $span['attributes'] );

		$this->assertSame( 'job_store_002', $attr_map['nvoos.continuation.job_id'] );
		$this->assertSame( 'sess_otel_abc', $attr_map['nvoos.continuation.session_id'] );
		$this->assertSame( 42, $attr_map['nvoos.continuation.assistant_id'] );
		$this->assertSame( 1, $attr_map['nvoos.continuation.message_count'] );
	}

	// ── Span buffering: ready / dispatched pair ────────────────────────────────

	/** @test */
	public function test_ready_opens_span_and_dispatched_closes_it() {
		$job_id = 'job_rd_003';
		$snap   = array_merge( self::$snapshot, array( 'job_id' => $job_id ) );

		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_ready( $snap, 'completed', array() );
		$open_spans = WP_MCP_AI_Otel_Span_Exporter::get_test_open_spans();
		$this->assertArrayHasKey( 'continuation_' . $job_id, $open_spans );

		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_dispatched( $job_id, $snap, 'completed' );
		$open_spans = WP_MCP_AI_Otel_Span_Exporter::get_test_open_spans();
		$this->assertArrayNotHasKey( 'continuation_' . $job_id, $open_spans );

		$buffer = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$names  = array_column( $buffer, 'name' );
		$this->assertContains( 'nvoos.chat.continuation.dispatched', $names );
	}

	/** @test */
	public function test_dispatched_without_ready_emits_point_span() {
		$job_id = 'job_pointspan_004';
		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_dispatched( $job_id, self::$snapshot, 'completed' );

		$buffer = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$names  = array_column( $buffer, 'name' );
		$this->assertContains( 'nvoos.chat.continuation.dispatched', $names );
	}

	// ── Span buffering: resumed ────────────────────────────────────────────────

	/** @test */
	public function test_resumed_action_buffers_span() {
		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_resumed(
			'job_res_005',
			self::$snapshot,
			'Here is your video!'
		);

		$buffer = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$span   = end( $buffer );
		$this->assertSame( 'nvoos.chat.continuation.resumed', $span['name'] );

		$attr_map = $this->decode_span_attributes( $span['attributes'] );
		$this->assertSame( 'job_res_005', $attr_map['nvoos.continuation.job_id'] );
		$this->assertSame( strlen( 'Here is your video!' ), $attr_map['nvoos.continuation.message_chars'] );
	}

	// ── Span buffering: errored ────────────────────────────────────────────────

	/** @test */
	public function test_errored_closes_open_ready_span() {
		$job_id = 'job_err_006';
		$snap   = array_merge( self::$snapshot, array( 'job_id' => $job_id ) );

		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_ready( $snap, 'completed', array() );
		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_errored( $job_id, $snap, 'LLM failed' );

		$open_spans = WP_MCP_AI_Otel_Span_Exporter::get_test_open_spans();
		$this->assertArrayNotHasKey( 'continuation_' . $job_id, $open_spans );

		$buffer   = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$names    = array_column( $buffer, 'name' );
		$this->assertContains( 'nvoos.chat.continuation.errored', $names );
	}

	/** @test */
	public function test_errored_without_ready_emits_point_span() {
		WP_MCP_AI_Otel_Span_Exporter::on_chat_continuation_errored(
			'job_err_007',
			self::$snapshot,
			'Router not available'
		);

		$buffer = WP_MCP_AI_Otel_Span_Exporter::get_test_buffer();
		$span   = end( $buffer );
		$this->assertSame( 'nvoos.chat.continuation.errored', $span['name'] );

		$attr_map = $this->decode_span_attributes( $span['attributes'] );
		$this->assertFalse( $attr_map['nvoos.continuation.success'] );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Decode the OTLP key-value attribute list back to a plain map.
	 *
	 * @param array $attributes OTLP-encoded attribute array.
	 * @return array Plain key => value map.
	 */
	private function decode_span_attributes( array $attributes ): array {
		$map = array();
		foreach ( $attributes as $kv ) {
			if ( ! isset( $kv['key'], $kv['value'] ) ) {
				continue;
			}
			$val_container = $kv['value'];
			if ( isset( $val_container['stringValue'] ) ) {
				$map[ $kv['key'] ] = $val_container['stringValue'];
			} elseif ( isset( $val_container['intValue'] ) ) {
				$map[ $kv['key'] ] = (int) $val_container['intValue'];
			} elseif ( isset( $val_container['boolValue'] ) ) {
				$map[ $kv['key'] ] = (bool) $val_container['boolValue'];
			} elseif ( isset( $val_container['doubleValue'] ) ) {
				$map[ $kv['key'] ] = (float) $val_container['doubleValue'];
			}
		}
		return $map;
	}
}
