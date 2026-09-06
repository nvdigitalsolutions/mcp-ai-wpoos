<?php
/**
 * Admin telemetry page tests.
 *
 * Covers the empty-state and populated-state markup, the reset handler
 * capability gate, and the reset handler clearing the option.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-request.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-telemetry.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-markup-telemetry-page.php';

/**
 * Test_Markup_Telemetry_Admin_Page test case.
 *
 * @group markup
 * @group admin
 */
class Test_Markup_Telemetry_Admin_Page extends WP_UnitTestCase {

	/**
	 * Page under test.
	 *
	 * @var WP_MCP_AI_Admin_Markup_Telemetry_Page
	 */
	private $page;

	/**
	 * Set up the page instance.
	 *
	 * Recording is handled by the globally registered recorder from
	 * includes/markup-init.php; a second instance would double-count every
	 * event fired by these fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Markup_Telemetry::reset();
		$this->page = new WP_MCP_AI_Admin_Markup_Telemetry_Page();
	}

	/**
	 * Reset counters.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Markup_Telemetry::reset();
		parent::tearDown();
	}

	/**
	 * Build a synthetic request bound to a given slug / mode.
	 *
	 * @param string $slug Tool slug.
	 * @param string $mode Markup mode.
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function build_request( $slug, $mode ) {
		return new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => $slug,
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => $mode,
				'target'      => array( 'attachment_id' => 1 ),
			)
		);
	}

	/**
	 * Capture the rendered HTML by promoting the current user to admin.
	 *
	 * @return string Rendered output.
	 */
	private function capture_render() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		ob_start();
		$this->page->render_page();
		return (string) ob_get_clean();
	}

	/**
	 * Empty-state render: heading + outcomes table + "No data yet" copy
	 * for both breakdown sections.
	 */
	public function test_empty_state_renders_outcomes_table_and_no_data_copy() {
		$html = $this->capture_render();

		$this->assertStringContainsString( 'NV oOS Markup Telemetry', $html );
		$this->assertStringContainsString( 'wp-mcp-ai-mt__cards', $html );
		$this->assertStringContainsString( 'By tool', $html );
		$this->assertStringContainsString( 'By mode', $html );
		$this->assertStringContainsString( 'No data yet.', $html );
		// Outcomes table rows for every bucket.
		foreach ( WP_MCP_AI_Markup_Telemetry::outcomes() as $outcome ) {
			$this->assertStringContainsString( '<code>' . $outcome . '</code>', $html );
		}
		// Reset form should always be present (it has its own capability check).
		$this->assertStringContainsString( WP_MCP_AI_Admin_Markup_Telemetry_Page::RESET_ACTION, $html );
	}

	/**
	 * Populated render: card values reflect counter totals and tool /
	 * mode tables list the recorded slugs.
	 */
	public function test_populated_render_shows_counts_and_breakdowns() {
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'edit_openai_image', 'mask' ), null );
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'edit_openai_image', 'mask' ), null );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'edit_openai_image', 'mask' ), 'completed' );
		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'crop_image', 'crop' ), 'cancelled' );

		$html = $this->capture_render();

		$this->assertStringContainsString( '<code>edit_openai_image</code>', $html );
		$this->assertStringContainsString( '<code>crop_image</code>', $html );
		$this->assertStringContainsString( '<code>mask</code>', $html );
		$this->assertStringContainsString( '<code>crop</code>', $html );
		// Completion-rate card should be rendered with one of the modifier classes.
		$this->assertMatchesRegularExpression( '/wp-mcp-ai-mt__card--(ok|warn|err)/', $html );
		// "No data yet" must not appear when both breakdowns are populated.
		$this->assertStringNotContainsString( 'No data yet.', $html );
	}

	/**
	 * The reset handler must reject users without `manage_options`.
	 */
	public function test_reset_handler_blocks_non_admins() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );

		$exception = null;
		try {
			$this->page->handle_reset();
		} catch ( WPDieException $e ) {
			$exception = $e;
		}

		$this->assertInstanceOf( 'WPDieException', $exception );
		// Counter must be untouched.
		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		$this->assertSame( 1, $summary['counts']['created'] );
	}

	/**
	 * The reset handler must clear counters for an admin and `wp_safe_redirect`
	 * back to the page.
	 */
	public function test_reset_handler_clears_counters_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		do_action( 'wp_mcp_ai_markup_request_created', $this->build_request( 'crop_image', 'crop' ), null );
		do_action( 'wp_mcp_ai_markup_resolved', $this->build_request( 'crop_image', 'crop' ), 'completed' );

		// Provide a valid nonce so check_admin_referer() passes.
		$_REQUEST['_wpnonce'] = wp_create_nonce( WP_MCP_AI_Admin_Markup_Telemetry_Page::RESET_ACTION );

		// Intercept wp_safe_redirect via filter to avoid exit.
		add_filter( 'wp_redirect', '__return_false' );

		$exception = null;
		try {
			$this->page->handle_reset();
		} catch ( WPDieException $e ) {
			$exception = $e;
		}

		remove_filter( 'wp_redirect', '__return_false' );
		unset( $_REQUEST['_wpnonce'] );

		// `exit` after `wp_safe_redirect` translates to a die in tests.
		// Either path is acceptable; we only care that the option was reset.
		unset( $exception );

		$summary = WP_MCP_AI_Markup_Telemetry::get_summary();
		foreach ( $summary['counts'] as $value ) {
			$this->assertSame( 0, $value );
		}
		$this->assertSame( array(), $summary['tools'] );
	}
}
