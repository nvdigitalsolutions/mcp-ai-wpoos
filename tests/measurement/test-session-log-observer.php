<?php
/**
 * Tests for the session-log observer (Proposal 029, Phase 5.8 —
 * telemetry single-path).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observer tests.
 */
class Test_WP_MCP_AI_Session_Log_Observer extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Stock_Metrics::register( WP_MCP_AI_Measurement_Registry::get_instance() );
		WP_MCP_AI_Chat_Turn_Metrics::register( WP_MCP_AI_Measurement_Registry::get_instance() );

		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();
		WP_MCP_AI_Session_Log_Observer::reset_instance();

		add_filter( 'wp_mcp_ai_session_log_observer_enabled', '__return_true' );
		WP_MCP_AI_Session_Log_Observer::get_instance()->attach();
	}

	/**
	 * Teardown.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Session_Log_Observer::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		remove_filter( 'wp_mcp_ai_session_log_observer_enabled', '__return_true' );
		parent::tearDown();
	}

	/**
	 * A successful tool_result entry projects count + success + duration.
	 */
	public function test_tool_result_success_projects_count_success_duration() {
		do_action(
			'wp_mcp_ai_session_log_event',
			'tool_result',
			array(
				'name'         => 'create_post',
				'content'      => 'ok',
				'outcome'      => 'success',
				'duration_ms'  => 12.5,
				'assistant_id' => 7,
				'user_id'      => 3,
			),
			4,
			1000.0
		);

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'tool.execution.count', $ids );
		$this->assertContains( 'tool.execution.success.count', $ids );
		$this->assertContains( 'tool.execution.duration_ms', $ids );
		$this->assertNotContains( 'tool.execution.error.count', $ids );

		// The duration record carries the projected value and context.
		foreach ( WP_MCP_AI_Metric_Collector::get_instance()->buffered() as $record ) {
			if ( 'tool.execution.duration_ms' === $record['id'] ) {
				$this->assertSame( 12.5, $record['value'] );
				$this->assertSame( 'create_post', $record['context']['tool'] );
				$this->assertSame( 'success', $record['context']['attributes']['outcome'] );
				$this->assertSame( 7, $record['context']['assistant_id'] );
				$this->assertSame( 3, $record['context']['user_id'] );
			}
		}
	}

	/**
	 * An errored tool_result projects the error counter instead.
	 */
	public function test_tool_result_error_projects_error_count() {
		do_action(
			'wp_mcp_ai_session_log_event',
			'tool_result',
			array(
				'name'        => 'save_post',
				'content'     => 'Error: nope',
				'outcome'     => 'error',
				'duration_ms' => 4.0,
			),
			5,
			1000.0
		);

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'tool.execution.error.count', $ids );
		$this->assertNotContains( 'tool.execution.success.count', $ids );
	}

	/**
	 * Turn boundaries project count, duration, and iterations.
	 */
	public function test_turn_boundaries_project_count_duration_iterations() {
		do_action(
			'wp_mcp_ai_session_log_event',
			'turn_started',
			array(
				'assistant_id' => 9,
				'user_id'      => 2,
			),
			1,
			1000.0
		);

		do_action(
			'wp_mcp_ai_session_log_event',
			'turn_ended',
			array(
				'assistant_id' => 9,
				'user_id'      => 2,
				'reason'       => 'completed',
				'iterations'   => 3,
			),
			10,
			1002.5
		);

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'chat.turn.count', $ids );
		$this->assertContains( 'chat.turn.duration_ms', $ids );
		$this->assertContains( 'chat.agentic.iterations', $ids );
		$this->assertNotContains( 'chat.turn.error.count', $ids );

		foreach ( WP_MCP_AI_Metric_Collector::get_instance()->buffered() as $record ) {
			if ( 'chat.turn.duration_ms' === $record['id'] ) {
				$this->assertSame( 2500.0, $record['value'] );
			}
			if ( 'chat.agentic.iterations' === $record['id'] ) {
				$this->assertSame( 3.0, $record['value'] );
			}
		}
	}

	/**
	 * A rejected turn projects the turn error counter.
	 */
	public function test_rejected_turn_projects_error_count() {
		do_action(
			'wp_mcp_ai_session_log_event',
			'turn_ended',
			array(
				'assistant_id' => 9,
				'reason'       => 'rejected',
				'iterations'   => 0,
			),
			2,
			1000.0
		);

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'chat.turn.error.count', $ids );
	}

	/**
	 * The observer is inert without the enabling flag.
	 */
	public function test_disabled_by_default_records_nothing() {
		// Detach the setUp-attached singleton first: reset_instance()
		// alone leaves the old instance's hook in place.
		WP_MCP_AI_Session_Log_Observer::get_instance()->detach();
		WP_MCP_AI_Session_Log_Observer::reset_instance();
		remove_filter( 'wp_mcp_ai_session_log_observer_enabled', '__return_true' );

		$this->assertFalse( WP_MCP_AI_Session_Log_Observer::get_instance()->attach() );

		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();

		do_action(
			'wp_mcp_ai_session_log_event',
			'tool_result',
			array(
				'name'    => 'create_post',
				'outcome' => 'success',
			),
			1,
			1000.0
		);

		$this->assertEmpty( WP_MCP_AI_Metric_Collector::get_instance()->buffered() );
	}
}
