<?php
/**
 * Tests for the Metric Collector.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Metric Collector.
 */
class Test_WP_MCP_AI_Metric_Collector extends WP_UnitTestCase {

	/**
	 * Collector.
	 *
	 * @var WP_MCP_AI_Metric_Collector
	 */
	private $collector;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();

		$registry = WP_MCP_AI_Measurement_Registry::get_instance();
		$registry->register(
			array(
				'id'    => 'test.metric',
				'label' => 'Test',
				'type'  => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'  => 'count',
			)
		);

		$this->collector = WP_MCP_AI_Metric_Collector::get_instance();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Known metric is recorded and buffered.
	 */
	public function test_record_known_metric() {
		$ok = $this->collector->record( 'test.metric', 1, array( 'tool' => 'ping' ) );
		$this->assertTrue( $ok );
		$buf = $this->collector->buffered();
		$this->assertCount( 1, $buf );
		$this->assertSame( 'test.metric', $buf[0]['id'] );
		$this->assertSame( 1.0, $buf[0]['value'] );
		$this->assertSame( 'ping', $buf[0]['context']['tool'] );
	}

	/**
	 * Unknown metrics are rejected.
	 */
	public function test_record_unknown_metric_rejected() {
		$this->assertFalse( $this->collector->record( 'does.not.exist', 1 ) );
		$this->assertCount( 0, $this->collector->buffered() );
	}

	/**
	 * Non-numeric values are rejected.
	 */
	public function test_record_rejects_non_numeric() {
		$this->assertFalse( $this->collector->record( 'test.metric', 'abc' ) );
		$this->assertFalse( $this->collector->record( 'test.metric', array() ) );
	}

	/**
	 * Ring buffer honors configured size.
	 */
	public function test_ring_buffer_eviction() {
		$this->collector->set_buffer_size( 3 );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->collector->record( 'test.metric', $i );
		}
		$buf = $this->collector->buffered();
		$this->assertCount( 3, $buf );
		// Oldest entries were evicted; values 3,4,5 remain.
		$values = array_map(
			static function ( $event ) {
				return $event['value'];
			},
			$buf
		);
		$this->assertSame( array( 3.0, 4.0, 5.0 ), $values );
	}

	/**
	 * The metric_recorded action fires for each successful record.
	 */
	public function test_metric_recorded_action_fires() {
		$fired = 0;
		add_action(
			'wp_mcp_ai_metric_recorded',
			function ( $event, $definition, $collector ) use ( &$fired ) {
				++$fired;
				$this->assertSame( 'test.metric', $event['id'] );
				$this->assertIsArray( $definition );
				$this->assertInstanceOf( 'WP_MCP_AI_Metric_Collector', $collector );
			},
			10,
			3
		);
		$this->collector->record( 'test.metric', 2 );
		$this->collector->record( 'test.metric', 3 );
		$this->assertSame( 2, $fired );
	}

	/**
	 * Sample rate of 0 drops all records.
	 */
	public function test_sample_rate_zero_drops() {
		$this->collector->set_metric_sample_rate( 'test.metric', 0.0 );
		$this->assertFalse( $this->collector->record( 'test.metric', 1 ) );
		$this->assertCount( 0, $this->collector->buffered() );
	}

	/**
	 * Export runs through the measurement_export filter.
	 */
	public function test_export_filter_runs() {
		$this->collector->record( 'test.metric', 42 );
		add_filter(
			'wp_mcp_ai_measurement_export',
			function ( $payload, $destination ) {
				$this->assertSame( 'otel', $destination );
				foreach ( $payload as &$event ) {
					$event['redacted'] = true;
				}
				return $payload;
			},
			10,
			2
		);
		$out = $this->collector->export( 'otel' );
		$this->assertTrue( $out[0]['redacted'] );
	}

	/**
	 * Context sanitization strips unexpected data types.
	 */
	public function test_context_sanitization() {
		$this->collector->record(
			'test.metric',
			1,
			array(
				'assistant_id' => '42abc',
				'tool'         => '<script>alert(1)</script>ping',
				'attributes'   => array(
					'ok'   => 'value',
					12345  => 'numeric-key-preserved',
					'bad'  => array( 'nested-not-allowed' ),
				),
			)
		);
		$event = $this->collector->buffered()[0];
		$this->assertSame( 42, $event['context']['assistant_id'] );
		$this->assertSame( 'ping', $event['context']['tool'] );
		$this->assertArrayHasKey( 'ok', $event['context']['attributes'] );
		// Numeric keys are coerced to string and kept by sanitize_key.
		$this->assertArrayHasKey( '12345', $event['context']['attributes'] );
		// Nested arrays are skipped because only scalar values are allowed.
		$this->assertArrayNotHasKey( 'bad', $event['context']['attributes'] );
	}
}
