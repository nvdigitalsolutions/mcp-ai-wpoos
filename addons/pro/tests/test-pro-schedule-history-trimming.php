<?php
/**
 * Tests for the action-log trimming applied before writes to the schedule
 * history ring buffer.
 *
 * Regression cover for WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION growing
 * without bound because `record_run()` persisted the raw `$action_log` — which
 * carries the full assistant response, every workflow step result, and the
 * complete workflow-builder node output — for 50 runs per schedule.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_Pro_Schedule_History_Trimming.
 */
class Test_Pro_Schedule_History_Trimming extends WP_UnitTestCase {

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
	 * Invoke the protected trimmer.
	 *
	 * @param array $action_log Raw action log.
	 * @return array Trimmed action log.
	 */
	protected function trim( array $action_log ) {
		$method = new ReflectionMethod( 'WP_MCP_AI_Pro_Schedule_Manager', 'trim_action_log_for_history' );
		$method->setAccessible( true );

		return $method->invoke( null, $action_log );
	}

	/**
	 * A long assistant response must be bounded but still present.
	 */
	public function test_assistant_response_is_bounded() {
		$trimmed = $this->trim(
			array(
				'type'      => 'assistant_run',
				'assistant' => array(
					'assistant_id' => 42,
					'is_agentic'   => true,
					'message'      => str_repeat( 'a', 40000 ),
					'response'     => str_repeat( 'b', 40000 ),
				),
			)
		);

		$this->assertSame( 'assistant_run', $trimmed['type'] );
		$this->assertSame( 42, $trimmed['assistant']['assistant_id'] );
		$this->assertTrue( $trimmed['assistant']['is_agentic'] );

		foreach ( array( 'message', 'response' ) as $field ) {
			$this->assertLessThanOrEqual(
				WP_MCP_AI_Pro_Schedule_Manager::MAX_HISTORY_TEXT_LENGTH + 4,
				strlen( $trimmed['assistant'][ $field ] ),
				"Assistant {$field} was not bounded."
			);
		}
	}

	/**
	 * Every field the history modal renders must survive trimming.
	 *
	 * Mirrors SM.formatActionLog() in addons/pro/assets/js/schedule-manager.js.
	 */
	public function test_history_ui_contract_is_preserved() {
		$trimmed = $this->trim(
			array(
				'type' => 'task',
				'hook' => 'my_custom_hook',
				'args' => array( 'post_id' => 7 ),
			)
		);
		$this->assertSame( 'my_custom_hook', $trimmed['hook'] );
		$this->assertSame( array( 'post_id' => 7 ), $trimmed['args'], 'Small args must keep their original shape.' );

		$trimmed = $this->trim(
			array(
				'type'  => 'workflow',
				'steps' => array(
					array(
						'tool_slug' => 'get_posts',
						'label'     => 'Fetch posts',
						'duration'  => 1.25,
						'result'    => str_repeat( 'c', 9000 ),
					),
				),
			)
		);
		$this->assertSame( 'get_posts', $trimmed['steps'][0]['tool_slug'] );
		$this->assertSame( 'Fetch posts', $trimmed['steps'][0]['label'] );
		$this->assertSame( 1.25, $trimmed['steps'][0]['duration'] );
		// The UI reads `result`, not `result_excerpt`.
		$this->assertArrayHasKey( 'result', $trimmed['steps'][0] );
		$this->assertLessThanOrEqual(
			WP_MCP_AI_Pro_Schedule_Manager::MAX_HISTORY_TEXT_LENGTH + 4,
			strlen( $trimmed['steps'][0]['result'] )
		);

		$trimmed = $this->trim(
			array(
				'type'      => 'channel_broadcast',
				'broadcast' => array(
					'channels' => array( 'slack', 'email' ),
					'message'  => 'Deploy finished.',
					'summary'  => array(
						'successful_channels' => 2,
						'total_channels'      => 2,
					),
				),
			)
		);
		$this->assertSame( array( 'slack', 'email' ), $trimmed['broadcast']['channels'] );
		$this->assertSame( 'Deploy finished.', $trimmed['broadcast']['message'] );
		$this->assertSame( 2, $trimmed['broadcast']['summary']['successful_channels'] );

		$trimmed = $this->trim(
			array(
				'type'                => 'workflow_builder',
				'workflow_builder_id' => 'simple_wf',
				'nodes'               => array(
					array( 'output' => str_repeat( 'd', 30000 ) ),
					array( 'output' => str_repeat( 'e', 30000 ) ),
				),
			)
		);
		$this->assertSame( 'simple_wf', $trimmed['workflow_builder_id'] );
		// The verbose node payload is replaced by a count.
		$this->assertArrayNotHasKey( 'nodes', $trimmed );
		$this->assertSame( 2, $trimmed['node_count'] );
	}

	/**
	 * A worst-case action log must encode to a small, bounded size.
	 */
	public function test_trimmed_log_is_small() {
		$steps = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$steps[] = array(
				'tool_slug' => 'tool_' . $i,
				'label'     => 'Step ' . $i,
				'duration'  => 0.5,
				'result'    => str_repeat( 'x', 50000 ),
			);
		}

		$raw = array(
			'type'      => 'workflow',
			'steps'     => $steps,
			'assistant' => array(
				'assistant_id' => 1,
				'response'     => str_repeat( 'y', 80000 ),
			),
			'nodes'     => array_fill( 0, 10, array( 'output' => str_repeat( 'z', 20000 ) ) ),
		);

		$raw_size     = strlen( (string) wp_json_encode( $raw ) );
		$trimmed_size = strlen( (string) wp_json_encode( $this->trim( $raw ) ) );

		$this->assertGreaterThan( 1000000, $raw_size, 'Fixture should represent a genuinely large log.' );
		$this->assertLessThan( 20000, $trimmed_size, 'Trimmed log must be bounded.' );
	}

	/**
	 * Oversized structured values degrade to a truncated string rather than
	 * being dropped entirely.
	 */
	public function test_oversized_structured_value_degrades_to_string() {
		$trimmed = $this->trim(
			array(
				'type' => 'task',
				'hook' => 'bulk_hook',
				'args' => array( 'payload' => array_fill( 0, 500, 'value' ) ),
			)
		);

		$this->assertIsString( $trimmed['args'] );
		$this->assertLessThanOrEqual(
			WP_MCP_AI_Pro_Schedule_Manager::MAX_HISTORY_TEXT_LENGTH + 4,
			strlen( $trimmed['args'] )
		);
	}

	/**
	 * Absent keys must not be invented.
	 */
	public function test_missing_keys_are_not_added() {
		$trimmed = $this->trim( array( 'type' => 'task' ) );

		$this->assertSame( array( 'type' => 'task' ), $trimmed );
	}
}
