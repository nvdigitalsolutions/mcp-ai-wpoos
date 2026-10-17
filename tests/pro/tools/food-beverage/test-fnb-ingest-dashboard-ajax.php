<?php
/**
 * F&B Ingest Dashboard AJAX Tests — preview/scan/import guard paths.
 *
 * Covers the three `wp_ajax_*` handlers registered by
 * WP_MCP_AI_Fnb_Ingest_Dashboard_Page:
 *   - wp_mcp_ai_fnb_ingest_preview  (edit_posts review, missing table_id 400)
 *   - wp_mcp_ai_fnb_ingest_scan     (edit_posts review, permission gate)
 *   - wp_mcp_ai_fnb_ingest_import   (manage_options write, permission gate)
 *
 * The handlers terminate via wp_send_json_*(); WP 6.9's wp_send_json() calls a
 * bare die() when wp_doing_ajax() is false, which would kill the suite. The
 * wp_doing_ajax filter routes termination through wp_die(), which the test
 * bootstrap converts into a catchable WPDieException, and the echoed JSON is
 * captured for assertions — the same pattern used by the Auth0 bridge toggle
 * test.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B ingest dashboard AJAX handlers.
 */
class Test_Fnb_Ingest_Dashboard_Ajax extends WP_UnitTestCase {

	/**
	 * Path to the ingest dashboard page class.
	 *
	 * @var string
	 */
	private $admin_file;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_file = dirname( __DIR__, 4 ) . '/addons/pro/includes/admin/class-wp-mcp-ai-fnb-ingest-dashboard-page.php';
		$this->assertFileExists( $this->admin_file );
		require_once $this->admin_file;

		// Register the AJAX hooks + menu/assets so the handler names are bound.
		if ( ! has_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_preview' ) ) {
			WP_MCP_AI_Fnb_Ingest_Dashboard_Page::init();
		}
	}

	/**
	 * Invoke a handler the way an admin-ajax request would, without killing the
	 * suite, and return the decoded JSON response body.
	 *
	 * @param string $method Static method name on the dashboard page class.
	 * @param string $nonce  Nonce value to submit.
	 * @param array  $post   Extra $_POST / $_REQUEST fields.
	 * @return array Decoded JSON response.
	 */
	protected function invoke_handler( $method, $nonce, array $post = array() ) {
		// check_ajax_referer() reads $_REQUEST, not $_POST.
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		foreach ( $post as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}

		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			WP_MCP_AI_Fnb_Ingest_Dashboard_Page::$method();
		} catch ( WPDieException $e ) {
			unset( $e ); // Expected termination via wp_send_json_*().
		}
		$response = ob_get_clean();

		remove_filter( 'wp_doing_ajax', '__return_true' );

		return json_decode( $response, true );
	}

	/**
	 * The three handler names must be registered on wp_ajax_ hooks.
	 */
	public function test_ajax_handlers_are_registered() {
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_preview' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_scan' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_fnb_ingest_import' ) );
	}

	/**
	 * Preview requires edit_posts and rejects a missing table_id with a 400.
	 */
	public function test_preview_rejects_missing_table_id() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$data = $this->invoke_handler(
			'ajax_preview',
			wp_create_nonce( 'wp_mcp_ai_fnb_ingest_preview' )
		);

		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Table ID is required', $data['data']['message'] );
	}

	/**
	 * Scan requires edit_posts; a subscriber is denied before any scan runs.
	 */
	public function test_scan_requires_edit_posts() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$data = $this->invoke_handler(
			'ajax_scan',
			wp_create_nonce( 'wp_mcp_ai_fnb_ingest_preview' )
		);

		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Permission denied', $data['data']['message'] );
	}

	/**
	 * Import is an ACT-tier write: manage_options is required, so an editor is
	 * denied regardless of nonce validity.
	 */
	public function test_import_requires_manage_options() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$data = $this->invoke_handler(
			'ajax_import',
			wp_create_nonce( 'wp_mcp_ai_fnb_ingest_preview' ),
			array( 'table_id' => '6.0' )
		);

		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'do not have permission', $data['data']['message'] );
	}
}
