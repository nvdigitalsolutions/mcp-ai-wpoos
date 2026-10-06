<?php
/**
 * Tests for the fleet status tools (get_fleet_status / get_site_uptime).
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the fleet status tooling.
 */
class Test_Fleet_Status_Tools extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-worker-config.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-fleet-status.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-uptime.php';
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		delete_option( 'wp_mcp_ai_media_worker_url' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_media_worker_url' );
	}

	/**
	 * A subscriber cannot execute the fleet status tool.
	 */
	public function test_fleet_status_capability_gate() {
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$tool   = new WP_MCP_AI_Tool_Get_Fleet_Status();
		$result = $tool->execute( array(), array( 'user_id' => $sub_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * An unconfigured worker yields a descriptive WP_Error (canonical envelope).
	 */
	public function test_fleet_status_unconfigured_returns_error() {
		$tool   = new WP_MCP_AI_Tool_Get_Fleet_Status();
		$result = $tool->execute( array(), array( 'user_id' => $this->editor_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_worker_not_configured', $result->get_error_code() );
	}

	/**
	 * The fleet summary is sanitized and filtered by slug when requested.
	 */
	public function test_fleet_status_returns_sanitized_summary() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$summary = array(
			'overall_status' => 'partial_outage',
			'sites'          => array(
				array(
					'slug'              => 'site-a',
					'status'            => 'operational',
					'message'           => '<script>alert(1)</script>fine',
					'site_url'          => 'https://site-a.example.com',
					'heartbeat_age_s'   => 42,
					'latency_ms'        => 120,
					'last_heartbeat_at' => time(),
				),
				array(
					'slug'              => 'site-b',
					'status'            => 'major_outage',
					'message'           => 'down',
					'site_url'          => 'https://site-b.example.com',
					'heartbeat_age_s'   => 900,
					'latency_ms'        => null,
					'last_heartbeat_at' => time() - 900,
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

		$tool = new WP_MCP_AI_Tool_Get_Fleet_Status();

		// Full summary.
		$result = $tool->execute( array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 'partial_outage', $result['overall_status'] );
		$this->assertCount( 2, $result['sites'] );
		$this->assertStringNotContainsString( '<script>', $result['sites'][0]['message'] );

		// Slug filter.
		$filtered = $tool->execute( array( 'slug' => 'site-b' ), array( 'user_id' => $this->editor_id ) );
		$this->assertCount( 1, $filtered['sites'] );
		$this->assertSame( 'site-b', $filtered['sites'][0]['slug'] );
		$this->assertSame( 'major_outage', $filtered['sites'][0]['status'] );
	}

	/**
	 * Get_site_uptime requires a slug and sanitizes the history payload.
	 */
	public function test_site_uptime_requires_slug_and_sanitizes() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		$tool = new WP_MCP_AI_Tool_Get_Site_Uptime();

		// Missing slug → WP_Error (canonical envelope, never a success array).
		$missing = $tool->execute( array(), array( 'user_id' => $this->editor_id ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_slug', $missing->get_error_code() );

		$history = array(
			'slug'           => 'site-a',
			'days'           => 7,
			'overall_uptime' => 99.5,
			'history'        => array(
				'2026-09-25' => 100,
				'2026-09-26' => 95.5,
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $history ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $history ),
				);
			},
			10,
			3
		);

		$result = $tool->execute(
			array(
				'slug' => 'site-a',
				'days' => 7,
			),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotWPError( $result );
		$this->assertSame( 'site-a', $result['slug'] );
		$this->assertSame( 7, $result['days'] );
		$this->assertSame( 99.5, $result['overall_uptime'] );
		$this->assertArrayHasKey( '2026-09-25', $result['history'] );
		$this->assertStringContainsString( 'site-a', $result['message'] );
	}

	/**
	 * Days is clamped to the 1–90 contract.
	 */
	public function test_site_uptime_clamps_days() {
		update_option( 'wp_mcp_ai_media_worker_url', 'http://media-worker:3100' );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->assertStringContainsString( 'days=90', $url );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'slug'           => 'site-a',
							'days'           => 90,
							'overall_uptime' => 99.0,
							'history'        => array(),
						)
					),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Get_Site_Uptime();
		$result = $tool->execute(
			array(
				'slug' => 'site-a',
				'days' => 999,
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 90, $result['days'] );
	}
}
