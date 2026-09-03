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
}
