<?php
/**
 * Run Timeline dashboard ported-class tests (Wave E-UI-1, sub-cluster 4).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Run_Timeline` preserves the public behaviour: the
 * byte-identical constants, page slug, nonce and AJAX action names,
 * the standalone-only menu registration under the NV Platform menu,
 * the per-mode collaborator seams (metric event store, reasoning
 * trace class, OTel probe, observability settings link), the render
 * surface (sidebar + detail + OTel notice), the assistant filter
 * options, the post-meta fallback summaries (run_id/started_at/trace
 * hydration), the summary envelope pagination math, the run detail
 * loader (meta trace + the detail filter), the AJAX nonce/capability/
 * envelope gates, and the per-mode asset enqueues. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Dashboards\RunTimelineDashboard;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the dashboard's protected statics and privates
 * are published as public wrappers.
 */
class RunTimelineDashboardExposer extends RunTimelineDashboard {

	public static function exposed_metric_store() {
		return self::metric_event_store();
	}

	public static function exposed_trace_class() {
		return self::reasoning_trace_class();
	}

	public static function exposed_trace_meta_key() {
		$class = self::reasoning_trace_class();
		return null === $class ? null : $class::META_KEY;
	}

	public static function exposed_otel(): bool {
		return self::otel_enabled();
	}

	public static function exposed_settings_url(): string {
		return self::observability_settings_url();
	}

	public static function exposed_summaries( $page = 1, $assistant_id = 0 ): array {
		$dashboard = new self();
		return $dashboard->load_run_summaries( $page, $assistant_id );
	}

	public static function exposed_meta_summaries( $assistant_id = 0 ): array {
		$dashboard = new self();
		return $dashboard->load_summaries_from_post_meta( $assistant_id );
	}

	public static function exposed_run_detail( $run_id ) {
		$dashboard = new self();
		return $dashboard->load_run_detail( $run_id );
	}

	public function exposed_render(): string {
		\ob_start();
		try {
			$this->render_page();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}
}

/**
 * Run Timeline dashboard characterisation suite (Wave E-UI-1, sub-cluster 4).
 */
#[\PHPUnit\Framework\Attributes\Group( 'dashboards' )]
class Test_Run_Timeline_Dashboard extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['page'], $_GET['assistant_id'], $_GET['run_id'], $_POST['action'], $_REQUEST['action'] );

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['page'], $_GET['assistant_id'], $_GET['run_id'], $_POST['action'], $_REQUEST['action'] );
		parent::tearDown();
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

	/**
	 * Count the registered callbacks on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function count_callbacks( string $hook ): int {
		global $wp_filter;
		return isset( $wp_filter[ $hook ] ) ? \count( $wp_filter[ $hook ]->callbacks ) : 0;
	}

	/**
	 * Invoke an AJAX handler and capture its echoed wp_die payload.
	 *
	 * @param callable $callback AJAX handler invocation.
	 * @return string Echoed payload (the WPDieException is swallowed).
	 */
	private function capture_ajax_call( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			unset( $e ); // Expected — the JSON payload was echoed before the die.
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Craft a valid nonce into the superglobals for the success paths.
	 *
	 * @return void
	 */
	private function set_nonce(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: craft a valid nonce to isolate the downstream gates.
		$_POST['nonce']    = \wp_create_nonce( RunTimelineDashboard::NONCE_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Remove the test nonce from the superglobals.
	 *
	 * @return void
	 */
	private function clear_nonce(): void {
		unset( $_POST['nonce'], $_REQUEST['nonce'], $_POST['action'], $_REQUEST['action'] );
	}

	/**
	 * Signal a simulated AJAX request WITHOUT a valid nonce.
	 *
	 * The suite's wp_doing_ajax filter counts a posted `action` as an AJAX
	 * request — without it, check_ajax_referer()/wp_send_json() hit WP 6.9's
	 * non-interceptable bare die()/exit() branches and kill the phpunit
	 * process. The ported handlers catch the nonce-failure WPDieException
	 * in their Throwable guard and re-emit a 500 envelope carrying the -1
	 * message — the assertion targets that envelope.
	 *
	 * @param string $action The AJAX action name.
	 * @return void
	 */
	private function set_ajax_action_without_nonce( string $action ): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: simulate an AJAX-shaped request with no nonce.
		$_POST['action']    = $action;
		$_REQUEST['action'] = $action;
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
		// phpcs:enable WordPress.Security.NonceVerification
	}

	// ─── Public surface constants ────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 20, RunTimelineDashboard::RUNS_PER_PAGE );
		$this->assertSame( 'wp_mcp_ai_run_timeline_', RunTimelineDashboard::CACHE_PREFIX );
		$this->assertSame( 'mcp-ai-run-timeline', RunTimelineDashboard::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_run_timeline', RunTimelineDashboard::NONCE_ACTION );
	}

	// ─── Menu + hook registration ────────────────────────────────

	public function test_menu_registration_resolves_per_install_mode(): void {
		global $submenu;

		// Isolate from any prior contamination — the submenu global is not
		// reliably reset between tests in this process.
		$submenu = array();

		// add_submenu_page() early-returns when the current user lacks the
		// declared capability, so an administrator must be set first.
		$this->admin_user();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base admin owns the same page under the base
			// settings dashboard menu; the ported class stays unwired.
			( new \WP_MCP_AI_Admin_Run_Timeline() )->add_menu_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'mcp-ai-run-timeline', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the dashboard registers under the NV Platform menu.
			$dashboard = new RunTimelineDashboard();
			$dashboard->register();
			$dashboard->add_menu_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'mcp-ai-run-timeline', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$dashboard = new RunTimelineDashboard();
		$dashboard->register();
		$dashboard->register();

		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_run_timeline_get_run' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_run_timeline_list_runs' ) );
	}

	// ─── Collaborator seams ──────────────────────────────────────

	public function test_metric_store_seam_resolves_per_install_mode(): void {
		$store = RunTimelineDashboardExposer::exposed_metric_store();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( \WP_MCP_AI_Metric_Event_Store::class, $store );
		} else {
			$this->assertInstanceOf( \NvoosContentGraphAiPlatform\Measurement\MetricEventStore::class, $store );
		}
	}

	public function test_reasoning_trace_seam_meta_key_byte_identical(): void {
		$this->assertSame( '_wp_mcp_ai_reasoning_trace', RunTimelineDashboardExposer::exposed_trace_meta_key() );
	}

	public function test_otel_probe_and_settings_url_resolve_per_install_mode(): void {
		$this->assertIsBool( RunTimelineDashboardExposer::exposed_otel() );

		$url = RunTimelineDashboardExposer::exposed_settings_url();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'wp-mcp-ai-dashboard', $url );
			$this->assertStringContainsString( 'view=observability', $url );
		} else {
			$this->assertStringContainsString( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $url );
			$this->assertStringNotContainsString( 'view=observability', $url );
		}
	}

	// ─── Render surface ──────────────────────────────────────────

	public function test_render_page_output(): void {
		$this->admin_user();
		$output = ( new RunTimelineDashboardExposer() )->exposed_render();

		$this->assertStringContainsString( 'NV oOS — Run Timeline', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-run-timeline', $output );
		$this->assertStringContainsString( 'rt-run-list', $output );
		$this->assertStringContainsString( 'rt-filter-assistant', $output );

		// The OTel notice renders whenever the exporter is not enabled
		// (always standalone — documented degradation).
		$this->assertStringContainsString( 'OpenTelemetry', $output );
	}

	public function test_render_page_blocks_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );

		( new RunTimelineDashboardExposer() )->exposed_render();
	}

	public function test_render_assistant_options_lists_assistants(): void {
		$trace_meta_key = RunTimelineDashboardExposer::exposed_trace_meta_key();

		$a1 = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Alpha Assistant',
				'post_status' => 'publish',
			)
		);
		$a2 = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Beta Assistant',
				'post_status' => 'publish',
			)
		);

		// The meta-summary fallback only counts assistants carrying the
		// reasoning-trace meta key.
		\update_post_meta( $a1, $trace_meta_key, array( 'created_at' => 100 ) );

		$summaries = RunTimelineDashboardExposer::exposed_meta_summaries();

		$this->assertCount( 1, $summaries );
		$this->assertSame( 'meta:' . $a1, $summaries[0]['run_id'] );
		$this->assertSame( $a1, $summaries[0]['assistant_id'] );
		$this->assertSame( 100, $summaries[0]['started_at'] );
		$this->assertSame( 100, $summaries[0]['trace']['created_at'] );
		$this->assertSame( 0, $summaries[0]['total_tokens'] );
		$this->assertSame( 0.0, $summaries[0]['cost_usd'] );

		// The assistant filter restricts the scan to the given post.
		$filtered = RunTimelineDashboardExposer::exposed_meta_summaries( $a2 );
		$this->assertCount( 0, $filtered );

		unset( $a2 );
	}

	public function test_summary_envelope_pagination_math(): void {
		$envelope = RunTimelineDashboardExposer::exposed_summaries( 2, 0 );

		$this->assertArrayHasKey( 'runs', $envelope );
		$this->assertArrayHasKey( 'total', $envelope );
		$this->assertArrayHasKey( 'page', $envelope );
		$this->assertArrayHasKey( 'per_page', $envelope );
		$this->assertSame( 2, $envelope['page'] );
		$this->assertSame( RunTimelineDashboard::RUNS_PER_PAGE, $envelope['per_page'] );
		$this->assertIsArray( $envelope['runs'] );
		$this->assertIsInt( $envelope['total'] );
		$this->assertGreaterThanOrEqual( \count( $envelope['runs'] ), $envelope['total'] );
		$this->assertLessThanOrEqual( RunTimelineDashboard::RUNS_PER_PAGE, \count( $envelope['runs'] ) );
		// The offset math against the envelope's own total.
		$expected = \min(
			RunTimelineDashboard::RUNS_PER_PAGE,
			\max( 0, $envelope['total'] - ( 2 - 1 ) * RunTimelineDashboard::RUNS_PER_PAGE )
		);
		$this->assertSame( $expected, \count( $envelope['runs'] ) );
	}

	// ─── Run detail loader ───────────────────────────────────────

	public function test_run_detail_meta_trace_and_filter(): void {
		$this->admin_user();
		$trace_meta_key = RunTimelineDashboardExposer::exposed_trace_meta_key();

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		\update_post_meta(
			$post_id,
			$trace_meta_key,
			array(
				'created_at' => 200,
				'answer'     => 'the answer',
				'plan'       => array( 'step one', 'step two' ),
			)
		);

		$captured = null;
		\add_filter(
			'wp_mcp_ai_run_timeline_detail',
			static function ( $detail, $run_id ) use ( &$captured ) {
				$captured = array( $detail, $run_id );
				return $detail;
			},
			10,
			2
		);

		$detail = RunTimelineDashboardExposer::exposed_run_detail( 'meta:' . $post_id );

		$this->assertNotNull( $detail );
		$this->assertSame( 'meta:' . $post_id, $detail['run_id'] );
		$this->assertSame( 200, $detail['trace']['created_at'] );
		$this->assertSame( 'the answer', $detail['trace']['answer'] );
		$this->assertArrayHasKey( 'steps', $detail );
		$this->assertArrayHasKey( 'summary', $detail );

		// The detail filter fired with the run id.
		$this->assertNotNull( $captured );
		$this->assertSame( 'meta:' . $post_id, $captured[1] );

		// Unknown run ids resolve null.
		$this->assertNull( RunTimelineDashboardExposer::exposed_run_detail( 'nope-123' ) );
	}

	// ─── AJAX gates ──────────────────────────────────────────────

	public function test_ajax_list_runs_gates_and_payload(): void {
		$this->admin_user();

		// Nonce gate: without a nonce the handler's Throwable guard catches
		// the harness's WPDieException( -1 ) and re-emits the 500 envelope.
		$this->set_ajax_action_without_nonce( 'wp_mcp_ai_run_timeline_list_runs' );
		$output = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_list_runs();
			}
		);
		$this->assertStringContainsString( 'Run Timeline failed to load runs: -1', $output );
		$this->clear_nonce();

		// Capability gate.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_list_runs();
			}
		);
		$this->assertStringContainsString( 'Permission denied.', $output );
		$this->clear_nonce();

		// Success payload with the summary envelope shape. The handler's
		// Throwable guard re-catches the harness's WPAjaxDieContinueException
		// from wp_send_json_success and appends a second 500 document, so
		// assert on the echoed strings rather than json_decode (byte-identical
		// harness artifact — production wp_die terminates before the guard).
		$this->admin_user();
		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_list_runs();
			}
		);
		$this->assertStringContainsString( '"success":true', $output );
		$this->assertStringContainsString( '"runs":', $output );
		$this->assertStringContainsString( '"total":', $output );
		$this->assertStringContainsString( '"per_page":' . RunTimelineDashboard::RUNS_PER_PAGE, $output );
		$this->clear_nonce();
	}

	public function test_ajax_get_run_envelopes(): void {
		$this->admin_user();

		// Nonce gate: the Throwable guard re-emits the -1 nonce failure as
		// the 500 envelope (same harness contract as ajax_list_runs).
		$this->set_ajax_action_without_nonce( 'wp_mcp_ai_run_timeline_get_run' );
		$output = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_get_run();
			}
		);
		$this->assertStringContainsString( 'Run Timeline failed to load run detail: -1', $output );
		$this->clear_nonce();

		// Missing run_id → 400 envelope.
		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_get_run();
			}
		);
		$this->assertStringContainsString( 'run_id is required.', $output );

		// Unknown run → 404 envelope.
		$_GET['run_id'] = 'ghost-run';
		$output         = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_get_run();
			}
		);
		$this->assertStringContainsString( 'Run not found.', $output );

		// Meta run → success payload with the trace hydrated.
		$trace_meta_key = RunTimelineDashboardExposer::exposed_trace_meta_key();
		$post_id        = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		\update_post_meta( $post_id, $trace_meta_key, array( 'created_at' => 300 ) );

		$_GET['run_id'] = 'meta:' . $post_id;
		$output         = $this->capture_ajax_call(
			static function (): void {
				( new RunTimelineDashboard() )->ajax_get_run();
			}
		);
		// Same harness double-document artifact as ajax_list_runs — string
		// assertions on the first echoed document.
		$this->assertStringContainsString( '"success":true', $output );
		$this->assertStringContainsString( '"run_id":"meta:' . $post_id . '"', $output );
		$this->assertStringContainsString( '"created_at":300', $output );

		$this->clear_nonce();
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();
		$dashboard = new RunTimelineDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_' . RunTimelineDashboard::PAGE_SLUG );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-run-timeline', 'registered' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-run-timeline', 'registered' ) );

		// The localized envelope carries the byte-identical key + i18n block.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-run-timeline']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiRunTimeline', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( '"nonce"', $data );
		$this->assertStringContainsString( 'downloadJSON', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_style( 'wp-mcp-ai-run-timeline' );

		$dashboard = new RunTimelineDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-run-timeline', 'registered' ) );
	}
}
