<?php
/**
 * Tests for the OTel JSON Exporter.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test OTel Exporter.
 */
class Test_WP_MCP_AI_OTel_Exporter extends WP_UnitTestCase {

	/**
	 * Measurement registry under test.
	 *
	 * @var WP_MCP_AI_Measurement_Registry
	 */
	private $registry;

	/**
	 * Metric collector under test.
	 *
	 * @var WP_MCP_AI_Metric_Collector
	 */
	private $collector;

	/**
	 * Set up fresh measurement singletons and metric definitions.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		$this->registry  = WP_MCP_AI_Measurement_Registry::get_instance();
		$this->collector = WP_MCP_AI_Metric_Collector::get_instance();
		$this->collector->clear_buffer();

		$this->registry->register(
			array(
				'id'           => 'model.cost_usd',
				'label'        => 'Model cost',
				'type'         => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'         => 'usd',
				'direction'    => WP_MCP_AI_Measurement_Registry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier' => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'description'  => 'Cost accrued for a model call.',
			)
		);
		$this->registry->register(
			array(
				'id'           => 'verifier.score',
				'label'        => 'Verifier score',
				'type'         => WP_MCP_AI_Measurement_Registry::TYPE_GAUGE,
				'unit'         => 'ratio',
				'direction'    => WP_MCP_AI_Measurement_Registry::DIRECTION_HIGHER_IS_BETTER,
				'privacy_tier' => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'description'  => 'Verifier score 0..1.',
			)
		);
	}

	/**
	 * Clean up measurement state after each test.
	 */
	public function tearDown(): void {
		$this->collector->clear_buffer();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		delete_option( WP_MCP_AI_OTel_Exporter::BUFFER_OPTION );
		remove_all_filters( 'wp_mcp_ai_otel_redact' );
		remove_all_filters( 'wp_mcp_ai_otel_payload' );
		remove_all_actions( 'wp_mcp_ai_otel_payload_ready' );
		parent::tearDown();
	}

	/**
	 * Tests the generated OTLP payload shape.
	 */
	public function test_build_payload_shape() {
		$this->collector->record( 'model.cost_usd', 0.004, array( 'provider' => 'openai' ) );
		$this->collector->record( 'verifier.score', 0.87, array( 'provider' => 'openai' ) );

		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		$payload  = $exporter->build_payload(
			array(
				'service_name'           => 'wp-mcp-ai-test',
				'deployment_environment' => 'ci',
			)
		);

		$this->assertArrayHasKey( 'resourceMetrics', $payload );
		$this->assertCount( 1, $payload['resourceMetrics'] );
		$res_metrics = $payload['resourceMetrics'][0];

		// Resource attributes are flattened to OTLP shape.
		$attrs = $res_metrics['resource']['attributes'];
		$keys  = array_column( $attrs, 'key' );
		$this->assertContains( 'service.name', $keys );
		$this->assertContains( 'deployment.environment', $keys );

		// Metric names and kinds map correctly.
		$metrics = $res_metrics['scopeMetrics'][0]['metrics'];
		$by_name = array_column( $metrics, null, 'name' );
		$this->assertArrayHasKey( 'model.cost_usd', $by_name );
		$this->assertArrayHasKey( 'verifier.score', $by_name );
		$this->assertArrayHasKey( 'sum', $by_name['model.cost_usd'] );
		$this->assertArrayHasKey( 'gauge', $by_name['verifier.score'] );

		// Data points carry the double value.
		$dp = $by_name['model.cost_usd']['sum']['dataPoints'][0];
		$this->assertEqualsWithDelta( 0.004, $dp['asDouble'], 0.0001 );
	}

	/**
	 * Tests that the redaction filter can drop events.
	 */
	public function test_redact_filter_can_drop_events() {
		$this->collector->record( 'model.cost_usd', 0.01 );
		$this->collector->record( 'verifier.score', 0.5 );

		add_filter(
			'wp_mcp_ai_otel_redact',
			static function ( $event ) {
				return 'verifier.score' === $event['id'] ? null : $event;
			}
		);

		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		$payload  = $exporter->build_payload();
		$metrics  = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
		$names    = array_column( $metrics, 'name' );
		$this->assertContains( 'model.cost_usd', $names );
		$this->assertNotContains( 'verifier.score', $names );
	}

	/**
	 * Tests that the final payload filter is applied.
	 */
	public function test_payload_filter_is_applied() {
		$this->collector->record( 'model.cost_usd', 0.01 );
		add_filter(
			'wp_mcp_ai_otel_payload',
			static function ( $p ) {
				$p['injected'] = true;
				return $p;
			}
		);
		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		$payload  = $exporter->build_payload();
		$this->assertTrue( $payload['injected'] );
	}

	/**
	 * Tests dispatch hooks and rolling-buffer persistence.
	 */
	public function test_dispatch_fires_ready_action_and_appends_rolling_buffer() {
		$this->collector->record( 'model.cost_usd', 0.02 );
		$fired = 0;
		add_action(
			'wp_mcp_ai_otel_payload_ready',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		$exporter->dispatch();

		$this->assertSame( 1, $fired );
		$buf = $exporter->read_rolling_buffer();
		$this->assertCount( 1, $buf );
		$this->assertSame( 'model.cost_usd', $buf[0]['id'] );
	}

	/**
	 * Tests that the rolling buffer respects its size cap.
	 */
	public function test_rolling_buffer_is_size_capped() {
		add_filter(
			'wp_mcp_ai_otel_buffer_max',
			static function () {
				return 3;
			}
		);
		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		for ( $i = 0; $i < 7; $i++ ) {
			$exporter->append_rolling_buffer(
				array(
					array(
						'id'    => 'model.cost_usd',
						'value' => $i + 1,
					),
				)
			);
		}
		$buf = $exporter->read_rolling_buffer();
		$this->assertCount( 3, $buf );
		// We kept the LAST three writes.
		$values = array_column( $buf, 'value' );
		$this->assertSame( array( 5.0, 6.0, 7.0 ), array_map( 'floatval', $values ) );
	}

	/**
	 * Tests clearing the rolling buffer.
	 */
	public function test_clear_rolling_buffer() {
		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		$exporter->append_rolling_buffer(
			array(
				array(
					'id'    => 'model.cost_usd',
					'value' => 1.0,
				),
			)
		);
		$this->assertCount( 1, $exporter->read_rolling_buffer() );
		$exporter->clear_rolling_buffer();
		$this->assertCount( 0, $exporter->read_rolling_buffer() );
	}

	/**
	 * Tests that malformed events are skipped during serialization.
	 */
	public function test_invalid_events_are_skipped() {
		$exporter = new WP_MCP_AI_OTel_Exporter( $this->collector, $this->registry );
		$payload  = $exporter->serialize_events(
			array(
				array(
					'id'    => 'model.cost_usd',
					'value' => 0.01,
				),
				array( 'not' => 'an event' ),
				'totally bogus',
			)
		);
		$metrics  = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
		$this->assertCount( 1, $metrics );
		$this->assertSame( 'model.cost_usd', $metrics[0]['name'] );
	}
}
