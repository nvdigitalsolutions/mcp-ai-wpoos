<?php
/**
 * Tests for WP_MCP_AI_Service_Status_Remote_Monitor_Source.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the fleet monitor status source.
 */
class Test_Status_Remote_Monitor_Source extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-service-status-source.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-worker-config.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-remote-monitor-source.php';
		delete_option( 'wp_mcp_ai_media_worker_url' );
		delete_transient( 'wp_mcp_ai_remote_monitor_last_status' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_media_worker_url' );
		delete_transient( 'wp_mcp_ai_remote_monitor_last_status' );
	}

	/**
	 * The source conforms to the status source contract.
	 */
	public function test_source_contract() {
		$source = new WP_MCP_AI_Service_Status_Remote_Monitor_Source();
		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Service_Status_Source', $source );
		$this->assertSame( 'remote_monitor', $source->get_slug() );
		$this->assertSame( 'remote_sites', $source->get_group() );
		$this->assertTrue( $source->is_public() );
		$this->assertNotEmpty( $source->get_name() );
	}

	/**
	 * An unconfigured worker yields degraded_performance — never a throw.
	 */
	public function test_check_health_unconfigured_is_degraded() {
		$source = new WP_MCP_AI_Service_Status_Remote_Monitor_Source();
		$result = $source->check_health();

		$this->assertIsArray( $result );
		$this->assertSame( 'degraded_performance', $result['status'] );
		$this->assertArrayHasKey( 'checked_at', $result );
	}

	/**
	 * An unreachable worker yields degraded_performance with the error text.
	 */
	public function test_check_health_unreachable_is_degraded() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection refused' );
			},
			10,
			3
		);

		$source = new WP_MCP_AI_Service_Status_Remote_Monitor_Source();
		$result = $source->check_health();

		$this->assertSame( 'degraded_performance', $result['status'] );
		$this->assertStringContainsString( 'unreachable', strtolower( $result['message'] ) );
	}

	/**
	 * The source reports this site's fleet-visible state from the summary.
	 */
	public function test_check_health_reports_own_entry() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$summary = array(
			'overall_status' => 'partial_outage',
			'sites'          => array(
				array(
					'slug'              => 'site-a',
					'status'            => 'operational',
					'site_url'          => home_url(),
					'last_heartbeat_at' => time(),
				),
				array(
					'slug'              => 'site-b',
					'status'            => 'major_outage',
					'site_url'          => 'https://other.example.com',
					'last_heartbeat_at' => time(),
				),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $summary ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $summary ),
				);
			},
			10,
			3
		);

		$source = new WP_MCP_AI_Service_Status_Remote_Monitor_Source();
		$result = $source->check_health();

		// site-a matches home_url() — the site's own state, not the overall.
		$this->assertSame( 'operational', $result['status'] );
		$this->assertStringContainsString( 'site-a', $result['message'] );
	}

	/**
	 * Without a matching entry, the fleet overall is reported.
	 */
	public function test_check_health_falls_back_to_overall() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$summary = array(
			'overall_status' => 'major_outage',
			'sites'          => array(
				array(
					'slug'              => 'site-b',
					'status'            => 'major_outage',
					'site_url'          => 'https://other.example.com',
					'last_heartbeat_at' => time(),
				),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $summary ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $summary ),
				);
			},
			10,
			3
		);

		$source = new WP_MCP_AI_Service_Status_Remote_Monitor_Source();
		$result = $source->check_health();

		$this->assertSame( 'major_outage', $result['status'] );
	}

	/**
	 * The worker's internal at_risk state normalizes into the public taxonomy.
	 */
	public function test_at_risk_normalizes_to_degraded() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$summary = array(
			'overall_status' => 'at_risk',
			'sites'          => array(),
		);

		add_filter(
			'pre_http_request',
			function () use ( $summary ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $summary ),
				);
			},
			10,
			3
		);

		$source = new WP_MCP_AI_Service_Status_Remote_Monitor_Source();
		$result = $source->check_health();

		$this->assertSame( 'degraded_performance', $result['status'] );
	}
}
