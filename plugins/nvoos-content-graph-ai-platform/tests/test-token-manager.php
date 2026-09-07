<?php
/**
 * Token manager page ported-class tests (Wave E-UI-2, sub-cluster 2).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Token_Manager` preserves the public behaviour: the
 * byte-identical page slug and admin_post action names, the
 * standalone-only menu registration under the NV Platform menu, the
 * per-mode credentials-store seam, the credentials listing + newest-
 * first sort, the statistics shape, the user display-name helper, the
 * render surface per mode (table + stats vs the empty state), the
 * restrictions panel per mode, the revoke/delete handler gates and
 * redirect envelopes, and the per-mode asset enqueues. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\TokenManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected statics and helpers are
 * published as public wrappers.
 */
class TokenManagerExposer extends TokenManager {

	public static function exposed_credentials_class() {
		return self::credentials_class();
	}

	public function exposed_page_hook(): string {
		return $this->page_hook;
	}

	public static function exposed_credentials(): array {
		$page = new self();
		return $page->get_all_credentials();
	}

	public static function exposed_statistics( array $credentials ): array {
		$page = new self();
		return $page->get_statistics( $credentials );
	}

	public static function exposed_display_name( $user_id ): string {
		$page = new self();
		return $page->get_user_display_name( $user_id );
	}

	public static function exposed_restrictions_panel(): string {
		$page = new self();
		\ob_start();
		try {
			$page->render_restrictions_panel();
		} finally {
			return (string) \ob_get_clean();
		}
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
 * Token manager characterisation suite (Wave E-UI-2, sub-cluster 2).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Token_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['action'], $_POST['assistant_id'], $_POST['credential_id'], $_POST['_wpnonce'] );

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
		unset( $_GET['action'], $_POST['assistant_id'], $_POST['credential_id'], $_POST['_wpnonce'] );
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

	// ─── Public surface ─────────────────────────────────────────

	public function test_page_slug_byte_identical(): void {
		$this->assertSame( 'wp-mcp-ai-token-manager', TokenManager::PAGE_SLUG );
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
			( new \WP_MCP_AI_Admin_Token_Manager() )->register_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'wp-mcp-ai-token-manager', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the page registers under the NV Platform menu.
			$page = new TokenManager();
			$page->register();
			$page->register_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'wp-mcp-ai-token-manager', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$page = new TokenManager();
		$page->register();
		$page->register();

		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_token_manager_revoke' ) );
		$this->assertSame( 1, $this->count_callbacks( 'admin_post_wp_mcp_ai_token_manager_delete' ) );
	}

	// ─── Credentials seam ───────────────────────────────────────

	public function test_credentials_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Credentials', TokenManagerExposer::exposed_credentials_class() );
		} else {
			$this->assertNull( TokenManagerExposer::exposed_credentials_class() );
		}
	}

	public function test_credentials_listing_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Seed an assistant + credential via the base store.
			$assistant_id = self::factory()->post->create(
				array(
					'post_type'   => 'mcp_ai_assistant',
					'post_title'  => 'Token Assistant',
					'post_status' => 'publish',
				)
			);
			\WP_MCP_AI_Credentials::issue_credential( $assistant_id, 1 );

			$credentials = TokenManagerExposer::exposed_credentials();

			$this->assertCount( 1, $credentials );
			$this->assertSame( $assistant_id, $credentials[0]['assistant_id'] );
			$this->assertSame( 'Token Assistant', $credentials[0]['assistant_title'] );
			$this->assertArrayHasKey( '_sort_timestamp', $credentials[0] );

			\WP_MCP_AI_Credentials::purge_assistant_credentials( $assistant_id );
		} else {
			// Standalone: no credentials store ported — empty listing.
			$this->assertSame( array(), TokenManagerExposer::exposed_credentials() );
		}
	}

	// ─── Statistics + display name helpers ───────────────────────

	public function test_statistics_shape(): void {
		$credentials = array(
			array(
				'id'           => 'a',
				'assistant_id' => 1,
				'revoked_at'   => '',
			),
			array(
				'id'           => 'b',
				'assistant_id' => 1,
				'revoked_at'   => '2026-01-01 00:00:00',
			),
			array(
				'id'           => 'c',
				'assistant_id' => 2,
				'revoked_at'   => '',
			),
		);

		$stats = TokenManagerExposer::exposed_statistics( $credentials );

		$this->assertSame( 3, $stats['total'] );
		$this->assertSame( 2, $stats['active'] );
		$this->assertSame( 1, $stats['revoked'] );
		$this->assertSame( 2, $stats['assistants'] );
	}

	public function test_display_name_helper(): void {
		$this->assertSame( 'System', TokenManagerExposer::exposed_display_name( 0 ) );

		$user_id = self::factory()->user->create( array( 'display_name' => 'Tok Admin' ) );
		$this->assertSame( 'Tok Admin', TokenManagerExposer::exposed_display_name( $user_id ) );

		$this->assertSame( 'Unknown', TokenManagerExposer::exposed_display_name( 99999999 ) );
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_render_page_per_install_mode(): void {
		$this->admin_user();
		$output = ( new TokenManagerExposer() )->exposed_render();

		$this->assertStringContainsString( 'NV oOS Token Manager', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-token-manager__intro', $output );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'wp-mcp-ai-restrictions-panel', $output );
			$this->assertStringContainsString( 'Restricted Users', $output );
		} else {
			// Standalone: restrictions panel hidden, empty credentials state.
			$this->assertStringNotContainsString( 'Restricted Users', $output );
			$this->assertStringContainsString( 'No Tokens Issued', $output );
		}
	}

	public function test_render_page_table_with_seeded_credential(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith-only: the credentials store is base-owned.' );
		}

		$this->admin_user();
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Listed Assistant',
				'post_status' => 'publish',
			)
		);
		\WP_MCP_AI_Credentials::issue_credential( $assistant_id, 1 );

		$output = ( new TokenManagerExposer() )->exposed_render();

		$this->assertStringContainsString( 'wp-mcp-ai-token-manager__stats', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-token-manager__table', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-token-manager__status--active', $output );
		$this->assertStringContainsString( 'Listed Assistant', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_token_manager_revoke', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_token_manager_delete', $output );

		\WP_MCP_AI_Credentials::purge_assistant_credentials( $assistant_id );
	}

	public function test_render_page_silently_skips_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$output = ( new TokenManagerExposer() )->exposed_render();

		// The base renders nothing for non-managers (silent return).
		$this->assertSame( '', $output );
	}

	// ─── Revoke/delete handler gates + redirects ─────────────────

	public function test_handlers_require_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$revoke = $this->capture_die_message(
			static function (): void {
				( new TokenManager() )->handle_revoke_token();
			}
		);
		$this->assertSame( 'You do not have permission to manage tokens.', $revoke );

		$delete = $this->capture_die_message(
			static function (): void {
				( new TokenManager() )->handle_delete_token();
			}
		);
		$this->assertSame( 'You do not have permission to manage tokens.', $delete );
	}

	public function test_handlers_reject_missing_identifiers(): void {
		$this->admin_user();

		$revoke = $this->capture_die_message(
			static function (): void {
				( new TokenManager() )->handle_revoke_token();
			}
		);
		$this->assertSame( 'Missing token identifier.', $revoke );

		$delete = $this->capture_die_message(
			static function (): void {
				( new TokenManager() )->handle_delete_token();
			}
		);
		$this->assertSame( 'Missing token identifier.', $delete );
	}

	public function test_revoke_redirect_envelopes(): void {
		$this->admin_user();

		// Standalone: no credentials store → action=error redirect.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$_POST['assistant_id']    = 42;
			$_POST['credential_id']   = 'cred_abc';
			$_POST['_wpnonce']        = \wp_create_nonce( 'wp_mcp_ai_token_manager_revoke_42_cred_abc' );
			$_REQUEST['_wpnonce']     = $_POST['_wpnonce'];

			$redirected = null;
			\add_filter(
				'wp_redirect',
				static function ( $location ) use ( &$redirected ) {
					$redirected = $location;
					throw new \RuntimeException( 'stop' );
				}
			);

			try {
				( new TokenManager() )->handle_revoke_token();
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Expected — the redirect filter short-circuits exit.
			}

			\remove_all_filters( 'wp_redirect' );
			$this->assertNotNull( $redirected );
			$this->assertStringContainsString( 'action=error', $redirected );
			unset( $_POST['assistant_id'], $_POST['credential_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
			return;
		}

		// Monolith: seed + revoke through the base store.
		$assistant_id  = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Revoke Assistant',
				'post_status' => 'publish',
			)
		);
		$issued        = \WP_MCP_AI_Credentials::issue_credential( $assistant_id, 1 );
		$credential_id = $issued['credential']['id'];

		$_POST['assistant_id']  = $assistant_id;
		$_POST['credential_id'] = $credential_id;
		$_POST['_wpnonce']      = \wp_create_nonce( 'wp_mcp_ai_token_manager_revoke_' . $assistant_id . '_' . $credential_id );
		$_REQUEST['_wpnonce']   = $_POST['_wpnonce'];

		$redirected = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new \RuntimeException( 'stop' );
			}
		);

		try {
			( new TokenManager() )->handle_revoke_token();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Expected — the redirect filter short-circuits exit.
		}

		\remove_all_filters( 'wp_redirect' );
		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'action=revoked', $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-token-manager', $redirected );

		unset( $_POST['assistant_id'], $_POST['credential_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		\WP_MCP_AI_Credentials::purge_assistant_credentials( $assistant_id );
	}

	public function test_delete_redirect_envelopes(): void {
		$this->admin_user();

		// Standalone: no credentials store → action=error redirect.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$_POST['assistant_id']  = 42;
			$_POST['credential_id'] = 'cred_abc';
			$_POST['_wpnonce']      = \wp_create_nonce( 'wp_mcp_ai_token_manager_delete_42_cred_abc' );
			$_REQUEST['_wpnonce']   = $_POST['_wpnonce'];

			$redirected = null;
			\add_filter(
				'wp_redirect',
				static function ( $location ) use ( &$redirected ) {
					$redirected = $location;
					throw new \RuntimeException( 'stop' );
				}
			);

			try {
				( new TokenManager() )->handle_delete_token();
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Expected — the redirect filter short-circuits exit.
			}

			\remove_all_filters( 'wp_redirect' );
			$this->assertNotNull( $redirected );
			$this->assertStringContainsString( 'action=error', $redirected );
			unset( $_POST['assistant_id'], $_POST['credential_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
			return;
		}

		// Monolith: seed + delete through the base store.
		$assistant_id  = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Delete Assistant',
				'post_status' => 'publish',
			)
		);
		$issued        = \WP_MCP_AI_Credentials::issue_credential( $assistant_id, 1 );
		$credential_id = $issued['credential']['id'];

		$_POST['assistant_id']  = $assistant_id;
		$_POST['credential_id'] = $credential_id;
		$_POST['_wpnonce']      = \wp_create_nonce( 'wp_mcp_ai_token_manager_delete_' . $assistant_id . '_' . $credential_id );
		$_REQUEST['_wpnonce']   = $_POST['_wpnonce'];

		$redirected = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new \RuntimeException( 'stop' );
			}
		);

		try {
			( new TokenManager() )->handle_delete_token();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Expected — the redirect filter short-circuits exit.
		}

		\remove_all_filters( 'wp_redirect' );
		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'action=deleted', $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-token-manager', $redirected );

		unset( $_POST['assistant_id'], $_POST['credential_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();

		$page = new TokenManagerExposer();
		$page->register_page();

		// The enqueue gate compares against the registered page hook.
		$page->enqueue_assets( $page->exposed_page_hook() );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-token-manager-inline', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-restrictions-admin', 'registered' ) );

		// The localized envelope carries the byte-identical key.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-restrictions-admin']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiRestrictionsAdmin', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( 'confirmLift', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_script( 'wp-mcp-ai-restrictions-admin' );

		$page = new TokenManager();
		$page->register_page();
		$page->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_script_is( 'wp-mcp-ai-restrictions-admin', 'registered' ) );
	}
}
