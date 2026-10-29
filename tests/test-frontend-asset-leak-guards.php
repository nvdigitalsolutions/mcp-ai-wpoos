<?php
/**
 * Regression tests for the front-end asset-leak fixes (issue #7270).
 *
 * Covers the three guards that stopped editor/admin-only assets from
 * loading on anonymous visitor pages:
 *
 *  1. Quick Actions widget assets must not enqueue on plain front-end
 *     requests (register-only outside the Elementor editor / wp-admin).
 *  2. chat.css must not declare cron-status.css as a dependency.
 *  3. The Konva markup stack must not enqueue merely because the chat
 *     bundle is REGISTERED — only when it is actually ENQUEUED.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the front-end asset-leak guards.
 */
class Test_Frontend_Asset_Leak_Guards extends WP_UnitTestCase {

	/**
	 * Snapshot of the plugin settings option before the test writes to it.
	 *
	 * @var mixed
	 */
	private $previous_settings;

	/**
	 * Set up: force the markup subsystem to report enabled so the footer
	 * shim's gate does not depend on settings written by other suites.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->previous_settings = get_option( 'wp_mcp_ai_settings', null );
		$settings                = is_array( $this->previous_settings ) ? $this->previous_settings : array();
		$settings['markup_enabled'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Tear down: restore the settings option and drop the static cache.
	 */
	public function tearDown(): void {
		if ( null === $this->previous_settings ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->previous_settings );
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		parent::tearDown();
	}

	/**
	 * Leak 1: quick-actions assets must never ENQUEUE on a visitor page.
	 *
	 * On a plain front-end request (PHPUnit context: not admin, no Elementor
	 * editor/preview) the handler either bails before registering (no
	 * Elementor at all) or registers the handles for Elementor's dependency
	 * mechanism without enqueueing them.
	 */
	public function test_quick_actions_assets_never_enqueue_on_frontend() {
		$handler = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		$handler->enqueue_assets();

		if ( ! did_action( 'elementor/loaded' ) && ! is_admin() ) {
			// No Elementor: the handler must bail before registering anything.
			$this->assertFalse( wp_style_is( 'wp-mcp-ai-quick-actions-widget', 'registered' ), 'Quick Actions style must not register without Elementor.' );
			$this->assertFalse( wp_script_is( 'wp-mcp-ai-quick-actions-widget', 'registered' ), 'Quick Actions script must not register without Elementor.' );
			return;
		}

		// Elementor active: handles may be registered (so the widget's
		// get_script_depends() / get_style_depends() resolve) but must not be
		// enqueued outside the editor / preview / admin contexts.
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-quick-actions-widget', 'registered' ), 'Quick Actions style should be registered when Elementor is active.' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-quick-actions-widget', 'registered' ), 'Quick Actions script should be registered when Elementor is active.' );
		$this->assertFalse( wp_style_is( 'wp-mcp-ai-quick-actions-widget', 'enqueued' ), 'Quick Actions style must not enqueue on a visitor page.' );
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-quick-actions-widget', 'enqueued' ), 'Quick Actions script must not enqueue on a visitor page.' );
	}

	/**
	 * Leak 2: chat.css must not declare cron-status.css as a dependency.
	 *
	 * The cron-status handle must still be registered so the admin
	 * dashboards and the front-end jobs counter can enqueue it explicitly.
	 */
	public function test_chat_style_does_not_depend_on_cron_status_style() {
		// The style registry is process-wide and never reset between suites: an
		// earlier admin suite can register wp-mcp-ai-chat with the cron-status
		// dependency, and wp_register_style() is a no-op for already-registered
		// handles. Deregister both handles first so this test asserts exactly
		// what register_assets() declares.
		wp_deregister_style( 'wp-mcp-ai-chat' );
		wp_deregister_style( 'wp-mcp-ai-cron-status' );

		$shortcode = new WP_MCP_AI_Shortcode();
		$shortcode->register_assets();

		$this->assertTrue( wp_style_is( 'wp-mcp-ai-chat', 'registered' ), 'Chat style should be registered.' );
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-cron-status', 'registered' ), 'Cron-status style should still be registered for explicit enqueues.' );

		$styles     = wp_styles();
		$chat_style = isset( $styles->registered['wp-mcp-ai-chat'] ) ? $styles->registered['wp-mcp-ai-chat'] : null;
		$this->assertNotNull( $chat_style, 'Chat style handle should exist in the style registry.' );
		$this->assertNotContains( 'wp-mcp-ai-cron-status', $chat_style->deps, 'chat.css must not drag in cron-status.css.' );
	}

	/**
	 * Leak 3: the markup stack must not enqueue when chat is only REGISTERED.
	 *
	 * The old shim enqueued the full Konva stack on wp_enqueue_scripts
	 * whenever the chat handle was registered (i.e. every front-end page).
	 * The fix moves the decision to wp_footer and gates on `enqueued`, so
	 * wp_enqueue_scripts must leave the stack untouched.
	 */
	public function test_markup_stack_not_enqueued_when_chat_only_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Markup_Assets' ) || ! class_exists( 'WP_MCP_AI_Markup_Loop_Interceptor' ) ) {
			$this->markTestSkipped( 'Markup subsystem not available.' );
		}

		WP_MCP_AI_Markup_Assets::register();
		wp_register_script( 'wp-mcp-ai-chat', 'https://example.org/wp-mcp-ai-chat.js', array(), 'test', true );

		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( 'wp-mcp-ai-markup-client', 'enqueued' ), 'Markup client must not enqueue when chat is only registered.' );
		$this->assertFalse( wp_style_is( 'wp-mcp-ai-markup-style', 'enqueued' ), 'Markup style must not enqueue when chat is only registered.' );
	}

	/**
	 * Leak 3 (positive path): the markup stack enqueues at wp_footer when the
	 * chat bundle is actually enqueued.
	 *
	 * Temporarily removes wp_print_footer_scripts() (priority 20) so it does
	 * not consume the script queue before the assertions run.
	 */
	public function test_markup_stack_enqueues_at_footer_when_chat_is_enqueued() {
		if ( ! class_exists( 'WP_MCP_AI_Markup_Assets' ) || ! class_exists( 'WP_MCP_AI_Markup_Loop_Interceptor' ) ) {
			$this->markTestSkipped( 'Markup subsystem not available.' );
		}

		WP_MCP_AI_Markup_Assets::register();
		wp_register_script( 'wp-mcp-ai-chat', 'https://example.org/wp-mcp-ai-chat.js', array(), 'test', true );
		wp_enqueue_script( 'wp-mcp-ai-chat' );

		// Keep the footer firing side-effect free: the deprecated core skip-link
		// callback would trip wp-phpunit's unexpected-deprecation guard, and
		// wp_print_footer_scripts would consume the script queue before the
		// assertions below run.
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
		remove_action( 'wp_footer', 'wp_print_footer_scripts', 20 );
		do_action( 'wp_footer' );
		add_action( 'wp_footer', 'the_block_template_skip_link' );
		add_action( 'wp_footer', 'wp_print_footer_scripts', 20 );

		$this->assertTrue( wp_script_is( 'wp-mcp-ai-markup-client', 'enqueued' ), 'Markup client should enqueue alongside an enqueued chat bundle.' );
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-markup-style', 'enqueued' ), 'Markup style should enqueue alongside an enqueued chat bundle.' );
	}
}
