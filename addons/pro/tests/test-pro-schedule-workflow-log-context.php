<?php
/**
 * Tests that workflow step logging no longer embeds the accumulating
 * `previous_results` execution context in the log buffers.
 *
 * Regression cover for the O(N²) growth inside
 * WP_MCP_AI_Pro_Schedule_Manager::dispatch_workflow(), where every step's log
 * entry carried the results of all earlier steps — including their full tool
 * outputs — into `wp_mcp_ai_recent_activity`.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';
require_once __DIR__ . '/class-wp-mcp-ai-workflow-log-context-recorder-tool.php';

/**
 * Test suite for the workflow step log context builder.
 */
class Test_Pro_Schedule_Workflow_Log_Context extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( 'wf_log_context_recorder' );

		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		parent::tearDown();
	}

	/**
	 * Invoke the protected log-context builder.
	 *
	 * @param array  $step_context     Execution context for the current step.
	 * @param array  $previous_results Results of earlier steps.
	 * @param string $label            Step label.
	 * @param float  $step_dur         Step duration.
	 * @return array Log context.
	 */
	protected function build_log_context( array $step_context, array $previous_results, $label, $step_dur ) {
		$method = new ReflectionMethod( 'WP_MCP_AI_Pro_Schedule_Manager', 'build_workflow_step_log_context' );
		$method->setAccessible( true );

		return $method->invoke( null, $step_context, $previous_results, $label, $step_dur );
	}

	/**
	 * The log context must never carry the accumulating execution array.
	 */
	public function test_log_context_excludes_previous_results() {
		$context = $this->build_log_context(
			array(
				'schedule_id'      => 'sched-1',
				'workflow_step'    => 3,
				'previous_results' => array( array( 'tool_slug' => 'a' ) ),
			),
			array(
				array(
					'tool_slug' => 'a',
					'label'     => 'A',
					'duration'  => 0.1,
					'result'    => 'done',
				),
			),
			'Step B',
			0.42
		);

		$this->assertArrayNotHasKey( 'previous_results', $context );
		$this->assertSame( 1, $context['previous_step_count'] );
		$this->assertSame( 'Step B', $context['step_label'] );
		$this->assertSame( 0.42, $context['step_duration'] );

		// Fields not part of the accumulated history survive.
		$this->assertSame( 'sched-1', $context['schedule_id'] );
		$this->assertSame( 3, $context['workflow_step'] );
	}

	/**
	 * Prior-step results must be bounded in the log context.
	 */
	public function test_log_context_bounds_prior_results() {
		$long_string = str_repeat( 'word ', 400 );

		$context = $this->build_log_context(
			array(),
			array(
				array(
					'tool_slug' => 'step_a',
					'label'     => 'A',
					'duration'  => 1.5,
					'result'    => $long_string,
				),
				array(
					'tool_slug' => 'step_b',
					'label'     => 'B',
					'duration'  => 0.2,
					'result'    => array_fill( 0, 500, 'payload' ),
				),
			),
			'Step C',
			0.1
		);

		$this->assertSame( 2, $context['previous_step_count'] );

		// Strings keep a word-limited excerpt.
		$this->assertLessThan( strlen( $long_string ), strlen( $context['previous_steps'][0]['result'] ) );

		// Structured results degrade to a short JSON preview (allow slack for the
		// JSON escaping of the truncated string plus the ellipsis).
		$this->assertIsString( $context['previous_steps'][1]['result'] );
		$this->assertLessThan( 300, strlen( $context['previous_steps'][1]['result'] ) );

		// Small structured results keep their shape and stay valid JSON.
		$small = $this->build_log_context(
			array(),
			array( array( 'result' => array( 'ok' => true ) ) ),
			'Step D',
			0.1
		);
		$this->assertSame( '{"ok":true}', $small['previous_steps'][0]['result'] );
	}

	/**
	 * A two-step workflow must log bounded summaries while tools keep receiving
	 * the full accumulated context.
	 */
	public function test_dispatch_workflow_logs_summary_and_preserves_execution_context() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Workflow_Log_Context_Recorder_Tool() );
		$tool = $registry->get_tool( 'wf_log_context_recorder' );

		$captured_entries = array();
		$filter           = static function ( $entry ) use ( &$captured_entries ) {
			if ( isset( $entry['type'] ) && ( 'tool_execution' === $entry['type'] || 'tool_error' === $entry['type'] ) ) {
				$captured_entries[] = $entry;
			}
			return $entry;
		};
		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );

		$completed_results = null;
		$on_completed      = static function ( $schedule_id, $schedule, $previous_results ) use ( &$completed_results ) {
			$completed_results = $previous_results;
		};
		add_action( 'wp_mcp_ai_pro_workflow_completed', $on_completed, 10, 3 );

		$schedule_id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'schedule_type'  => 'workflow',
				'name'           => 'Two step workflow',
				'schedule'       => 'single',
				'timestamp'      => time() + 3600,
				'workflow_steps' => array(
					array(
						'tool_slug' => 'wf_log_context_recorder',
						'arguments' => array( 'message' => 'first' ),
						'label'     => 'Step one',
					),
					array(
						'tool_slug' => 'wf_log_context_recorder',
						'arguments' => array( 'message' => 'second' ),
						'label'     => 'Step two',
					),
				),
			),
			$admin_id
		);

		$this->assertNotWPError( $schedule_id );

		$result = WP_MCP_AI_Pro_Schedule_Manager::dispatch( $schedule_id );

		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );
		remove_action( 'wp_mcp_ai_pro_workflow_completed', $on_completed, 10 );

		$this->assertTrue( $result );

		// The workflow completion hook still receives the full accumulated array.
		$this->assertIsArray( $completed_results );
		$this->assertCount( 2, $completed_results );

		// The second step's tool saw the first step's result in its context.
		$this->assertNotNull( $tool->last_context );
		$this->assertArrayHasKey( 'previous_results', $tool->last_context );
		$this->assertArrayHasKey( 0, $tool->last_context['previous_results'] );

		// Every captured log entry carries a bounded summary, never the full array.
		$this->assertNotEmpty( $captured_entries );
		$this->assertCount( 2, $captured_entries );

		$first  = $captured_entries[0]['context'];
		$second = $captured_entries[1]['context'];

		$this->assertArrayNotHasKey( 'previous_results', $first );
		$this->assertArrayNotHasKey( 'previous_results', $second );

		// Step one saw no history; step two saw exactly one prior step.
		$this->assertSame( 0, $first['previous_step_count'] );
		$this->assertSame( 1, $second['previous_step_count'] );
		$this->assertSame( 'Step one', $second['previous_steps'][0]['label'] );
		$this->assertSame( 'wf_log_context_recorder', $second['previous_steps'][0]['tool_slug'] );
	}
}
