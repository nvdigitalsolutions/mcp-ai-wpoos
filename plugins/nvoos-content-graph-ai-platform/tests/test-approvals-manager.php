<?php
/**
 * Approvals manager page ported-class tests (Wave E-UI-2, sub-cluster 1).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Approvals` preserves the public behaviour: the
 * byte-identical page slug, nonce and AJAX action names, the
 * standalone-only menu registration (with the pending-count badge)
 * under the NV Platform menu, the per-mode approval-queue seam, the
 * pending-count probe, the render surface (toolbar + filter + table),
 * the assistant option list, the AJAX nonce/capability gates, the
 * list enrichment (requester name + formatted dates), the
 * approve/deny/invalid resolution envelopes, and the per-mode asset
 * enqueues. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\ApprovalsManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected statics and helpers are
 * published as public wrappers.
 */
class ApprovalsManagerExposer extends ApprovalsManager {

	public static function exposed_queue() {
		return self::approval_queue();
	}

	public static function exposed_pending_count(): int {
		$page = new self();
		return $page->get_pending_count();
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
 * Approvals manager characterisation suite (Wave E-UI-2, sub-cluster 1).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Approvals_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['assistant_id'], $_POST['approval_id'], $_POST['resolution'], $_POST['note'] );

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['assistant_id'], $_POST['approval_id'], $_POST['resolution'], $_POST['note'] );
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
	 * Invoke an AJAX handler and capture the WPDieException message instead
	 * of the echo buffer. check_ajax_referer() failures call wp_die( -1 )
	 * through the throwing test die-handler, which echoes nothing — the
	 * payload lives in the exception.
	 *
	 * @param callable $callback AJAX handler invocation.
	 * @return string The die message, or the echoed output if no die fired.
	 */
	private function capture_ajax_die_message( callable $callback ): string {
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
	 * Craft a valid nonce into the superglobals for the success paths.
	 *
	 * @return void
	 */
	private function set_nonce(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: craft a valid nonce to isolate the downstream gates.
		$_POST['nonce']    = \wp_create_nonce( ApprovalsManager::NONCE_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Remove the test nonce from the superglobals.
	 *
	 * @return void
	 */
	private function clear_nonce(): void {
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
	}

	// ─── Public surface constants ────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp-ai-approvals', ApprovalsManager::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_approvals', ApprovalsManager::NONCE_ACTION );
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
			( new \WP_MCP_AI_Admin_Approvals() )->add_menu_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'mcp-ai-approvals', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the page registers under the NV Platform menu.
			$page = new ApprovalsManager();
			$page->register();
			$page->add_menu_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'mcp-ai-approvals', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$page = new ApprovalsManager();
		$page->register();
		$page->register();

		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_list_approvals' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_resolve_approval' ) );
	}

	// ─── Approval queue seam + pending count ─────────────────────

	public function test_approval_queue_seam_resolves_per_install_mode(): void {
		$queue = ApprovalsManagerExposer::exposed_queue();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( \WP_MCP_AI_Approval_Queue::class, $queue );
		} else {
			$this->assertInstanceOf( \NvoosContentGraphAiPlatform\Approvals\ApprovalQueue::class, $queue );
		}
	}

	public function test_pending_count_reflects_queue(): void {
		$queue = ApprovalsManagerExposer::exposed_queue();

		$this->assertSame( 0, ApprovalsManagerExposer::exposed_pending_count() );

		$id1 = $queue->enqueue(
			array(
				'tool'   => 'orchtest_tool',
				'reason' => 'one',
			)
		);
		$id2 = $queue->enqueue(
			array(
				'tool'   => 'orchtest_tool',
				'reason' => 'two',
			)
		);

		$this->assertIsInt( $id1 );
		$this->assertIsInt( $id2 );
		$this->assertSame( 2, ApprovalsManagerExposer::exposed_pending_count() );

		\wp_delete_post( $id1, true );
		\wp_delete_post( $id2, true );
	}

	// ─── Render surface ──────────────────────────────────────────

	public function test_render_page_output(): void {
		$this->admin_user();
		$output = ( new ApprovalsManagerExposer() )->exposed_render();

		$this->assertStringContainsString( 'NV oOS — Approval Queue', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-approvals', $output );
		$this->assertStringContainsString( 'approvals-filter-assistant', $output );
		$this->assertStringContainsString( 'approvals-table', $output );
		$this->assertStringContainsString( 'approvals-refresh', $output );
	}

	public function test_render_page_blocks_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );

		( new ApprovalsManagerExposer() )->exposed_render();
	}

	// ─── AJAX gates + envelopes ──────────────────────────────────

	public function test_ajax_list_requires_nonce(): void {
		$this->admin_user();

		$output = $this->capture_ajax_die_message(
			static function (): void {
				( new ApprovalsManager() )->ajax_list();
			}
		);

		$this->assertSame( '-1', $output );
	}

	public function test_ajax_list_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->set_nonce();

		$output = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_list();
			}
		);

		$this->assertStringContainsString( 'Permission denied.', $output );
		$this->clear_nonce();
	}

	public function test_ajax_list_success_payload_and_enrichment(): void {
		$this->admin_user();
		$requester = self::factory()->user->create( array( 'display_name' => 'Req User' ) );
		$queue     = ApprovalsManagerExposer::exposed_queue();

		$id = $queue->enqueue(
			array(
				'tool'         => 'orchtest_approve_me',
				'reason'       => 'needs eyes',
				'requester_id' => $requester,
			)
		);

		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_list();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertArrayHasKey( 'approvals', $payload['data'] );

		$found = null;
		foreach ( $payload['data']['approvals'] as $item ) {
			if ( (int) $id === (int) $item['id'] ) {
				$found = $item;
				break;
			}
		}
		$this->assertNotNull( $found, 'Seeded approval missing from the list payload.' );
		$this->assertSame( 'Req User', $found['requester_name'] );
		$this->assertArrayHasKey( 'created_at_formatted', $found );
		$this->assertArrayHasKey( 'expires_at_formatted', $found );

		$this->clear_nonce();
		\wp_delete_post( $id, true );
	}

	public function test_ajax_resolve_envelopes(): void {
		$this->admin_user();
		$this->set_nonce();

		// Invalid approval ID.
		$output = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_resolve();
			}
		);
		$this->assertStringContainsString( 'Invalid approval ID.', $output );

		// Invalid resolution.
		$_POST['approval_id'] = 1;
		$_POST['resolution']  = 'nope';
		$output               = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_resolve();
			}
		);
		$this->assertStringContainsString( 'Invalid resolution. Use \"approve\" or \"deny\".', $output );

		// Approve flow.
		$queue = ApprovalsManagerExposer::exposed_queue();
		$id    = $queue->enqueue( array( 'tool' => 'orchtest_resolve_me' ) );

		$_POST['approval_id'] = $id;
		$_POST['resolution']  = 'approve';
		$_POST['note']        = 'looks fine';
		$output               = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_resolve();
			}
		);
		$payload              = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 'approved', $payload['data']['status'] );
		$this->assertSame( $id, $payload['data']['approval_id'] );

		// Deny flow.
		$id2                  = $queue->enqueue( array( 'tool' => 'orchtest_deny_me' ) );
		$_POST['approval_id'] = $id2;
		$_POST['resolution']  = 'deny';
		$_POST['note']        = 'no way';
		$output               = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_resolve();
			}
		);
		$payload              = \json_decode( $output, true );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 'denied', $payload['data']['status'] );

		// Already-resolved → WP_Error envelope.
		$_POST['approval_id'] = $id;
		$_POST['resolution']  = 'approve';
		$output               = $this->capture_ajax_call(
			static function (): void {
				( new ApprovalsManager() )->ajax_resolve();
			}
		);
		$payload              = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertFalse( $payload['success'] );

		unset( $_POST['approval_id'], $_POST['resolution'], $_POST['note'] );
		$this->clear_nonce();
		\wp_delete_post( $id, true );
		\wp_delete_post( $id2, true );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();
		$page = new ApprovalsManager();
		$page->enqueue_assets( 'toplevel_page_' . ApprovalsManager::PAGE_SLUG );

		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-approvals', 'registered' ) );

		// The localized envelope carries the byte-identical key + i18n block.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-approvals']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiApprovals', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( '"nonce"', $data );
		$this->assertStringContainsString( 'noPending', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_script( 'wp-mcp-ai-approvals' );

		$page = new ApprovalsManager();
		$page->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_script_is( 'wp-mcp-ai-approvals', 'registered' ) );
	}
}
