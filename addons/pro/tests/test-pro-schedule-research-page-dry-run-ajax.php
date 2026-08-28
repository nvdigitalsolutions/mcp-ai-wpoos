<?php
/**
 * Tests for the dry-run AJAX endpoint on the Schedule Research page.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Research_Page_Dry_Run_Ajax
 */
class Test_Pro_Schedule_Research_Page_Dry_Run_Ajax extends WP_Ajax_UnitTestCase {

	/**
	 * Skip when the Pro addon isn't active.
	 */
	protected function setUp(): void {
		parent::setUp();

		$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-research-page.php';
		if ( file_exists( $page_file ) ) {
			require_once $page_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Research_Page' ) ) {
			$this->markTestSkipped( 'Pro Schedule Research Page not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'Pro Schedule Manager not available.' );
		}

		// The page class auto-inits (registering its wp_ajax_* handlers) when
		// the file is first required. If that require happened inside another
		// suite's test method, WP_UnitTestCase::tear_down() restores the hook
		// state captured before that test and wipes the handlers for the rest
		// of the run. Re-register them so this test's dispatch reaches the
		// handler. Re-adding identical static callbacks is safe here because
		// tear_down() strips them again after each test.
		WP_MCP_AI_Pro_Schedule_Research_Page::init();
	}

	/**
	 * Non-admins must receive a 403 from the dry-run endpoint.
	 */
	public function test_dry_run_ajax_requires_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST = array(
			'action'      => 'wp_mcp_ai_dry_run_schedule_from_research',
			'nonce'       => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
			'schedule_id' => 'whatever',
		);

		try {
			$this->_handleAjax( 'wp_mcp_ai_dry_run_schedule_from_research' );
			$this->fail( 'Expected WPAjaxDieStopException.' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through. -- phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Admin happy path: real schedule, dry-run returns a structured preview.
	 */
	public function test_dry_run_ajax_returns_preview_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$created = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'name'          => 'Dry-run AJAX fixture',
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_test_dryrun_ajax_hook',
				'schedule'      => 'daily',
				'enabled'       => true,
			),
			$admin
		);

		if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
			$this->markTestSkipped( 'Could not create fixture schedule.' );
		}

		$_POST = array(
			'action'      => 'wp_mcp_ai_dry_run_schedule_from_research',
			'nonce'       => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
			'schedule_id' => (string) $created['id'],
		);

		try {
			$this->_handleAjax( 'wp_mcp_ai_dry_run_schedule_from_research' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through. -- phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame( (string) $created['id'], $response['data']['schedule_id'] );
		$this->assertSame( 'task', $response['data']['schedule_type'] );
		$this->assertArrayHasKey( 'next_runs', $response['data'] );
		$this->assertArrayHasKey( 'warnings', $response['data'] );
		$this->assertArrayHasKey( 'action', $response['data'] );

		WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $created['id'] );
	}

	/**
	 * Missing schedule_id is rejected with a 400-ish error.
	 */
	public function test_dry_run_ajax_requires_schedule_id() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST = array(
			'action' => 'wp_mcp_ai_dry_run_schedule_from_research',
			'nonce'  => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
		);

		try {
			$this->_handleAjax( 'wp_mcp_ai_dry_run_schedule_from_research' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through. -- phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}
}
