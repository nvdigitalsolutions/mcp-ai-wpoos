<?php
/**
 * Tests for the OTLP trace exporter (Proposal 091, Track E).
 *
 * @package WP_MCP_AI
 */

/**
 * OTLP exporter test case.
 */
class Test_OTLP_Exporter extends WP_UnitTestCase {

	/**
	 * Captured wp_remote_post requests.
	 *
	 * @var array[]
	 */
	private static $requests = array();

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_OTLP_Exporter::reset();
		self::$requests = array();
		delete_option( WP_MCP_AI_OTLP_Exporter::OPTION_ENDPOINT );
		remove_all_filters( 'wp_mcp_ai_otlp_endpoint' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * The exporter is off by default (decision D4).
	 *
	 * @return void
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( WP_MCP_AI_OTLP_Exporter::is_active() );
		$this->assertNull( WP_MCP_AI_OTLP_Exporter::flush() );
	}

	/**
	 * Endpoint validation rejects non-http(s) schemes and garbage.
	 *
	 * @return void
	 */
	public function test_endpoint_validation() {
		$this->assertTrue( WP_MCP_AI_OTLP_Exporter::validate_endpoint( 'https://otel.example.com/v1/traces' ) );
		$this->assertTrue( WP_MCP_AI_OTLP_Exporter::validate_endpoint( 'http://localhost:4318/v1/traces' ) );
		$this->assertFalse( WP_MCP_AI_OTLP_Exporter::validate_endpoint( 'file:///etc/passwd' ) );
		$this->assertFalse( WP_MCP_AI_OTLP_Exporter::validate_endpoint( 'not a url' ) );
	}

	/**
	 * The OTLP batch shape matches the spec (resourceSpans → scopeSpans).
	 *
	 * @return void
	 */
	public function test_batch_shape() {
		$batch = WP_MCP_AI_OTLP_Exporter::build_batch(
			array(
				array(
					'gen_ai.operation.name'     => 'chat',
					'gen_ai.usage.input_tokens' => 12,
					'nvoos.timestamp'           => 1700000000,
				),
			)
		);

		$span = $batch['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
		$this->assertSame( 'chat', $span['name'] );
		$this->assertSame( '1700000000000000000', $span['startTimeUnixNano'] );
		$this->assertSame( 'nvoos', $batch['resourceSpans'][0]['scopeSpans'][0]['scope']['name'] );

		$keys = wp_list_pluck( $span['attributes'], 'key' );
		$this->assertContains( 'gen_ai.operation.name', $keys );
	}

	/**
	 * With an endpoint configured, enqueued spans flush as one POST.
	 *
	 * @return void
	 */
	public function test_flush_sends_single_batch() {
		update_option( WP_MCP_AI_OTLP_Exporter::OPTION_ENDPOINT, 'https://otel.example.com/v1/traces' );
		WP_MCP_AI_OTLP_Exporter::init();

		$this->assertTrue( WP_MCP_AI_OTLP_Exporter::is_active() );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				self::$requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				);
			},
			10,
			3
		);

		WP_MCP_AI_OTLP_Exporter::enqueue(
			array(
				'gen_ai.operation.name' => 'chat',
				'gen_ai.request.model'  => 'test-model',
			)
		);

		$result = WP_MCP_AI_OTLP_Exporter::flush();

		$this->assertCount( 1, self::$requests );
		$this->assertSame( 'https://otel.example.com/v1/traces', self::$requests[0]['url'] );
		$this->assertStringContainsString( 'resourceSpans', self::$requests[0]['args']['body'] );
		$this->assertStringContainsString( 'test-model', self::$requests[0]['args']['body'] );
		$this->assertIsArray( $result );

		// Second flush with an empty buffer is a no-op.
		$this->assertNull( WP_MCP_AI_OTLP_Exporter::flush() );
	}
}
