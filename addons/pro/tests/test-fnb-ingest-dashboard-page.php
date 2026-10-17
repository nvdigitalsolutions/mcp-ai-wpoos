<?php
/**
 * Tests for the F&B Ingest & Preview dashboard page.
 *
 * The import *write* is ACT-tier (manage_options). This file drives the real
 * AJAX endpoint the client-side resume/retry loop depends on and locks the
 * contract that keeps a large import from restarting at zero after a transient
 * failure:
 *
 *   1. The `wp_mcp_ai_fnb_ingest_import` action is registered and gated at
 *      manage_options (never the review-only edit_posts).
 *   2. A bad nonce is rejected.
 *   3. A limited import forwards offset/limit and returns `next_offset`/
 *      `has_more` so the frontend can page through the source without
 *      re-sending rows it already wrote (idempotent upserts make a retry of the
 *      *same* offset safe).
 *   4. The inline JS ships the resume cursor + retry machinery.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Class Test_Fnb_Ingest_Dashboard_Page
 */
class Test_Fnb_Ingest_Dashboard_Page extends WP_Ajax_UnitTestCase {

	/**
	 * Nonce action used by every handler on the ingest dashboard.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_fnb_ingest_preview';

	/**
	 * AJAX action for the import write.
	 *
	 * @var string
	 */
	const IMPORT_ACTION = 'wp_mcp_ai_fnb_ingest_import';

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up admin context, the fixture adapter and native storage.
	 */
	public function setUp(): void {
		parent::setUp();

		// Force the no-network CSV fixture adapter (the Drive adapter would
		// attempt real HTTP and fails in CI).
		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$this->require_services();

		// The AJAX write is gated on native storage + the CPT being registered.
		// Guard on the class because require_services() is a no-op without Pro.
		if ( class_exists( 'WP_MCP_AI_Fnb_Booking_CPT' ) ) {
			update_option(
				'wp_mcp_ai_fnb_settings',
				array( 'enable_fnb_native_storage' => true )
			);
			WP_MCP_AI_Fnb_Booking_CPT::register();
		}

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialize the page so the wp_ajax_* handlers are registered. A prior
		// test's tear_down() may have stripped them, so re-register (idempotent).
		if ( class_exists( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page' ) ) {
			WP_MCP_AI_Fnb_Ingest_Dashboard_Page::init();
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		unset( $_POST['action'], $_POST['nonce'], $_POST['table_id'], $_POST['offset'], $_POST['limit'] );
		parent::tearDown();
	}

	/**
	 * Require the toolkit services and the dashboard page class.
	 *
	 * @return void
	 */
	private function require_services() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return;
		}

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/';
		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-fixture-reader.php',
				'trait-wp-mcp-ai-fnb-native-cpt-storage.php',
				'class-wp-mcp-ai-fnb-cpt-reader.php',
				'class-wp-mcp-ai-fnb-sale-cpt.php',
				'class-wp-mcp-ai-fnb-purchase-cpt.php',
				'class-wp-mcp-ai-fnb-stock-count-cpt.php',
				'class-wp-mcp-ai-fnb-waste-cpt.php',
				'class-wp-mcp-ai-fnb-booking-cpt.php',
			) as $file
		) {
			$path = $base . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		$page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-ingest-dashboard-page.php';
		if ( file_exists( $page ) ) {
			require_once $page;
		}
	}

	/**
	 * Dispatch the import AJAX action and return the decoded response.
	 *
	 * @param array $payload POST fields (without the action).
	 * @return array|null Decoded JSON response data.
	 */
	private function dispatch_import( array $payload ) {
		$_POST                = array_merge(
			array(
				'action' => self::IMPORT_ACTION,
				'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
			),
			$payload
		);
		$this->_last_response = '';

		try {
			$this->_handleAjax( self::IMPORT_ACTION );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — wp_send_json_* exits via the throwing test handler.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected.
		}

		return json_decode( $this->_last_response, true );
	}

	/**
	 * The import AJAX action is registered for logged-in users only.
	 */
	public function test_import_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'F&B ingest dashboard page not available.' );
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_' . self::IMPORT_ACTION, array( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page', 'ajax_import' ) ),
			'Import AJAX action must be registered.'
		);
	}

	/**
	 * Non-admins must be denied from the import write (manage_options gate).
	 */
	public function test_import_ajax_requires_manage_options() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'F&B ingest dashboard page not available.' );
		}

		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$response = $this->dispatch_import( array( 'table_id' => '16.0' ) );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * A bad nonce is rejected before any write happens.
	 */
	public function test_import_ajax_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'F&B ingest dashboard page not available.' );
		}

		$_POST                = array(
			'action'   => self::IMPORT_ACTION,
			'nonce'    => 'not-a-real-nonce',
			'table_id' => '16.0',
		);
		$this->_last_response = '';

		// check_ajax_referer() fails the request via wp_die() — under wp-phpunit
		// that terminates with a die exception (not a JSON success body). A
		// sacrificial buffer absorbs the handler's double-wp_die.
		ob_start();
		$terminated = false;
		try {
			$this->_handleAjax( self::IMPORT_ACTION );
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$terminated = true;
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Some WP versions route the -1 through the continue path instead.
		}
		// Discard the sacrificial buffer that absorbed the handler's double-wp_die.
		ob_get_clean();

		$response = json_decode( $this->_last_response, true );

		// No success envelope may be produced for an invalid nonce.
		$this->assertFalse(
			is_array( $response ) && ! empty( $response['success'] ),
			'An invalid nonce must not produce a success response.'
		);

		// The request must have terminated (die) rather than falling through.
		$this->assertTrue(
			$terminated || '' === $this->_last_response,
			'A bad nonce must halt the request before any write.'
		);
	}

	/**
	 * A limited import returns next_offset/has_more, and the next call with that
	 * offset advances — proving the resume cursor has a server contract to
	 * depend on.
	 */
	public function test_import_ajax_exposes_resumable_offsets() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'F&B ingest dashboard page not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Booking_CPT' ) ) {
			$this->markTestSkipped( 'F&B booking CPT not available.' );
		}

		// First batch: 2 rows starting at offset 0.
		$first = $this->dispatch_import(
			array(
				'table_id' => '16.0',
				'offset'   => 0,
				'limit'    => 2,
			)
		);

		$this->assertIsArray( $first );
		$this->assertTrue( $first['success'], 'First batch should succeed: ' . wp_json_encode( $first ) );

		$data = $first['data'];
		$this->assertArrayHasKey( 'has_more', $data );
		$this->assertArrayHasKey( 'next_offset', $data );
		$this->assertTrue( $data['has_more'], 'A small limit over a larger source must report more rows.' );
		$this->assertSame( 2, (int) $data['next_offset'], 'next_offset advances by the row count of the batch.' );

		// Second batch resumes exactly where the first left off.
		$second = $this->dispatch_import(
			array(
				'table_id' => '16.0',
				'offset'   => $data['next_offset'],
				'limit'    => 2,
			)
		);

		$this->assertIsArray( $second );
		$this->assertTrue( $second['success'], 'Resumed batch should succeed: ' . wp_json_encode( $second ) );
		$this->assertSame( 4, (int) $second['data']['next_offset'], 'The resumed batch keeps advancing the cursor.' );
	}

	/**
	 * The inline JS ships the resume cursor + retry machinery that keeps a large
	 * import from restarting at zero after a transient batch failure.
	 */
	public function test_dashboard_js_ships_resume_and_retry_machinery() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'F&B ingest dashboard page not available.' );
		}

		$ref = new ReflectionMethod( 'WP_MCP_AI_Fnb_Ingest_Dashboard_Page', 'get_dashboard_js' );
		$ref->setAccessible( true );
		$js = $ref->invoke( null );

		// Resume cursor: a failed run persists the offset for the next click.
		$this->assertStringContainsString( 'resumeCursor', $js, 'JS must track a resume cursor.' );

		// The resumed run seeds its running total with the already-imported rows
		// so the final count reflects prior runs, not just the current click.
		$this->assertStringContainsString( '{ total: resumeCursor', $js, 'JS must seed the running total with the resume cursor.' );

		// Retry loop: transient failures retry the same offset (idempotent),
		// bounded so it can't loop forever.
		$this->assertStringContainsString( 'importRetrying', $js, 'JS must surface a retry message.' );
		$this->assertStringContainsString( 'attempt < 3', $js, 'JS must bound retries.' );
		$this->assertStringContainsString( 'runImportBatch(tableId, offset, totals, msgEl, attempt + 1)', $js, 'Retry must reuse the same offset.' );
	}
}
