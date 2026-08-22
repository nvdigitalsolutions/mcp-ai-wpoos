<?php
/**
 * Tests for the Artifact Failure Replay (Phase B.2).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test failure replay from harness trace runs.
 */
class Test_Artifact_Failure_Replay extends WP_UnitTestCase {

	/**
	 * Assistant ID used for trace runs.
	 *
	 * @var int
	 */
	private $assistant_id = 999999;

	/**
	 * Clean up trace data between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_replay_case_rules' );
		if ( class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
		}

		parent::tearDown();
	}

	/**
	 * Seed a trace run with the given tool-call records.
	 *
	 * @param array $records Tool-call records.
	 */
	private function seed_run( $records ) {
		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Harness_Trace_Store class not available.' );
		}

		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id );
		$this->assertNotWPError( $run_id );

		foreach ( $records as $record ) {
			WP_MCP_AI_Harness_Trace_Store::append_jsonl( $run_id, 'tool_calls.jsonl', $record );
		}

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id );
	}

	/**
	 * No trace data → WP_Error with the expected code.
	 */
	public function test_no_traces_returns_error() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		$result = WP_MCP_AI_Artifact_Failure_Replay::collect_failures( $this->assistant_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_failure_replay_no_cases', $result->get_error_code() );
	}

	/**
	 * Failed calls become replay cases; successful calls are excluded.
	 */
	public function test_failures_become_cases_and_successes_are_excluded() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		$this->seed_run(
			array(
				array(
					'seq'            => 1,
					'slug'           => 'web_search',
					'args_summary'   => wp_json_encode( array( 'q' => 'test' ) ),
					'result_success' => false,
					'result_type'    => 'wp_error',
					'result_summary' => 'wp_mcp_ai_search_failed',
					'duration_ms'    => 100,
					'timestamp'      => time(),
				),
				array(
					'seq'            => 2,
					'slug'           => 'ok_tool',
					'args_summary'   => wp_json_encode( array( 'q' => 'fine' ) ),
					'result_success' => true,
					'result_type'    => 'array',
					'result_summary' => '3 keys',
					'duration_ms'    => 10,
					'timestamp'      => time(),
				),
			)
		);

		$cases = WP_MCP_AI_Artifact_Failure_Replay::build_cases( $this->assistant_id );

		$this->assertIsArray( $cases );
		$this->assertCount( 1, $cases );
		$this->assertSame( 'artifact_replay', $cases[0]['verifier_slug'] );
		$this->assertSame( 'trace_replay', $cases[0]['metadata']['source'] );
		$this->assertSame( 'web_search', $cases[0]['metadata']['tool_slug'] );
		$this->assertSame( 'web_search', $cases[0]['input']['tool_slug'] );
		$this->assertStringContainsString( 'web_search', $cases[0]['input']['prompt'] );
	}

	/**
	 * Duplicate failures are deduped; slugs whitelist filters tools.
	 */
	public function test_dedupe_and_slug_filter() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		$this->seed_run(
			array(
				array(
					'seq'            => 1,
					'slug'           => 'web_search',
					'args_summary'   => wp_json_encode( array( 'q' => 'same' ) ),
					'result_success' => false,
					'result_summary' => 'error_a',
				),
				array(
					'seq'            => 2,
					'slug'           => 'web_search',
					'args_summary'   => wp_json_encode( array( 'q' => 'same' ) ),
					'result_success' => false,
					'result_summary' => 'error_a',
				),
				array(
					'seq'            => 3,
					'slug'           => 'other_tool',
					'args_summary'   => wp_json_encode( array( 'x' => 1 ) ),
					'result_success' => false,
					'result_summary' => 'error_b',
				),
			)
		);

		$cases = WP_MCP_AI_Artifact_Failure_Replay::build_cases(
			$this->assistant_id,
			array( 'slugs' => array( 'web_search' ) )
		);

		$this->assertIsArray( $cases );
		$this->assertCount( 1, $cases );
		$this->assertSame( 'web_search', $cases[0]['input']['tool_slug'] );
	}

	/**
	 * PII in error/args summaries is scrubbed when the filter is loaded.
	 */
	public function test_pii_is_scrubbed() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pii_Filter' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pii_Filter class not available.' );
		}

		$this->seed_run(
			array(
				array(
					'seq'            => 1,
					'slug'           => 'send_email',
					'args_summary'   => wp_json_encode( array( 'to' => 'bob@example.com' ) ),
					'result_success' => false,
					'result_summary' => 'failed for bob@example.com',
				),
			)
		);

		$cases = WP_MCP_AI_Artifact_Failure_Replay::build_cases( $this->assistant_id );

		$this->assertIsArray( $cases );
		$this->assertStringNotContainsString( 'bob@example.com', $cases[0]['input']['error'] );
		$this->assertStringNotContainsString( 'bob@example.com', wp_json_encode( $cases[0]['input'] ) );
	}

	/**
	 * Build_suite produces an artifact-scoped suite.
	 */
	public function test_build_suite_is_artifact_scoped() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		$this->seed_run(
			array(
				array(
					'seq'            => 1,
					'slug'           => 'web_search',
					'args_summary'   => wp_json_encode( array( 'q' => 'test' ) ),
					'result_success' => false,
					'result_summary' => 'failed',
				),
			)
		);

		$suite = WP_MCP_AI_Artifact_Failure_Replay::build_suite( $this->assistant_id, array( 'artifact_type' => 'prompt' ) );

		$this->assertNotWPError( $suite );
		$this->assertSame( 'prompt', $suite->get_artifact_type() );
		$this->assertSame( (string) $this->assistant_id, $suite->get_artifact_id() );
		$this->assertSame( 1, $suite->count_cases() );
	}

	/**
	 * Per-case rules from the filter land in the case expected payload.
	 */
	public function test_case_rules_filter_applied() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		add_filter(
			'wp_mcp_ai_artifact_replay_case_rules',
			static function () {
				return array(
					array(
						'type'  => 'enum',
						'path'  => 'value',
						'value' => array( 'ok' ),
					),
				);
			}
		);

		$this->seed_run(
			array(
				array(
					'seq'            => 1,
					'slug'           => 'web_search',
					'args_summary'   => wp_json_encode( array( 'q' => 'test' ) ),
					'result_success' => false,
					'result_summary' => 'failed',
				),
			)
		);

		$cases = WP_MCP_AI_Artifact_Failure_Replay::build_cases( $this->assistant_id );

		$this->assertIsArray( $cases );
		$this->assertSame( array( 'ok' ), $cases[0]['expected']['rules'][0]['value'] );
	}

	/**
	 * The replay verifier's baseline rule: non-empty output passes, empty fails.
	 */
	public function test_replay_verifier_baseline_rule() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Replay_Verifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Replay_Verifier class not available.' );
		}

		$verifier = new WP_MCP_AI_Artifact_Replay_Verifier();

		$empty = $verifier->verify(
			array(
				'value'    => '',
				'input'    => array(),
				'expected' => array( 'success' => true ),
			)
		);
		$this->assertFalse( $empty['passed'] );

		$filled = $verifier->verify(
			array(
				'value'    => 'A corrected answer.',
				'input'    => array(),
				'expected' => array( 'success' => true ),
			)
		);
		$this->assertTrue( $filled['passed'] );
	}

	/**
	 * Per-case rules in the expected payload are enforced by the replay verifier.
	 */
	public function test_replay_verifier_enforces_case_rules() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Replay_Verifier' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Replay_Verifier class not available.' );
		}

		$verifier = new WP_MCP_AI_Artifact_Replay_Verifier();
		$subject  = array(
			'value'    => 'not-ok',
			'input'    => array(),
			'expected' => array(
				'success' => true,
				'rules'   => array(
					array(
						'type'  => 'enum',
						'path'  => 'value',
						'value' => array( 'ok' ),
					),
				),
			),
		);

		$failed = $verifier->verify( $subject );
		$this->assertFalse( $failed['passed'] );

		$subject['value'] = 'ok';
		$passed           = $verifier->verify( $subject );
		$this->assertTrue( $passed['passed'] );
	}
}
