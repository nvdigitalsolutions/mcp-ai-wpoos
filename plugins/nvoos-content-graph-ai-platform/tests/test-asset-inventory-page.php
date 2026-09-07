<?php
/**
 * Asset inventory page ported-class tests (Wave E-UI-2, sub-cluster 7).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Asset_Inventory_Admin` preserves the public behaviour: the
 * byte-identical page slug, the standalone-only menu registration
 * under the NV Platform menu (fleet capability), register idempotence
 * (hook-registry dedup delta), the per-mode asset-inventory-engine and
 * fleet-capability seams, the per-mode inventory resolution (empty-state
 * degradation standalone), the render surface (Discover Assets action,
 * stats grid, filter selects, assets table with classification badges,
 * empty state, ISO 27001 about section), the seeded-inventory table
 * (monolith), and the per-mode asset enqueues with the
 * `wpMcpAiAssetInventory` localized envelope. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Managers\AssetInventoryPage;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the page's protected statics and helpers are
 * published as public wrappers.
 */
class AssetInventoryPageExposer extends AssetInventoryPage {

	public static function exposed_asset_inventory_class() {
		return self::asset_inventory_class();
	}

	public static function exposed_required_capability(): string {
		return self::required_capability();
	}

	public static function exposed_resolve_inventory(): array {
		$page = new self();
		return $page->resolve_inventory();
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
 * Asset inventory page characterisation suite (Wave E-UI-2, sub-cluster 7).
 */
#[\PHPUnit\Framework\Attributes\Group( 'managers' )]
class Test_Asset_Inventory_Page extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		\delete_option( 'wp_mcp_ai_asset_inventory' );

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
		\delete_option( 'wp_mcp_ai_asset_inventory' );
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

	// ─── Public surface ─────────────────────────────────────────

	public function test_page_slug_byte_identical(): void {
		$this->assertSame( 'nvoos-asset-inventory', AssetInventoryPage::PAGE_SLUG );
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
			// Pro dashboard menu; the ported class stays unwired.
			( new \WP_MCP_AI_Asset_Inventory_Admin() )->add_admin_menu();

			$slugs = isset( $submenu['nvoos-pro-dashboard'] ) ? \wp_list_pluck( $submenu['nvoos-pro-dashboard'], 2 ) : array();
			$this->assertContains( 'nvoos-asset-inventory', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the page registers under the NV Platform menu.
			$page = new AssetInventoryPage();
			$page->register();
			$page->add_admin_menu();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'nvoos-asset-inventory', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$this->admin_user();

		// Count admin_menu callbacks bound to an add_admin_menu method —
		// the base admin loader (monolith) may already have its own.
		$count = static function (): int {
			global $wp_filter;
			$total = 0;
			foreach ( (array) ( $wp_filter['admin_menu']->callbacks ?? array() ) as $priority_group ) {
				foreach ( (array) $priority_group as $cb ) {
					$fn = $cb['function'] ?? null;
					if ( \is_array( $fn ) && isset( $fn[1] ) && 'add_admin_menu' === $fn[1] ) {
						++$total;
					}
				}
			}
			return $total;
		};

		$before = $count();

		$page = new AssetInventoryPage();
		$page->register();
		$page->register();

		// Double-registering adds exactly one callback (hook-registry dedup).
		$this->assertSame( 1, $count() - $before );
	}

	// ─── Per-mode seams ─────────────────────────────────────────

	public function test_asset_inventory_class_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Asset_Inventory', AssetInventoryPageExposer::exposed_asset_inventory_class() );
		} else {
			// Standalone: the engine is base-owned and not yet ported.
			$this->assertNull( AssetInventoryPageExposer::exposed_asset_inventory_class() );
		}
	}

	public function test_required_capability_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame(
				\wp_mcp_ai_fleet_capability(),
				AssetInventoryPageExposer::exposed_required_capability()
			);
		} else {
			$this->assertSame( 'manage_options', AssetInventoryPageExposer::exposed_required_capability() );

			// The byte-identical filter still resolves standalone.
			\add_filter( 'wp_mcp_ai_fleet_capability', static fn() => 'custom_fleet_cap' );
			$this->assertSame( 'custom_fleet_cap', AssetInventoryPageExposer::exposed_required_capability() );
			\remove_all_filters( 'wp_mcp_ai_fleet_capability' );
		}
	}

	// ─── Inventory resolution ───────────────────────────────────

	public function test_resolve_inventory_shape_per_install_mode(): void {
		$resolved = AssetInventoryPageExposer::exposed_resolve_inventory();

		$this->assertArrayHasKey( 'inventory', $resolved );
		$this->assertArrayHasKey( 'stats', $resolved );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the engine's own output (empty store → false).
			$this->assertSame( \get_option( 'wp_mcp_ai_asset_inventory', false ), $resolved['inventory'] );
			$this->assertSame( 0, $resolved['stats']['total'] );
		} else {
			// Standalone: null inventory + zeroed stats.
			$this->assertNull( $resolved['inventory'] );
			$this->assertSame( 0, $resolved['stats']['total'] );
			$this->assertSame( array(), $resolved['stats']['by_classification'] );
		}
	}

	// ─── Render surface ─────────────────────────────────────────

	public function test_render_page_common_surface(): void {
		$this->admin_user();
		$output = ( new AssetInventoryPageExposer() )->exposed_render();

		$this->assertStringContainsString( 'wp-mcp-ai-asset-inventory', $output );
		$this->assertStringContainsString( 'Discover Assets', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-discover-assets', $output );
		$this->assertStringContainsString( 'About Asset Inventory', $output );
		$this->assertStringContainsString( 'ISO 27001:2022 Control A.5.9', $output );

		// The filters + table render only when an inventory exists — the
		// empty store (both modes) renders the byte-identical empty state
		// (esc_html encodes the quotes).
		$this->assertStringContainsString( 'No asset inventory found. Click &quot;Discover Assets&quot; to generate the inventory.', $output );
		$this->assertStringNotContainsString( 'wp-mcp-ai-filter-classification', $output );
	}

	public function test_render_page_with_seeded_inventory(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith-only: the inventory store is base-owned.' );
		}

		$this->admin_user();

		\update_option(
			'wp_mcp_ai_asset_inventory',
			array(
				'generated_at' => '2026-09-07 10:00:00',
				'assets'       => array(
					array(
						'name'           => 'OpenAI API Key',
						'type'           => 'api_key',
						'classification' => 'confidential',
						'description'    => 'Provider credential',
						'owner'          => 'admin',
						'location'       => 'wp_options',
						'last_modified'  => '2026-09-06 09:00:00',
					),
				),
			)
		);

		$output = ( new AssetInventoryPageExposer() )->exposed_render();

		$this->assertStringContainsString( 'wp-mcp-ai-assets-table', $output );
		$this->assertStringContainsString( 'OpenAI API Key', $output );
		$this->assertStringContainsString( 'API Key/Credential', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-badge-confidential', $output );
		$this->assertStringContainsString( 'Confidential', $output );
		$this->assertStringContainsString( 'wp_options', $output );
		$this->assertStringContainsString( 'data-classification="confidential"', $output );
		$this->assertStringContainsString( 'data-type="api_key"', $output );
		$this->assertStringContainsString( 'Asset Statistics', $output );
		$this->assertStringContainsString( 'Last updated: 2026-09-07 10:00:00', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-filter-classification', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-filter-type', $output );
		$this->assertStringNotContainsString( 'No asset inventory found.', $output );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();

		// Register the parent menu first — without it add_submenu_page
		// returns the admin_page_* fallback hook (not in the allowlist).
		\add_menu_page(
			'NV Platform',
			'NV Platform',
			'manage_options',
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG
		);

		$page = new AssetInventoryPageExposer();
		$page->add_admin_menu();

		// The enqueue gate compares against the registered page hook.
		$page->enqueue_assets( $page->exposed_page_hook() );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-asset-inventory', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-asset-inventory', 'registered' ) );

		// The localized envelope carries the byte-identical key.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-asset-inventory']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiAssetInventory', $data );
		$this->assertStringContainsString( 'apiUrl', $data );
		$this->assertStringContainsString( 'discovering', $data );
		$this->assertStringContainsString( 'discoverButton', $data );
	}

	public function test_enqueue_assets_accepts_base_hook_suffixes(): void {
		$this->admin_user();

		$page = new AssetInventoryPage();
		$page->add_admin_menu();
		$page->enqueue_assets( 'nvoos-pro-dashboard_page_nvoos-asset-inventory' );

		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-asset-inventory', 'registered' ) );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_script( 'wp-mcp-ai-asset-inventory' );

		$page = new AssetInventoryPage();
		$page->add_admin_menu();
		$page->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_script_is( 'wp-mcp-ai-asset-inventory', 'registered' ) );
	}
}
