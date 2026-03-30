<?php
/**
 * Tests for Schedule Manager Page asset enqueuing.
 *
 * Verifies that the standalone Schedule Manager admin page correctly
 * enqueues CSS/JS assets via the section class, including the fallback
 * path used in the base + pro separate-plugin scenario.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-manager-page.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php';

/**
 * Test Schedule Manager Page asset enqueuing.
 */
class Test_Schedule_Manager_Page_Assets extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure an admin user is set so capability checks pass.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Dequeue assets from previous tests.
		wp_dequeue_style( 'wp-mcp-ai-schedule-manager' );
		wp_dequeue_script( 'wp-mcp-ai-schedule-manager' );
	}

	/**
	 * Verify the page class has the PAGE_SLUG constant.
	 */
	public function test_page_slug_constant() {
		$this->assertSame(
			'nvoos-pro-schedule-manager',
			WP_MCP_AI_Pro_Schedule_Manager_Page::PAGE_SLUG
		);
	}

	/**
	 * Verify enqueue_assets does nothing on unrelated admin pages.
	 */
	public function test_enqueue_assets_skips_unrelated_pages() {
		$page = new WP_MCP_AI_Pro_Schedule_Manager_Page();

		// Simulate being on the edit.php page.
		$page->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'wp-mcp-ai-schedule-manager', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-schedule-manager', 'enqueued' ) );
	}

	/**
	 * Verify enqueue_assets triggers on the correct page via $_GET fallback.
	 *
	 * This test simulates the base + pro separate-plugin scenario where the
	 * page_hook is empty (add_submenu_page hasn't been called yet) but the
	 * $_GET['page'] parameter matches the page slug.
	 */
	public function test_enqueue_assets_uses_get_page_fallback() {
		$page = new WP_MCP_AI_Pro_Schedule_Manager_Page();

		// Simulate the $_GET['page'] parameter for the standalone page.
		$_GET['page'] = 'nvoos-pro-schedule-manager';

		// Call enqueue_assets with a generic hook (simulating the fallback case).
		$page->enqueue_assets( 'admin_page_nvoos-pro-schedule-manager' );

		// CSS and JS should now be enqueued via the section delegation.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-schedule-manager', 'enqueued' ),
			'Schedule manager CSS should be enqueued when $_GET[page] matches the page slug.'
		);

		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-schedule-manager', 'enqueued' ),
			'Schedule manager JS should be enqueued when $_GET[page] matches the page slug.'
		);

		unset( $_GET['page'] );
	}

	/**
	 * Verify section's enqueue_assets also supports $_GET fallback for standalone page.
	 */
	public function test_section_enqueue_assets_uses_get_page_fallback() {
		$section = new WP_MCP_AI_Section_Schedule_Manager();

		// Dequeue any previously enqueued assets.
		wp_dequeue_style( 'wp-mcp-ai-schedule-manager' );
		wp_dequeue_script( 'wp-mcp-ai-schedule-manager' );

		// Simulate $_GET['page'] for the standalone page.
		$_GET['page'] = 'nvoos-pro-schedule-manager';

		// Call with a hook that doesn't match the computed standalone_hook.
		$section->enqueue_assets( 'some_other_hook' );

		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-schedule-manager', 'enqueued' ),
			'Section should enqueue CSS via $_GET[page] fallback for standalone page.'
		);

		unset( $_GET['page'] );
	}

	/**
	 * Verify enqueue_assets does not fire when $_GET['page'] is a different page.
	 */
	public function test_enqueue_assets_ignores_wrong_get_page() {
		$page = new WP_MCP_AI_Pro_Schedule_Manager_Page();

		$_GET['page'] = 'some-other-page';

		$page->enqueue_assets( 'admin_page_some-other-page' );

		$this->assertFalse(
			wp_style_is( 'wp-mcp-ai-schedule-manager', 'enqueued' ),
			'Should not enqueue assets for an unrelated page slug.'
		);

		unset( $_GET['page'] );
	}

	/**
	 * Verify the container re-population logic used in the separate-plugin scenario.
	 *
	 * When the base plugin loads before the Pro addon, the container singleton
	 * for 'section.schedule_manager' is resolved to null (class not loaded yet).
	 * PHP's isset() returns false for null, so the next get() call re-runs the
	 * factory.  After the Pro addon loads the section class, a subsequent get()
	 * call MUST return a real instance.
	 */
	public function test_container_resolves_section_after_class_loaded() {
		if ( ! function_exists( 'wp_mcp_ai_container' ) ) {
			$this->markTestSkipped( 'Container not available' );
		}

		$container = wp_mcp_ai_container();

		// In the test environment, tests/bootstrap.php loads the full plugin
		// via wp_mcp_ai_manually_load_plugin() on muplugins_loaded, which
		// triggers loader.php → addons/pro/mcp-ai-wpoos-pro.php, making the
		// section class available before any test runs.
		$section = $container->get( 'section.schedule_manager' );

		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Schedule_Manager',
			$section,
			'Container should return a real section instance when the class is available.'
		);
	}

	/**
	 * Verify that render_page produces output when the container has a valid section.
	 */
	public function test_render_page_produces_output_with_valid_section() {
		if ( ! function_exists( 'wp_mcp_ai_container' ) ) {
			$this->markTestSkipped( 'Container not available' );
		}

		$container = wp_mcp_ai_container();

		// The container must already hold a real instance (loaded by the Pro
		// bootstrap).  Assert this rather than silently fixing it up — if this
		// fails, the Pro load-admin-sections fix is not working.
		$section = $container->get( 'section.schedule_manager' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Schedule_Manager',
			$section,
			'Container should already hold a real section instance from the Pro bootstrap.'
		);

		$page = new WP_MCP_AI_Pro_Schedule_Manager_Page();

		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertNotEmpty(
			$output,
			'render_page() should produce non-empty output when the container has a valid section.'
		);

		$this->assertStringContainsString(
			'Pro Schedule Manager',
			$output,
			'render_page() output should contain the page title.'
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		unset( $_GET['page'] );
		wp_dequeue_style( 'wp-mcp-ai-schedule-manager' );
		wp_dequeue_script( 'wp-mcp-ai-schedule-manager' );
		parent::tearDown();
	}
}
