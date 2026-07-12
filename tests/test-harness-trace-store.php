<?php
/**
 * Tests for the Harness Trace Store.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Harness Trace Store tests.
 *
 * @since 1.9.0
 */
class Test_Harness_Trace_Store extends WP_UnitTestCase {

	private $assistant_id;

	public function setUp(): void {
		parent::setUp();
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_start_run_returns_non_empty_run_id() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		$this->assertIsString( $run_id );
		$this->assertNotEmpty( $run_id );
		$this->assertStringContainsString( 'assistant_' . $this->assistant_id, $run_id );

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );
		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_start_run_with_invalid_assistant_returns_error() {
		$result = WP_MCP_AI_Harness_Trace_Store::start_run( 0 );
		$this->assertWPError( $result );
	}

	public function test_write_and_read_artifact_round_trip() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		$data   = array(
			'test_key' => 'test_value',
			'nested'   => array( 'a' => 1 ),
		);

		$written = WP_MCP_AI_Harness_Trace_Store::write_artifact( $run_id, 'test.json', $data );
		$this->assertTrue( $written );

		$read = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'test.json', $this->assistant_id );
		$this->assertIsArray( $read );
		$this->assertSame( 'test_value', $read['test_key'] );

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );
		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_write_text_and_read() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		$text   = 'Hello from trace store test.';

		$written = WP_MCP_AI_Harness_Trace_Store::write_text( $run_id, 'response.txt', $text );
		$this->assertTrue( $written );

		$read = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'response.txt', $this->assistant_id );
		$this->assertSame( $text, $read );

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );
		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_append_jsonl_and_read() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );

		WP_MCP_AI_Harness_Trace_Store::append_jsonl(
			$run_id,
			'tools.jsonl',
			array(
				'slug'   => 'tool_a',
				'result' => 'ok',
			)
		);
		WP_MCP_AI_Harness_Trace_Store::append_jsonl(
			$run_id,
			'tools.jsonl',
			array(
				'slug'   => 'tool_b',
				'result' => 'ok',
			)
		);

		$read = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'tools.jsonl', $this->assistant_id );
		$this->assertIsArray( $read );
		$this->assertCount( 2, $read );
		$this->assertSame( 'tool_a', $read[0]['slug'] );
		$this->assertSame( 'tool_b', $read[1]['slug'] );

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );
		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_finish_run_records_duration() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id, array( 'aggregate' => array( 'score' => 0.85 ) ) );

		$meta = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'meta.json', $this->assistant_id );
		$this->assertIsArray( $meta );
		$this->assertNotNull( $meta['finished_at'] );
		$this->assertGreaterThan( 0, $meta['duration_ms'] );

		$score = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'score.json', $this->assistant_id );
		$this->assertIsArray( $score );
		$this->assertSame( 0.85, $score['aggregate']['score'] );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_list_runs_returns_ordered_runs() {
		$run1 = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		WP_MCP_AI_Harness_Trace_Store::finish_run( $run1 );

		sleep( 1 );

		$run2 = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		WP_MCP_AI_Harness_Trace_Store::finish_run( $run2 );

		$runs = WP_MCP_AI_Harness_Trace_Store::list_runs( $this->assistant_id, 10 );
		$this->assertCount( 2, $runs );

		// Most recent first.
		$this->assertGreaterThanOrEqual( $runs[1]['started_at'], $runs[0]['started_at'] );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_get_run_manifest_returns_files() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		WP_MCP_AI_Harness_Trace_Store::write_artifact( $run_id, 'profile.json', array( 'enabled' => true ) );
		WP_MCP_AI_Harness_Trace_Store::write_text( $run_id, 'response.txt', 'test' );
		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );

		$manifest = WP_MCP_AI_Harness_Trace_Store::get_run_manifest( $run_id, $this->assistant_id );
		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'files', $manifest );
		$this->assertArrayHasKey( 'total_size', $manifest );
		$this->assertGreaterThanOrEqual( 2, count( $manifest['files'] ) );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_is_active_returns_true_for_active_run() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		$this->assertTrue( WP_MCP_AI_Harness_Trace_Store::is_active( $run_id ) );

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );
		$this->assertFalse( WP_MCP_AI_Harness_Trace_Store::is_active( $run_id ) );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
	}

	public function test_read_artifact_returns_null_for_missing() {
		$result = WP_MCP_AI_Harness_Trace_Store::read_artifact( 'nonexistent_run_999', 'meta.json', $this->assistant_id );
		$this->assertNull( $result );
	}

	public function test_delete_all_for_assistant_cleans_up() {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );

		$runs = WP_MCP_AI_Harness_Trace_Store::list_runs( $this->assistant_id );
		$this->assertEmpty( $runs );
	}
}
