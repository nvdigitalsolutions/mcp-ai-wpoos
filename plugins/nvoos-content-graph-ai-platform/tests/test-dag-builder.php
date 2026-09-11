<?php
/**
 * DAG builder page ported-class tests (Wave E-UI-2, sub-cluster 4).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_DAG_Builder` preserves the public behaviour: the
 * byte-identical page slug, the standalone-only menu registration
 * under the NV Platform menu (with idempotent add_menu_page), the
 * per-mode workflow-CPT seam, the non-manager render gate, the empty
 * + seeded render surface (sidebar list, version badges, is-active
 * marker, canvas root data attribute), the query-string workflow-id
 * resolution with CPT ownership verification, and the per-mode asset
 * enqueues with the `mcpAiDagBuilder` localized envelope (incl. the
 * per-workflow version resolution). Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\DagBuilder;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected statics and helpers are
 * published as public wrappers.
 */
class DagBuilderExposer extends DagBuilder {

	public static function exposed_workflow_cpt_class() {
		return self::workflow_cpt_class();
	}

	public function exposed_resolve_workflow_id(): int {
		return $this->resolve_workflow_id();
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
 * DAG builder characterisation suite (Wave E-UI-2, sub-cluster 4).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Dag_Builder extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['workflow_id'] );

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
		unset( $_GET['workflow_id'] );
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
	 * Create a published workflow post with a version meta.
	 *
	 * @param string $title   Post title.
	 * @param string $version Version meta value (empty = unset).
	 * @return int
	 */
	private function create_workflow( string $title, string $version = '' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_workflow',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( '' !== $version ) {
			\update_post_meta( $id, '_wp_mcp_ai_workflow_version', $version );
		}

		return $id;
	}

	// ─── Public surface ─────────────────────────────────────────

	public function test_page_slug_byte_identical(): void {
		$this->assertSame( 'wp-mcp-ai-dag-builder', DagBuilder::PAGE_SLUG );
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
			( new \WP_MCP_AI_Admin_DAG_Builder() )->add_menu_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'wp-mcp-ai-dag-builder', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the page registers under the NV Platform menu.
			$page = new DagBuilder();
			$page->register();
			$page->add_menu_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'wp-mcp-ai-dag-builder', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$this->admin_user();

		// Count admin_menu callbacks bound to an add_menu_page method — the
		// base admin loader (monolith) may already have its own registered.
		$before = 0;
		global $wp_filter;
		foreach ( (array) ( $wp_filter['admin_menu']->callbacks ?? array() ) as $priority_group ) {
			foreach ( (array) $priority_group as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( \is_array( $fn ) && isset( $fn[1] ) && 'add_menu_page' === $fn[1] ) {
					++$before;
				}
			}
		}

		$page = new DagBuilder();
		$page->register();
		$page->register();

		$after = 0;
		foreach ( (array) ( $wp_filter['admin_menu']->callbacks ?? array() ) as $priority_group ) {
			foreach ( (array) $priority_group as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( \is_array( $fn ) && isset( $fn[1] ) && 'add_menu_page' === $fn[1] ) {
					++$after;
				}
			}
		}

		// Double-registering adds exactly one callback (hook-registry dedup).
		$this->assertSame( 1, $after - $before );
	}

	// ─── Per-mode workflow-CPT seam ──────────────────────────────

	public function test_workflow_cpt_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Workflow_CPT', DagBuilderExposer::exposed_workflow_cpt_class() );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Workflows\WorkflowCpt', DagBuilderExposer::exposed_workflow_cpt_class() );
		}
	}

	// ─── Query-string workflow-id resolution ─────────────────────

	public function test_workflow_id_resolution_defaults_to_zero(): void {
		$this->admin_user();

		$this->assertSame( 0, ( new DagBuilderExposer() )->exposed_resolve_workflow_id() );
	}

	public function test_workflow_id_resolution_rejects_non_workflow_posts(): void {
		$this->admin_user();

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Not a workflow',
				'post_status' => 'publish',
			)
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter under test.
		$_GET['workflow_id'] = $page_id;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->assertSame( 0, ( new DagBuilderExposer() )->exposed_resolve_workflow_id() );
	}

	public function test_workflow_id_resolution_accepts_workflow_posts(): void {
		$this->admin_user();
		$workflow_id = $this->create_workflow( 'Resolvable WF' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter under test.
		$_GET['workflow_id'] = $workflow_id;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->assertSame( $workflow_id, ( new DagBuilderExposer() )->exposed_resolve_workflow_id() );
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_render_page_blocks_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new DagBuilderExposer() )->exposed_render();
			}
		);
		$this->assertSame( 'You do not have permission to access this page.', $message );
	}

	public function test_render_page_empty_state(): void {
		$this->admin_user();
		$output = ( new DagBuilderExposer() )->exposed_render();

		$this->assertStringContainsString( 'mcp-ai-dag-wrap', $output );
		$this->assertStringContainsString( 'Workflow DAG Builder', $output );
		$this->assertStringContainsString( 'mcp-ai-dag-sidebar', $output );
		$this->assertStringContainsString( 'New Workflow', $output );
		$this->assertStringContainsString( 'No workflows yet. Create one!', $output );
		$this->assertStringContainsString( 'mcp-ai-dag-builder-root', $output );
		$this->assertStringContainsString( 'data-workflow-id="0"', $output );
		$this->assertStringContainsString( 'mcp-ai-dag-loading', $output );
	}

	public function test_render_page_with_seeded_workflow(): void {
		$this->admin_user();
		$workflow_id = $this->create_workflow( 'DAG WF', '1.2.3' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter under test.
		$_GET['workflow_id'] = $workflow_id;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$output = ( new DagBuilderExposer() )->exposed_render();

		$this->assertStringContainsString( 'DAG WF', $output );
		$this->assertStringContainsString( 'v1.2.3', $output );
		$this->assertStringContainsString( 'mcp-ai-dag-workflow-item is-active', $output );
		$this->assertStringContainsString( 'data-workflow-id="' . $workflow_id . '"', $output );
		$this->assertStringContainsString( 'mcp-ai-dag-run-btn', $output );
		$this->assertStringNotContainsString( 'No workflows yet. Create one!', $output );
	}

	public function test_render_page_version_falls_back_to_1_0_0(): void {
		$this->admin_user();
		$this->create_workflow( 'Unversioned WF' );

		$output = ( new DagBuilderExposer() )->exposed_render();

		$this->assertStringContainsString( 'Unversioned WF', $output );
		$this->assertStringContainsString( 'v1.0.0', $output );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();

		$page = new DagBuilderExposer();
		$page->add_menu_page();

		// The enqueue gate matches on the slug substring.
		$page->enqueue_assets( 'toplevel_page_wp-mcp-ai-dag-builder' );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-dag-builder', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-dag-builder', 'registered' ) );

		// The localized envelope carries the byte-identical key.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-dag-builder']->extra['data'] ?? '';
		$this->assertStringContainsString( 'mcpAiDagBuilder', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( 'restUrl', $data );
		$this->assertStringContainsString( '"workflowId":"0"', $data );
		$this->assertStringContainsString( '"version":"1.0.0"', $data );
	}

	public function test_enqueue_assets_resolves_workflow_version(): void {
		$this->admin_user();
		$workflow_id = $this->create_workflow( 'Versioned WF', '2.4.0' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter under test.
		$_GET['workflow_id'] = $workflow_id;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page = new DagBuilderExposer();
		$page->add_menu_page();
		$page->enqueue_assets( $page->exposed_page_hook() );

		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-dag-builder']->extra['data'] ?? '';
		$this->assertStringContainsString( '"workflowId":"' . $workflow_id . '"', $data );
		$this->assertStringContainsString( '"version":"2.4.0"', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_script( 'wp-mcp-ai-dag-builder' );

		$page = new DagBuilder();
		$page->add_menu_page();
		$page->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_script_is( 'wp-mcp-ai-dag-builder', 'registered' ) );
	}
}
