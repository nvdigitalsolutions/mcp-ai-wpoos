<?php
/**
 * Tests for the global admin scripts registrar (WP_MCP_AI_Admin_Scripts).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the admin scripts registrar, including the jquery-ui-sortable
 * compatibility shim for third-party admin scripts on post edit screens.
 */
class Test_Admin_Scripts extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// WP_UnitTestCase does not reset the WP_Scripts queue between
		// tests, so clear any enqueue state leaked by earlier tests in this
		// process. Dequeueing through the public API also invalidates the
		// memoized all_queued_deps set that wp_script_is() falls back to.
		// Registered handles persist too, so drop the model selector handle
		// this class owns.
		$wp_scripts = wp_scripts();
		foreach ( (array) $wp_scripts->queue as $handle ) {
			wp_dequeue_script( $handle );
		}
		wp_deregister_script( 'wp-mcp-ai-model-selector' );

		// Remove fallback hooks registered by earlier tests in this process.
		remove_action( 'admin_head', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ), 1 );
		remove_action( 'wp_print_footer_scripts', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ), 1 );
	}

	/**
	 * The compatibility shim must enqueue jquery-ui-sortable on post edit
	 * screens so third-party admin scripts that call .sortable() without
	 * declaring the dependency (e.g. Newspaper's td_wp_admin.min.js) do not
	 * throw "sortable is not a function" and halt page loading.
	 */
	public function test_register_scripts_enqueues_sortable_on_post_php() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post.php' );

		$this->assertTrue(
			wp_script_is( 'jquery-ui-sortable', 'enqueued' ),
			'jquery-ui-sortable should be enqueued on post.php.'
		);
	}

	/**
	 * The shim also covers the new-post screen.
	 */
	public function test_register_scripts_enqueues_sortable_on_post_new_php() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post-new.php' );

		$this->assertTrue(
			wp_script_is( 'jquery-ui-sortable', 'enqueued' ),
			'jquery-ui-sortable should be enqueued on post-new.php.'
		);
	}

	/**
	 * The shim must stay scoped to post edit screens and not leak jQuery UI
	 * onto unrelated admin pages.
	 */
	public function test_register_scripts_skips_other_screens() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'index.php' );

		$this->assertFalse(
			wp_script_is( 'jquery-ui-sortable', 'enqueued' ),
			'jquery-ui-sortable should not be enqueued on non-post screens.'
		);
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-model-selector', 'registered' ),
			'The model selector should not be registered on non-post screens.'
		);
	}

	/**
	 * The model selector script should still be registered (with localization
	 * attached) on post edit screens, alongside the sortable shim.
	 */
	public function test_register_scripts_registers_model_selector_on_post_screens() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post.php' );

		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-model-selector', 'registered' ),
			'The model selector script should be registered on post.php.'
		);

		global $wp_scripts;
		$model_selector = $wp_scripts->registered['wp-mcp-ai-model-selector'];

		$this->assertContains(
			'jquery',
			$model_selector->deps,
			'The model selector should depend on jQuery.'
		);
		$this->assertNotNull(
			$wp_scripts->get_data( 'wp-mcp-ai-model-selector', 'data' ),
			'The model selector should carry localized data.'
		);
	}

	/**
	 * The fallback callbacks must be hooked on post edit screens so a
	 * dequeued/deregistered core handle is still compensated for.
	 */
	public function test_register_scripts_hooks_sortable_fallback_on_post_screens() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post.php' );

		$this->assertNotFalse(
			has_action( 'admin_head', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ) ),
			'The admin_head fallback should be hooked on post.php.'
		);
		$this->assertNotFalse(
			has_action( 'wp_print_footer_scripts', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ) ),
			'The footer safety-net fallback should be hooked on post.php.'
		);
	}

	/**
	 * The fallback must not be hooked on non-post screens.
	 */
	public function test_register_scripts_skips_sortable_fallback_on_other_screens() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'index.php' );

		$this->assertFalse(
			has_action( 'admin_head', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ) ),
			'The admin_head fallback should not be hooked on non-post screens.'
		);
		$this->assertFalse(
			has_action( 'wp_print_footer_scripts', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ) ),
			'The footer fallback should not be hooked on non-post screens.'
		);
	}

	/**
	 * When the core handle is registered with a source and enqueued, the
	 * fallback must print nothing and leave the footer hook in place.
	 */
	public function test_fallback_prints_nothing_when_core_handle_healthy() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post.php' );

		ob_start();
		WP_MCP_AI_Admin_Scripts::print_sortable_compatibility_fallback();
		$output = ob_get_clean();

		$this->assertSame(
			'',
			$output,
			'The fallback should not print when the core handle will load.'
		);
		$this->assertNotFalse(
			has_action( 'wp_print_footer_scripts', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ) ),
			'The footer safety net should remain hooked when nothing was printed.'
		);
	}

	/**
	 * When the core handle is dequeued and deregistered, the fallback must
	 * print the bundled jQuery UI Sortable copy inline and unhook the footer
	 * safety net to prevent a duplicate print.
	 */
	public function test_fallback_prints_bundled_sortable_when_core_handle_removed() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post.php' );
		wp_dequeue_script( 'jquery-ui-sortable' );
		wp_deregister_script( 'jquery-ui-sortable' );

		ob_start();
		WP_MCP_AI_Admin_Scripts::print_sortable_compatibility_fallback();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ui.sortable',
			$output,
			'The fallback should print the bundled jQuery UI Sortable copy.'
		);
		$this->assertStringContainsString(
			'jQuery UI Sortable',
			$output,
			'The fallback should print the bundled jQuery UI Sortable license banner.'
		);
		$this->assertFalse(
			has_action( 'wp_print_footer_scripts', array( 'WP_MCP_AI_Admin_Scripts', 'print_sortable_compatibility_fallback' ) ),
			'The footer safety net should be removed after the head print.'
		);
	}

	/**
	 * A hijacked handle that is enqueued but registered without a usable
	 * source must also trigger the fallback.
	 */
	public function test_fallback_prints_when_core_handle_has_no_source() {
		WP_MCP_AI_Admin_Scripts::register_scripts( 'post.php' );
		wp_deregister_script( 'jquery-ui-sortable' );
		wp_register_script( 'jquery-ui-sortable', false );
		wp_enqueue_script( 'jquery-ui-sortable' );

		ob_start();
		WP_MCP_AI_Admin_Scripts::print_sortable_compatibility_fallback();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ui.sortable',
			$output,
			'The fallback should print when the enqueued core handle has no source.'
		);
	}
}
