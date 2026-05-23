<?php
/**
 * Tests for the Scheduled Result REST controller.
 *
 * Covers permission gating (public_render off vs. on), redaction on the
 * unauthenticated path, and presence of the ETag header on the latest-result
 * route.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Result_REST.
 */
class Test_Pro_Schedule_Result_REST extends WP_UnitTestCase {

	/**
	 * Skip when manager unavailable.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'Pro Schedule Manager not available.' );
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-schedule-result-controller.php';
	}

	/**
	 * Reset between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::RESULTS_OPTION );

		// Ensure routes are registered.
		do_action( 'rest_api_init' );
	}

	/**
	 * Seed a schedule + envelope.
	 *
	 * @param array $display Display sub-array overrides.
	 * @return string Schedule ID.
	 */
	protected function seed_schedule( array $display = array() ) {
		$schedule_id = 'rest_test_' . uniqid();
		$schedule    = array(
			'id'            => $schedule_id,
			'name'          => 'REST Test',
			'schedule_type' => 'task',
			'display'       => WP_MCP_AI_Pro_Schedule_Manager::sanitize_display_fields( $display ),
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array( $schedule_id => $schedule )
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::RESULTS_OPTION,
			array(
				$schedule_id => array(
					array(
						'summary'      => 'Hello world',
						'data'         => array(
							'items'  => array( 'a', 'b' ),
							'secret' => 'x',
						),
						'render'       => 'list',
						'status'       => 'success',
						'error'        => '',
						'generated_at' => 1700000000,
					),
				),
			)
		);
		return $schedule_id;
	}

	/**
	 * Unauthenticated request to a schedule without public_render returns 401/403.
	 */
	public function test_unauth_blocked_when_public_render_off() {
		$schedule_id = $this->seed_schedule( array( 'public_render' => false ) );
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/' . $schedule_id . '/latest-result' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertTrue( in_array( $response->get_status(), array( 401, 403 ), true ), 'should refuse unauth' );
	}

	/**
	 * Unauthenticated request with public_render=true returns a redacted envelope.
	 */
	public function test_unauth_redacted_when_public_render_on() {
		$schedule_id = $this->seed_schedule(
			array(
				'public_render' => true,
				'public_fields' => array( 'summary', 'data.items' ),
			)
		);
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/' . $schedule_id . '/latest-result' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'envelope', $data );
		$this->assertSame( 'Hello world', $data['envelope']['summary'] );
		$this->assertSame( array( 'a', 'b' ), $data['envelope']['data']['items'] );
		$this->assertArrayNotHasKey( 'secret', $data['envelope']['data'] );

		// ETag should be present.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'ETag', $headers );
	}

	/**
	 * Authenticated admin gets the un-redacted envelope.
	 */
	public function test_admin_gets_full_envelope() {
		$schedule_id = $this->seed_schedule( array( 'public_render' => false ) );
		$user_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules/' . $schedule_id . '/latest-result' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'x', $data['envelope']['data']['secret'] );
	}

	/**
	 * Preview route requires authentication + a valid nonce.
	 */
	public function test_preview_requires_nonce_and_caps() {
		$schedule_id = $this->seed_schedule();
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/schedules/' . $schedule_id . '/preview' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertTrue( in_array( $response->get_status(), array( 401, 403 ), true ) );
	}

	/**
	 * The selectable schedules endpoint lists what the picker should show.
	 */
	public function test_selectable_endpoint_returns_array() {
		$this->seed_schedule();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/schedules' );
		$request->set_param( 'selectable', '1' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}
}
