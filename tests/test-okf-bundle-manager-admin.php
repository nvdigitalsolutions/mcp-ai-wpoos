<?php
/**
 * Rendering + registration tests for the OKF Bundle Manager admin page (Base).
 *
 * Covers page registration under the assistant CPT, the export admin-post
 * hook, and admin rendering of the Bundles tab. State-changing handler
 * coverage (nonce/capability/happy-path matrix) lives in
 * test-okf-bundle-manager-admin-ajax.php.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * OKF Bundle Manager admin page — rendering & registration.
 */
class Test_OKF_Bundle_Manager_Admin extends WP_UnitTestCase {

	/**
	 * Get a fresh admin page instance.
	 *
	 * @return WP_MCP_AI_OKF_Bundle_Manager_Admin_Page
	 */
	private function page() {
		return new WP_MCP_AI_OKF_Bundle_Manager_Admin_Page();
	}

	/**
	 * Test that the page registers under the assistant CPT.
	 */
	public function test_page_registered_under_assistant_cpt() {
		wp_set_current_user( 1 ); // Administrator (add_submenu_page checks the capability).

		// Ensure the parent menu entry exists before registering, mirroring a
		// real admin request (the assistant CPT menu is registered at admin_menu).
		$GLOBALS['menu'][] = array(
			'',
			'read',
			'edit.php?post_type=mcp_ai_assistant',
			'',
			'menu-top',
			'',
			'dashicons-admin-post',
		);
		if ( ! is_array( $GLOBALS['submenu'] ) ) {
			$GLOBALS['submenu'] = array();
		}

		$this->page()->register_page();

		$found = false;
		foreach ( $GLOBALS['submenu'] as $parent => $items ) {
			if ( 'edit.php?post_type=mcp_ai_assistant' !== $parent ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( WP_MCP_AI_OKF_Bundle_Manager_Admin_Page::PAGE_SLUG === $item[2] ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found, 'The OKF Bundle Manager submenu page was not registered.' );
	}

	/**
	 * Test that the export admin-post hook is registered.
	 */
	public function test_export_hook_registered() {
		$this->page();

		$this->assertNotFalse(
			has_action( 'admin_post_wp_mcp_ai_okf_bundle_export' ),
			'The export admin-post hook is not registered.'
		);
	}

	/**
	 * Test that the seven AJAX hooks are registered.
	 */
	public function test_ajax_hooks_registered() {
		$this->page();

		$actions = array(
			'wp_ajax_wp_mcp_ai_okf_bundle_create',
			'wp_ajax_wp_mcp_ai_okf_bundle_rename',
			'wp_ajax_wp_mcp_ai_okf_bundle_archive',
			'wp_ajax_wp_mcp_ai_okf_bundle_delete',
			'wp_ajax_wp_mcp_ai_okf_bundle_import',
			'wp_ajax_wp_mcp_ai_okf_bundle_save_concept',
			'wp_ajax_wp_mcp_ai_okf_bundle_delete_concept',
		);

		foreach ( $actions as $action ) {
			$this->assertNotFalse( has_action( $action ), "The {$action} hook is not registered." );
		}
	}

	/**
	 * Test that render_page() outputs the Bundles tab for an admin.
	 */
	public function test_render_page_bundles_tab() {
		wp_set_current_user( 1 ); // Administrator.

		ob_start();
		$this->page()->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'OKF Bundle Manager', $output );
		$this->assertStringContainsString( 'okf-create-bundle-form', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-okf-bundle-manager', $output );
	}

	/**
	 * Test that render_page() validates the tab parameter.
	 */
	public function test_render_page_rejects_unknown_tab() {
		wp_set_current_user( 1 ); // Administrator.

		$_GET['tab'] = '../../etc/passwd';

		ob_start();
		$this->page()->render_page();
		$output = ob_get_clean();

		// Falls back to the bundles tab; never renders the raw tab value.
		$this->assertStringContainsString( 'okf-create-bundle-form', $output );
		$this->assertStringNotContainsString( '../../etc/passwd', $output );
	}
}
