<?php
/**
 * Tests for the Pause/Resume and Run-now AJAX endpoints on the Schedule
 * Research page.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Research_Page_Action_Ajax
 */
class Test_Pro_Schedule_Research_Page_Action_Ajax extends WP_Ajax_UnitTestCase {

	/**
	 * Skip when the Pro addon isn't active.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Research_Page' ) ) {
			$this->markTestSkipped( 'Pro Schedule Research Page not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'Pro Schedule Manager not available.' );
		}
	}

	/**
	 * Helper: dispatch an AJAX action and decode the response JSON.
	 *
	 * @param string $action AJAX action.
	 * @return array Decoded response.
	 */
	protected function dispatch( $action ) {
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// fall through. -- phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch.
		}
		return json_decode( $this->_last_response, true );
	}

	/**
	 * Helper: create an enabled fixture schedule.
	 *
	 * @param int $admin Admin user id.
	 * @return array|null
	 */
	protected function make_schedule( $admin ) {
		$created = WP_MCP_AI_Pro_Schedule_Manager::create_schedule(
			array(
				'name'          => 'Action AJAX fixture',
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_test_action_ajax_hook',
				'schedule'      => 'daily',
				'enabled'       => true,
			),
			$admin
		);

		if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
			return null;
		}
		return $created;
	}

	/**
	 * Non-admins must receive a permission-denied response from the toggle endpoint.
	 */
	public function test_toggle_requires_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST = array(
			'action'      => 'wp_mcp_ai_toggle_schedule_from_research',
			'nonce'       => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
			'schedule_id' => 'whatever',
			'enabled'     => 0,
		);

		$response = $this->dispatch( 'wp_mcp_ai_toggle_schedule_from_research' );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Admin happy path: toggle disables an enabled schedule.
	 */
	public function test_toggle_disables_schedule_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$created = $this->make_schedule( $admin );
		if ( null === $created ) {
			$this->markTestSkipped( 'Could not create fixture schedule.' );
		}

		$_POST = array(
			'action'      => 'wp_mcp_ai_toggle_schedule_from_research',
			'nonce'       => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
			'schedule_id' => (string) $created['id'],
			'enabled'     => 0,
		);

		$response = $this->dispatch( 'wp_mcp_ai_toggle_schedule_from_research' );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['enabled'] );

		$after = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $created['id'] );
		$this->assertNotNull( $after );
		$this->assertFalse( ! empty( $after['enabled'] ) );

		WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $created['id'] );
	}

	/**
	 * Missing schedule_id is rejected.
	 */
	public function test_toggle_requires_schedule_id() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST = array(
			'action'  => 'wp_mcp_ai_toggle_schedule_from_research',
			'nonce'   => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
			'enabled' => 1,
		);

		$response = $this->dispatch( 'wp_mcp_ai_toggle_schedule_from_research' );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Non-admins must be denied from run-now.
	 */
	public function test_run_now_requires_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST = array(
			'action'      => 'wp_mcp_ai_run_now_schedule_from_research',
			'nonce'       => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
			'schedule_id' => 'whatever',
		);

		$response = $this->dispatch( 'wp_mcp_ai_run_now_schedule_from_research' );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Missing schedule_id on run-now is rejected.
	 */
	public function test_run_now_requires_schedule_id() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST = array(
			'action' => 'wp_mcp_ai_run_now_schedule_from_research',
			'nonce'  => wp_create_nonce( 'wp_mcp_ai_research_pro_schedule' ),
		);

		$response = $this->dispatch( 'wp_mcp_ai_run_now_schedule_from_research' );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}
}
