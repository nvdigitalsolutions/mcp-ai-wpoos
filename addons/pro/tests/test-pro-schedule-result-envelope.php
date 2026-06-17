<?php
/**
 * Tests for the scheduled-result envelope produced by WP_MCP_AI_Pro_Schedule_Manager.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_Result_Envelope.
 */
class Test_Pro_Schedule_Result_Envelope extends WP_UnitTestCase {

	/**
	 * Skip when the manager is unavailable.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not available.' );
		}
	}

	/**
	 * Reset stored options between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::RESULTS_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
	}

	/**
	 * Build_result_envelope() produces a typed envelope with summary/data/render.
	 */
	public function test_build_envelope_assistant_run_success() {
		$schedule = array(
			'schedule_type' => 'assistant_run',
			'name'          => 'Daily Digest',
		);
		$log      = array(
			'assistant' => array(
				'assistant_id' => 42,
				'response'     => 'Five priority emails today.',
				'is_agentic'   => true,
			),
		);
		$envelope = WP_MCP_AI_Pro_Schedule_Manager::build_result_envelope( $schedule, $log, true, '' );

		$this->assertIsArray( $envelope );
		$this->assertSame( 'success', $envelope['status'] );
		$this->assertNotEmpty( $envelope['summary'] );
		$this->assertSame( 42, $envelope['data']['assistant_id'] );
		$this->assertContains( $envelope['render'], array( 'markdown', 'text', 'summary-card', 'list', 'table', 'metric', 'timeline' ) );
		$this->assertIsInt( $envelope['generated_at'] );
	}

	/**
	 * On failure the envelope captures the error message and status flips to 'failure'.
	 */
	public function test_build_envelope_failure_status() {
		$envelope = WP_MCP_AI_Pro_Schedule_Manager::build_result_envelope(
			array(
				'schedule_type' => 'task',
				'name'          => 'X',
			),
			array( 'hook' => 'do_thing' ),
			false,
			'boom'
		);
		$this->assertSame( 'failure', $envelope['status'] );
		$this->assertSame( 'boom', $envelope['error'] );
	}

	/**
	 * Retention: trims the result store to the configured retention size.
	 */
	public function test_retention_trims_result_store() {
		$schedule_id = 'unit_test_retention';
		$schedule    = array(
			'id'            => $schedule_id,
			'name'          => 'Test',
			'schedule_type' => 'task',
			'display'       => WP_MCP_AI_Pro_Schedule_Manager::sanitize_display_fields(
				array(
					'result_capture'   => 'summary',
					'result_retention' => 3,
				)
			),
		);
		// Seed the schedule record so the manager can find it.
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array( $schedule_id => $schedule )
		);

		$method = new ReflectionMethod( 'WP_MCP_AI_Pro_Schedule_Manager', 'store_result_envelope' );
		$method->setAccessible( true );

		for ( $i = 0; $i < 5; $i++ ) {
			$env = WP_MCP_AI_Pro_Schedule_Manager::build_result_envelope( $schedule, array( 'hook' => 'h' . $i ), true, '' );
			$method->invoke( null, $schedule_id, $env, $schedule );
		}

		$all = WP_MCP_AI_Pro_Schedule_Manager::get_results( $schedule_id, 100 );
		$this->assertCount( 3, $all, 'retention should cap the store at 3' );
	}

	/**
	 * Result store is independent of the run history option.
	 */
	public function test_results_store_separate_from_history() {
		$schedule_id = 'unit_test_separation';
		$schedule    = array(
			'id'            => $schedule_id,
			'name'          => 'Test',
			'schedule_type' => 'task',
			'display'       => WP_MCP_AI_Pro_Schedule_Manager::sanitize_display_fields( array() ),
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array( $schedule_id => $schedule )
		);

		$method = new ReflectionMethod( 'WP_MCP_AI_Pro_Schedule_Manager', 'store_result_envelope' );
		$method->setAccessible( true );

		$env = WP_MCP_AI_Pro_Schedule_Manager::build_result_envelope( $schedule, array(), true, '' );
		$method->invoke( null, $schedule_id, $env, $schedule );

		// Result store should be populated, history option should not.
		$results = get_option( WP_MCP_AI_Pro_Schedule_Manager::RESULTS_OPTION );
		$this->assertArrayHasKey( $schedule_id, $results );

		$history = get_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION, array() );
		$this->assertArrayNotHasKey( $schedule_id, is_array( $history ) ? $history : array() );
	}

	/**
	 * Public redaction strips data when public_render is off.
	 */
	public function test_public_redaction_strips_data_when_disabled() {
		$schedule = array(
			'name'    => 'X',
			'display' => array(
				'public_render' => false,
				'public_fields' => array( 'summary' ),
			),
		);
		$envelope = array(
			'summary'      => 'top secret',
			'data'         => array( 'secret_field' => 'should not leak' ),
			'render'       => 'text',
			'status'       => 'success',
			'error'        => '',
			'generated_at' => 1000,
		);
		$redacted = WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $envelope, $schedule );
		$this->assertSame( '', $redacted['summary'] );
		$this->assertSame( 'forbidden', $redacted['status'] );
		$this->assertSame( array(), $redacted['data'] );
	}

	/**
	 * Public redaction honours the public_fields allow-list.
	 */
	public function test_public_redaction_allow_list() {
		$schedule = array(
			'name'    => 'X',
			'display' => array(
				'public_render' => true,
				'public_fields' => array( 'summary', 'data.top_3' ),
			),
		);
		$envelope = array(
			'summary'      => 'Five emails today',
			'data'         => array(
				'top_3'  => array( 'A', 'B', 'C' ),
				'secret' => 'redacted',
			),
			'render'       => 'list',
			'status'       => 'success',
			'error'        => '',
			'generated_at' => 1000,
		);

		$redacted = WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $envelope, $schedule );
		$this->assertSame( 'Five emails today', $redacted['summary'] );
		$this->assertArrayHasKey( 'top_3', $redacted['data'] );
		$this->assertSame( array( 'A', 'B', 'C' ), $redacted['data']['top_3'] );
		$this->assertArrayNotHasKey( 'secret', $redacted['data'] );
	}

	/**
	 * Sanitize_display_fields clamps retention and validates render mode.
	 */
	public function test_sanitize_display_fields_clamps_and_validates() {
		$out = WP_MCP_AI_Pro_Schedule_Manager::sanitize_display_fields(
			array(
				'result_capture'   => 'bogus',
				'result_retention' => 9999,
				'public_render'    => 'truthy',
				'public_fields'    => array( 'summary', 'bad path', 'data.items' ),
				'widget_defaults'  => array(
					'render_mode'      => 'evil-mode',
					'refresh_interval' => 99999,
				),
			)
		);
		$this->assertSame( 'summary', $out['result_capture'] );
		$this->assertSame( 100, $out['result_retention'] );
		$this->assertTrue( $out['public_render'] );
		$this->assertSame( array( 'summary', 'badpath', 'data.items' ), $out['public_fields'] );
		$this->assertSame( 'summary-card', $out['widget_defaults']['render_mode'] );
		$this->assertSame( 3600, $out['widget_defaults']['refresh_interval'] );
	}
}
