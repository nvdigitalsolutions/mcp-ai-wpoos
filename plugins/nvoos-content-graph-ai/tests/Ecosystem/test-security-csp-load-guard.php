<?php
/**
 * CSP headers + load guard port tests (Wave D4j/D4k).
 *
 * Characterization suite for `CspHeaders` and `LoadGuard`. Assertions
 * mirror the base plugin: default CSP directives, report-only switching,
 * directive filtering (via a capturing subclass), plugin-route scoping,
 * 429 backpressure envelopes, the running-async counter lifecycle, and
 * the maximum-concurrency seam per install mode.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\CspHeaders;
use NvoosContentGraphAi\Security\LoadGuard;

/**
 * Capturing CSP subclass that forces the admin context.
 */
class Testable_Csp_Headers extends CspHeaders {

	/**
	 * Captured (name, value) pairs.
	 *
	 * @var array
	 */
	public static $sent = array();

	protected static function is_admin_context() {
		return true;
	}

	protected static function send_header( $name, $value ): void {
		self::$sent[] = array( $name, $value );
	}
}

/**
 * @group security
 */
class Test_Csp_Load_Guard extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		Testable_Csp_Headers::$sent = array();

		\remove_all_actions( 'wp_mcp_ai_sse_stream_started' );

		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_csp_directives' );
		\remove_all_filters( 'wp_mcp_ai_csp_report_only' );
		\remove_all_filters( 'wp_mcp_ai_load_guard_max_concurrent' );

		\delete_transient( LoadGuard::RUNNING_COUNT_KEY );

		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_job_queue';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test teardown for monolith support table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		parent::tearDown();
	}

	// ─── CSP headers ─────────────────────────────────────────────────

	public function test_csp_default_directives_are_emitted(): void {
		Testable_Csp_Headers::emit_csp_headers();

		$this->assertCount( 1, Testable_Csp_Headers::$sent );
		$this->assertSame( 'Content-Security-Policy', Testable_Csp_Headers::$sent[0][0] );

		$value = Testable_Csp_Headers::$sent[0][1];
		$this->assertStringContainsString( "default-src 'self'", $value );
		$this->assertStringContainsString( "script-src 'self' 'unsafe-inline' 'unsafe-eval'", $value );
		$this->assertStringContainsString( "connect-src 'self' https://api.openai.com", $value );
		$this->assertStringContainsString( "object-src 'none'", $value );
	}

	public function test_csp_report_only_mode_switches_header_name(): void {
		\add_filter( 'wp_mcp_ai_csp_report_only', '__return_true' );

		Testable_Csp_Headers::emit_csp_headers();

		$this->assertSame( 'Content-Security-Policy-Report-Only', Testable_Csp_Headers::$sent[0][0] );
	}

	public function test_csp_directives_filter_is_honoured(): void {
		\add_filter(
			'wp_mcp_ai_csp_directives',
			static function () {
				return array( "default-src 'none'" );
			}
		);

		Testable_Csp_Headers::emit_csp_headers();

		$this->assertSame( "default-src 'none'", Testable_Csp_Headers::$sent[0][1] );
	}

	// ─── Load guard ──────────────────────────────────────────────────

	public function test_load_guard_constants(): void {
		$this->assertSame( 'wp_mcp_ai_load_guard_running_async', LoadGuard::RUNNING_COUNT_KEY );
		$this->assertSame( 10, LoadGuard::DEFAULT_MAX_CONCURRENT );
	}

	public function test_non_plugin_routes_pass_through(): void {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\set_transient( LoadGuard::RUNNING_COUNT_KEY, 999 );

		$this->assertNull( LoadGuard::check_load( null, null, $request ) );
	}

	public function test_at_capacity_returns_429_envelope(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base Resource Manager defaults to 2 when
			// wp_mcp_ai_settings is unset; create the async job queue
			// table so get_queue_stats() returns zeroes deterministically.
			global $wpdb;
			$table = $wpdb->prefix . 'mcp_ai_job_queue';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				"CREATE TABLE {$table} (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					job_type VARCHAR(50) NOT NULL,
					job_data LONGTEXT NOT NULL,
					status VARCHAR(20) NOT NULL DEFAULT 'queued',
					created_at DATETIME NOT NULL,
					PRIMARY KEY (id)
				) DEFAULT CHARSET=utf8mb4"
			);

			\set_transient( LoadGuard::RUNNING_COUNT_KEY, 2 );
			$expected_max = 2;
		} else {
			\add_filter( 'wp_mcp_ai_load_guard_max_concurrent', static function () {
				return 3;
			} );
			\set_transient( LoadGuard::RUNNING_COUNT_KEY, 3 );
			$expected_max = 3;
		}

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$result  = LoadGuard::check_load( null, null, $request );

		$this->assertWPError( $result );
		$this->assertSame( 'system_overloaded', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 30, $data['retry_after'] );
		$this->assertSame( $expected_max, $data['active_jobs'] );
		$this->assertSame( $expected_max, $data['max_jobs'] );
	}

	public function test_under_capacity_passes_through(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: Resource Manager default is 2.
			\set_transient( LoadGuard::RUNNING_COUNT_KEY, 1 );
		} else {
			\add_filter( 'wp_mcp_ai_load_guard_max_concurrent', static function () {
				return 3;
			} );
			\set_transient( LoadGuard::RUNNING_COUNT_KEY, 2 );
		}

		$request = new \WP_REST_Request( 'POST', '/nvoos-content-graph/v1/graph' );
		$this->assertNull( LoadGuard::check_load( null, null, $request ) );
	}

	public function test_running_async_counter_lifecycle(): void {
		LoadGuard::increment_running_async();
		LoadGuard::increment_running_async();
		$this->assertSame( 2, (int) \get_transient( LoadGuard::RUNNING_COUNT_KEY ) );

		LoadGuard::decrement_running_async();
		$this->assertSame( 1, (int) \get_transient( LoadGuard::RUNNING_COUNT_KEY ) );

		LoadGuard::decrement_running_async();
		$this->assertFalse( \get_transient( LoadGuard::RUNNING_COUNT_KEY ) );

		// Decrementing below zero stays at zero.
		LoadGuard::decrement_running_async();
		$this->assertFalse( \get_transient( LoadGuard::RUNNING_COUNT_KEY ) );
	}
}
