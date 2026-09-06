<?php
/**
 * Markup telemetry/UI port tests (Wave E6, sub-cluster 2).
 *
 * Characterization suite for the ported `MarkupTelemetry` recorder,
 * the `MarkupStatsSlashCommand` report surface, the
 * `MarkupTelemetryAdminPage` dashboard, and the `MarkupAssets`
 * registration: byte-identical option key, outcome buckets, bounded
 * per-tool/per-mode breakdowns, lifecycle handlers, the reset gates,
 * the Markdown report shapes, the admin render, and the asset
 * handles. Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\Markup\MarkupAssets;
use NvoosContentGraphAi\Engine\Markup\MarkupRequest;
use NvoosContentGraphAi\Engine\Markup\MarkupStatsSlashCommand;
use NvoosContentGraphAi\Engine\Markup\MarkupTelemetry;
use NvoosContentGraphAi\Engine\Markup\MarkupTelemetryAdminPage;

/**
 * @group markup
 */
class Test_Markup_Telemetry_Ui extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( MarkupTelemetry::OPTION_NAME );
		\wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		\delete_option( MarkupTelemetry::OPTION_NAME );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Build a minimal markup request.
	 *
	 * @param string $tool Tool slug.
	 * @param string $mode Mode.
	 * @return MarkupRequest
	 */
	private function make_request( string $tool = 'image_inpainting', string $mode = 'mask' ): MarkupRequest {
		return new MarkupRequest(
			array(
				'tool_slug'   => $tool,
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => $mode,
				'user_id'     => 0,
			)
		);
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	// ─── Telemetry ────────────────────────────────────────────────

	public function test_default_summary_shape(): void {
		$summary = MarkupTelemetry::default_summary();

		foreach ( MarkupTelemetry::outcomes() as $outcome ) {
			$this->assertArrayHasKey( $outcome, $summary['counts'] );
			$this->assertSame( 0, $summary['counts'][ $outcome ] );
		}
		$this->assertSame( array(), $summary['last_seen'] );
		$this->assertSame( array(), $summary['tools'] );
		$this->assertSame( array(), $summary['modes'] );
	}

	public function test_request_created_increments_counter(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request() );

		$summary = MarkupTelemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['created'] );
		$this->assertArrayHasKey( 'created', $summary['last_seen'] );
	}

	public function test_resolved_completed_bumps_completed(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_resolved( $this->make_request(), 'completed' );

		$this->assertSame( 1, MarkupTelemetry::get_summary()['counts']['completed'] );
	}

	public function test_resolved_negative_outcomes_map_correctly(): void {
		$telemetry = new MarkupTelemetry();

		$telemetry->on_resolved( $this->make_request(), 'invalid' );
		$telemetry->on_resolved( $this->make_request(), 'tool_error' );
		$telemetry->on_resolved( $this->make_request(), 'cancelled' );

		$summary = MarkupTelemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['invalid'] );
		$this->assertSame( 1, $summary['counts']['tool_error'] );
		$this->assertSame( 1, $summary['counts']['cancelled'] );
	}

	public function test_unknown_resolution_status_is_ignored(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_resolved( $this->make_request(), 'exploded' );

		$summary = MarkupTelemetry::get_summary();
		foreach ( MarkupTelemetry::outcomes() as $outcome ) {
			$this->assertSame( 0, $summary['counts'][ $outcome ] );
		}
	}

	public function test_submitted_and_validated_are_separate_buckets(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_submitted( $this->make_request() );
		$telemetry->on_validated( $this->make_request() );

		$summary = MarkupTelemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['submitted'] );
		$this->assertSame( 1, $summary['counts']['validated'] );
	}

	public function test_per_tool_breakdown_accumulates(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request( 'tool_a' ) );
		$telemetry->on_resolved( $this->make_request( 'tool_a' ), 'completed' );
		$telemetry->on_request_created( $this->make_request( 'tool_b' ) );

		$summary = MarkupTelemetry::get_summary();
		$this->assertSame( 1, $summary['tools']['tool_a']['created'] );
		$this->assertSame( 1, $summary['tools']['tool_a']['completed'] );
		$this->assertSame( 1, $summary['tools']['tool_b']['created'] );
		$this->assertSame( 'mask', \array_key_first( $summary['modes'] ) );
	}

	public function test_non_request_payload_does_not_explode(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( 'not-a-request' );

		$summary = MarkupTelemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['created'] );
		$this->assertSame( array(), $summary['tools'] );
		$this->assertSame( array(), $summary['modes'] );
	}

	public function test_reset_clears_counters(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request() );

		MarkupTelemetry::reset();
		$this->assertSame( 0, MarkupTelemetry::get_summary()['counts']['created'] );
	}

	// ─── Slash command ────────────────────────────────────────────

	public function test_empty_state_message(): void {
		$command = new MarkupStatsSlashCommand();
		$result  = $command->execute( array(), array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '## Markup Telemetry', $result['message'] );
		$this->assertStringContainsString( '_No markup events have been recorded yet._', $result['message'] );
	}

	public function test_populated_report_lists_top_tools(): void {
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request( 'tool_a' ) );
		$telemetry->on_resolved( $this->make_request( 'tool_a' ), 'completed' );

		$command = new MarkupStatsSlashCommand();
		$result  = $command->execute( array(), array(), array() );

		$this->assertStringContainsString( '| Requests created | 1 |', $result['message'] );
		$this->assertStringContainsString( 'tool_a', $result['message'] );
		$this->assertStringContainsString( 'By tool', $result['message'] );
	}

	public function test_json_flag_returns_raw_summary(): void {
		$command = new MarkupStatsSlashCommand();
		$result  = $command->execute( array(), array( 'json' => true ), array() );

		$this->assertTrue( $result['success'] );
		$decoded = \json_decode( $result['message'], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'counts', $decoded );
	}

	public function test_reset_requires_manage_options(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$command = new MarkupStatsSlashCommand();
		$result  = $command->execute( array(), array( 'reset' => true ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_reset_clears_counters_for_admin(): void {
		$this->admin_user();
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request() );

		$command = new MarkupStatsSlashCommand();
		$result  = $command->execute( array(), array( 'reset' => true ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, MarkupTelemetry::get_summary()['counts']['created'] );
	}

	// ─── Admin telemetry page ─────────────────────────────────────

	public function test_empty_state_renders_outcomes_table_and_no_data_copy(): void {
		$this->admin_user();
		$page = new MarkupTelemetryAdminPage();

		\ob_start();
		$page->render_page();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'NV oOS Markup Telemetry', $output );
		$this->assertStringContainsString( 'Outcomes', $output );
		$this->assertStringContainsString( 'No data yet.', $output );
	}

	public function test_populated_render_shows_counts_and_breakdowns(): void {
		$this->admin_user();
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request( 'tool_a' ) );
		$telemetry->on_resolved( $this->make_request( 'tool_a' ), 'completed' );

		$page = new MarkupTelemetryAdminPage();

		\ob_start();
		$page->render_page();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'tool_a', $output );
		$this->assertStringContainsString( 'By tool', $output );
		$this->assertStringContainsString( 'Maintenance', $output );
	}

	public function test_reset_handler_blocks_non_admins(): void {
		$page = new MarkupTelemetryAdminPage();

		$this->expectException( \WPDieException::class );
		$page->handle_reset();
	}

	public function test_reset_handler_clears_counters_for_admin(): void {
		$this->admin_user();
		$telemetry = new MarkupTelemetry();
		$telemetry->on_request_created( $this->make_request() );

		$_REQUEST['_wpnonce'] = \wp_create_nonce( MarkupTelemetryAdminPage::RESET_ACTION );

		\add_filter( 'wp_redirect', '__return_false' );
		$page = new MarkupTelemetryAdminPage();
		try {
			$page->handle_reset();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Expected under the test redirect interception contract.
		}
		\remove_filter( 'wp_redirect', '__return_false' );

		unset( $_REQUEST['_wpnonce'] );

		$this->assertSame( 0, MarkupTelemetry::get_summary()['counts']['created'] );
	}

	// ─── Assets ───────────────────────────────────────────────────

	public function test_assets_register_idempotent_with_byte_identical_handles(): void {
		MarkupAssets::register();
		MarkupAssets::register();

		$this->assertTrue( \wp_script_is( MarkupAssets::HANDLE_KONVA, 'registered' ) );
		$this->assertTrue( \wp_script_is( MarkupAssets::HANDLE_EXPORT, 'registered' ) );
		$this->assertTrue( \wp_script_is( MarkupAssets::HANDLE_FALLBACK, 'registered' ) );
		$this->assertTrue( \wp_script_is( MarkupAssets::HANDLE_WIDGET, 'registered' ) );
		$this->assertTrue( \wp_script_is( MarkupAssets::HANDLE_CLIENT, 'registered' ) );
		$this->assertTrue( \wp_style_is( MarkupAssets::HANDLE_STYLE, 'registered' ) );

		$this->assertSame( 'wp-mcp-ai-konva', MarkupAssets::HANDLE_KONVA );
		$this->assertSame( 'wp-mcp-ai-markup-widget', MarkupAssets::HANDLE_WIDGET );
		$this->assertSame( '9.3.16', MarkupAssets::KONVA_VERSION );
	}
}
