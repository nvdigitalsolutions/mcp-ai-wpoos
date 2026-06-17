<?php
/**
 * Tests for WP_MCP_AI_Pro_Schedule_Otel_Subscriber.
 *
 * Verifies that schedule run-completed events are correctly translated
 * into collector metric records. These tests stub the collector via a
 * lightweight in-memory spy instead of relying on the full measurement
 * stack — the goal is to verify subscription logic, not to re-test the
 * collector's own buffer.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Otel_Subscriber
 */
class Test_Pro_Schedule_Otel_Subscriber extends WP_UnitTestCase {

	/**
	 * Skip when the required classes are not available.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Otel_Subscriber' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Otel_Subscriber not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Metrics' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Metrics not available.' );
		}
		// Disable JIT OTel dispatch so tests don't need the exporter.
		add_filter( 'wp_mcp_ai_pro_schedule_otel_jit_dispatch', '__return_false' );
		// Reset subscriber state before each test.
		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::reset();
	}

	/**
	 * Restore filters after each test.
	 */
	protected function tearDown(): void {
		remove_filter( 'wp_mcp_ai_pro_schedule_otel_jit_dispatch', '__return_false' );
		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::reset();
		parent::tearDown();
	}

	/**
	 * After boot(), the subscriber must be attached to
	 * `wp_mcp_ai_pro_schedule_run_completed`.
	 */
	public function test_boot_registers_run_completed_action() {
		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::boot();

		$priority = has_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			array( 'WP_MCP_AI_Pro_Schedule_Otel_Subscriber', 'on_run_completed' )
		);

		$this->assertNotFalse( $priority, 'on_run_completed should be registered.' );
	}

	/**
	 * A successful run must record duration_ms but NOT failure_count.
	 */
	public function test_successful_run_records_duration_not_failure() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Metric_Collector not available.' );
		}

		$recorded = array();
		add_action(
			'wp_mcp_ai_metric_recorded',
			static function ( $event ) use ( &$recorded ) {
				$recorded[] = $event;
			},
			10,
			1
		);

		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::boot();

		do_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			'test-schedule-001',
			array(
				'success'    => true,
				'duration'   => 1.5,
				'error'      => '',
				'action_log' => array(),
				'schedule'   => array( 'schedule_type' => 'task' ),
			)
		);

		remove_all_actions( 'wp_mcp_ai_metric_recorded' );

		$ids = array_column( $recorded, 'id' );

		$this->assertContains( WP_MCP_AI_Pro_Schedule_Metrics::RUN_DURATION_MS, $ids );
		$this->assertNotContains( WP_MCP_AI_Pro_Schedule_Metrics::RUN_FAILURE_COUNT, $ids );
	}

	/**
	 * A failed run must record both duration_ms and failure_count.
	 */
	public function test_failed_run_records_duration_and_failure() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Metric_Collector not available.' );
		}

		$recorded = array();
		add_action(
			'wp_mcp_ai_metric_recorded',
			static function ( $event ) use ( &$recorded ) {
				$recorded[] = $event;
			},
			10,
			1
		);

		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::boot();

		do_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			'test-schedule-002',
			array(
				'success'    => false,
				'duration'   => 0.8,
				'error'      => 'Something went wrong',
				'action_log' => array(),
				'schedule'   => array( 'schedule_type' => 'assistant_run' ),
			)
		);

		remove_all_actions( 'wp_mcp_ai_metric_recorded' );

		$ids = array_column( $recorded, 'id' );

		$this->assertContains( WP_MCP_AI_Pro_Schedule_Metrics::RUN_DURATION_MS, $ids );
		$this->assertContains( WP_MCP_AI_Pro_Schedule_Metrics::RUN_FAILURE_COUNT, $ids );
	}

	/**
	 * A run with duration=0 must NOT record duration_ms (zero-value guard).
	 */
	public function test_zero_duration_skips_duration_metric() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Metric_Collector not available.' );
		}

		$recorded = array();
		add_action(
			'wp_mcp_ai_metric_recorded',
			static function ( $event ) use ( &$recorded ) {
				$recorded[] = $event;
			},
			10,
			1
		);

		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::boot();

		do_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			'test-schedule-003',
			array(
				'success'    => true,
				'duration'   => 0.0,
				'error'      => '',
				'action_log' => array(),
				'schedule'   => array( 'schedule_type' => 'task' ),
			)
		);

		remove_all_actions( 'wp_mcp_ai_metric_recorded' );

		$ids = array_column( $recorded, 'id' );

		$this->assertNotContains(
			WP_MCP_AI_Pro_Schedule_Metrics::RUN_DURATION_MS,
			$ids,
			'Zero duration should not produce a duration_ms event.'
		);
	}

	/**
	 * The schedule_type attribute flows through to the context in
	 * the recorded event.
	 */
	public function test_schedule_type_appears_in_context_attributes() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Metric_Collector not available.' );
		}

		$recorded = array();
		add_action(
			'wp_mcp_ai_metric_recorded',
			static function ( $event ) use ( &$recorded ) {
				$recorded[] = $event;
			},
			10,
			1
		);

		WP_MCP_AI_Pro_Schedule_Otel_Subscriber::boot();

		do_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			'test-schedule-004',
			array(
				'success'    => true,
				'duration'   => 0.3,
				'error'      => '',
				'action_log' => array(),
				'schedule'   => array( 'schedule_type' => 'channel_broadcast' ),
			)
		);

		remove_all_actions( 'wp_mcp_ai_metric_recorded' );

		$duration_event = null;
		foreach ( $recorded as $event ) {
			if ( isset( $event['id'] ) && WP_MCP_AI_Pro_Schedule_Metrics::RUN_DURATION_MS === $event['id'] ) {
				$duration_event = $event;
				break;
			}
		}

		$this->assertNotNull( $duration_event, 'Expected a duration_ms event to be recorded.' );
		$this->assertArrayHasKey( 'context', $duration_event );
		$context = $duration_event['context'];
		$this->assertArrayHasKey( 'attributes', $context );
		$this->assertSame( 'channel_broadcast', $context['attributes']['schedule_type'] );
	}
}
