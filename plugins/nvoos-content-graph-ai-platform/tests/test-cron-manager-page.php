<?php
/**
 * Cron manager page ported-class tests (Wave E-UI-2, sub-cluster 3).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Cron_Manager` preserves the public behaviour: the
 * byte-identical page slug, nonce action and admin_post/AJAX action
 * names, the standalone-only menu registration under the NV Platform
 * menu, the per-mode cron-manager/DLQ/SLA/job-store seams, the
 * per-mode retention-period seam, the byte-identical DLQ cross-link,
 * the statistics shape, the render surface per mode (auto-refresh
 * controls + intro + health section + empty state / jobs table with
 * per-row delete forms), the updated-notice surfaces, the delete
 * handler gates and redirect envelopes, the AJAX nonce/capability
 * gates and success payload, and the per-mode asset enqueues. Runs in
 * both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\CronManagerPage;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected statics and helpers are
 * published as public wrappers.
 */
class CronManagerPageExposer extends CronManagerPage {

	public static function exposed_cron_manager_class() {
		return self::cron_manager_class();
	}

	public static function exposed_dlq_class() {
		return self::dlq_class();
	}

	public static function exposed_sla_class() {
		return self::sla_class();
	}

	public static function exposed_job_store_class() {
		return self::job_store_class();
	}

	public static function exposed_retention_hours() {
		return self::retention_hours();
	}

	public static function exposed_dlq_manager_url() {
		return self::dlq_manager_url();
	}

	public static function exposed_statistics( array $jobs ): array {
		$page = new self();
		return $page->get_statistics( $jobs );
	}

	public function exposed_page_hook(): string {
		return $this->page_hook;
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
 * Cron manager page characterisation suite (Wave E-UI-2, sub-cluster 3).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Cron_Manager_Page extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['updated'], $_POST['job_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['nonce'], $_REQUEST['nonce'], $_POST['action'] );

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}

		// Isolate cron state: the ported and base cron managers share the
		// byte-identical `wp_mcp_ai_cron_jobs` option key.
		\_set_cron_array( array() );
		\delete_option( 'wp_mcp_ai_cron_jobs' );
		\delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['updated'], $_POST['job_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['nonce'], $_REQUEST['nonce'], $_POST['action'] );

		\_set_cron_array( array() );
		\delete_option( 'wp_mcp_ai_cron_jobs' );
		\delete_option( 'wp_mcp_ai_settings' );
		\remove_all_filters( 'wp_redirect' );

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
	 * Invoke a handler and capture its echoed wp_die payload.
	 *
	 * @param callable $callback Handler invocation.
	 * @return string Echoed payload (the WPDieException is swallowed).
	 */
	private function capture_call( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			unset( $e ); // Expected — the message was echoed before the die.
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Invoke a handler and capture the WPDieException message instead of
	 * the echo buffer (the throwing test die-handler echoes nothing).
	 *
	 * @param callable $callback Handler invocation.
	 * @return string The die message, or the echoed output if no die fired.
	 */
	private function capture_die_message( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			\ob_end_clean();
			return $e->getMessage();
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Craft a valid nonce into the superglobals for the AJAX success paths.
	 *
	 * @return void
	 */
	private function set_nonce(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: craft a valid nonce to isolate the downstream gates.
		$_POST['nonce']    = \wp_create_nonce( CronManagerPage::NONCE_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Intercept wp_redirect() and capture the location, rethrowing so the
	 * handler's bare `exit` cannot terminate the process.
	 *
	 * @param callable $handler Handler invocation.
	 * @return string|null The redirect location, or null if none fired.
	 */
	private function capture_redirect( callable $handler ) {
		$redirected = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new \RuntimeException( 'stop' );
			}
		);

		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Expected — the redirect filter short-circuits exit.
		}

		\remove_all_filters( 'wp_redirect' );
		return $redirected;
	}

	// ─── Public surface ─────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp-mcp-ai-cron-manager', CronManagerPage::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_cron_manager', CronManagerPage::NONCE_ACTION );
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
			( new \WP_MCP_AI_Admin_Cron_Manager() )->register_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'wp-mcp-ai-cron-manager', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the page registers under the NV Platform menu.
			$page = new CronManagerPage();
			$page->register();
			$page->register_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'wp-mcp-ai-cron-manager', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$page = new CronManagerPage();
		$page->register();
		$page->register();

		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_delete_cron' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_get_cron_manager_stats' ) );
	}

	// ─── Per-mode collaborator seams ─────────────────────────────

	public function test_cron_manager_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Cron_Manager', CronManagerPageExposer::exposed_cron_manager_class() );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Queues\CronManager', CronManagerPageExposer::exposed_cron_manager_class() );
		}
	}

	public function test_dlq_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Dead_Letter_Queue', CronManagerPageExposer::exposed_dlq_class() );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Queues\DeadLetterQueue', CronManagerPageExposer::exposed_dlq_class() );
		}
	}

	public function test_sla_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_SLA_Manager', CronManagerPageExposer::exposed_sla_class() );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Queues\SlaManager', CronManagerPageExposer::exposed_sla_class() );
		}
	}

	public function test_job_store_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Job_Store', CronManagerPageExposer::exposed_job_store_class() );
		} else {
			// Standalone: the job store is base-owned and not yet ported —
			// the section is hidden (documented deviation).
			$this->assertNull( CronManagerPageExposer::exposed_job_store_class() );
		}
	}

	public function test_retention_hours_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the settings registry owns the value.
			$this->assertSame(
				\absint( \WP_MCP_AI_Settings_Registry::get_setting( 'cron_job_retention_period', 24 ) ),
				CronManagerPageExposer::exposed_retention_hours()
			);
		} else {
			// Standalone: the wp_mcp_ai_settings option (E2 convention).
			$this->assertSame( 24, CronManagerPageExposer::exposed_retention_hours() );

			\update_option( 'wp_mcp_ai_settings', array( 'cron_job_retention_period' => 6 ) );
			$this->assertSame( 6, CronManagerPageExposer::exposed_retention_hours() );

			// Non-numeric values collapse to zero via absint.
			\update_option( 'wp_mcp_ai_settings', array( 'cron_job_retention_period' => 'abc' ) );
			$this->assertSame( 0, CronManagerPageExposer::exposed_retention_hours() );
		}
	}

	public function test_dlq_manager_url_is_byte_identical(): void {
		$url = CronManagerPageExposer::exposed_dlq_manager_url();

		$this->assertStringContainsString( 'admin.php', $url );
		$this->assertStringContainsString( 'page=wp-mcp-ai-dlq-manager', $url );
	}

	// ─── Statistics shape ────────────────────────────────────────

	public function test_statistics_shape(): void {
		$future = \time() + HOUR_IN_SECONDS;

		\wp_schedule_single_event( $future, 'stats_active_oneoff', array( 'a' ) );
		\wp_schedule_event( $future + 60, 'daily', 'stats_active_recurring', array( 'b' ) );

		$jobs = array(
			'j1' => array(
				'hook'     => 'stats_active_oneoff',
				'args'     => array( 'a' ),
				'schedule' => 'single',
			),
			'j2' => array(
				'hook'     => 'stats_active_recurring',
				'args'     => array( 'b' ),
				'schedule' => 'daily',
			),
			'j3' => array(
				'hook'     => 'stats_inactive_oneoff',
				'args'     => array( 'c' ),
				'schedule' => 'single',
			),
			'j4' => array(
				'hook'     => 'stats_empty_schedule',
				'args'     => array( 'd' ),
				'schedule' => '',
			),
		);

		$stats = CronManagerPageExposer::exposed_statistics( $jobs );

		$this->assertSame( 4, $stats['total'] );
		$this->assertSame( 2, $stats['active'] );
		$this->assertSame( 2, $stats['inactive'] );
		$this->assertSame( 1, $stats['recurring'] );
		$this->assertSame( 3, $stats['one_off'] );
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_render_page_per_install_mode(): void {
		$this->admin_user();
		$output = ( new CronManagerPageExposer() )->exposed_render();

		$this->assertStringContainsString( 'NV oOS Cron Manager', $output );
		$this->assertStringContainsString( 'toggle-auto-refresh', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-cron-manager__intro', $output );
		$this->assertStringContainsString( 'About Cron Manager', $output );

		// DLQ + SLA sections render in both modes (both class seams resolve).
		$this->assertStringContainsString( 'Job Queue Health', $output );
		$this->assertStringContainsString( 'SLA Prioritization', $output );

		// Empty state.
		$this->assertStringContainsString( 'No Scheduled Events', $output );
		$this->assertStringContainsString( 'create_cron_job', $output );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the job-store section may render when its table has
			// rows — assert only the common surface above.
			return;
		}

		// Standalone: the job store is base-owned — section hidden.
		$this->assertStringNotContainsString( 'Job Store', $output );
	}

	public function test_render_page_with_seeded_job(): void {
		$cron_class = CronManagerPageExposer::exposed_cron_manager_class();
		$this->assertNotNull( $cron_class, 'A cron manager class must resolve in this install mode.' );

		$seeder = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Cron Seeder',
			)
		);
		$ts     = \time() + HOUR_IN_SECONDS;

		\wp_schedule_single_event( $ts, 'page_render_hook', array( 'r' ) );
		$job_id = $cron_class::record_job( 'page_render_hook', array( 'r' ), 'single', $ts, $seeder );

		$this->admin_user();
		$output = ( new CronManagerPageExposer() )->exposed_render();

		$this->assertStringContainsString( 'cron-jobs-table', $output );
		$this->assertStringContainsString( 'page_render_hook', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-cron-manager__status--active', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-cron-manager__status--oneoff', $output );
		$this->assertStringContainsString( 'Cron Seeder', $output );
		// The per-row delete form carries the job id + the byte-identical
		// admin-post action (the nonce action itself only exists hashed).
		$this->assertStringContainsString( 'wp_mcp_ai_delete_cron', $output );
		$this->assertStringContainsString( 'name="job_id" value="' . $job_id . '"', $output );
		$this->assertStringNotContainsString( 'No Scheduled Events', $output );

		$cron_class::remove_job( $job_id );
	}

	public function test_render_page_silently_skips_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$output = ( new CronManagerPageExposer() )->exposed_render();

		// The base renders nothing for non-managers (silent return).
		$this->assertSame( '', $output );
	}

	public function test_updated_notice_surfaces(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for the admin notice display.
		$_GET['updated'] = '1';
		$success         = ( new CronManagerPageExposer() )->exposed_render();
		$this->assertStringContainsString( 'Cron event successfully removed and unscheduled from WordPress Cron.', $success );

		$_GET['updated'] = '0';
		$error           = ( new CronManagerPageExposer() )->exposed_render();
		$this->assertStringContainsString( 'The cron event could not be removed.', $error );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	// ─── Delete handler gates + redirects ────────────────────────

	public function test_delete_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new CronManagerPage() )->handle_delete_cron();
			}
		);
		$this->assertSame( 'You do not have permission to manage cron events.', $message );
	}

	public function test_delete_rejects_missing_job_id(): void {
		$this->admin_user();

		$message = $this->capture_die_message(
			static function (): void {
				( new CronManagerPage() )->handle_delete_cron();
			}
		);
		$this->assertSame( 'Missing cron identifier.', $message );
	}

	public function test_delete_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_POST['job_id']       = 'page-bogus-job';
		$_REQUEST['_wpnonce']  = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new CronManagerPage() )->handle_delete_cron();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_delete_redirect_envelopes(): void {
		$this->admin_user();

		$cron_class = CronManagerPageExposer::exposed_cron_manager_class();
		$this->assertNotNull( $cron_class, 'A cron manager class must resolve in this install mode.' );

		$ts = \time() + HOUR_IN_SECONDS;
		\wp_schedule_single_event( $ts, 'delete_flow_hook', array( 'd' ) );
		$job_id = $cron_class::record_job( 'delete_flow_hook', array( 'd' ), 'single', $ts, 1 );

		// Success path: the stored job is removed and unscheduled.
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['job_id']       = $job_id;
		$_POST['_wpnonce']     = \wp_create_nonce( 'wp_mcp_ai_delete_cron_' . $job_id );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new CronManagerPage() )->handle_delete_cron();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'updated=1', $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-cron-manager', $redirected );
		$this->assertNull( $cron_class::get_job( $job_id ) );
		$this->assertFalse( \wp_next_scheduled( 'delete_flow_hook', array( 'd' ) ) );

		// Unknown-job path: remove fails → updated=0 envelope.
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['job_id']      = 'missing-job';
		$_POST['_wpnonce']    = \wp_create_nonce( 'wp_mcp_ai_delete_cron_missing-job' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new CronManagerPage() )->handle_delete_cron();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'updated=0', $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-cron-manager', $redirected );
	}

	// ─── AJAX gates + payload ────────────────────────────────────

	public function test_ajax_requires_nonce(): void {
		$this->admin_user();

		$output = $this->capture_die_message(
			static function (): void {
				( new CronManagerPage() )->ajax_get_stats();
			}
		);

		$this->assertSame( '-1', $output );
	}

	public function test_ajax_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->set_nonce();

		$output = $this->capture_call(
			static function (): void {
				( new CronManagerPage() )->ajax_get_stats();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions.', $output );
	}

	public function test_ajax_success_payload(): void {
		$this->admin_user();

		$cron_class = CronManagerPageExposer::exposed_cron_manager_class();
		$this->assertNotNull( $cron_class, 'A cron manager class must resolve in this install mode.' );

		$ts = \time() + HOUR_IN_SECONDS;
		\wp_schedule_single_event( $ts, 'ajax_stats_hook', array( 's' ) );
		$job_id = $cron_class::record_job( 'ajax_stats_hook', array( 's' ), 'single', $ts, \get_current_user_id() );

		$this->set_nonce();
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: AJAX action into the superglobals.
		$_POST['action'] = 'wp_mcp_ai_get_cron_manager_stats';
		// phpcs:enable WordPress.Security.NonceVerification

		$output = $this->capture_call(
			static function (): void {
				( new CronManagerPage() )->ajax_get_stats();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload, 'The AJAX response must be clean JSON.' );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 1, $payload['data']['stats']['total'] );
		$this->assertSame( 1, $payload['data']['stats']['active'] );
		$this->assertSame( 1, $payload['data']['stats']['one_off'] );

		$this->assertCount( 1, $payload['data']['jobs'] );
		$job = $payload['data']['jobs'][0];
		$this->assertSame( 'ajax_stats_hook', $job['hook'] );
		$this->assertSame( array( 's' ), $job['args'] );
		$this->assertSame( 'single', $job['schedule'] );
		$this->assertTrue( $job['is_active'] );
		$this->assertFalse( $job['is_recurring'] );
		$this->assertFalse( $job['was_executed'] );
		$this->assertSame( $ts, $job['next_run'] );
		$this->assertNotNull( $job['next_run_formatted'] );
		$this->assertSame( $job_id, $job['job_id'] );
		$this->assertNotEmpty( $job['delete_nonce'] );
		$this->assertSame( $ts, $job['first_timestamp'] );

		// Both modes surface the DLQ stats; the job-store stats key always
		// exists — standalone resolves it to null (store not yet ported).
		$this->assertIsArray( $payload['data']['dlq_stats'] );
		$this->assertArrayHasKey( 'job_store_stats', $payload['data'] );
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertNull( $payload['data']['job_store_stats'] );
		}

		$cron_class::remove_job( $job_id );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();

		$page = new CronManagerPageExposer();
		$page->register_page();

		// The enqueue gate compares against the registered page hook.
		$page->enqueue_assets( $page->exposed_page_hook() );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-admin-monitor-shared', 'enqueued' ) );
		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-cron-manager-inline', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-admin-cron-manager', 'registered' ) );

		// The localized envelope carries the byte-identical key.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-admin-cron-manager']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiCronManager', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( 'noJobs', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_script( 'wp-mcp-ai-admin-cron-manager' );

		$page = new CronManagerPage();
		$page->register_page();
		$page->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_script_is( 'wp-mcp-ai-admin-cron-manager', 'registered' ) );
	}
}
