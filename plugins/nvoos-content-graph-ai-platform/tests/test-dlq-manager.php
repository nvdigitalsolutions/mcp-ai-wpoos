<?php
/**
 * DLQ manager page ported-class tests (Wave E-UI-2, sub-cluster 5).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_DLQ_Manager` preserves the public behaviour: the
 * byte-identical page slug and admin_post action names, the
 * standalone-only menu registration under the NV Platform menu, the
 * per-mode dead-letter-queue seam, the page-URL builder, the
 * type-badge and item-action helpers, the non-manager render gate,
 * the empty + seeded render surface (intro, stats cards, filter
 * form, seven-column items table, notices), the bulk-action handler
 * gates and redirect envelopes (missing params, dismiss/delete
 * processed+errors counts), the single-action handler gates and
 * redirect envelopes (dismiss/delete success, retry error code), and
 * the inline-stylesheet enqueue. Runs in both matrices against the
 * real DLQ table (the E2 suite's DDL suspension pattern).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\DlqManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected statics and helpers are
 * published as public wrappers.
 */
class DlqManagerExposer extends DlqManager {

	public static function exposed_dlq_class() {
		return self::dlq_class();
	}

	public static function exposed_page_url( array $args = array() ): string {
		$page = new self();
		return $page->get_page_url( $args );
	}

	public static function exposed_format_type( $type ): string {
		$page = new self();
		return $page->format_type( $type );
	}

	public static function exposed_item_actions( array $item ): string {
		$page = new self();
		return $page->render_item_actions( $item );
	}

	public static function exposed_notices(): string {
		\ob_start();
		try {
			( new self() )->render_notices();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}

	public static function exposed_statistics( array $stats ): string {
		\ob_start();
		try {
			( new self() )->render_statistics( $stats );
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}

	public static function exposed_filters( $filter_type, $filter_dismissed ): string {
		\ob_start();
		try {
			( new self() )->render_filters( $filter_type, $filter_dismissed );
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
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
 * DLQ manager characterisation suite (Wave E-UI-2, sub-cluster 5).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Dlq_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['item_id'], $_GET['dlq_action'], $_GET['success'], $_GET['action_result'], $_GET['bulk_action'], $_GET['processed'], $_GET['errors'], $_GET['error'], $_GET['filter_type'], $_GET['filter_dismissed'] );
		unset( $_POST['action'], $_POST['dlq_items'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}

		// Exercise the REAL DLQ table (E2 suite's DDL-suspension pattern).
		$this->suspend_temporary_table_rewrite();
		$this->drop_temporary_shadow();
		$this->ensure_table();
		$this->truncate_table();
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['item_id'], $_GET['dlq_action'], $_GET['success'], $_GET['action_result'], $_GET['bulk_action'], $_GET['processed'], $_GET['errors'], $_GET['error'], $_GET['filter_type'], $_GET['filter_dismissed'] );
		unset( $_POST['action'], $_POST['dlq_items'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		\remove_all_filters( 'wp_redirect' );

		// Leave the REAL table in place (bootstrap wiring + later test files
		// depend on it) and truncate instead of dropping.
		$this->ensure_table();
		$this->truncate_table();
		$this->restore_temporary_table_rewrite();

		parent::tearDown();
	}

	// ─── Table maintenance helpers (E2 pattern) ──────────────────

	/**
	 * Suspend the WP test framework's CREATE/DROP TABLE → TEMPORARY rewrite.
	 *
	 * Temporary tables are invisible to `SHOW TABLES`, so the DLQ's
	 * `use_custom_table()` probe can never observe them. The real table
	 * is exercised instead (see the E2 dead-letter-queue suite).
	 *
	 * @return void
	 */
	private function suspend_temporary_table_rewrite(): void {
		\remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Re-arm the framework's TEMPORARY-table rewrite for subsequent tests.
	 *
	 * @return void
	 */
	private function restore_temporary_table_rewrite(): void {
		\add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Drop any TEMPORARY shadow table left on this connection: MySQL
	 * prefers the temporary table over a real one of the same name.
	 *
	 * @return void
	 */
	private function drop_temporary_shadow(): void {
		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		if ( null === $dlq_class ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . $dlq_class::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Create the DLQ table when missing (real DDL under the suspended rewrite).
	 *
	 * @return void
	 */
	private function ensure_table(): void {
		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		if ( null !== $dlq_class ) {
			$dlq_class::create_table();
		}
	}

	/**
	 * Truncate the DLQ table for deterministic counts.
	 *
	 * @return void
	 */
	private function truncate_table(): void {
		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		if ( null === $dlq_class ) {
			return;
		}

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . $dlq_class::TABLE_NAME ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static plugin-controlled table name; test isolation on the custom table.
	}

	/**
	 * Seed a DLQ item and return its ID.
	 *
	 * @param string $type       Item type.
	 * @param string $identifier Item identifier.
	 * @param array  $data       Item data.
	 * @param string $reason     Failure reason.
	 * @return string Item ID.
	 */
	private function add_item( string $type, string $identifier, array $data = array(), string $reason = 'Failed' ): string {
		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		$this->assertNotNull( $dlq_class, 'A DLQ class must resolve in this install mode.' );

		$dlq_class::add( $type, $identifier, $data, $reason );

		$items   = $dlq_class::get_all();
		$item_id = (string) \key( $items );

		return $item_id;
	}

	// ─── Shared helpers ──────────────────────────────────────────

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

	public function test_page_slug_byte_identical(): void {
		$this->assertSame( 'wp-mcp-ai-dlq-manager', DlqManager::PAGE_SLUG );
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
			( new \WP_MCP_AI_Admin_DLQ_Manager() )->register_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'wp-mcp-ai-dlq-manager', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the page registers under the NV Platform menu.
			$page = new DlqManager();
			$page->register();
			$page->register_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'wp-mcp-ai-dlq-manager', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$page = new DlqManager();
		$page->register();
		$page->register();

		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_dlq_bulk_action' ) );
		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_dlq_single_action' ) );
	}

	// ─── Per-mode DLQ seam + helpers ─────────────────────────────

	public function test_dlq_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Dead_Letter_Queue', DlqManagerExposer::exposed_dlq_class() );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Queues\DeadLetterQueue', DlqManagerExposer::exposed_dlq_class() );
		}
	}

	public function test_page_url_builder(): void {
		$url = DlqManagerExposer::exposed_page_url();

		$this->assertStringContainsString( 'admin.php', $url );
		$this->assertStringContainsString( 'page=wp-mcp-ai-dlq-manager', $url );

		$url = DlqManagerExposer::exposed_page_url(
			array(
				'bulk_action' => 'delete',
				'processed'   => 2,
			)
		);
		$this->assertStringContainsString( 'bulk_action=delete', $url );
		$this->assertStringContainsString( 'processed=2', $url );
	}

	public function test_format_type_badge(): void {
		$badge = DlqManagerExposer::exposed_format_type( 'webhook' );

		$this->assertStringContainsString( 'wp-mcp-ai-dlq__type wp-mcp-ai-dlq__type--webhook', $badge );
		$this->assertStringContainsString( 'Webhook', $badge );

		// Unknown types fall back to the raw label with a sanitized class.
		$badge = DlqManagerExposer::exposed_format_type( 'weird type!' );
		$this->assertStringContainsString( 'weird type!', $badge );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__type--weirdtype', $badge );
	}

	public function test_item_actions_links(): void {
		$actions = DlqManagerExposer::exposed_item_actions(
			array(
				'id'        => 'item-abc',
				'dismissed' => 0,
			)
		);

		// The nonce actions only exist hashed inside the _wpnonce query
		// values — assert on the visible query surface instead.
		$this->assertStringContainsString( 'dlq_action=retry', $actions );
		$this->assertStringContainsString( 'dlq_action=dismiss', $actions );
		$this->assertStringContainsString( 'dlq_action=delete', $actions );
		$this->assertStringContainsString( 'item_id=item-abc', $actions );
		$this->assertStringContainsString( '>Retry<', $actions );
		$this->assertStringContainsString( '>Dismiss<', $actions );
		$this->assertStringContainsString( '>Delete<', $actions );

		// Dismissed items hide the Dismiss link.
		$actions = DlqManagerExposer::exposed_item_actions(
			array(
				'id'        => 'item-abc',
				'dismissed' => 1,
			)
		);
		$this->assertStringNotContainsString( 'dlq_action=dismiss', $actions );
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_render_page_empty_state(): void {
		$this->admin_user();
		$output = ( new DlqManagerExposer() )->exposed_render();

		$this->assertStringContainsString( 'Dead Letter Queue', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__intro', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__stats', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__filters', $output );
		$this->assertStringContainsString( 'No items in dead letter queue', $output );
		$this->assertStringNotContainsString( 'wp-mcp-ai-dlq__table', $output );
	}

	public function test_render_page_with_seeded_item(): void {
		$this->admin_user();
		$item_id = $this->add_item( 'webhook', 'page-hook-1', array( 'url' => 'https://example.com/hook' ), 'Connection refused' );

		$output = ( new DlqManagerExposer() )->exposed_render();

		$this->assertStringContainsString( 'wp-mcp-ai-dlq__table', $output );
		$this->assertStringContainsString( 'page-hook-1', $output );
		$this->assertStringContainsString( 'Connection refused', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__type--webhook', $output );
		$this->assertStringContainsString( 'dlq_action=retry', $output );
		$this->assertStringContainsString( 'item_id=' . $item_id, $output );
		$this->assertStringContainsString( 'dlq_items[]', $output );
		$this->assertStringNotContainsString( 'No items in dead letter queue', $output );
	}

	public function test_render_page_silently_skips_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$output = ( new DlqManagerExposer() )->exposed_render();

		// The base renders nothing for non-managers (silent return).
		$this->assertSame( '', $output );
	}

	// ─── Notices / statistics / filters ──────────────────────────

	public function test_notices_surfaces(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters under test.
		$_GET['success']       = '1';
		$_GET['action_result'] = 'delete';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->assertStringContainsString( 'Item deleted.', DlqManagerExposer::exposed_notices() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters under test.
		unset( $_GET['success'], $_GET['action_result'] );
		$_GET['bulk_action'] = 'dismiss';
		$_GET['processed']   = '2';
		$_GET['errors']      = '1';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->assertStringContainsString( 'Bulk action completed: 2 items processed, 1 errors.', DlqManagerExposer::exposed_notices() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters under test.
		unset( $_GET['bulk_action'], $_GET['processed'], $_GET['errors'] );
		$_GET['error'] = 'missing_params';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->assertStringContainsString( 'An error occurred. Please try again.', DlqManagerExposer::exposed_notices() );
	}

	public function test_statistics_render_values(): void {
		$output = DlqManagerExposer::exposed_statistics(
			array(
				'total'     => 5,
				'active'    => 3,
				'dismissed' => 2,
			)
		);

		$this->assertStringContainsString( 'Total Items', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__stat-value">5</div>', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__stat-value">3</div>', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-dlq__stat-value">2</div>', $output );
	}

	public function test_filters_render_selected(): void {
		$output = DlqManagerExposer::exposed_filters( 'cron_job', 'yes' );

		// The selected() helper prints " selected='selected'" (leading space).
		$this->assertStringContainsString( 'value="cron_job"', $output );
		$this->assertStringContainsString( 'value="yes"', $output );
		$this->assertSame( 2, \substr_count( $output, "selected='selected'" ) );
		$this->assertStringContainsString( 'name="filter_type"', $output );
		$this->assertStringContainsString( 'name="filter_dismissed"', $output );
	}

	// ─── Bulk-action handler gates + redirects ───────────────────

	public function test_bulk_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new DlqManager() )->handle_bulk_action();
			}
		);
		$this->assertSame( 'You do not have permission to manage the dead letter queue.', $message );
	}

	public function test_bulk_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_POST['action']      = 'dismiss';
		$_POST['dlq_items']   = array( 'item-a' );
		$_REQUEST['_wpnonce'] = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new DlqManager() )->handle_bulk_action();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_bulk_missing_params_redirects(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['_wpnonce']     = \wp_create_nonce( 'wp_mcp_ai_dlq_bulk_action' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_bulk_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'error=missing_params', $redirected );
	}

	public function test_bulk_dismiss_envelope(): void {
		$this->admin_user();

		$id1 = $this->add_item( 'webhook', 'bulk-1' );
		$id2 = $this->add_item( 'webhook', 'bulk-2' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['action']      = 'dismiss';
		$_POST['dlq_items']   = array( $id1, $id2 );
		$_POST['_wpnonce']    = \wp_create_nonce( 'wp_mcp_ai_dlq_bulk_action' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_bulk_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'bulk_action=dismiss', $redirected );
		$this->assertStringContainsString( 'processed=2', $redirected );
		$this->assertStringContainsString( 'errors=0', $redirected );

		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		$this->assertSame( 1, (int) $dlq_class::get( $id1 )['dismissed'] );
		$this->assertSame( 1, (int) $dlq_class::get( $id2 )['dismissed'] );
	}

	public function test_bulk_retry_envelope_counts_errors(): void {
		$this->admin_user();

		// Incomplete webhook payload + a missing id: both retry calls fail
		// with WP_Error envelopes (E2-proven deterministic degradation) —
		// remove() cannot produce errors (false !== $deleted quirk).
		$id1 = $this->add_item( 'webhook', 'bulk-retry-1', array( 'payload' => array() ), 'Failed' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['action']      = 'retry';
		$_POST['dlq_items']   = array( $id1, 'missing-item' );
		$_POST['_wpnonce']    = \wp_create_nonce( 'wp_mcp_ai_dlq_bulk_action' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_bulk_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'bulk_action=retry', $redirected );
		$this->assertStringContainsString( 'processed=0', $redirected );
		$this->assertStringContainsString( 'errors=2', $redirected );

		// The failed retry leaves the item in place.
		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		$this->assertNotNull( $dlq_class::get( $id1 ) );
	}

	public function test_bulk_delete_removes_items(): void {
		$this->admin_user();

		$id1 = $this->add_item( 'webhook', 'bulk-delete-1' );
		$id2 = $this->add_item( 'webhook', 'bulk-delete-2' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['action']      = 'delete';
		$_POST['dlq_items']   = array( $id1, $id2 );
		$_POST['_wpnonce']    = \wp_create_nonce( 'wp_mcp_ai_dlq_bulk_action' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_bulk_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'bulk_action=delete', $redirected );
		$this->assertStringContainsString( 'processed=2', $redirected );
		$this->assertStringContainsString( 'errors=0', $redirected );

		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		$this->assertNull( $dlq_class::get( $id1 ) );
		$this->assertNull( $dlq_class::get( $id2 ) );
	}

	// ─── Single-action handler gates + redirects ─────────────────

	public function test_single_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new DlqManager() )->handle_single_action();
			}
		);
		$this->assertSame( 'You do not have permission to manage the dead letter queue.', $message );
	}

	public function test_single_rejects_missing_params(): void {
		$this->admin_user();

		$message = $this->capture_die_message(
			static function (): void {
				( new DlqManager() )->handle_single_action();
			}
		);
		$this->assertSame( 'Missing parameters.', $message );
	}

	public function test_single_rejects_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_GET['item_id']       = 'item-a';
		$_GET['dlq_action']    = 'delete';
		$_REQUEST['_wpnonce']  = 'bogus';
		// phpcs:enable WordPress.Security.NonceVerification

		$message = $this->capture_die_message(
			static function (): void {
				( new DlqManager() )->handle_single_action();
			}
		);
		$this->assertSame( 'The link you followed has expired.', $message );
	}

	public function test_single_dismiss_envelope(): void {
		$this->admin_user();
		$item_id = $this->add_item( 'webhook', 'single-dismiss-1' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_GET['item_id']      = $item_id;
		$_GET['dlq_action']   = 'dismiss';
		$_GET['_wpnonce']     = \wp_create_nonce( 'wp_mcp_ai_dlq_dismiss_' . $item_id );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_single_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'action_result=dismiss', $redirected );
		$this->assertStringContainsString( 'success=1', $redirected );

		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		$this->assertSame( 1, (int) $dlq_class::get( $item_id )['dismissed'] );
	}

	public function test_single_delete_envelope(): void {
		$this->admin_user();
		$item_id = $this->add_item( 'webhook', 'single-delete-1' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_GET['item_id']      = $item_id;
		$_GET['dlq_action']   = 'delete';
		$_GET['_wpnonce']     = \wp_create_nonce( 'wp_mcp_ai_dlq_delete_' . $item_id );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_single_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'action_result=delete', $redirected );
		$this->assertStringContainsString( 'success=1', $redirected );

		$dlq_class = DlqManagerExposer::exposed_dlq_class();
		$this->assertNull( $dlq_class::get( $item_id ) );
	}

	public function test_single_retry_error_envelope(): void {
		$this->admin_user();

		// Incomplete webhook payload → the retry path records the attempt
		// and returns the byte-identical WP_Error code in both matrices
		// (E2-proven deterministic degradation, no network involved).
		$item_id = $this->add_item( 'webhook', 'single-retry-1', array( 'payload' => array() ), 'Failed' );

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_GET['item_id']      = $item_id;
		$_GET['dlq_action']   = 'retry';
		$_GET['_wpnonce']     = \wp_create_nonce( 'wp_mcp_ai_dlq_retry_' . $item_id );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new DlqManager() )->handle_single_action();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'action_result=retry', $redirected );
		$this->assertStringContainsString( 'error=invalid_webhook_data', $redirected );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();

		$page = new DlqManagerExposer();
		$page->register_page();

		// The enqueue gate compares against the registered page hook.
		$page->enqueue_assets( $page->exposed_page_hook() );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-dlq-inline', 'enqueued' ) );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_style( 'wp-mcp-ai-dlq-inline' );

		$page = new DlqManager();
		$page->register_page();
		$page->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-dlq-inline', 'enqueued' ) );
	}
}
